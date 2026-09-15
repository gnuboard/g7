<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Feature;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

class MigrationSmokeTest extends PluginTestCase
{
    /**
     * 격리 DB에서 마이그레이션 재실행의 데이터 보존과 롤백 및 재생성을 검증합니다.
     *
     * @effects migration_preserves_existing_history, reversible_schema
     *
     * @return void 반환값 없음
     */
    public function test_migrations_preserve_rows_and_support_down_up_in_isolated_database(): void
    {
        $original = Schema::getFacadeRoot();
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $schema = $connection->getSchemaBuilder();
        Schema::swap($schema);
        try {
            $migrations = array_map(static fn ($path) => require $path, glob(dirname(__DIR__, 2).'/database/migrations/*.php'));
            foreach ($migrations as $migration) {
                $migration->up();
            }
            $this->assertTrue($schema->hasColumns('koreaexim_rate_runs', ['source_run_id', 'raw_response', 'request_count']));
            $this->assertTrue($schema->hasColumns('koreaexim_rate_run_items', ['currency_snapshot', 'api_fields', 'previous_rate', 'new_rate']));
            $connection->table('koreaexim_rate_runs')->insert([
                'trigger' => 'manual', 'status' => 'success', 'rate_type' => 'deal_bas_r',
                'base_currency' => 'KRW', 'search_date' => '2026-09-14', 'base_unit' => 1000,
                'started_at' => '2026-09-14 03:00:00',
            ]);
            foreach ($migrations as $migration) {
                $migration->up();
            }
            $this->assertSame(1, $connection->table('koreaexim_rate_runs')->count());
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            $this->assertFalse($schema->hasTable('koreaexim_rate_run_items'));
            $this->assertFalse($schema->hasTable('koreaexim_rate_runs'));
            foreach ($migrations as $migration) {
                $migration->up();
            }
            $this->assertSame(0, $connection->table('koreaexim_rate_runs')->count());
            $this->assertTrue($schema->hasTable('koreaexim_rate_run_items'));
        } finally {
            Schema::swap($original);
            $connection->disconnect();
        }
    }
}
