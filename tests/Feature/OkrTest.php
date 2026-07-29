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
            ->assertSee('Dasar analisis AI')
            ->assertSee('bukti terverifikasi')
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

        $this->assertSame($agathaColumn->id, $task->fresh()->board_column_id);

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

    public function test_arahan_panel_lengkap_ditolak_jika_objective_hanya_mewakili_satu_fungsi(): void
    {
        $super = $this->user(User::ROLE_SUPER_ADMIN, 'okrpanelgate');
        $member = $this->user(User::ROLE_ADMIN, 'panelgatepic');
        [$board, $todo] = $this->board($super);
        $this->app->instance(AiProvider::class, $this->fakeDraft($member, $todo->id));
        $payload = $this->generatePayload($board->id);
        $payload['direction'] = 'Susun OKR perusahaan dengan CMO, CFO, dan COO bekerja bersama.';

        $this->actingAs($super)
            ->from(route('okr.create'))
            ->post(route('okr.generate'), $payload)
            ->assertRedirect(route('okr.create'))
            ->assertSessionHas('error');

        $this->assertSame(0, OkrCycle::count());
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
}
