<?php

namespace Tests\Feature;

use App\Support\PdfTextExtractor;
use App\Support\PdfUnicodeText;
use Tests\TestCase;

/**
 * PdfUnicodeText: teks PDF ber-font CID / Identity-H (gaya Canva, Google Docs, Word) diterjemahkan lewat CMap
 * ToUnicode — string literal 2-byte, hex, larik TJ, bfchar & bfrange, object stream, plus font sederhana (Latin-1).
 */
class PdfUnicodeTextTest extends TestCase
{
    private const CMAP = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
        ."3 beginbfchar <0001> <0052> <0002> <0069> <0005> <0020> endbfchar\n"
        ."2 beginbfrange <0010> <0012> <0041> <0020> <0021> [<006E> <0061>] endbfrange\nendcmap";

    /** Kode 0001=R 0002=i 0020=n 0021=a 0005=spasi 0010..0012=A..C; /F2 font sederhana tanpa ToUnicode. */
    private const KONTEN = "BT /F1 12 Tf 1 0 0 -1 10 20 Tm (\\000\\001\\000\\002) Tj <00200021> Tj [(\\000\\005) -300 <001000110012>] TJ ET\n"
        .'BT /F2 10 Tf 1 0 0 1 10 40 Tm (Halo dunia) Tj 0 -12 Td (baris \\(dua\\)) Tj ET';

    private function stream(string $isi, bool $flate = true): string
    {
        $data = $flate ? gzcompress($isi) : $isi;

        return '<< /Length '.strlen($data).($flate ? ' /Filter /FlateDecode' : '')." >>\nstream\n{$data}\nendstream";
    }

    /** @param  array<int, string>  $objek */
    private function pdf(array $objek): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        $isi = "%PDF-1.4\n";
        foreach ($objek as $id => $badan) {
            $isi .= "{$id} 0 obj\n{$badan}\nendobj\n";
        }
        file_put_contents($path, $isi.'%%EOF');

