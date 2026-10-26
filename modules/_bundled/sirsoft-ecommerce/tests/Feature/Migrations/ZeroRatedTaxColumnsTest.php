<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 옵션별 과세 종류·영세 금액 컬럼 스키마 잠금 (#612)
 *
 * 스키마를 **읽기만** 한다(`Schema::getColumns`) — DDL 을 돌리면 MySQL 이 암묵 커밋해 테스트 트랜잭션이
 * 깨지므로 down→up 왕복은 마이그레이션 파일의 hasTable·hasColumn 가드로 대신한다(기존 스키마 테스트와 같은 방식).
 */
class ZeroRatedTaxColumnsTest extends ModuleTestCase
{
    /**
     * 테이블의 컬럼 정의를 이름으로 찾습니다.
     *
     * @param  string  $table  테이블명
     * @param  string  $column  컬럼명
     * @return array<string, mixed>|null 컬럼 정의
     */
    private function columnOf(string $table, string $column): ?array
    {
        foreach (Schema::getColumns($table) as $definition) {
            if (($definition['name'] ?? null) === $column) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * 옵션·주문상품의 과세 종류는 null 허용(null = 상품 따름 / 백필 전), 주문 영세 금액은 기본 0 인지 확인합니다.
     *
     * @effects zero_rated_columns_exist_with_defaults
     *
     * @return void 반환값 없음
     */
    public function test_columns_exist_with_expected_nullability_and_defaults(): void
    {
        $optionStatus = $this->columnOf('ecommerce_product_options', 'tax_status');
        $this->assertNotNull($optionStatus);
        $this->assertTrue($optionStatus['nullable']);

        $rowStatus = $this->columnOf('ecommerce_order_options', 'tax_status');
        $this->assertNotNull($rowStatus);
        $this->assertTrue($rowStatus['nullable']);

        $zero = $this->columnOf('ecommerce_orders', 'total_zero_rated_amount');
        $this->assertNotNull($zero);
        $this->assertFalse($zero['nullable']);
        $this->assertSame(0.0, (float) $zero['default']);

        $this->assertNotNull($this->columnOf('ecommerce_orders', 'mc_total_zero_rated_amount'));
    }

    /**
     * 세 마이그레이션의 down 이 존재를 확인하고 지우는지(되돌림 안전) 확인합니다.
     *
     * @effects zero_rated_columns_exist_with_defaults
     *
     * @return void 반환값 없음
     */
    public function test_migration_down_is_guarded(): void
    {
        $files = glob(base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/2026_10_26_00000[123]_*.php')) ?: [];
        $this->assertCount(3, $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $down = substr($source, (int) strpos($source, 'public function down'));

            $this->assertStringContainsString('Schema::hasColumn(', $down, basename($file));
            $this->assertStringContainsString('dropColumn(', $down, basename($file));
        }
    }
}
