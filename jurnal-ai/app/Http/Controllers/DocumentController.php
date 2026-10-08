<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountingException;
use App\Models\Client;
use App\Models\Document;
use App\Models\Journal;
use App\Services\Accounting\JournalPoster;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProviderFactory;
use App\Services\Intake\DocumentExtractor;
use App\Services\Intake\JournalDrafter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Alur inti: UPLOAD → AI BACA → REVIEW MANUSIA → POSTING.
 *
 * Langkah review tidak bisa dilewati. Hasil baca AI bagus untuk menghemat
 * ketikan, tapi dia tetap bisa salah baca angka buram atau salah pilih akun —
 * jadi yang masuk buku selalu angka yang sudah dilihat manusia.
 */
class DocumentController extends Controller
{
    public function __construct(
        private DocumentExtractor $extractor,
        private JournalDrafter $drafter,
        private JournalPoster $poster,
    ) {}

    public function index(Client $client, Request $request)
    {
        return view('documents.index', [
            'client' => $client,
            'documents' => $client->documents()
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
                ->withCount(['journals' => fn ($q) => $q->where('status', '!=', Journal::STATUS_VOID)])
                ->latest()->paginate(20)->withQueryString(),
            'status' => $request->string('status')->toString(),
            'kind' => $request->string('kind')->toString(),
        ]);
    }

    public function create(Client $client)
    {
        return view('documents.create', [
            'client' => $client,
            'aiReady' => AiProviderFactory::configured(),
            'maxKb' => (int) config('ai.intake.max_upload_kb'),
        ]);
    }