        return $path;
    }

    private function objekDasar(): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Page /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /Contents 7 0 R >>',
            4 => '<< /Type /Font /Subtype /Type0 /BaseFont /AAAAAA+OpenSauce /Encoding /Identity-H /ToUnicode 5 0 R >>',
            5 => $this->stream(self::CMAP),
            6 => '<< /Type /Font /Subtype /TrueType /BaseFont /Arial >>',
            7 => $this->stream(self::KONTEN),
        ];
    }

    public function test_font_cid_diterjemahkan_lewat_tounicode(): void
    {
        $path = $this->pdf($this->objekDasar());

        $this->assertSame("Rina ABC\nHalo dunia\nbaris (dua)", PdfUnicodeText::extract($path));
        // Ekstraktor lama tak bisa membaca kode 2-byte → tetap jatuh ke jalur berkas untuk Report Bot.
        $this->assertStringNotContainsString('Rina', PdfTextExtractor::extract($path));
    }

    public function test_kamus_font_di_object_stream(): void
    {
        $objek = $this->objekDasar();
        $kepala = '4 0 ';
        $objek[8] = '<< /Type /ObjStm /N 1 /First '.strlen($kepala).' /Length 0 /Filter /FlateDecode >>'
            ."\nstream\n".gzcompress($kepala.$objek[4])."\nendstream";
        unset($objek[4]);

        $this->assertSame("Rina ABC\nHalo dunia\nbaris (dua)", PdfUnicodeText::extract($this->pdf($objek)));
    }

    public function test_stream_gambar_dilewati_walau_bytenya_mirip_teks(): void
    {
        $objek = $this->objekDasar();
        // JPEG lampiran scan yang kebetulan memuat byte "BT (…) Tj" — dulu muncul sebagai teks sampah.
        $jpeg = "\xFF\xD8\xFF\xE0 BT (SAMPAH BINER) Tj ET \xFF\xD9";
        $objek[9] = '<</Subtype/Image/Type/XObject/Filter/DCTDecode/Length '.strlen($jpeg).">>\nstream\n{$jpeg}\nendstream";

        $teks = PdfUnicodeText::extract($this->pdf($objek));
        $this->assertStringNotContainsString('SAMPAH', $teks);
        $this->assertStringContainsString('Rina ABC', $teks);
    }

    public function test_baris_ditentukan_posisi_vertikal(): void
    {
        $konten = implode("\n", [
            // Tiap kata di blok BT sendiri (gaya pembuat lamaran dart_pdf): segaris → digabung dengan spasi.
            'BT /F2 12 Tf 10 700 Td (Nama) Tj ET BT /F2 12 Tf 60 700 Td (Lengkap) Tj ET BT /F2 12 Tf 10 680 Td (Baris) Tj ET',
            // Tiap huruf digeser Td di blok yang sama (gaya Canva): tanpa spasi tambahan.
            'BT /F1 12 Tf 1 0 0 -1 10 600 Tm (\\000\\001) Tj 7 0 Td (\\000\\002) Tj 5 0 Td <0020> Tj ET',
            // Posisi lewat CTM (cm) di dalam q/Q: Td sama, baris beda.
            'q 1 0 0 1 0 500 cm BT /F2 12 Tf 0 0 Td (atas) Tj ET Q q 1 0 0 1 0 480 cm BT /F2 12 Tf 0 0 Td (bawah) Tj ET Q',
        ]);
        $objek = $this->objekDasar();
        $objek[7] = $this->stream($konten);

        $this->assertSame("Nama Lengkap\nBaris\nRin\natas\nbawah", PdfUnicodeText::extract($this->pdf($objek)));
    }

    public function test_font_sederhana_tetap_1_byte_walau_codespace_2_byte(): void
    {
        // Gaya Word: TrueType WinAnsi + ToUnicode ber-codespacerange <0000> <FFFF> tapi kodenya 1 byte.
        $cmap = "begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n"
            ."6 beginbfchar <20> <0020> <49> <0049> <4E> <004E> <4F> <004F> <50> <0050> <52> <0052> endbfchar\nendcmap";
        $objek = [
            3 => '<</Type/Page/Resources<</Font<</TT0 4 0 R>>>>/Contents 6 0 R>>',
            4 => '<</BaseFont/ABCDEF+Calibri/Encoding/WinAnsiEncoding/Subtype/TrueType/ToUnicode 5 0 R/Type/Font>>',
            5 => $this->stream($cmap),
            6 => $this->stream('BT /TT0 1 Tf 12 0 0 12 50 700 Tm [(P)-5 (ON)1.6 ( I)-10 (RON)]TJ ET'),
        ];

        $this->assertSame('PON IRON', PdfUnicodeText::extract($this->pdf($objek)));
    }

    public function test_nama_font_sama_beda_halaman_pakai_resources_masing_masing(): void
    {
        $cmapA = "begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n1 beginbfchar <0001> <0041> endbfchar\nendcmap";
        $cmapB = "begincmap\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n1 beginbfchar <0001> <0042> endbfchar\nendcmap";
        $objek = [
            2 => '<</Type/Pages/Kids[3 0 R 7 0 R]/Count 2>>',
            3 => '<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>/Contents 6 0 R>>',
            4 => '<</Type/Font/Subtype/Type0/Encoding/Identity-H/ToUnicode 5 0 R>>',
            5 => $this->stream($cmapA),
            6 => $this->stream('BT /F1 12 Tf 10 700 Td <00010001> Tj ET'),
            7 => '<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 8 0 R>>>>/Contents 10 0 R>>',
            8 => '<</Type/Font/Subtype/Type0/Encoding/Identity-H/ToUnicode 9 0 R>>',
            9 => $this->stream($cmapB),
            10 => $this->stream('BT /F1 12 Tf 10 700 Td <000100010001> Tj ET'),
        ];

        $this->assertSame("AA\nBBB", PdfUnicodeText::extract($this->pdf($objek)));
    }

    public function test_pdf_tanpa_teks_kosong(): void
    {
        $this->assertSame('', PdfUnicodeText::extract($this->pdf([1 => '<< /Type /Catalog >>', 2 => $this->stream('q 1 0 0 1 0 0 cm Q')])));
        $this->assertSame('', PdfUnicodeText::extract(sys_get_temp_dir().'/tidak-ada-'.uniqid().'.pdf'));
    }
}
