<?php

namespace Tests\Feature;

use App\Models\TiktokConnection;
use App\Support\TimezoneShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimezoneShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_runs_on_jakarta_time(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    public function test_shifts_instants_but_leaves_wall_clock_dates_alone(): void
    {
        // Satu tabel yang punya ketiganya: timestamp, datetime, DATE.
        $c = TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'access_expires_at' => '2026-07-15 05:15:00',   // DATETIME → instan
            'last_synced_at' => '2026-07-15 05:15:00',      // TIMESTAMP → instan
            'deduct_from' => '2026-07-15',                  // DATE → tanggal jam-dinding
        ]);
        DB::table('tiktok_connections')->where('id', $c->id)->update(['created_at' => '2026-07-15 05:15:00']);

        $r = TimezoneShift::shift(7);

        $row = DB::table('tiktok_connections')->where('id', $c->id)->first();
        // instan digeser +7 jam (05:15 UTC = 12:15 WIB — momen yang sama)
        $this->assertStringStartsWith('2026-07-15 12:15:00', $row->last_synced_at);
        $this->assertStringStartsWith('2026-07-15 12:15:00', $row->access_expires_at);
        $this->assertStringStartsWith('2026-07-15 12:15:00', $row->created_at);
        // DATE TIDAK digeser — kalau ikut tergeser, batas potong stok jadi meleset sehari
        $this->assertStringStartsWith('2026-07-15', $row->deduct_from);
        $this->assertGreaterThan(0, $r['columns']);
    }

    public function test_null_stays_null(): void
    {
        $c = TiktokConnection::create([
            'shop_id' => 'S2', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'last_synced_at' => null, 'deduct_from' => null,
        ]);

        TimezoneShift::shift(7);

        $row = DB::table('tiktok_connections')->where('id', $c->id)->first();
        $this->assertNull($row->last_synced_at);
        $this->assertNull($row->deduct_from);
    }

    public function test_shift_is_reversible(): void
    {
        $c = TiktokConnection::create([
            'shop_id' => 'S3', 'shop_cipher' => 'C', 'access_token' => 'a', 'refresh_token' => 'r',
            'last_synced_at' => '2026-07-15 05:15:00',
        ]);

        TimezoneShift::shift(7);
        TimezoneShift::shift(-7);   // rollback migrasi

        $this->assertStringStartsWith(
            '2026-07-15 05:15:00',
            DB::table('tiktok_connections')->where('id', $c->id)->value('last_synced_at'),
        );
    }

    public function test_infrastructure_tables_are_never_touched(): void
    {
        // Menggeser token reset justru MEMPERPANJANG masa berlakunya — jangan disentuh.
        $tables = array_keys(TimezoneShift::columns());

        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'password_reset_tokens', 'migrations'] as $skip) {
            $this->assertNotContains($skip, $tables, "{$skip} seharusnya dilewati");
        }
    }

    public function test_covers_the_columns_that_actually_matter(): void
    {
        $map = TimezoneShift::columns();

        // Ini yang menggerakkan laporan stok & batas potong — wajib ikut tergeser.
        $this->assertContains('created_at', $map['stock_movements'] ?? []);
        $this->assertContains('order_created_at', $map['tiktok_orders'] ?? []);
        $this->assertContains('statement_time', $map['tiktok_settlements'] ?? []);
    }
}
