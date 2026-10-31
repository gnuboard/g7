<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 판매자가 접수한 회원 주문 클레임의 추가결제는 회원 수단(마일리지)을 고를 수 있다 (S3-D15, 2026-10-07 실측)
 *
 * 운영자 직권 접수 건은 `requested_by` 가 null 이다 — 비회원 신청과 같은 값이다. 결제 수단 선택지와 기본 수단은
 * 그 값으로 「결제할 사람」을 정해, 회원 주문인데도 비회원처럼 마일리지를 빼고 카드만 권했다. 회원은 고객 화면에서
 * 「결제 수단 신용카드」만 보고 마일리지로 낼 길이 없었다(실측: 회원 주문 20261007-0346009059 · 판매자 접수 교환
 * EX20261007-0346461835 · 옵션 차액 3,000원 · `claim.charge_payment_methods` = card·point). 서버의 결제 진입은
 * 로그인한 회원 id 로 판정해 `point` 를 받아 준다 — 화면만 그 길을 감췄다.
 *
 * 신청서 모델의 비회원 판정(`isGuestRequest`)은 이미 「주문의 주인」으로 가른다. 결제할 사람도 같은 기준이다.
 */
class ClaimPaymentPayerOfSellerFiledClaimTest extends ModuleTestCase
{
    /**
     * 판매자 접수(requested_by = null) 교환과 결제 대기 건을 만듭니다.
     *
     * @param  int|null  $userId  주문의 회원 id (null 이면 비회원 주문)
     * @return OrderExchange 교환 신청서
     */
    private function sellerFiledExchangeWithPendingPayment(?int $userId): OrderExchange
    {
        app(EcommerceSettingsService::class)->setSetting('claim.charge_payment_methods', ['card', 'point']);
        app(EcommerceSettingsService::class)->clearCache();

        $order = Order::factory()->create(['user_id' => $userId]);
        $exchange = OrderExchange::factory()->create([
            'order_id' => $order->id,
            'requested_by' => null,
            'exchange_status' => ExchangeStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_APPROVAL,
            'total_charge_amount' => 3000,
        ]);
        ClaimPayment::factory()->create([
            'payable_type' => ClaimTypeEnum::EXCHANGE->value,
            'payable_id' => $exchange->id,
            'order_id' => $order->id,
            'payment_method' => PaymentMethodEnum::CARD->value,
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return $exchange->fresh();
    }

    /**
     * 회원 주문이면 마일리지가 선택지에 오른다
     *
     * @scenario order_owner=member
     *
     * @effects seller_filed_member_claim_offers_mileage
     *
     * @return void 반환값 없음
     */
    public function test_seller_filed_claim_on_a_member_order_offers_mileage(): void
    {
        $user = User::factory()->create();
        $exchange = $this->sellerFiledExchangeWithPendingPayment($user->id);

        $choices = app(UserClaimService::class)->paymentBlock($exchange)['available_payment_methods'];

        $this->assertContains(PaymentMethodEnum::POINT->value, $choices, '회원 주문인데 비회원처럼 마일리지를 뺐다');
        $this->assertContains(PaymentMethodEnum::CARD->value, $choices);
    }

    /**
     * 비회원 주문이면 종전처럼 마일리지가 없다
     *
     * @scenario order_owner=guest
     *
     * @effects seller_filed_guest_claim_excludes_mileage
     *
     * @return void 반환값 없음
     */
    public function test_seller_filed_claim_on_a_guest_order_still_excludes_mileage(): void
    {
        $exchange = $this->sellerFiledExchangeWithPendingPayment(null);

        $choices = app(UserClaimService::class)->paymentBlock($exchange)['available_payment_methods'];

        $this->assertNotContains(PaymentMethodEnum::POINT->value, $choices);
        $this->assertContains(PaymentMethodEnum::CARD->value, $choices);
    }
}
