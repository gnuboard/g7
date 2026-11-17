<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * API 문서 실측용 샘플 결제 명세 (공개 계약 DTO)
 *
 * @since 1.4.0
 */
final readonly class SamplePaymentSpec
{
    /** 결제 행에 쓸 수 있는 컬럼 */
    public const ALLOWED_PAYMENT_ATTRIBUTES = [
        'transaction_id', 'merchant_order_id', 'payment_status', 'payment_method', 'is_escrow',
        'buyer_name', 'buyer_email', 'buyer_phone', 'payment_name',
    ];

    /**
     * SamplePaymentSpec 생성자
     *
     * @param  string  $orderNumber  샘플 주문번호(이 번호로 멱등 생성)
     * @param  int  $ownerUserId  샘플 주문 소유 회원 id
     * @param  string  $pgProvider  결제사 id
     * @param  array<string, mixed>  $paymentAttributes  결제 행 컬럼(ALLOWED_PAYMENT_ATTRIBUTES 만)
     * @param  array<string, mixed>  $paymentMeta  결제 meta
     * @param  ShippingAddress  $shippingAddress  배송지
     */
    public function __construct(
        public string $orderNumber,
        public int $ownerUserId,
        public string $pgProvider,
        public array $paymentAttributes,
        public array $paymentMeta,
        public ShippingAddress $shippingAddress,
    ) {}
}
