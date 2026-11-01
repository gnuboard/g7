<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 영세율 마이그레이션 3종 왕복(up → down → up) 안전성 테스트 (#612)
 *
 * 모듈 삭제(delete_data)와 업그레이드 중 부분 적용 상태의 재실행에서 down()·up() 이 SQL 오류 없이 돌아야 한다.
 */
class ZeroRatedTaxMigrationRoundTripTest extends ModuleTestCase
{
    private const MIGRATION_DIR = 'modules/_bundled/sirsoft-ecommerce/database/migrations';

    /**
     * 마이그레이션 파일과 그 마이그레이션이 다루는 테이블·컬럼 목록을 돌려줍니다.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}> 이름 => [파일, 테이블, 컬럼들]
     */
    private function migrations(): array
    {
        return [
            'product_options' => [self::MIGRATION_DIR.'/2026_10_26_000001_add_tax_status_to_ecommerce_product_options_table.php', 'ecommerce_product_options', ['tax_status']],
            'order_options' => [self::MIGRATION_DIR.'/2026_10_26_000002_add_tax_status_to_ecommerce_order_options_table.php', 'ecommerce_order_options', ['tax_status']],
            'orders' => [self::MIGRATION_DIR.'/2026_10_26_000003_add_total_zero_rated_amount_to_ecommerce_orders_table.php', 'ecommerce_orders', ['total_zero_rated_amount', 'mc_total_zero_rated_amount']],
        ];
    }

    /**
     * 세 마이그레이션이 down 으로 컬럼을 지우고 up 으로 다시 만들며, 재실행(up 두 번·down 두 번)도 안전한지 확인합니다.
     *
     * @effects zero_rated_columns_exist_with_defaults
     *
     * @return void 반환값 없음
     */
    public function test_migrations_round_trip_and_are_idempotent(): void
    {
        foreach ($this->migrations() as $name => [$path, $table, $columns]) {
            $migration = require base_path($path);

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$name}: up 상태에서 {$column} 이 있어야 한다");
            }

            $migration->down();
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$name}: down 뒤 {$column} 이 없어야 한다");
            }

            // 이미 내려간 상태에서 다시 down — 가드가 없으면 "Can't DROP" 오류
            $migration->down();

            $migration->up();
            // 이미 올라간 상태에서 다시 up — 가드가 없으면 "Duplicate column" 오류
            $migration->up();

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$name}: 왕복 뒤 {$column} 이 다시 있어야 한다");
            }
        }
    }

    /**
     * 마이그레이션이 모듈 클래스를 참조하지 않는지 확인합니다 (모듈 오토로더 없이 migrate 가 돌 수 있다).
     *
     * @effects zero_rated_columns_exist_with_defaults
     *
     * @return void 반환값 없음
     */
    public function test_migrations_do_not_reference_module_classes(): void
    {
        foreach ($this->migrations() as $name => [$path]) {
            $this->assertStringNotContainsString('Modules\\Sirsoft', (string) file_get_contents(base_path($path)), $name);
        }
    }
}
