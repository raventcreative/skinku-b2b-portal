<?php

namespace Database\Seeders;

use App\Models\AccAccount;
use App\Models\AccBranch;
use App\Models\AccJournal;
use App\Models\AccJournalLine;
use App\Models\AiKnowledge;
use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardColumn;
use App\Models\ContentPost;
use App\Models\ContentPostTarget;
use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use App\Models\Kol;
use App\Models\KolPipelineCard;
use App\Models\LearningModule;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Mindmap;
use App\Models\MindmapEdge;
use App\Models\MindmapNode;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\OkrCycle;
use App\Models\OkrKeyResult;
use App\Models\OkrObjective;
use App\Models\OkrTask;
use App\Models\Product;
use App\Models\Production;
use App\Models\ProductionCost;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotent local showcase data for the portal's primary modules.
 * Run with: php artisan db:seed --class=Database\\Seeders\\SkinkuLocalDemoSeeder
 */
class SkinkuLocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('SkinkuLocalDemoSeeder hanya berjalan di environment local.');

            return;
        }

        $this->call(DevDataSeeder::class);
        $this->call(ChartOfAccountSeeder::class);
        $this->call(ContentCreatorDemoSeeder::class);
        $this->call(ReturnUiDemoSeeder::class);

        $user = User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])->orderBy('id')->first();
        $creator = User::where('username', 'creator_demo')->first();
        $product = Product::where('sku', 'SKN-ABW-01')->first() ?? Product::query()->first();

        if (! $user) {
            $this->command?->warn('Tidak ada akun admin untuk data demo.');

            return;
        }

        $this->seedChats();
        $this->seedContent($creator ?? $user);
        $this->seedKol($user);
        $this->seedWorkManagement($user);
        $this->seedLearning($user);
        $this->seedMindmap($user);
        $this->seedMarketplace($product);
        $this->seedSupplyAndProduction($user, $product);
        $this->seedAccounting();
        $this->seedKnowledge();

        $this->command?->info('Demo lokal SKINKU selesai. Semua record tambahan diberi penanda Demo Lokal.');
    }

    private function seedChats(): void
    {
        foreach ([
            ['tiktok', 'demo-tiktok-001', 'Nadia Putri', 'open', null, 'Apakah body serum aman untuk kulit sensitif?', 'buyer'],
            ['shopee', 'demo-shopee-001', 'Rani Beauty', 'replied', 'ai', 'Terima kasih, pesanan sudah diterima.', 'ai'],
        ] as [$channel, $externalId, $buyer, $status, $via, $preview, $replyVia]) {
            $conversation = EcomChatConversation::updateOrCreate(
                ['channel' => $channel, 'external_conversation_id' => $externalId],
                [
                    'buyer_name' => $buyer,
                    'buyer_id' => 'demo-'.$channel.'-buyer',
                    'last_message_at' => now()->subMinutes(4),
                    'last_incoming_at' => now()->subMinutes(9),
                    'last_message_preview' => $preview,
                    'status' => $status,
                    'last_reply_via' => $via,
                    'flagged' => $status === 'open',
                    'ai_draft' => $status === 'open' ? 'Bisa digunakan sesuai petunjuk pada kemasan. Untuk kulit sensitif, coba dahulu pada area kecil.' : null,
                    'ai_decision' => $status === 'open' ? 'to_staff' : null,
                    'ai_reason' => $status === 'open' ? 'Pertanyaan produk perlu diperiksa staf.' : null,
                ],
            );

            $messages = $status === 'open'
                ? [['buyer', 'buyer', 'Apakah body serum aman untuk kulit sensitif?'], ['seller', 'ai', 'Bisa digunakan sesuai petunjuk. Untuk kulit sensitif, coba dahulu pada area kecil.']]
                : [['buyer', 'buyer', 'Paketnya sudah sampai, terima kasih.'], ['seller', 'ai', 'Terima kasih sudah berbelanja di SKINKU.']];

            foreach ($messages as $i => [$sender, $messageVia, $text]) {
                EcomChatMessage::updateOrCreate(
                    ['channel' => $channel, 'external_message_id' => $externalId.'-message-'.($i + 1)],
                    [
                        'conversation_id' => $conversation->id,
                        'sender' => $sender,
                        'via' => $messageVia,
                        'type' => 'text',
                        'text' => $text,
                        'sent_at' => now()->subMinutes(10 - $i),
                    ],
                );
            }
        }
    }

    private function seedContent(User $creator): void
    {
        $post = ContentPost::updateOrCreate(
            ['title' => '[Demo Lokal] Perkenalan produk SKINKU', 'user_id' => $creator->id],
            [
                'type' => 'image',
                'caption' => 'Kenali rangkaian perawatan tubuh SKINKU. Simpan konten ini sebagai contoh alur review lokal.',
                'status' => ContentPost::DRAFT,
                'creator_note' => 'Data contoh lokal untuk pratinjau dashboard.',
            ],
        );

        foreach (['facebook', 'instagram'] as $platform) {
            ContentPostTarget::updateOrCreate(
                ['content_post_id' => $post->id, 'platform' => $platform],
                ['caption_override' => null, 'status' => ContentPostTarget::PENDING],
            );
        }
    }

    private function seedKol(User $user): void
    {
        $kol = Kol::updateOrCreate(
            ['tiktok_username' => 'skinku_demo_creator'],
            [
                'name' => '[Demo Lokal] Alya Beauty', 'role' => 'both', 'platform' => 'tiktok',
                'followers' => 24500, 'kategori' => 'Beauty', 'provinsi' => 'DKI Jakarta',
                'status' => Kol::STATUS_PROSPEK, 'catatan' => 'Data contoh lokal untuk pratinjau modul KOL.',
            ],
        );

        KolPipelineCard::updateOrCreate(
            ['kol_id' => $kol->id, 'track' => KolPipelineCard::TRACK_KOL],
            ['stage' => 'dihubungi', 'next_action' => 'Kirim brief campaign demo', 'next_action_at' => now()->addDays(2), 'created_by' => $user->id],
        );
    }

    private function seedWorkManagement(User $user): void
    {
        $board = Board::firstOrCreate(['name' => '[Demo Lokal] Operasional SKINKU'], ['created_by' => $user->id]);
        $columns = collect(['To Do', 'Proses', 'Selesai'])->values()->map(fn ($name, $position) => BoardColumn::firstOrCreate(
            ['board_id' => $board->id, 'name' => $name], ['position' => $position],
        ));
        BoardCard::firstOrCreate(
            ['column_id' => $columns[1]->id, 'title' => '[Demo Lokal] Periksa kalender konten'],
            ['description' => 'Contoh kartu untuk melihat tampilan papan dan status tugas.', 'assignee_user_id' => $user->id, 'created_by' => $user->id, 'position' => 1],
        );

        $cycle = OkrCycle::firstOrCreate(
            ['name' => '[Demo Lokal] OKR Operasional SKINKU'],
            [
                'period_type' => OkrCycle::PERIOD_QUARTERLY, 'period_label' => 'Q4 2026',
                'start_date' => '2026-10-01', 'end_date' => '2026-12-31',
                'scope_type' => OkrCycle::SCOPE_COMPANY, 'direction' => 'Contoh sasaran perusahaan untuk pratinjau lokal.',
                'status' => OkrCycle::STATUS_ACTIVE, 'created_by' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(),
            ],
        );
        $objective = OkrObjective::firstOrCreate(
            ['okr_cycle_id' => $cycle->id, 'title' => '[Demo Lokal] Tingkatkan pengalaman mitra'],
            ['specialist' => 'coo', 'description' => 'Sasaran contoh untuk menampilkan relasi objective dan key result.', 'owner_user_id' => $user->id, 'position' => 1],
        );
        $keyResult = OkrKeyResult::firstOrCreate(
            ['okr_objective_id' => $objective->id, 'title' => 'Percepat penyelesaian permintaan mitra'],
            ['metric' => 'Jam', 'target' => '24', 'owner_user_id' => $user->id, 'due_date' => '2026-12-31', 'position' => 1],
        );
        OkrTask::firstOrCreate(
            ['okr_key_result_id' => $keyResult->id, 'title' => '[Demo Lokal] Tinjau antrean layanan'],
            ['description' => 'Contoh tugas yang tertaut ke key result.', 'assignee_user_id' => $user->id, 'due_date' => '2026-10-15', 'position' => 1],
        );
    }

    private function seedLearning(User $user): void
    {
        $module = LearningModule::firstOrCreate(
            ['title' => '[Demo Lokal] Dasar perawatan tubuh'],
            ['description' => 'Modul contoh untuk pratinjau SKINKU Academy.', 'sort_order' => 1, 'is_published' => true, 'created_by' => $user->id],
        );
        Lesson::firstOrCreate(
            ['module_id' => $module->id, 'title' => '[Demo Lokal] Memilih produk sesuai kebutuhan'],
            ['type' => Lesson::TYPE_DOCUMENT, 'description' => 'Materi teks contoh. Tidak memuat video atau tautan eksternal.', 'category' => 'Produk', 'audience' => ['distributor', 'reseller'], 'sort_order' => 1, 'is_published' => true, 'created_by' => $user->id],
        );
    }

    private function seedMindmap(User $user): void
    {
        $map = Mindmap::firstOrCreate(['title' => '[Demo Lokal] Alur konten SKINKU'], ['created_by' => $user->id]);
        $first = MindmapNode::firstOrCreate(
            ['mindmap_id' => $map->id, 'text' => '[Demo Lokal] Rencana'],
            ['x' => 80, 'y' => 80, 'color' => 'rose', 'created_by' => $user->id],
        );
        $second = MindmapNode::firstOrCreate(
            ['mindmap_id' => $map->id, 'text' => 'Produksi konten'],
            ['x' => 360, 'y' => 80, 'color' => 'kuning', 'created_by' => $user->id],
        );
        MindmapEdge::firstOrCreate(['mindmap_id' => $map->id, 'from_node_id' => $first->id, 'to_node_id' => $second->id], ['label' => 'lalu']);
    }

    private function seedMarketplace(?Product $product): void
    {
        $master = MarketplaceMaster::firstOrCreate(
            ['master_sku' => 'DEMO-SKN-ABW'],
            ['name' => '[Demo Lokal] Amino Body Wash', 'product_id' => $product?->id, 'base_stock' => 24, 'base_price' => 75000],
        );
        foreach (['tiktok', 'shopee'] as $channel) {
            MarketplaceMasterChannel::firstOrCreate(
                ['master_id' => $master->id, 'channel' => $channel],
                ['stock' => null, 'price' => null],
            );
        }
    }

    private function seedSupplyAndProduction(User $user, ?Product $product): void
    {
        Supplier::firstOrCreate(
            ['name' => '[Demo Lokal] Supplier Kemasan'],
            ['phone' => '080000000000', 'address' => 'Data alamat contoh lokal', 'notes' => 'Data contoh lokal.', 'status' => Supplier::STATUS_ACTIVE, 'created_by' => $user->id],
        );

        Material::firstOrCreate(
            ['name' => '[Demo Lokal] Botol kemasan'],
            ['unit' => 'pcs', 'stock' => 500, 'avg_cost' => 1200, 'status' => Material::STATUS_ACTIVE, 'notes' => 'Stok contoh lokal.', 'created_by' => $user->id],
        );

        if ($product) {
            $production = Production::firstOrCreate(
                ['production_number' => 'DEMO-LOCAL-001'],
                [
                    'product_id' => $product->id, 'product_name' => $product->name,
                    'produced_at' => now()->toDateString(), 'output_qty' => 10,
                    'material_cost' => 12000, 'other_cost' => 3000, 'total_cost' => 15000,
                    'hpp_per_unit' => 1500, 'cogs_before' => $product->cogs, 'cogs_after' => $product->cogs,
                    'notes' => 'Data contoh lokal; tidak mengubah stok produk.', 'created_by' => $user->id,
                ],
            );
            ProductionCost::firstOrCreate(['production_id' => $production->id, 'label' => '[Demo Lokal] Biaya kemasan'], ['amount' => 3000]);
        }

    }

    private function seedAccounting(): void
    {
        $branch = AccBranch::first();
        $cash = AccAccount::where('code', '1002')->first();
        $expense = AccAccount::where('code', '6004')->first();
        if (! $branch || ! $cash || ! $expense) {
            return;
        }

        $journal = AccJournal::firstOrCreate(
            ['reference' => 'DEMO-LOCAL-JOURNAL-001'],
            ['branch_id' => $branch->id, 'date' => now()->toDateString(), 'period' => now()->format('Y-m'), 'description' => '[Demo Lokal] Biaya administrasi contoh', 'type' => 'general', 'status' => 'posted'],
        );
        AccJournalLine::firstOrCreate(
            ['journal_id' => $journal->id, 'account_id' => $expense->id],
            ['branch_id' => $branch->id, 'debit' => 25000, 'credit' => 0, 'memo' => '[Demo Lokal] Beban administrasi'],
        );
        AccJournalLine::firstOrCreate(
            ['journal_id' => $journal->id, 'account_id' => $cash->id],
            ['branch_id' => $branch->id, 'debit' => 0, 'credit' => 25000, 'memo' => '[Demo Lokal] Kas keluar'],
        );
    }

    private function seedKnowledge(): void
    {
        AiKnowledge::firstOrCreate(
            ['section' => 'demo_local_ui'],
            ['group' => 'sistem', 'content' => 'Data contoh lokal untuk pratinjau UI SKINKU. Jangan gunakan sebagai kebijakan operasional.'],
        );
    }
}
