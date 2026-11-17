<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 결제 행 목록용 요약 (공개 계약 DTO) — 주문번호, 거래번호, 결제 meta 세 칸만 싣는다
 *
 * 관리자 주문 목록의 테스트 결제 배지처럼 몇 칸만 보는 목록 소비자가 쓴다. 결제 행 전체가 필요하면 `PaymentRecord` 를 돌려주는
 * 조회를 쓴다.
 *
 * @since 1.4.0
 */
final readonly class PaymentBrief
{
    /**
     * PaymentBrief 생성자
     *
     * @param  string  $orderNumber  주문번호
     * @param  string|null  $transactionId  결제사 거래번호
     * @param  array<string, mixed>  $paymentMeta  결제 meta(없으면 빈 배열)
     */
    public function __construct(
        public string $orderNumber,
        public ?string $transactionId,
        public array $paymentMeta,
    ) {}
}
