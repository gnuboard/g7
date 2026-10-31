<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문의 「결제 이후」 판정과 주문 상품 정가 규칙의 단일 원천
 *
 * 「결제 이후」 판정식은 입금 기록 서비스, 상태 전이 서비스, 입금확인 창 레이아웃이 각자 들고 있었고(창은 상태 문자열을
 * 직접 비교), 주문 상품 정가는 주문 상세 리소스와 클레임 비교 카드가 서로 다른 대체 규칙을 썼다 — 상품 정가가 없는
 * 주문에서 주문 상세는 판매 단가를, 클레임 카드는 옵션 조정액만(0 + 조정액)을 정가로 그려 같은 주문의 두 화면 값이 갈렸다.
 */
class OrderPaymentStageAndListPriceRuleTest extends ModuleTestCase
{
    /**
     * 결제 전 상태와 취소는 결제 이후가 아니고, 결제완료부터 구매확정까지는 결제 이후다.
     *
     * @scenario rule=payment_stage
     *
     * @effects order_status_past_payment_is_single_enum_rule
     *
     * @return void 반환값 없음
     */
    public function test_order_status_past_payment(): void
    {
        $this->assertFalse(OrderStatusEnum::PENDING_ORDER->isPastPayment());
        $this->assertFalse(OrderStatusEnum::PENDING_PAYMENT->isPastPayment());
        $this->assertFalse(OrderStatusEnum::CANCELLED->isPastPayment());

        foreach (OrderStatusEnum::cases() as $status) {
            $expected = ! $status->isBeforePayment() && $status !== OrderStatusEnum::CANCELLED;
            $this->assertSame($expected, $status->isPastPayment(), $status->value);
        }

        $this->assertTrue(OrderStatusEnum::PAYMENT_COMPLETE->isPastPayment());
        $this->assertTrue(OrderStatusEnum::PREPARING->isPastPayment());
        $this->assertTrue(OrderStatusEnum::DELIVERED->isPastPayment());
    }

    /**
     * 정가는 옵션 스냅샷 정가 → 상품 정가 + 옵션 조정액 순이고, 근거가 없으면 null 이다.
     *
     * @scenario rule=list_price
     *
     * @effects order_option_list_price_rule_is_single
     *
     * @return void 반환값 없음
     */
    public function test_list_price_from_snapshots(): void
    {
        $this->assertSame(9000.0, OrderOption::resolveListPriceFromSnapshots(['list_price' => 9000, 'price_adjustment' => 3000], ['list_price' => 4000]));
        $this->assertSame(7000.0, OrderOption::resolveListPriceFromSnapshots(['price_adjustment' => 3000], ['list_price' => 4000]));
        $this->assertNull(OrderOption::resolveListPriceFromSnapshots(['price_adjustment' => 3000], ['selling_price' => 2000]));
    }

    /**
     * 정가 근거가 없으면 판매 단가(상품 판매가 + 옵션 조정액)로 대신한다 — 주문 상세가 판매 단가로 대신하는 것과 같다.
     *
     * @scenario rule=list_price
     *
     * @effects order_option_list_price_fallback_matches_order_detail
     *
     * @return void 반환값 없음
     */
    public function test_list_or_unit_price_falls_back_to_selling_price(): void
    {
        $this->assertSame(7000.0, OrderOption::resolveListOrUnitPriceFromSnapshots(['price_adjustment' => 3000], ['list_price' => 4000, 'selling_price' => 2000]));
        $this->assertSame(5000.0, OrderOption::resolveListOrUnitPriceFromSnapshots(['price_adjustment' => 3000], ['selling_price' => 2000]));
        $this->assertSame(0.0, OrderOption::resolveListOrUnitPriceFromSnapshots([], []));
    }
}
