<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소신청서 재활성 기록 컬럼 스키마 잠금 (Q29)
 *
 * 스키마를 **읽기만** 한다(`Schema::getColumns`) — DDL 을 돌리면 MySQL 이 암묵 커밋해 테스트 트랜잭션이
 * 깨지므로 down→up 왕복은 마이그레이션 파일의 hasTable·hasColumn 가드로 대신한다(기존 스키마 테스트와 같은 방식).
 *
 * 시나리오 매니페스트: `tests/scenarios/cancel-reactivation-badge.yaml`
 */
class OrderCancelReactivationColumnsTest extends ModuleTestCase
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
     * 취소신청서에 재활성 수량(기본 0)·시각(null 허용), 취소 상품에 재활성 수량(기본 0)이 있다.
     *
     * @return void 반환값 없음
     *
     * @effects revival_attributes_quantity_to_cancel_documents_before_merge
     */
    public function test_reactivation_columns_exist_with_defaults(): void
    {
        $quantity = $this->columnOf('ecommerce_order_cancels', 'reactivated_quantity');
        $this->assertNotNull($quantity);
        $this->assertSame('0', (string) $quantity['default']);
        $this->assertFalse($quantity['nullable']);

        $at = $this->columnOf('ecommerce_order_cancels', 'reactivated_at');
        $this->assertNotNull($at);
        $this->assertTrue($at['nullable']);

        $optionQuantity = $this->columnOf('ecommerce_order_cancel_options', 'reactivated_quantity');
        $this->assertNotNull($optionQuantity);
        $this->assertSame('0', (string) $optionQuantity['default']);
    }

    /**
     * 마이그레이션의 down 이 컬럼마다 존재를 확인하고 지운다(되돌림 안전).
     *
     * @effects revival_attributes_quantity_to_cancel_documents_before_merge
     *
     * @return void 반환값 없음
     */
    public function test_migration_down_is_guarded(): void
    {
        $files = glob(base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/2026_10_05_000010_*.php')) ?: [];
        $this->assertCount(1, $files);

        $source = (string) file_get_contents($files[0]);
        $down = substr($source, (int) strpos($source, 'public function down'));

        $this->assertStringContainsString("Schema::hasColumn('ecommerce_order_cancels', \$column)", $down);
        $this->assertStringContainsString("Schema::hasColumn('ecommerce_order_cancel_options', 'reactivated_quantity')", $down);
    }
}
