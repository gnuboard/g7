<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Concerns;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 수동 결제 처리·재활성 테스트용 주문 조립 헬퍼 (Q13·Q14·Q26)
 *
 * 결제 전 주문은 생성 시점과 같은 모양(결제행 미결제·`paid_amount_base` 에 결제예정금액 — 0-4 ⑭)으로
 * 만들고, 취소 주문은 **관리자 취소 API(실제 경로)** 로 만든다. 취소 엔진이 남기는 값(합계 0·환불 누적·
 * 원장 복원 행·`pre_cancel_snapshot`)을 손으로 흉내 내면 재활성 복원이 실제 코드와 무관한 값을 검증한다.
 */
trait BuildsManualPaidOrders
{
    use BuildsClaimFlowOrders;

    /**
     * 마일리지 기능을 켜고 KRW 사용 규칙(1P=1원, 제한 없음)을 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function enableManualPaidMileage(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
    }

    /**
     * PG 환불 훅을 성공으로 고정합니다. (카드 주문 취소의 PG 단계)
     *
     * @param  array|null  $calls  호출 기록 (참조)
     * @return void 반환값 없음
     */
    protected function fakeManualPaidPgRefund(?array &$calls = null): void
    {
        $calls = [];

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default, $order, $payment, $amount) use (&$calls) {
            $calls[] = ['amount' => $amount];

            return ['success' => true, 'transaction_id' => 'PGTXN-'.count($calls), 'error_code' => null, 'error_message' => null];
        });
    }

    /**
     * 주문에 국내 배송지를 붙입니다. (단건 수정 요청의 수취인 필수값 전제)
     *
     * @param  Order  $order  주문
     * @return void 반환값 없음
     */
    protected function attachManualPaidAddress(Order $order): void
    {
        if ($order->addresses()->where('address_type', 'shipping')->exists()) {
            return;
        }

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'orderer_name' => '홍길동',
            'orderer_phone' => '010-1234-5678',
            'orderer_email' => 'manual-paid@example.com',
            'recipient_country_code' => 'KR',
            'zipcode' => '06236',
            'address' => '서울 강남구 테헤란로 1',
            'address_detail' => '101호',
        ]);
    }

    /**
     * 단건 수정 요청의 수취인 본문을 돌려줍니다.
     *
     * @return array<string, string> 수취인 필드
     */
    protected function recipientBody(): array
    {
        return [
            'recipient_name' => '홍길동',
            'recipient_phone' => '010-1234-5678',
            'recipient_zipcode' => '06236',
            'recipient_address' => '서울 강남구 테헤란로 1',
            'recipient_detail_address' => '101호',
        ];
    }

    /**
     * 결제 전(주문대기) 상태의 마일리지 사용 주문을 만듭니다.
     *
     * 결제행은 생성 시점처럼 미결제(`ready`)인데 `paid_amount_base` 에 결제예정금액이 들어 있다(0-4 ⑭).
     * 「결제완료 시 차감」 결제수단이라 사용 마일리지·재고는 아직 빠지지 않았다.
     *
     * @param  int  $points  사용 마일리지 (0 이면 사용 없음)
     * @param  int  $balance  회원 마일리지 잔액 (사용액과 별개로 지급)
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  string  $method  결제수단 코드
     * @param  User|null  $member  주문 회원 (null 이면 새 회원, 비회원은 guest: true)
     * @param  bool  $guest  비회원 주문 여부
     * @param  int  $stock  상품 옵션 재고
     * @return Order 결제 전 주문 (options·payment 로드됨)
     */
    protected function pendingOrderUsingMileage(
        int $points = 5000,
        int $balance = 10000,
        int $price = 35000,
        int $quantity = 1,
        string $method = 'card',
        ?User $member = null,
        bool $guest = false,
        int $stock = 100,
    ): Order {
        $this->enableManualPaidMileage();
        $member = $guest ? null : ($member ?? User::factory()->create());

        $policy = $this->createClaimShippingPolicy();
        [$product, $option] = $this->createClaimProduct($price, $policy);
        $option->update(['stock_quantity' => $stock]);
        $product->update(['stock_quantity' => $stock]);

        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: $quantity)],
                usePoints: $guest ? 0 : $points,
            ),
            [
                'user_id' => $member?->id,
                'order_status' => OrderStatusEnum::PENDING_ORDER,
                'is_mileage_deducted' => false,
                'currency_snapshot' => $this->krwCurrencySnapshot(),
            ],
            [
                'payment_method' => $method,
                'pg_provider' => $method === PaymentMethodEnum::DBANK->value ? '' : 'tosspayments',
                'payment_status' => PaymentStatusEnum::READY,
                'paid_at' => null,
            ],
        );
        $order = $this->attachPolicyShippings($order, $policy);

        $due = (float) $order->total_paid_amount;
        $order->forceFill(['total_paid_amount' => 0, 'total_due_amount' => $due, 'paid_at' => null])->save();
        $order->payment->forceFill(['paid_amount_base' => $due, 'paid_amount_local' => $due])->save();

        foreach ($order->options as $row) {
            $row->forceFill(['option_status' => OrderStatusEnum::PENDING_ORDER, 'is_stock_deducted' => false])->save();
        }

        if ($member !== null && $balance > 0) {
            $this->grantMileage($member->id, $balance);
        }

        $this->attachManualPaidAddress($order);

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 사용 마일리지와 현금을 함께 낸 카드 주문을 **관리자 취소 API** 로 전체취소한 주문을 만듭니다.
     *
     * 결과: 주문 `cancelled`·합계 0, 환불 누적(현금 `cash`·포인트 `points`), 원장 `refund_restore`(+points),
     * 상품 행 `cancelled`·재고 복원(`is_stock_deducted=false`).
     *
     * @param  int  $points  사용 마일리지
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  User|null  $member  주문 회원
     * @param  int  $extraBalance  취소 뒤 남길 추가 잔액
     * @return Order 취소된 주문
     */
    protected function cancelledPaidOrderWithMileageRefund(
        int $points = 5000,
        int $price = 35000,
        int $quantity = 1,
        ?User $member = null,
        int $extraBalance = 0,
    ): Order {
        $order = $this->paidOrderUsingMileage($points, $price, $quantity, $member, $extraBalance);

        return $this->cancelThroughAdmin($order, 'full');
    }

    /**
     * 사용 마일리지와 현금을 함께 낸 결제완료 카드 주문을 만듭니다. (마일리지는 원장에서 실제로 차감됨)
     *
     * @param  int  $points  사용 마일리지
     * @param  int  $price  판매가
     * @param  int  $quantity  수량
     * @param  User|null  $member  주문 회원
     * @param  int  $extraBalance  사용액 외에 남길 잔액
     * @param  array<int, array{price: int, quantity: int}>  $extraLines  추가 상품 줄
     * @param  array<int>  $couponIssueIds  주문·배송비 쿠폰 발급 ID
     * @param  int  $shippingFee  고정 배송비 (0 이면 무료 배송정책)
     * @return Order 결제완료 주문
     */
    protected function paidOrderUsingMileage(
        int $points = 5000,
        int $price = 35000,
        int $quantity = 1,
        ?User $member = null,
        int $extraBalance = 0,
        array $extraLines = [],
        array $couponIssueIds = [],
        int $shippingFee = 0,
    ): Order {
        $this->enableManualPaidMileage();
        $member ??= User::factory()->create();

        $policy = $shippingFee > 0
            ? $this->createClaimShippingPolicy(ChargePolicyEnum::FIXED, $shippingFee)
            : $this->createClaimShippingPolicy();
        $items = [];
        foreach (array_merge([['price' => $price, 'quantity' => $quantity]], $extraLines) as $line) {
            [$product, $option] = $this->createClaimProduct($line['price'], $policy);
            $items[] = new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: $line['quantity']);
        }

        foreach ($couponIssueIds as $issueId) {
            CouponIssue::whereKey($issueId)->update(['user_id' => $member->id]);
        }

        $input = new CalculationInput(items: $items, couponIssueIds: $couponIssueIds, usePoints: $points);
        $order = $this->createClaimOrder(
            $input,
            [
                'user_id' => $member->id,
                'is_mileage_deducted' => $points > 0,
                'currency_snapshot' => $this->krwCurrencySnapshot(),
            ],
            ['payment_method' => PaymentMethodEnum::CARD->value, 'pg_provider' => 'tosspayments'],
        );
        $order = $this->withPromotionsSnapshot($order, $input);
        $order = $this->attachPolicyShippings($order, $policy);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PAYMENT_COMPLETE);

        foreach ($couponIssueIds as $issueId) {
            CouponIssue::whereKey($issueId)->update([
                'status' => CouponIssueRecordStatus::USED,
                'used_at' => now(),
                'order_id' => $order->id,
            ]);
        }

        $used = (int) $order->total_points_used_amount;
        if ($used > 0) {
            $this->grantMileage($member->id, $used);
            app(UserMileageService::class)->deductFifo($member->id, $used, 'KRW', $order);
        }
        if ($extraBalance > 0) {
            $this->grantMileage($member->id, $extraBalance);
        }

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 주문 생성 경로와 같은 모양의 프로모션 스냅샷을 주문에 기록합니다.
     *
     * @param  Order  $order  주문
     * @param  CalculationInput  $input  주문 생성 계산 입력
     * @return Order 갱신된 주문
     */
    protected function withPromotionsSnapshot(Order $order, CalculationInput $input): Order
    {
        $result = app(OrderCalculationService::class)->calculate($input);

        $order->forceFill([
            'promotions_applied_snapshot' => array_merge($result->promotions->toArray(), [
                'coupon_issue_ids' => $input->couponIssueIds,
                'item_coupons' => $input->itemCoupons,
                'discount_code' => $input->discountCode,
            ]),
            'total_product_coupon_discount_amount' => $result->summary->productCouponDiscount,
            'total_order_coupon_discount_amount' => $result->summary->orderCouponDiscount,
            'total_coupon_discount_amount' => $result->summary->productCouponDiscount + $result->summary->orderCouponDiscount,
            'total_vat_amount' => (float) ($result->summary->vatAmount ?? 0),
        ])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 주문 쿠폰과 발급 행을 만듭니다. (발급 행은 사용 가능 상태)
     *
     * @param  float  $discount  할인액
     * @param  float  $minOrderAmount  최소 주문금액
     * @param  CouponTargetType  $target  적용 대상
     * @return CouponIssue 쿠폰 발급 행
     */
    protected function createManualPaidCoupon(
        float $discount = 2000,
        float $minOrderAmount = 0,
        CouponTargetType $target = CouponTargetType::ORDER_AMOUNT,
    ): CouponIssue {
        $coupon = Coupon::create([
            'name' => ['ko' => '주문 '.number_format($discount).'원 할인', 'en' => 'Order discount'],
            'description' => ['ko' => '테스트 쿠폰', 'en' => 'Test coupon'],
            'target_type' => $target,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => $discount,
            'discount_max_amount' => null,
            'min_order_amount' => $minOrderAmount,
            'target_scope' => CouponTargetScope::ALL,
            'is_combinable' => true,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDays(30),
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => User::factory()->create()->id,
            'coupon_code' => 'MP'.uniqid(),
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);
    }

    /**
     * 관리자 취소 API 로 주문을 취소합니다. (환불까지 한 번에)
     *
     * @param  Order  $order  주문
     * @param  string  $type  취소 유형 (full 전체 · partial 부분)
     * @param  array  $items  부분취소 대상 상품 행과 수량 목록
     * @return Order 취소 뒤 주문
     */
    protected function cancelThroughAdmin(Order $order, string $type = 'full', array $items = []): Order
    {
        $this->fakeManualPaidPgRefund();
        // 「환불까지 한 번에」 는 환불 실행 권한을 요구한다 (7.1.0 — 접수 권한만으로 PG 환불까지 가지 못한다).
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read', 'sirsoft-ecommerce.orders.update', 'sirsoft-ecommerce.claims.execute-refund']);

        $body = ['type' => $type, 'reason' => 'changed_mind', 'auto_advance_refund' => true];
        if ($type === 'partial') {
            $body['items'] = $items;
        }

        $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel", $body)
            ->assertOk();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 무통장(주문 시 차감) 미입금 주문을 만들어 관리자 취소 API 로 전체취소합니다. (환불 없는 취소 — 경로 A)
     *
     * @param  int  $points  사용 마일리지
     * @param  int  $price  판매가
     * @param  User|null  $member  주문 회원
     * @return Order 취소된 주문
     */
    protected function cancelledUnpaidDbankOrderWithRestoredMileage(int $points = 5000, int $price = 35000, ?User $member = null): Order
    {
        $member ??= User::factory()->create();
        $order = $this->pendingOrderUsingMileage(
            points: $points,
            balance: 0,
            price: $price,
            method: PaymentMethodEnum::DBANK->value,
            member: $member,
        );

        $order->forceFill(['order_status' => OrderStatusEnum::PENDING_PAYMENT, 'is_mileage_deducted' => $points > 0])->save();
        $order->payment->forceFill(['payment_status' => PaymentStatusEnum::WAITING_DEPOSIT])->save();
        foreach ($order->options as $row) {
            $row->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT, 'is_stock_deducted' => true])->save();
        }

        if ($points > 0) {
            $this->grantMileage($member->id, $points);
            app(UserMileageService::class)->deductFifo($member->id, $points, 'KRW', $order);
        }

        return $this->cancelThroughAdmin($order->fresh(['options', 'payment', 'shippings']), 'full');
    }
}
