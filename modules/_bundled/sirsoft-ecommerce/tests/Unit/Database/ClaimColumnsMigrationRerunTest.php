<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 컬럼 추가 마이그레이션(2026_08_20_000008)의 재실행 안전성 회귀 테스트
 *
 * MySQL 은 DDL 을 트랜잭션으로 되돌리지 않는다. up() 이 중간에 실패하면 그 앞부분은
 * 커밋된 채로 남는데 마이그레이션 기록은 남지 않아, 다음 실행이 같은 up() 을 처음부터
 * 다시 돌린다. 이때 이미 만들어진 컬럼·인덱스·외래키를 다시 만들려 하면 영구 실패한다.
 *
 * 컬럼만 `Schema::hasColumn` 으로 막아 두면 인덱스·외래키 단계에서 그대로 걸린다
 * ("Duplicate key name"). 이 테스트는 up() 을 두 번 실행해 전 단계가 멱등인지 고정한다.
 */
class ClaimColumnsMigrationRerunTest extends ModuleTestCase
{
    /**
     * 마이그레이션 인스턴스를 로드합니다.
     *
     * @return object 익명 마이그레이션 클래스 인스턴스
     */
    private function migration(): object
    {
        return require $this->getModuleBasePath()
            .'/database/migrations/2026_08_20_000008_add_claim_columns_to_existing_tables.php';
    }

    #[Test]
    public function up_을_다시_실행해도_실패하지_않는다(): void
    {
        // RefreshDatabase 로 이미 1회 적용된 상태 — 같은 up() 을 다시 돌린다.
        // (부분 적용 후 재실행 시나리오의 최대 케이스)
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('ecommerce_order_refunds', 'refund_source_type'));
        $this->assertTrue(Schema::hasColumn('ecommerce_shipping_policy_country_settings', 'claim_fee_mode'));
        $this->assertTrue(Schema::hasColumn('ecommerce_mileage_transactions', 'claim_payment_id'));
    }

    #[Test]
    public function 재실행_후에도_인덱스가_중복_생성되지_않는다(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('ecommerce_order_refunds', 'ecommerce_order_refunds_source_index'));
        $this->assertTrue(Schema::hasIndex('ecommerce_order_refunds', 'ecommerce_order_refunds_requested_by_index'));
        $this->assertTrue(Schema::hasIndex('ecommerce_mileage_transactions', 'ecommerce_mileage_trx_claim_payment_index'));
    }

    #[Test]
    public function 재실행_후에도_외래키가_한_벌만_남는다(): void
    {
        $this->migration()->up();

        $refundFks = collect(Schema::getForeignKeys('ecommerce_order_refunds'))
            ->where('name', 'fk_rf_requested_by');
        $mileageFks = collect(Schema::getForeignKeys('ecommerce_mileage_transactions'))
            ->where('name', 'fk_mt_claim_payment_id');

        $this->assertCount(1, $refundFks, '재실행이 외래키를 중복 생성하면 안 된다');
        $this->assertCount(1, $mileageFks, '재실행이 외래키를 중복 생성하면 안 된다');
    }
}