    /**
     * Terima upload atau teks tempel, lalu langsung minta AI membacanya.
     * Dokumen tetap tersimpan walau pembacaan gagal — user bisa ulangi tanpa
     * upload lagi.
     */
    public function store(Client $client, Request $request): RedirectResponse
    {
        $maxKb = (int) config('ai.intake.max_upload_kb');

        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Document::KINDS))],
            'title' => ['nullable', 'string', 'max:190'],
            'file' => ['nullable', 'file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'],
            'raw_text' => ['nullable', 'string', 'max:50000'],
        ], [
            'file.max' => "Berkas maksimal {$maxKb} KB. Kompres gambarnya dulu.",
            'file.mimes' => 'Format didukung: JPG, PNG, WEBP, HEIC, atau PDF.',
        ]);

        if (blank($data['file'] ?? null) && blank($data['raw_text'] ?? null)) {
            return back()->withInput()->withErrors(['file' => 'Upload berkas atau tempel teksnya — salah satu harus ada.']);
        }

        $attributes = [
            'kind' => $data['kind'],
            'title' => $data['title'] ?? null,
            'raw_text' => $data['raw_text'] ?? null,
            'uploaded_by' => $request->user()->id,
            'status' => Document::STATUS_UPLOADED,
        ];

        if ($file = $request->file('file')) {
            $bytes = (string) file_get_contents($file->getRealPath());
            $attributes += [
                'original_name' => $file->getClientOriginalName(),
                'disk' => 'local',
                'path' => $file->store("dokumen/{$client->id}", 'local'),
                // MIME dari isi berkas (server), bukan klaim browser.
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size' => $file->getSize(),
                'content_hash' => hash('sha256', $bytes),
            ];
        } else {
            $attributes['content_hash'] = hash('sha256', trim($data['raw_text']));
        }

        // Peringatkan kalau dokumen identik sudah pernah diolah — tidak diblokir
        // (kadang memang perlu dicatat ulang), tapi user harus sadar.
        $duplicate = $client->documents()
            ->where('content_hash', $attributes['content_hash'])
            ->where('status', '!=', Document::STATUS_FAILED)
            ->first();

        $document = $client->documents()->create($attributes);

        try {
            $this->extractor->extract($document);
        } catch (AiException $e) {
            return redirect()->route('documents.show', [$client, $document])
                ->withErrors(['ai' => $e->getMessage()]);
        }

        return redirect()->route('documents.show', [$client, $document])
            ->with('status', 'Dokumen sudah dibaca AI. Periksa rincian & akunnya, lalu posting.')
            ->with('duplicate_of', $duplicate?->id);
    }

    /** Layar review: rincian hasil baca AI + usulan jurnal yang bisa diedit. */
    public function show(Client $client, Document $document)
    {
        $this->assertOwned($client, $document);

        return view('documents.show', [
            'client' => $client,
            'document' => $document,
            'drafts' => $document->status === Document::STATUS_EXTRACTED ? $this->drafter->draft($document) : [],
            'accounts' => $client->accounts()->active()->orderBy('code')->get(),
            'postedJournals' => $document->journals()->with('lines.account')->get(),
            'lowConfidence' => DocumentExtractor::LOW_CONFIDENCE,
        ]);
    }

    /** Berkas asli — dilayani lewat route (disk `local`, tidak publik). */
    public function file(Client $client, Document $document)
    {
        $this->assertOwned($client, $document);
        abort_if(blank($document->path), 404);

        $disk = Storage::disk($document->disk ?: 'local');
        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
        ]);
    }

    /** Baca ulang — dipakai kalau hasil pertama ngawur atau sempat gagal. */
    public function reextract(Client $client, Document $document): RedirectResponse
    {
        $this->assertOwned($client, $document);

        try {
            $this->extractor->extract($document);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $e->getMessage()]);
        }

        return back()->with('status', 'Dokumen dibaca ulang oleh AI.');
    }

    /**
     * Posting usulan jurnal yang sudah direview. Semua baris datang dari form,
     * bukan dari hasil AI yang disimpan — jadi koreksi user-lah yang masuk buku.
     */
    public function post(Client $client, Document $document, Request $request): RedirectResponse
    {
        $this->assertOwned($client, $document);

        $data = $request->validate([
            'drafts' => ['required', 'array', 'min:1'],
            'drafts.*.post' => ['nullable', 'boolean'],
            'drafts.*.date' => ['required_with:drafts.*.post', 'nullable', 'date'],
            'drafts.*.reference' => ['nullable', 'string', 'max:190'],
            'drafts.*.description' => ['nullable', 'string', 'max:500'],
            'drafts.*.type' => ['nullable', 'string', 'max:30'],
            'drafts.*.fingerprint' => ['nullable', 'string', 'max:64'],
            'drafts.*.lines' => ['required_with:drafts.*.post', 'array'],
            'drafts.*.lines.*.account_id' => ['nullable', 'integer'],
            'drafts.*.lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'drafts.*.lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'drafts.*.lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $posted = 0;
        $duplicate = 0;
        $errors = [];

        try {
            DB::transaction(function () use ($data, $client, $document, $request, &$posted, &$duplicate, &$errors) {
                foreach ($data['drafts'] as $i => $draft) {
                    if (! filter_var($draft['post'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        continue;
                    }

                    $fingerprint = $draft['fingerprint'] ?? null;
                    if ($fingerprint && $this->poster->alreadyPosted($client->id, $fingerprint)) {
                        $duplicate++;

                        continue;
                    }

                    try {
                        $this->poster->record([
                            'client_id' => $client->id,
                            'document_id' => $document->id,
                            'date' => $draft['date'],
                            'reference' => $draft['reference'] ?? null,
                            'description' => $draft['description'] ?? null,
                            'type' => $draft['type'] ?? 'expense',
                            'fingerprint' => $fingerprint,
                            'created_by' => $request->user()->id,
                        ], $draft['lines'] ?? []);
                        $posted++;
                    } catch (AccountingException $e) {
                        $errors[] = 'Jurnal #'.($i + 1).': '.$e->getMessage();
                    }
                }

                if ($errors !== []) {
                    // Gagal sebagian = batalkan semua. Setengah dokumen masuk buku
                    // lebih berbahaya daripada tidak masuk sama sekali.
                    throw new AccountingException(implode(' ', $errors));
                }

                if ($posted > 0) {
                    $document->update(['status' => Document::STATUS_POSTED]);
                }
            });
        } catch (AccountingException) {
            return back()->withInput()->withErrors(['posting' => $errors ?: ['Posting dibatalkan.']]);
        }

        if ($posted === 0 && $duplicate === 0) {
            return back()->withErrors(['posting' => 'Tidak ada jurnal yang dicentang untuk diposting.']);
        }

        $message = "{$posted} jurnal diposting.";
        if ($duplicate > 0) {
            $message .= " {$duplicate} dilewati karena sidik jarinya sudah pernah masuk buku (anti-dobel).";
        }

        return redirect()->route('documents.show', [$client, $document])->with('status', $message);
    }

    public function destroy(Client $client, Document $document): RedirectResponse
    {
        $this->assertOwned($client, $document);

        // Jurnal yang sudah diposting tetap tinggal (document_id jadi null) —
        // menghapus dokumen tidak boleh mengubah angka di laporan.
        if (filled($document->path)) {
            Storage::disk($document->disk ?: 'local')->delete($document->path);
        }
        $document->delete();

        return redirect()->route('documents.index', $client)
            ->with('status', 'Dokumen dihapus. Jurnal yang sudah diposting tetap tersimpan.');
    }

    private function assertOwned(Client $client, Document $document): void
    {
        abort_unless($document->client_id === $client->id, 404);
    }
}
