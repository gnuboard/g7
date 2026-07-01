<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 마이그레이션 스키마 검증 (§18.2-K)
 *
 * RefreshDatabase 가 up() 을 실행하므로, 생성된 컬럼/인덱스/유니크가
 * 마이그레이션 정의와 일치하는지 스키마 introspection 으로 단언한다.
 */
class MileageMigrationsSchemaTest extends ModuleTestCase
{
    /**
     * transactions 테이블 컬럼 전수 존재.
     */
    public function test_transactions_table_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ecommerce_mileage_transactions'));

        $columns = [
            'id', 'user_id', 'currency', 'type', 'amount', 'remaining_amount', 'balance_after',
            'order_id', 'order_option_id', 'order_cancel_id', 'source_transaction_id', 'granted_by',
            'description', 'memo', 'expires_at', 'expired_at', 'metadata', 'created_at', 'updated_at',
        ];

        $this->assertTrue(
            Schema::hasColumns('ecommerce_mileage_transactions', $columns),
            'transactions 테이블 컬럼이 누락되었습니다.'
        );
    }

    /**
     * order_option_id 는 FK 미설정(병합 하드삭제 대응) — 인덱스만 존재.
     */
    public function test_transactions_indexes_exist(): void
    {
        $indexes = collect(Schema::getIndexes('ecommerce_mileage_transactions'))->pluck('name')->all();

        $this->assertContains('ecommerce_mileage_transactions_user_currency_expires_index', $indexes);
        $this->assertContains('ecommerce_mileage_transactions_order_option_index', $indexes);
        $this->assertContains('ecommerce_mileage_transactions_order_cancel_index', $indexes);
    }

    /**
     * order_options 에 delivered_at + mc_subtotal_earned_points_amount 추가.
     */
    public function test_order_options_added_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('ecommerce_order_options', 'delivered_at'));
        $this->assertTrue(Schema::hasColumn('ecommerce_order_options', 'mc_subtotal_earned_points_amount'));
    }

    /**
     * balances 캐시 테이블 컬럼 + (user_id, currency) 유니크.
     */
    public function test_balances_table_columns_and_unique(): void
    {
        $this->assertTrue(Schema::hasTable('ecommerce_mileage_balances'));

        $columns = [
            'id', 'user_id', 'currency', 'available', 'pending', 'total_earned', 'total_used',
            'expiring_soon', 'expiring_date', 'recalculated_at', 'created_at', 'updated_at',
        ];
        $this->assertTrue(Schema::hasColumns('ecommerce_mileage_balances', $columns));

        $indexes = collect(Schema::getIndexes('ecommerce_mileage_balances'))->pluck('name')->all();
        $this->assertContains('ecommerce_mileage_balances_user_currency_unique', $indexes);
    }
}
