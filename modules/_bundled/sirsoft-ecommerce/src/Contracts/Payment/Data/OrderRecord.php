<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;

/**
 * 다른 확장(결제사 플러그인)에 건네는 주문 요약 (공개 계약 DTO)
 *
 * 주문 meta 전체는 싣지 않는다. 결제 실패 정보(결제 실패 처리가 남긴 값)만 별도 필드로 준다.
 *
 * @since 1.4.0
 */
final readonly class OrderRecord
{
    /**
     * OrderRecord 생성자
     *
     * @param  int  $id  주문 id
     * @param  string  $orderNumber  주문번호
     * @param  int|null  $userId  회원 id(비회원이면 null)
     * @param  OrderStatusEnum  $orderStatus  주문 상태
     * @param  string  $currency  결제 통화
     * @param  array<string, mixed>  $currencySnapshot  주문 시점 통화 스냅샷
     * @param  float  $totalAmount  기준 통화 주문 총액
     * @param  float  $totalDueAmount  기준 통화 결제 예정액
     * @param  string|null  $paymentFailureCode  마지막 결제 실패 코드
     * @param  string|null  $paymentFailureMessage  마지막 결제 실패 메시지
     * @param  string|null  $paymentFailedAt  마지막 결제 실패 시각(ISO 8601 문자열 그대로)
     * @param  bool  $hasActiveOptions  취소되지 않은 주문 상품이 있는지
     * @param  string|null  $ordererName  주문자명(배송지 행)
     * @param  string|null  $ordererEmail  주문자 이메일(배송지 행)
     * @param  string|null  $ordererPhone  주문자 전화(배송지 행)
     * @param  PaymentRecord|null  $payment  결제 행(호출 결제사가 볼 수 있을 때만)
     */
    public function __construct(
        public int $id,
        public string $orderNumber,
        public ?int $userId,
        public OrderStatusEnum $orderStatus,
        public string $currency,
        public array $currencySnapshot,
        public float $totalAmount,
        public float $totalDueAmount,
        public ?string $paymentFailureCode,
        public ?string $paymentFailureMessage,
        public ?string $paymentFailedAt,
        public bool $hasActiveOptions,
        public ?string $ordererName,
        public ?string $ordererEmail,
        public ?string $ordererPhone,
        public ?PaymentRecord $payment,
    ) {}

    /**
     * 주문 Model 과 계약 구현이 미리 읽어 둔 값에서 DTO 를 만듭니다. 이 메서드는 조회하지 않는다(주문 Model 의 칸만 읽는다).
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  Order  $order  주문
     * @param  PaymentRecord|null  $payment  범위 판정을 거친 결제 행
     * @param  bool  $hasActiveOptions  취소되지 않은 주문 상품이 있는지(계약 구현이 판정)
     * @param  OrderAddress|null  $address  배송지 행(계약 구현이 읽어 둔 값)
     * @return self DTO
     */
    public static function fromModel(Order $order, ?PaymentRecord $payment, bool $hasActiveOptions, ?OrderAddress $address): self
    {
        $meta = is_array($order->order_meta) ? $order->order_meta : [];
        $optional = static fn ($value): ?string => $value === null || $value === '' ? null : (string) $value;

        return new self(
            id: (int) $order->id,
            orderNumber: (string) $order->order_number,
            userId: $order->user_id === null ? null : (int) $order->user_id,
            orderStatus: $order->order_status instanceof OrderStatusEnum
                ? $order->order_status
                : OrderStatusEnum::from((string) $order->order_status),
            currency: (string) $order->currency,
            currencySnapshot: is_array($order->currency_snapshot) ? $order->currency_snapshot : [],
            totalAmount: (float) $order->total_amount,
            totalDueAmount: (float) $order->total_due_amount,
            paymentFailureCode: $optional($meta['payment_failure_code'] ?? null),
            paymentFailureMessage: $optional($meta['payment_failure_message'] ?? null),
            paymentFailedAt: $optional($meta['payment_failed_at'] ?? null),
            hasActiveOptions: $hasActiveOptions,
            ordererName: $optional($address?->orderer_name),
            ordererEmail: $optional($address?->orderer_email),
            ordererPhone: $optional($address?->orderer_phone),
            payment: $payment,
        );
    }
}
