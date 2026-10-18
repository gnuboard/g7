<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 행 송장 스키마 형태 잠금 (#599 후속 §3-1 — 구조는 실행 세션이 바꾸지 않는다)
 *
 * 스키마를 **읽기만** 한다(`Schema::hasColumn`·`hasIndex`·`getForeignKeys`·`getTables`) — DDL 을 돌리지
 * 않으므로 테스트 트랜잭션을 깨지 않는다.
 *
 * 검증 축:
 * - 교환 상품 행에 회수·재발송 송장 8칸, 반품 상품 행에 회수 송장 4칸 · 반품 상품 행에 재발송 칸 없음
 * - 신청서 헤더 송장 칸·반품 헤더 `refund_id` 부재 · 주문 배송 행의 휴면 클레임 송장 칸 부재(S12)
 * - 선교환·판매자 접수·대리 철회·상품 행 사실(S3·S4·S6·S7·D3) 칸 존재
 * - 운송장 인덱스·택배사 외래키 · 「상자」 테이블(`%shipment%`) 부재 · 새 마이그레이션에 테이블 생성 없음
 */
class ClaimItemTrackingSchemaTest extends ModuleTestCase
{
    /**
     * 이 계획이 만든 마이그레이션 파일 목록을 돌려줍니다.
     *
     * @return array<int, string> 절대 경로
     */
    private function planMigrations(): array
    {
        return glob(base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/2026_09_25_*.php')) ?: [];
    }

    /**
     * 테이블에 컬럼이 모두 있는지 단언합니다.
     *
     * @param  string  $table  테이블명
     * @param  array<int, string>  $columns  컬럼 목록
     * @return void 반환값 없음
     */
    private function assertColumnsPresent(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} 컬럼이 없습니다.");
        }
    }

    /**
     * 테이블에 컬럼이 하나도 없는지 단언합니다.
     *
     * @param  string  $table  테이블명
     * @param  array<int, string>  $columns  컬럼 목록
     * @return void 반환값 없음
     */
    private function assertColumnsAbsent(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} 컬럼이 남아 있습니다.");
        }
    }

    /**
     * 테이블의 외래키 이름 목록을 돌려줍니다.
     *
     * @param  string  $table  테이블명
     * @return array<int, string> 외래키 이름
     */
    private function foreignKeyNames(string $table): array
    {
        return array_map(fn (array $foreignKey) => (string) ($foreignKey['name'] ?? ''), Schema::getForeignKeys($table));
    }

    /**
     * 송장은 상품 행에 있다 — 교환 행은 회수·재발송 8칸, 반품 행은 회수 4칸이고 반품 행에는 재발송 칸이 없다.
     * 운송장으로 묶고 찾으므로 운송장 인덱스가, 택배사 삭제에 대비해 외래키가 있다.
     *
     * @return void 반환값 없음
     *
     * @effects schema_puts_tracking_on_option_rows
     */
    public function test_tracking_columns_live_on_option_rows(): void
    {
        $collection = ['collection_carrier_id', 'collection_tracking_number', 'collection_shipped_at', 'collected_at'];
        $redelivery = ['redelivery_carrier_id', 'redelivery_tracking_number', 'redelivery_shipped_at', 'redelivered_at'];

        $this->assertColumnsPresent('ecommerce_order_exchange_options', [...$collection, ...$redelivery]);
        $this->assertColumnsPresent('ecommerce_order_return_options', $collection);
        $this->assertColumnsAbsent('ecommerce_order_return_options', $redelivery);

        $this->assertTrue(Schema::hasIndex('ecommerce_order_exchange_options', 'ecommerce_order_ex_opts_collection_tracking_index'));
        $this->assertTrue(Schema::hasIndex('ecommerce_order_exchange_options', 'ecommerce_order_ex_opts_redelivery_tracking_index'));
        $this->assertTrue(Schema::hasIndex('ecommerce_order_return_options', 'ecommerce_order_rt_opts_collection_tracking_index'));

        $this->assertContains('fk_ex_opt_collection_carrier_id', $this->foreignKeyNames('ecommerce_order_exchange_options'));
        $this->assertContains('fk_ex_opt_redelivery_carrier_id', $this->foreignKeyNames('ecommerce_order_exchange_options'));
        $this->assertContains('fk_rt_opt_collection_carrier_id', $this->foreignKeyNames('ecommerce_order_return_options'));
    }

    /**
     * 신청서 헤더에는 송장 칸이 없다(두 번째 진실 공급원 금지). 반품 헤더 `refund_id` 도 상품 행으로 옮겨졌고,
     * 주문 배송 행의 휴면 클레임 송장 칸도 사라졌다(S12). 헤더의 「전체」 도착 시각은 남는다(S2).
     *
     * @return void 반환값 없음
     *
     * @effects schema_has_no_header_tracking_columns
     */
    public function test_header_tracking_columns_are_gone(): void
    {
        $this->assertColumnsAbsent('ecommerce_order_exchanges', ['collection_carrier_id', 'collection_tracking_number', 'redelivery_carrier_id', 'redelivery_tracking_number']);
        $this->assertColumnsAbsent('ecommerce_order_returns', ['collection_carrier_id', 'collection_tracking_number', 'refund_id']);
        $this->assertColumnsAbsent('ecommerce_order_shippings', ['return_carrier_id', 'return_tracking_number', 'exchange_carrier_id', 'exchange_tracking_number']);

        $this->assertColumnsPresent('ecommerce_order_exchanges', ['collection_completed_at', 'redelivered_at']);
        $this->assertColumnsPresent('ecommerce_order_returns', ['collection_completed_at']);
        $this->assertColumnsPresent('ecommerce_order_shippings', ['return_shipping_amount']);
    }

    /**
     * 상품 행 사실(반려 사유·재고 복구·회수지·미회수·반품 환불 연결)과 선교환·판매자 접수·대리 철회 칸이 있다.
     *
     * @return void 반환값 없음
     *
     * @effects schema_has_item_level_and_mode_columns
     */
    public function test_item_level_mode_and_actor_columns_exist(): void
    {
        $this->assertColumnsPresent('ecommerce_order_exchange_options', ['reject_reason', 'is_stock_restored', 'return_address_snapshot', 'unreturned_at', 'unreturned_reason']);
        $this->assertColumnsPresent('ecommerce_order_return_options', ['reject_reason', 'is_stock_restored', 'return_address_snapshot', 'refund_id']);
        $this->assertColumnsAbsent('ecommerce_order_return_options', ['unreturned_at', 'unreturned_reason']);
        $this->assertColumnsPresent('ecommerce_order_refund_options', ['reject_reason']);

        $this->assertColumnsPresent('ecommerce_order_exchanges', ['exchange_mode', 'exchange_mode_set_by', 'exchange_mode_set_at', 'collection_due_at', 'collection_overdue_notified_at']);
        $this->assertColumnsAbsent('ecommerce_order_returns', ['exchange_mode', 'collection_due_at']);

        foreach (['ecommerce_order_exchanges', 'ecommerce_order_returns'] as $table) {
            $this->assertColumnsPresent($table, ['requested_by_admin', 'withdrawn_by_admin', 'withdraw_reason']);
        }

        $this->assertColumnsPresent('ecommerce_order_refunds', ['withdrawn_by_admin', 'withdraw_reason']);
        $this->assertTrue(Schema::hasIndex('ecommerce_order_exchanges', 'ecommerce_order_exchanges_mode_due_index'));
    }

    /**
     * 「상자」 테이블은 만들지 않는다(PO 2026-09-25 D4 개정) — `%shipment%` 이름의 테이블이 없고, 이 계획의
     * 마이그레이션 파일은 테이블을 만들지 않으며 모듈 클래스를 참조하지 않는다(오토로더 없이도 돈다).
     *
     * @return void 반환값 없음
     *
     * @effects schema_has_no_shipment_box_table
     */
    public function test_no_shipment_box_table_is_created(): void
    {
        $shipmentTables = array_filter(
            array_map(fn (array $table) => (string) ($table['name'] ?? ''), Schema::getTables()),
            fn (string $name) => str_contains($name, 'ecommerce') && str_contains($name, 'shipment'),
        );

        $this->assertSame([], array_values($shipmentTables));

        $files = $this->planMigrations();
        $this->assertCount(4, $files, '이 계획의 마이그레이션은 4개다(§6-1).');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Schema::create', $source, basename($file).' 가 테이블을 만듭니다.');
            $this->assertStringNotContainsString('Modules\\Sirsoft', $source, basename($file).' 가 모듈 클래스를 참조합니다.');
            $this->assertStringContainsString('public function down(): void', $source, basename($file).' 에 down() 이 없습니다.');
        }
    }
}
