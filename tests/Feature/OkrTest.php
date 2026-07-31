<?php

namespace Tests\Feature;

use App\Models\AiKnowledge;
use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardColumn;
use App\Models\OkrCycle;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;
use App\Services\OkrBusinessSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class OkrTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $name): User
    {
        return User::create([
            'name' => $name,
            'fullname' => strtoupper($name),
            'username' => $name,
            'email' => "{$name}@skinku.test",
            'password' => Hash::make('secret123'),
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    /** @return array{0:Board,1:BoardColumn,2:BoardColumn} */
    private function board(User $creator): array
    {
        $board = Board::create(['name' => 'Papan Marketing', 'created_by' => $creator->id]);
        $todo = $board->columns()->create(['name' => 'To Do BILLY', 'position' => 0]);
        $done = $board->columns()->create(['name' => 'Done BILLY', 'position' => 1]);

        return [$board, $todo, $done];
    }

    private function fakeDraft(
        User $member,
        int $columnId,
        array $overrides = [],
        array $proposalOverrides = [],
    ): FakeAiProvider {
        $args = array_replace_recursive([
            'name' => 'OKR Pertumbuhan Q3',
            'analysis_summary' => 'Data tiga fungsi menunjukkan pertumbuhan penjualan harus dijalankan bersama pengendalian margin dan kesiapan stok. Target tidak boleh langsung diterjemahkan menjadi campaign; tim perlu menutup gap omzet dengan prioritas channel yang terbukti, menjaga kas, dan memastikan produk tersedia sebelum skala dinaikkan.',
            'evidence' => [
                [
                    'source_path' => 'cmo.penjualan.total_sales',
                    'interpretation' => 'Nilai penjualan bulan referensi menjadi dasar untuk menghitung gap omzet menuju target periode.',
                ],
                [
                    'source_path' => 'cfo.laba_rugi.penjualan_bersih',
                    'interpretation' => 'Penjualan bersih akuntansi dipakai untuk menguji apakah pertumbuhan menghasilkan kualitas pendapatan yang sehat.',
                ],
                [
                    'source_path' => 'coo.stok.total_hq',
                    'interpretation' => 'Jumlah stok HQ membatasi kecepatan campaign dan harus dibandingkan dengan kebutuhan unit penjualan.',
                ],
            ],
            'assumptions' => [
                'Produktivitas affiliate per orang belum tersimpan dan harus divalidasi sebelum target aktivasi dibagi.',
            ],
            'conflicts' => [[
                'issue' => 'Pertumbuhan omzet dapat mendorong diskon dan kebutuhan stok lebih cepat daripada kesiapan kas.',
                'impact' => 'Margin, cashflow, dan service level berisiko turun jika campaign diluncurkan tanpa gate.',
                'decision_required' => 'BOD menetapkan batas diskon, margin minimum, dan kesiapan stok sebelum scale-up.',
            ]],
            'objectives' => [[
                'specialist' => 'cmo',
                'title' => 'Percepat pertumbuhan TikTok',
                'description' => 'Pertumbuhan yang sehat dan terukur.',
                'rationale' => 'Objective dipilih karena gap omzet harus ditutup lewat channel yang terukur tanpa mengabaikan batas margin dan kapasitas operasional.',
                'owner_user_id' => $member->id,
                'key_results' => [[
                    'title' => 'Naikkan omzet TikTok 30%',
                    'metric' => 'Pertumbuhan omzet',
                    'target' => '30%',
                    'baseline_status' => 'actual',
                    'baseline_source_path' => 'cmo.penjualan.total_sales',
                    'baseline_interpretation' => 'Omzet semua channel pada bulan referensi dari laporan penjualan sistem.',
                    'target_gap' => 'Selisih dari omzet bulan referensi menuju pertumbuhan 30% harus ditutup melalui conversion dan repeat order.',
                    'owner_user_id' => $member->id,
                    'due_date' => '2026-09-30',
                    'tasks' => [[
                        'title' => 'Susun kalender konten TikTok',
                        'description' => 'Buat kalender empat minggu.',
                        'assignee_user_id' => $member->id,
                        'board_column_id' => $columnId,
                        'due_date' => '2026-08-15',
                    ]],
                ]],
            ]],
        ], $overrides);

        $proposal = function (string $id, string $label) use ($proposalOverrides) {
            $specialist = strtolower($label);
            $paths = match ($specialist) {
                'cfo' => ['cfo.laba_rugi.penjualan_bersih', 'cfo.laba_rugi.hpp'],
                'coo' => ['coo.stok.total_hq', 'coo.stok.total_partner'],
                default => ['cmo.penjualan.total_sales', 'cmo.penjualan.total_po'],
            };

            $arguments = array_replace_recursive([
                'analysis' => "Analisis {$label} membandingkan kondisi aktual dengan sasaran periode, menguji akar gap, dan memilih intervensi dengan dampak paling masuk akal.",
                'facts' => [
                    [
                        'source_path' => $paths[0],
                        'finding' => 'Angka pertama menunjukkan kondisi aktual yang harus menjadi titik awal, bukan target buatan.',
                    ],
                    [
                        'source_path' => $paths[1],
                        'finding' => 'Angka kedua menunjukkan batas atau kapasitas yang perlu diperhitungkan sebelum memilih strategi.',
                    ],
                ],
                'target_gap_analysis' => 'Gap target belum bisa ditutup hanya dengan menambah aktivitas; fungsi ini perlu memprioritaskan pengungkit terukur dan gate evaluasi.',
                'data_gaps' => ['Produktivitas per aktivitas belum tersedia lengkap di sistem.'],
                'tradeoffs' => ['Kecepatan eksekusi harus diseimbangkan dengan kualitas hasil dan kapasitas tim.'],
                'risks' => ['Kapasitas tim'],
                'objectives' => [[
                    'title' => "Usulan {$label}",
                    'rationale' => 'Berdasarkan data aktual.',
                    'key_results' => [[
                        'title' => 'Hasil terukur',
                        'metric' => 'Persentase',
                        'target' => '20%',
                        'workstreams' => ['Eksekusi lintas fungsi'],
                    ]],
                ]],
            ], $proposalOverrides[$specialist] ?? []);

            return new AiTurn(toolCalls: [[
                'id' => $id,
                'name' => 'usulkan_okr_spesialis',
                'arguments' => $arguments,
            ]]);
        };

        return new FakeAiProvider([
            $proposal('panel-cmo', 'CMO'),
            $proposal('panel-cfo', 'CFO'),
            $proposal('panel-coo', 'COO'),
            new AiTurn(toolCalls: [[
                'id' => 'okr-final',
                'name' => 'susun_draf_okr',
                'arguments' => $args,
            ]]),
        ]);
    }

    private function generatePayload(?int $boardId = null): array
    {
        return [
            'period_type' => 'quarterly',
            'period_year' => 2026,
            'period_quarter' => 3,
            'scope_type' => 'company',
            'preferred_board_id' => $boardId,
            'direction' => 'Naikkan penjualan TikTok tanpa membuat beban tim timpang.',
        ];
    }

    public function test_hak_akses_dan_halaman_baru_render_ok(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrsa');
        $admin = $this->user(User::ROLE_ADMIN, 'okradm');
        $partner = $this->user(User::ROLE_DISTRIBUTOR, 'okrmitra');

        $response = $this->actingAs($super)->get(route('okr.create'));
        $response->assertOk()
            ->assertSee('Susun OKR dengan AI')
            ->assertSee('Bulanan')
            ->assertSee('Kuartalan');
        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('okr.index').'"'));
        $this->actingAs($admin)->get(route('okr.index'))->assertOk();
        $this->actingAs($admin)->get(route('okr.create'))->assertForbidden();
        $this->actingAs($partner)->get(route('okr.index'))->assertForbidden();
    }

    public function test_ai_membuat_draf_dari_pengetahuan_tanpa_membuat_kartu(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrsuper');
        $member = $this->user(User::ROLE_ADMIN, 'billy');
        [$board, $todo] = $this->board($super);
        AiKnowledge::create(['section' => 'okr_strategy', 'content' => 'Target tahunan omzet sepuluh miliar.']);
        $fake = $this->fakeDraft($member, $todo->id);
        $this->app->instance(AiProvider::class, $fake);

        $response = $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id));

        $cycle = OkrCycle::firstOrFail();
        $response->assertRedirect(route('okr.show', $cycle));
        $this->assertTrue($cycle->isDraft());
        $this->assertSame('Q3 2026', $cycle->period_label);
        $this->assertSame('2026-07-01', $cycle->start_date->toDateString());
        $this->assertSame('2026-09-30', $cycle->end_date->toDateString());
        $this->assertDatabaseHas('okr_tasks', [
            'title' => 'Susun kalender konten TikTok',
            'assignee_user_id' => $member->id,
            'board_column_id' => $todo->id,
            'board_card_id' => null,
        ]);
        $this->assertSame(0, BoardCard::count());
        $this->assertGreaterThanOrEqual(3, count($cycle->analysis_evidence));
        $this->assertSame(0.0, (float) $cycle->analysis_evidence[0]['value']);
        $this->assertSame('cmo.penjualan.total_sales', $cycle->analysis_evidence[0]['source_path']);

        $this->assertCount(4, $fake->sent);
        $this->assertStringContainsString('spesialis CMO AI', $fake->sent[0]['messages'][0]['content']);
        $this->assertStringContainsString('spesialis CFO AI', $fake->sent[1]['messages'][0]['content']);
        $this->assertStringContainsString('spesialis COO AI', $fake->sent[2]['messages'][0]['content']);
        $this->assertStringContainsString('AI Orchestrator', $fake->sent[3]['messages'][0]['content']);
        $this->assertStringContainsString('Target tahunan omzet sepuluh miliar', $fake->sent[0]['messages'][1]['content']);
        $orchestrator = $fake->sent[3]['messages'][1]['content'];
        $this->assertStringContainsString('Papan Marketing', $orchestrator);
        $this->assertStringContainsString('BILLY', $orchestrator);
        $this->assertStringContainsString('Usulan CFO', $orchestrator);

        $this->actingAs($super)->get(route('okr.show', $cycle))->assertOk()
            ->assertSee('Pratinjau')
            ->assertSee('CMO AI')
            ->assertSee('Fakta server')
            ->assertSee('fakta terverifikasi')
            ->assertSee('bisa berbeda tiap generate')
            ->assertSee('Gap ke target')
            ->assertSee('Percepat pertumbuhan TikTok')
            ->assertSee('Susun kalender konten TikTok')
            ->assertSee('Edit Objective')
            ->assertSee('Simpan Perubahan')
            ->assertDontSee('Koreksi manual');
    }

    public function test_periode_bulanan_dan_cakupan_individu_tersimpan_benar(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrmonthly');
        $member = $this->user(User::ROLE_ADMIN, 'monthlyowner');
        [$board, $todo] = $this->board($super);
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id, [
            'objectives' => [[
                'key_results' => [[
                    'due_date' => '2026-08-31',
                    'tasks' => [['due_date' => '2026-08-20']],
                ]],
            ]],
        ]));

        $this->actingAs($super)->post(route('okr.generate'), [
            'period_type' => 'monthly',
            'period_month' => '2026-08',
            'scope_type' => 'individual',
            'scope_owner_user_id' => $member->id,
            'preferred_board_id' => $board->id,
            'direction' => 'Buat OKR individu yang terukur.',
        ])->assertRedirect();

        $cycle = OkrCycle::firstOrFail();
        $this->assertSame('2026-08-01', $cycle->start_date->toDateString());
        $this->assertSame('2026-08-31', $cycle->end_date->toDateString());
        $this->assertSame(OkrCycle::SCOPE_INDIVIDUAL, $cycle->scope_type);
        $this->assertSame($member->id, $cycle->scope_owner_user_id);
        $this->assertStringContainsString('MONTHLYOWNER', $cycle->scopeLabel());
    }

    public function test_pratinjau_bisa_diedit_lalu_approval_membuat_kartu_ai(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrapprove');
        $member = $this->user(User::ROLE_ADMIN, 'agatha');
        [$board, $todo] = $this->board($super);
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id));
        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id));

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $objective = $cycle->objectives->first();
        $kr = $objective->keyResults->first();
        $task = $kr->tasks->first();
        $approvalTask = $kr->tasks->last();

        $this->actingAs($super)->put(route('okr.update', $cycle), [
            'name' => 'OKR Q3 Final',
            'direction' => $cycle->direction,
            'objectives' => [
                $objective->id => [
                    'specialist' => 'cmo',
                    'title' => 'Objective hasil koreksi',
                    'description' => $objective->description,
                    'rationale' => $objective->rationale,
                    'owner_user_id' => $member->id,
                ],
            ],
            'key_results' => [
                $kr->id => [
                    'title' => 'KR hasil koreksi',
                    'metric' => 'Omzet',
                    'target' => '35%',
                    'baseline_status' => $kr->baseline_status,
                    'baseline' => $kr->baseline,
                    'baseline_source' => $kr->baseline_source,
                    'target_gap' => $kr->target_gap,
                    'owner_user_id' => $member->id,
                    'due_date' => '2026-09-30',
                ],
            ],
            'tasks' => [
                $task->id => [
                    'title' => 'Kartu hasil koreksi',
                    'description' => 'Deskripsi final.',
                    'assignee_user_id' => $member->id,
                    'board_column_id' => $todo->id,
                    'due_date' => '2026-08-20',
                ],
                $approvalTask->id => [
                    'title' => $approvalTask->title,
                    'description' => $approvalTask->description,
                    'assignee_user_id' => $approvalTask->assignee_user_id,
                    'assignee_name' => $approvalTask->assignee_name,
                    'board_column_id' => $approvalTask->board_column_id,
                    'due_date' => $approvalTask->due_date->toDateString(),
                ],
            ],
        ])->assertRedirect(route('okr.show', $cycle));

        $this->actingAs($super)->post(route('okr.approve', $cycle))
            ->assertRedirect(route('okr.show', $cycle));

        $cycle->refresh();
        $this->assertSame(OkrCycle::STATUS_ACTIVE, $cycle->status);
        $this->assertNotNull($cycle->approved_at);
        $card = BoardCard::where('title', 'Kartu hasil koreksi')->firstOrFail();
        $this->assertSame('Kartu hasil koreksi', $card->title);
        $this->assertSame($member->id, $card->assignee_user_id);
        $this->assertSame($todo->id, $card->column_id);
        $this->assertSame('ai', $card->created_via);
        $this->assertStringContainsString('Spesialis AI: CMO', $card->description);
        $this->assertStringContainsString('Objective hasil koreksi', $card->description);
        $this->assertDatabaseHas('okr_tasks', ['id' => $task->id, 'board_card_id' => $card->id]);

        // Klik ulang tak boleh menduplikasi kartu.
        $this->actingAs($super)->post(route('okr.approve', $cycle))->assertSessionHasErrors('okr');
        $this->assertSame(2, BoardCard::count());
    }

    public function test_progres_okr_otomatis_mengikuti_kartu_done(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrprogress');
        $member = $this->user(User::ROLE_ADMIN, 'tiar');
        [$board, $todo, $done] = $this->board($super);
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id));
        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id));
        $cycle = OkrCycle::firstOrFail();
        $this->actingAs($super)->post(route('okr.approve', $cycle));

        $this->actingAs($member)->get(route('okr.show', $cycle))
            ->assertOk()
            ->assertSee('0%');

        $cards = BoardCard::all();
        $cards->each->update(['column_id' => $done->id]);
        $this->assertTrue($cards->every(fn (BoardCard $card) => $card->fresh()->completed_at !== null));

        $this->actingAs($member)->get(route('okr.show', $cycle))
            ->assertOk()
            ->assertSee('100%')
            ->assertSee('✓');

        $cards->each->update(['column_id' => $todo->id]);
        $this->assertTrue($cards->every(fn (BoardCard $card) => $card->fresh()->completed_at === null));
        $this->actingAs($member)->get(route('okr.index'))->assertOk()->assertSee('0%');
    }

    public function test_id_ai_yang_tidak_valid_dinormalisasi_untuk_preview(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrdefensive');
        $member = $this->user(User::ROLE_ADMIN, 'grace');
        [$board, $todo] = $this->board($super);
        $fake = $this->fakeDraft($member, 999999, [
            'objectives' => [[
                'key_results' => [[
                    'tasks' => [[
                        'assignee_user_id' => 999999,
                        'board_column_id' => 999999,
                    ]],
                ]],
            ]],
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id));

        $task = OkrCycle::firstOrFail()->objectives()->first()->keyResults()->first()->tasks()->first();
        $this->assertNull($task->assignee_user_id);
        $this->assertSame($todo->id, $task->board_column_id);
        $this->assertSame(0, BoardCard::count());
    }

    public function test_pic_owner_detail_kolom_dan_tenggat_diisi_otomatis_dari_pengetahuan_ai(): void
    {
        $this->travelTo('2026-07-29 10:00:00');

        $super = $this->user(User::ROLE_SUPER_ADMIN, 'autoowner');
        $freddie = $this->user(User::ROLE_ADMIN, 'freddie');
        $this->user(User::ROLE_ADMIN, 'billy');
        $this->user(User::ROLE_ADMIN, 'devrina');
        $agatha = $this->user(User::ROLE_ADMIN, 'agatha');
        $tiar = $this->user(User::ROLE_ADMIN, 'tiar');

        $board = Board::create(['name' => 'Task SKINKU Management', 'created_by' => $super->id]);
        $agathaColumn = $board->columns()->create(['name' => 'To Do Agatha', 'position' => 0]);
        $tiarColumn = $board->columns()->create(['name' => 'To Do Tiar', 'position' => 1]);
        $board->columns()->create(['name' => 'Done', 'position' => 2]);

        AiKnowledge::create([
            'section' => 'team',
            'content' => implode("\n", [
                '- Billy — CFO. Keuangan, margin, dan pembayaran.',
                '- Freddie — CMO. Marketing, branding, konten, dan campaign.',
                '- Devrina — COO. Operasional, stok, dan pengiriman.',
                '- Desain, visual, poster, cover, materi promosi → Agatha',
                '- Rekrut affiliate, onboarding KOL, request sample → Tiar',
            ]),
        ]);

        $fake = $this->fakeDraft($super, $tiarColumn->id, [
            'objectives' => [[
                'specialist' => 'cmo',
                'owner_user_id' => $super->id,
                'key_results' => [[
                    'owner_user_id' => $super->id,
                    'tasks' => [[
                        'title' => 'Rancang materi promosi untuk parfum',
                        'description' => '',
                        'assignee_user_id' => $tiar->id,
                        'board_column_id' => $tiarColumn->id,
                        'due_date' => '2026-07-15',
                    ]],
                ]],
            ]],
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $objective = $cycle->objectives->first();
        $kr = $objective->keyResults->first();
        $task = $kr->tasks->first();
        $approvalTask = $kr->tasks->last();

        $this->assertSame($freddie->id, $objective->owner_user_id);
        $this->assertSame('Freddie', $objective->owner_name);
        $this->assertSame($freddie->id, $kr->owner_user_id);
        $this->assertSame('Freddie', $kr->owner_name);
        $this->assertSame($agatha->id, $task->assignee_user_id);
        $this->assertSame($agathaColumn->id, $task->board_column_id);
        $this->assertNotEmpty($task->description);
        $this->assertSame('2026-09-30', $task->due_date->toDateString());

        $this->actingAs($super)->put(route('okr.update', $cycle), [
            'name' => $cycle->name,
            'direction' => $cycle->direction,
            'objectives' => [
                $objective->id => [
                    'specialist' => $objective->specialist,
                    'title' => $objective->title,
                    'description' => $objective->description,
                    'rationale' => $objective->rationale,
                    'owner_user_id' => $objective->owner_user_id,
                ],
            ],
            'key_results' => [
                $kr->id => [
                    'title' => $kr->title,
                    'metric' => $kr->metric,
                    'target' => $kr->target,
                    'baseline_status' => $kr->baseline_status,
                    'baseline' => $kr->baseline,
                    'baseline_source' => $kr->baseline_source,
                    'target_gap' => $kr->target_gap,
                    'owner_user_id' => $kr->owner_user_id,
                    'due_date' => $kr->due_date->toDateString(),
                ],
            ],
            'tasks' => [
                $task->id => [
                    'title' => $task->title,
                    'description' => $task->description,
                    'assignee_user_id' => $agatha->id,
                    'board_column_id' => $tiarColumn->id,
                    'due_date' => $task->due_date->toDateString(),
                ],
                $approvalTask->id => [
                    'title' => $approvalTask->title,
                    'description' => $approvalTask->description,
                    'assignee_user_id' => $approvalTask->assignee_user_id,
                    'assignee_name' => $approvalTask->assignee_name,
                    'board_column_id' => $approvalTask->board_column_id,
                    'due_date' => $approvalTask->due_date->toDateString(),
                ],
            ],
        ])->assertRedirect(route('okr.show', $cycle));

        // Kolom pilihan manusia saat edit DIHORMATI, tidak ditimpa normalisasi
        // otomatis (auto-match kolom hanya berlaku saat generate).
        $this->assertSame($tiarColumn->id, $task->fresh()->board_column_id);

        $this->actingAs($super)->get(route('okr.show', $cycle))
            ->assertOk()
            ->assertSee('Pratinjau OKR')
            ->assertSee('Edit Objective')
            ->assertSee('Setujui & Buat 2 Kartu', false)
            ->assertSee('Penanggung jawab:');
    }

    public function test_bod_tanpa_akun_portal_tetap_tersimpan_sebagai_penanggung_jawab(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'textowner');
        $board = Board::create(['name' => 'Papan BOD', 'created_by' => $super->id]);
        $todo = $board->columns()->create(['name' => 'To Do Freddie', 'position' => 0]);
        $board->columns()->create(['name' => 'Done Freddie', 'position' => 1]);
        AiKnowledge::create([
            'section' => 'team',
            'content' => implode("\n", [
                '- Billy — CFO. Keuangan dan margin.',
                '- Freddie — CMO. Marketing dan branding.',
                '- Devrina — COO. Operasional dan stok.',
            ]),
        ]);
        $this->app->instance(AiProvider::class, $this->fakeDraft($super, $todo->id));

        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults')->firstOrFail();
        $objective = $cycle->objectives->first();
        $kr = $objective->keyResults->first();

        $this->assertSame($super->id, $objective->owner_user_id);
        $this->assertSame('Freddie', $objective->owner_name);
        $this->assertSame('Freddie', $objective->ownerLabel());
        $this->assertSame($super->id, $kr->owner_user_id);
        $this->assertSame('Freddie', $kr->owner_name);
        $this->assertSame('Freddie', $kr->ownerLabel());
        $approvalTask = $kr->tasks->first(fn ($task) => str_contains($task->title, 'Review dan approval'));
        $this->assertNotNull($approvalTask);
        $this->assertSame($super->id, $approvalTask->assignee_user_id);
        $this->assertSame('Freddie', $approvalTask->assignee_name);
        $this->assertSame($todo->id, $approvalTask->board_column_id);

        $this->actingAs($super)->get(route('okr.show', $cycle))
            ->assertOk()
            ->assertSee('Penanggung jawab:')
            ->assertSee('Freddie')
            ->assertDontSee('Draf lama terdeteksi');

        $this->actingAs($super)->post(route('okr.approve', $cycle))
            ->assertRedirect(route('okr.show', $cycle));
        $this->assertSame(OkrCycle::STATUS_ACTIVE, $cycle->fresh()->status);
    }

    public function test_job_desk_talent_dan_pic_tanpa_akun_ditangani_secara_eksplisit(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'coverageowner');
        $agatha = $this->user(User::ROLE_ADMIN, 'agathacoverage');
        $gracelyn = $this->user(User::ROLE_ADMIN, 'gracelyn');
        $board = Board::create(['name' => 'Papan Coverage', 'created_by' => $super->id]);
        $agathaColumn = $board->columns()->create(['name' => 'To Do Agatha', 'position' => 0]);
        $gracelynColumn = $board->columns()->create(['name' => 'To Do Gracelyn', 'position' => 1]);
        $board->columns()->create(['name' => 'To Do Freddie', 'position' => 2]);
        $board->columns()->create(['name' => 'Done', 'position' => 3]);

        AiKnowledge::create([
            'section' => 'team',
            'content' => implode("\n", [
                '- Freddie — CMO. Marketing, branding, dan approval campaign.',
                '- Agatha — tim desain & content creator. Desain grafis, poster, cover, dan materi promosi.',
                '- Gracelyn — talent. Syuting, UGC, video content, dan model di konten.',
                '- Hida — Live Host. Live streaming, script live, closing, dan demo produk.',
                '- Syuting, UGC, talent video/konten → Gracelyn',
            ]),
        ]);
        $fake = $this->fakeDraft($agatha, $agathaColumn->id, [
            'objectives' => [[
                'key_results' => [[
                    'tasks' => [[
                        'title' => 'Susun kalender campaign Q3',
                        'description' => 'Susun kalender campaign dan target publikasinya.',
                        'assignee_user_id' => $agatha->id,
                        'board_column_id' => $agathaColumn->id,
                    ]],
                ]],
            ]],
        ]);
        $this->app->instance(AiProvider::class, $fake);
        $payload = $this->generatePayload($board->id);
        $payload['direction'] = 'Jalankan produksi video UGC dan live commerce selama Q3.';

        $this->actingAs($super)->post(route('okr.generate'), $payload)->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $task = $cycle->objectives->first()->keyResults->first()->tasks
            ->firstWhere('title', 'Produksi materi video dan UGC untuk campaign Q3');
        $this->assertNotNull($task);
        $this->assertSame($gracelyn->id, $task->assignee_user_id);
        $this->assertSame($gracelynColumn->id, $task->board_column_id);

        $this->actingAs($super)->get(route('okr.show', $cycle))
            ->assertOk()
            ->assertSee('Hida disebut dalam pekerjaan periode ini tetapi belum mempunyai akun internal aktif.');
    }

    public function test_panel_menerima_snapshot_data_aktual_dan_mematuhi_izin(): void
    {
        $this->travelTo('2026-07-15 09:00:00'); // pin tengah bulan; hindari tepi akhir bulan
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrlive');
        $member = $this->user(User::ROLE_ADMIN, 'livepic');
        $partner = $this->user(User::ROLE_DISTRIBUTOR, 'livepartner');
        [$board, $todo] = $this->board($super);
        Product::create([
            'name' => 'Serum Snapshot',
            'sku' => 'SNAP-1',
            'category' => 'Serum',
            'hq_stock' => 7,
            'status' => Product::STATUS_ACTIVE,
        ]);
        PurchaseOrder::create([
            'po_number' => 'PO-OKR-LIVE',
            'created_by' => $super->id,
            'user_id' => $partner->id,
            'company_name' => 'Mitra Snapshot',
            'user_role' => $partner->role,
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'total_amount' => 250000,
            'order_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);
        $fake = $this->fakeDraft($member, $todo->id);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))->assertRedirect();

        $this->assertStringContainsString('250000', $fake->sent[0]['messages'][1]['content']);
        $this->assertStringContainsString('Serum Snapshot', $fake->sent[2]['messages'][1]['content']);
        $this->assertStringContainsString('tren_penjualan_3_bulan', $fake->sent[0]['messages'][1]['content']);
        $this->assertStringContainsString('mencapai_100_juta', $fake->sent[0]['messages'][1]['content']);

        $gudang = $this->user(User::ROLE_GUDANG, 'snapshotgudang');
        $snapshot = app(OkrBusinessSnapshotService::class)->for('cfo', $gudang, [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);
        $this->assertSame('ditutup karena user tidak punya view_accounting', $snapshot['akuntansi']['akses']);
        $this->assertArrayNotHasKey('laba_rugi', $snapshot);
    }

    public function test_snapshot_memisahkan_kol_dari_affiliate_dan_menghitung_funnel_distributor(): void
    {
        $this->travelTo('2026-07-15 09:00:00'); // pin tengah bulan; hindari tepi akhir bulan
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrfacts');
        // Satu distributor onboarding (tanpa PO), satu yang aktif bertransaksi.
        $this->user(User::ROLE_DISTRIBUTOR, 'distonboard');
        $aktif = $this->user(User::ROLE_DISTRIBUTOR, 'distaktif');
        PurchaseOrder::create([
            'po_number' => 'PO-FUNNEL-1',
            'created_by' => $super->id,
            'user_id' => $aktif->id,
            'company_name' => 'Distributor Aktif',
            'user_role' => $aktif->role,
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'total_amount' => 150_000_000,
            'order_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);

        // OKR periode DEPAN → bulan referensi = bulan berjalan (MTD).
        $input = [
            'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->endOfMonth()->toDateString(),
        ];
        $cmo = app(OkrBusinessSnapshotService::class)->for('cmo', $super, $input);

        // Affiliate TERPISAH dari KOL (kunci sendiri) & ditandai belum tersedia.
        $this->assertSame('source_not_available', $cmo['affiliate']['status']);
        $this->assertArrayHasKey('affiliate', $cmo);
        $this->assertArrayNotHasKey('affiliate_status', $cmo['kol']); // affiliate tak dilipat ke KOL
        $this->assertStringContainsString('endorsement', mb_strtolower($cmo['kol']['catatan_cakupan']));
        $this->assertStringContainsString('bukan sumber affiliate', mb_strtolower($cmo['kol']['catatan_cakupan']));

        // Funnel distributor dari PO: onboarding (tanpa PO) & tercapai Rp100jt.
        $this->assertArrayHasKey('onboarding', $cmo['distributor']);
        $this->assertSame(1, $cmo['distributor']['onboarding']);
        $this->assertSame(1, $cmo['distributor']['mencapai_100_juta']);
        $this->assertStringContainsString('belum pernah', $cmo['distributor']['definisi']['onboarding']);

        // CFO: baseline dari bulan tutup terakhir + bulan berjalan ditandai MTD.
        $cfo = app(OkrBusinessSnapshotService::class)->for('cfo', $super, $input);
        $this->assertSame(now()->subMonth()->format('Y-m'), $cfo['laba_rugi_bulan_tutup_terakhir']['bulan']);
        $this->assertStringContainsString('berjalan', $cfo['laba_rugi_periode']['status_periode']);
    }

    public function test_fakta_server_identik_antar_generate_walau_ai_kutip_beda(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrconsist');
        $member = $this->user(User::ROLE_ADMIN, 'consistpic');
        [$board, $todo] = $this->board($super);

        // Generate #1 — AI mengutip set bukti default.
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id));
        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))->assertRedirect();
        $first = OkrCycle::latest('id')->firstOrFail()->analysis_evidence;

        // Generate #2 — AI SENGAJA mengutip fakta berbeda.
        OkrCycle::query()->delete();
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id, [
            'evidence' => [
                ['source_path' => 'coo.stok.total_hq', 'interpretation' => 'Kali ini AI mengutip fakta yang berbeda dari generate sebelumnya secara sengaja.'],
            ],
        ]));
        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))->assertRedirect();
        $second = OkrCycle::latest('id')->firstOrFail()->analysis_evidence;

        // Fakta server (path, urutan, nilai) IDENTIK — tidak ikut berubah walau
        // pilihan kutipan AI berbeda.
        $shape = fn ($facts) => collect($facts)->map(fn ($f) => [$f['source_path'], (string) $f['value']])->all();
        $this->assertSame($shape($first), $shape($second));
        // Urutan tetap diawali omzet total.
        $this->assertSame('cmo.penjualan.total_sales', $first[0]['source_path']);
        // Bukan hasil kutipan AI: fakta tak punya interpretasi model.
        $this->assertArrayNotHasKey('interpretation', $first[0]);
        // Metrik yang diminta ikut tampil di daftar tetap.
        $paths = collect($first)->pluck('source_path');
        $this->assertTrue($paths->contains('cmo.distributor.omzet_selesai'));
        $this->assertTrue($paths->contains('cfo.laba_rugi.net_income'));
        $this->assertTrue($paths->contains('cfo.laba_rugi.penjualan_bersih'));
    }

    public function test_delegasi_menang_untuk_affiliate_dan_pic_ambigu_tetap_kosong(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrdel');
        $tiar = $this->user(User::ROLE_ADMIN, 'tiar');
        $this->user(User::ROLE_ADMIN, 'agatha');
        [$board, $todo] = $this->board($super);
        AiKnowledge::create([
            'section' => 'team',
            'content' => implode("\n", [
                '- Desain, visual, poster, materi promosi → Agatha',
                '- Rekrut affiliate, onboarding KOL, request sample → Tiar',
            ]),
        ]);

        $fake = $this->fakeDraft($super, $todo->id, [
            'objectives' => [[
                'specialist' => 'cmo',
                'owner_user_id' => $super->id,
                'key_results' => [[
                    'owner_user_id' => $super->id,
                    'tasks' => [
                        [
                            // Model menebak PIC salah (super); delegasi harus menang → Tiar.
                            'title' => 'Rekrut affiliate baru untuk TikTok Shop',
                            'description' => 'Jalankan rekrutmen affiliate periode ini.',
                            'assignee_user_id' => $super->id,
                            'board_column_id' => $todo->id,
                            'due_date' => '2026-08-20',
                        ],
                        [
                            // Tak ada kata kunci delegasi & PIC tak valid → belum ditentukan.
                            'title' => 'Analisis tren pasar umum kuartal ini',
                            'description' => 'Rangkum kondisi pasar tanpa PIC spesifik.',
                            'assignee_user_id' => 0,
                            'board_column_id' => $todo->id,
                            'due_date' => '2026-08-21',
                        ],
                    ],
                ]],
            ]],
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))->assertRedirect();

        $tasks = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail()
            ->objectives->first()->keyResults->first()->tasks;
        $affiliateTask = $tasks->firstWhere('title', 'Rekrut affiliate baru untuk TikTok Shop');
        $ambiguousTask = $tasks->firstWhere('title', 'Analisis tren pasar umum kuartal ini');

        // Delegasi menang: affiliate → Tiar, bukan tebakan model.
        $this->assertSame($tiar->id, $affiliateTask->assignee_user_id);
        // PIC ambigu tetap kosong (belum ditentukan) — tidak ditebak/dilempar.
        $this->assertNull($ambiguousTask->assignee_user_id);
        $this->assertNull($ambiguousTask->assignee_name);
    }

    public function test_data_gap_hanya_affiliate_dan_source_path_mentah_dibuang(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrgap');
        $member = $this->user(User::ROLE_ADMIN, 'okrgappic');
        [$board, $todo] = $this->board($super);
        $fake = $this->fakeDraft($member, $todo->id, [
            'assumptions' => [
                'cfo.laba_rugi.net_income',   // path mentah → dibuang (datanya ADA di katalog)
                'cfo.arus_kas.operasi',        // path mentah → dibuang
                'Distributor baru perlu waktu untuk mulai bertransaksi.', // prosa → tetap
            ],
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))->assertRedirect();

        $assumptions = mb_strtolower(implode("\n", OkrCycle::firstOrFail()->analysis_assumptions));

        // Path akuntansi mentah tidak lagi muncul sebagai "belum tersedia".
        $this->assertStringNotContainsString('cfo.laba_rugi.net_income', $assumptions);
        $this->assertStringNotContainsString('cfo.arus_kas.operasi', $assumptions);
        // Prosa asli tetap dipertahankan.
        $this->assertStringContainsString('distributor baru perlu waktu', $assumptions);
        // Affiliate = satu-satunya sumber yang benar-benar belum tersambung.
        $this->assertStringContainsString('affiliate', $assumptions);
        $this->assertStringContainsString('belum tersambung', $assumptions);
    }

    public function test_ringkasan_generik_dan_bukti_palsu_dipulihkan_dari_panel_dan_server(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrquality');
        $member = $this->user(User::ROLE_ADMIN, 'qualitypic');
        [$board, $todo] = $this->board($super);
        $fake = $this->fakeDraft($member, $todo->id, [
            'analysis_summary' => 'Target perlu dicapai dengan kerja sama tim.',
            'evidence' => [
                ['source_path' => 'cmo.angka_rekaan', 'interpretation' => 'Angka rekaan tidak boleh lolos validasi sumber server.'],
                ['source_path' => 'cfo.angka_rekaan', 'interpretation' => 'Angka rekaan tidak boleh lolos validasi sumber server.'],
                ['source_path' => 'coo.angka_rekaan', 'interpretation' => 'Angka rekaan tidak boleh lolos validasi sumber server.'],
            ],
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::firstOrFail();
        $this->assertStringContainsString('CMO:', $cycle->analysis_summary);
        $this->assertGreaterThanOrEqual(3, count($cycle->analysis_evidence));
        $this->assertNotContains('cmo.angka_rekaan', collect($cycle->analysis_evidence)->pluck('source_path')->all());
        $this->assertSame(0, BoardCard::count());
    }

    public function test_arahan_panel_lengkap_memulihkan_objective_fungsi_yang_hilang(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrpanelgate');
        $member = $this->user(User::ROLE_ADMIN, 'panelgatepic');
        [$board, $todo] = $this->board($super);
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id));
        $payload = $this->generatePayload($board->id);
        $payload['direction'] = 'Susun OKR perusahaan dengan CMO, CFO, dan COO bekerja bersama.';

        $this->actingAs($super)
            ->post(route('okr.generate'), $payload)
            ->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $this->assertSame(
            ['cmo', 'cfo', 'coo'],
            $cycle->objectives->pluck('specialist')->values()->all(),
        );
        $this->assertTrue($cycle->objectives->every(fn ($objective) => $objective->keyResults->isNotEmpty()));
        $this->assertTrue($cycle->objectives->flatMap->keyResults->every(fn ($kr) => $kr->tasks->isNotEmpty()));
    }

    public function test_bukti_spesialis_yang_formatnya_meleset_dilengkapi_dari_server(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrgrounding');
        $member = $this->user(User::ROLE_ADMIN, 'groundingpic');
        [$board, $todo] = $this->board($super);
        $fake = $this->fakeDraft($member, $todo->id, [], [
            'cfo' => [
                'analysis' => 'Analisis singkat.',
                'facts' => [
                    ['source_path' => 'laba_rugi.penjualan', 'finding' => 'Path model tidak persis sama dengan katalog server.'],
                    ['source_path' => 'arus_kas.net', 'finding' => 'Path model tidak persis sama dengan katalog server.'],
                ],
                'target_gap_analysis' => 'Belum lengkap.',
            ],
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $this->assertSame(1, OkrCycle::count());
        $orchestratorPrompt = $fake->sent[3]['messages'][1]['content'];
        $this->assertStringContainsString('Panel CFO harus menguji target', $orchestratorPrompt);
        $this->assertStringContainsString('cfo.laba_rugi.penjualan_bersih', $orchestratorPrompt);
        $this->assertStringContainsString('cfo.laba_rugi.hpp', $orchestratorPrompt);
    }

    public function test_semua_output_terstruktur_ai_yang_kosong_dipulihkan_tanpa_error(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrmalformed');
        $this->user(User::ROLE_ADMIN, 'malformedpic');
        [$board] = $this->board($super);
        $emptyTool = fn (string $id) => new AiTurn(toolCalls: [[
            'id' => $id,
            'name' => 'usulkan_okr_spesialis',
            'arguments' => [],
        ]]);
        $fake = new FakeAiProvider([
            new AiTurn(text: 'CMO tidak memanggil alat.'),
            $emptyTool('cfo-empty'),
            new AiTurn(toolCalls: [[
                'id' => 'coo-wrong-tool',
                'name' => 'alat_yang_salah',
                'arguments' => [],
            ]]),
            new AiTurn(text: 'Orchestrator juga tidak memanggil alat.'),
        ]);
        $this->app->instance(AiProvider::class, $fake);

        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $this->assertSame(['cmo', 'cfo', 'coo'], $cycle->objectives->pluck('specialist')->all());
        $this->assertTrue($cycle->objectives->every(fn ($objective) => filled($objective->rationale)));
        $this->assertTrue($cycle->objectives->flatMap->keyResults->every(
            fn ($kr) => filled($kr->metric)
                && filled($kr->target)
                && filled($kr->baseline)
                && filled($kr->target_gap)
                && $kr->tasks->isNotEmpty()
        ));
        $this->assertGreaterThanOrEqual(3, count($cycle->analysis_evidence));
        $this->assertSame(0, BoardCard::count());

        $taskTotal = $cycle->objectives->flatMap->keyResults->flatMap->tasks->count();
        $this->actingAs($super)->post(route('okr.approve', $cycle))->assertRedirect();
        $this->assertSame(OkrCycle::STATUS_ACTIVE, $cycle->fresh()->status);
        $this->assertSame($taskTotal, BoardCard::count());
        $this->assertTrue(BoardCard::query()->get()->every(fn (BoardCard $card) => $card->created_via === 'ai'));
    }

    public function test_objective_duplikat_kr_minim_dan_tugas_kosong_dinormalisasi_sekaligus(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrduplicate');
        $member = $this->user(User::ROLE_ADMIN, 'duplicatepic');
        [$board, $todo] = $this->board($super);
        $fake = $this->fakeDraft($member, $todo->id, [
            'objectives' => [
                [
                    'key_results' => [[
                        'tasks' => [['title' => '']],
                    ]],
                ],
                [
                    'specialist' => 'cmo',
                    'title' => 'Objective CMO duplikat',
                    'description' => '',
                    'rationale' => '',
                    'owner_user_id' => 999999,
                    'key_results' => [[
                        'title' => 'KR tambahan tanpa kelengkapan',
                        'metric' => '',
                        'target' => '',
                        'baseline_status' => 'actual',
                        'baseline_source_path' => 'sumber.palsu',
                        'baseline_interpretation' => '',
                        'target_gap' => '',
                        'owner_user_id' => 999999,
                        'due_date' => '2020-01-01',
                        'tasks' => [],
                    ]],
                ],
            ],
        ]);
        $this->app->instance(AiProvider::class, $fake);
        $payload = $this->generatePayload($board->id);
        $payload['direction'] = 'CMO, CFO, dan COO wajib bekerja bersama.';

        $this->actingAs($super)->post(route('okr.generate'), $payload)->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $this->assertSame(['cmo', 'cfo', 'coo'], $cycle->objectives->pluck('specialist')->all());
        $this->assertSame(2, $cycle->objectives->first()->keyResults->count());
        $this->assertTrue($cycle->objectives->flatMap->keyResults->every(
            fn ($kr) => filled($kr->metric)
                && filled($kr->target)
                && filled($kr->baseline)
                && filled($kr->target_gap)
                && $kr->due_date->betweenIncluded($cycle->start_date, $cycle->end_date)
                && $kr->tasks->isNotEmpty()
        ));
    }

    public function test_snapshot_tanpa_metrik_ditandai_untuk_validasi_dan_tidak_menggagalkan_okr(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrnodata');
        $this->user(User::ROLE_ADMIN, 'nodatapic');
        [$board] = $this->board($super);
        $this->app->instance(OkrBusinessSnapshotService::class, new class extends OkrBusinessSnapshotService
        {
            public function __construct() {}

            public function for(string $specialist, User $user, array $input): array
            {
                return [
                    'periode_referensi' => '2026-07',
                    'status_periode' => 'bulan berjalan',
                    'catatan' => 'Belum ada metrik numerik yang dapat diakses.',
                ];
            }
        });
        $this->app->instance(AiProvider::class, new FakeAiProvider([
            new AiTurn(text: 'CMO tanpa struktur.'),
            new AiTurn(text: 'CFO tanpa struktur.'),
            new AiTurn(text: 'COO tanpa struktur.'),
            new AiTurn(text: 'Orchestrator tanpa struktur.'),
        ]));

        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $this->assertSame([], $cycle->analysis_evidence);
        $this->assertStringContainsString(
            'baseline lain tidak boleh diasumsikan',
            implode(' ', $cycle->analysis_assumptions),
        );
        $this->assertSame(['cmo', 'cfo', 'coo'], $cycle->objectives->pluck('specialist')->all());
        $this->assertTrue($cycle->objectives->flatMap->keyResults->every(
            fn ($kr) => $kr->baseline_status === 'needs_validation'
                && $kr->tasks->isNotEmpty()
        ));

        $this->actingAs($super)->post(route('okr.approve', $cycle))->assertRedirect();
        $this->assertSame(OkrCycle::STATUS_ACTIVE, $cycle->fresh()->status);
    }

    public function test_timeout_orchestrator_memakai_hasil_panel_dan_tetap_membuat_pratinjau(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrtimeoutfinal');
        $member = $this->user(User::ROLE_ADMIN, 'timeoutfinalpic');
        [$board, $todo] = $this->board($super);
        $seed = $this->fakeDraft($member, $todo->id);
        $this->app->instance(AiProvider::class, new class($seed) implements AiProvider
        {
            private int $calls = 0;

            public function __construct(private FakeAiProvider $seed) {}

            public function chat(array $messages, array $tools): AiTurn
            {
                $this->calls++;
                if ($this->calls === 4) {
                    throw new AiException('Orchestrator timeout.', transient: true);
                }

                return $this->seed->chat($messages, $tools);
            }
        });

        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $this->assertSame(['cmo', 'cfo', 'coo'], $cycle->objectives->pluck('specialist')->all());
        $this->assertStringContainsString(
            'Orchestrator OpenAI gagal sementara',
            implode(' ', $cycle->analysis_assumptions),
        );
        $this->assertTrue($cycle->objectives->flatMap->keyResults->flatMap->tasks->isNotEmpty());
    }

    public function test_timeout_seluruh_panel_memakai_fallback_transparan_tanpa_halaman_error(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrtimeoutall');
        $this->user(User::ROLE_ADMIN, 'timeoutallpic');
        [$board] = $this->board($super);
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function chat(array $messages, array $tools): AiTurn
            {
                throw new AiException('OpenAI timeout.', transient: true);
            }
        });

        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cycle = OkrCycle::with('objectives.keyResults.tasks')->firstOrFail();
        $assumptions = implode(' ', $cycle->analysis_assumptions);
        $this->assertStringContainsString('panel CMO OpenAI gagal sementara', $assumptions);
        $this->assertStringContainsString('Orchestrator OpenAI gagal sementara', $assumptions);
        $this->assertSame(['cmo', 'cfo', 'coo'], $cycle->objectives->pluck('specialist')->all());
    }

    public function test_error_openai_permanen_tetap_ditampilkan_dan_tidak_disamarkan(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrpermanent');
        $this->user(User::ROLE_ADMIN, 'permanentpic');
        [$board] = $this->board($super);
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function chat(array $messages, array $tools): AiTurn
            {
                throw new AiException('Key OpenAI ditolak.');
            }
        });

        // Generate jalan di background (queue sync -> inline). Error permanen
        // TIDAK disamarkan: siklus ditandai GAGAL + pesannya, tampil di halaman.
        $this->actingAs($super)
            ->post(route('okr.generate'), $this->generatePayload($board->id))
            ->assertRedirect();

        $cycle = OkrCycle::firstOrFail();
        $this->assertSame(OkrCycle::GEN_FAILED, $cycle->generation_status);
        $this->assertStringContainsString('Key OpenAI ditolak.', (string) $cycle->generation_error);
        $this->assertSame(0, $cycle->objectives()->count());
    }

    public function test_generate_asinkron_menandai_generating_lalu_ready(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrasync');
        $member = $this->user(User::ROLE_ADMIN, 'asyncpic');
        [$board, $todo] = $this->board($super);
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id));

        // Queue sync → job jalan inline → langsung ready dengan objektif terisi.
        $this->actingAs($super)->post(route('okr.generate'), $this->generatePayload($board->id))->assertRedirect();

        $cycle = OkrCycle::firstOrFail();
        $this->assertSame(OkrCycle::GEN_READY, $cycle->generation_status);
        $this->assertTrue($cycle->objectives()->exists());

        // Endpoint status untuk polling.
        $this->actingAs($super)->get(route('okr.status', $cycle))
            ->assertOk()
            ->assertJson(['status' => OkrCycle::GEN_READY]);
    }
}
