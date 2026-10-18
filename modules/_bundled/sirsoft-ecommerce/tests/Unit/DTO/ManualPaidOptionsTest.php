<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\DTO;

use Modules\Sirsoft\Ecommerce\DTO\ManualPaidOptions;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 수동 결제 처리 확인 창 세 항목 DTO (Q13)
 *
 * 요청에 키가 없으면 켬(true) — 키를 모르는 기존 API 클라이언트가 업그레이드만으로 마일리지·재고를
 * 빼먹던 종전 결함으로 돌아가지 않게 한다.
 */
class ManualPaidOptionsTest extends ModuleTestCase
{
    /**
     * 키가 없으면 세 항목 모두 켬이다.
     *
     * @effects omitted_flags_default_to_true
     *
     * @return void 반환값 없음
     */
    public function test_missing_keys_default_to_true(): void
    {
        $options = ManualPaidOptions::fromInput(['order_status' => 'payment_complete']);

        $this->assertTrue($options->applyStockDeduction);
        $this->assertTrue($options->applyMileageDeduction);
        $this->assertTrue($options->recordPaidAmount);
        $this->assertTrue($options->applyRestoration);
        $this->assertSame([], $options->skippedKeys());
    }

    /**
     * "0"·false·"false" 는 끔이다.
     *
     * @effects unchecked_item_is_skipped_only
     *
     * @return void 반환값 없음
     */
    public function test_falsy_values_turn_items_off(): void
    {
        $options = ManualPaidOptions::fromInput([
            'apply_stock_deduction' => '0',
            'apply_mileage_deduction' => false,
            'record_paid_amount' => 'false',
        ]);

        $this->assertFalse($options->applyStockDeduction);
        $this->assertFalse($options->applyMileageDeduction);
        $this->assertFalse($options->recordPaidAmount);
        $this->assertSame(['apply_stock_deduction', 'apply_mileage_deduction', 'record_paid_amount'], $options->skippedKeys());
    }

    /**
     * extract 는 세 키를 입력에서 떼어 낸다 — 주문 저장에 섞이면 없는 컬럼을 쓰려다 실패한다.
     *
     * @effects omitted_flags_default_to_true
     *
     * @return void 반환값 없음
     */
    public function test_extract_removes_the_keys(): void
    {
        $data = ['order_status' => 'preparing', 'record_paid_amount' => false, 'admin_memo' => 'x'];

        $options = ManualPaidOptions::extract($data);

        $this->assertSame(['order_status' => 'preparing', 'admin_memo' => 'x'], $data);
        $this->assertFalse($options->recordPaidAmount);
        $this->assertSame(['apply_stock_deduction' => true, 'apply_mileage_deduction' => true, 'record_paid_amount' => false], $options->toArray());
    }

    /**
     * with 는 고른 항목만 바꾼 사본을 만든다(일괄 2단계 처리).
     *
     * @effects bulk_mileage_short_is_all_or_nothing_and_names_every_order
     *
     * @return void 반환값 없음
     */
    public function test_with_overrides_only_given_items(): void
    {
        $base = new ManualPaidOptions(true, false, true);

        $phase1 = $base->with(stock: false, record: false, restoration: false);

        $this->assertFalse($phase1->applyStockDeduction);
        $this->assertFalse($phase1->applyMileageDeduction);
        $this->assertFalse($phase1->recordPaidAmount);
        $this->assertFalse($phase1->applyRestoration);
        $this->assertTrue($base->applyRestoration);
    }
}
