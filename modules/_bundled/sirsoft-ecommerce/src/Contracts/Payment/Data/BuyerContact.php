<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 주문 구매자 연락처 (공개 계약 DTO)
 *
 * 칸마다 결제 행의 구매자 값을 먼저 쓰고, 비어 있으면(null) 주문 배송지 행의 주문자 값으로 채운다.
 * 결제사 범위를 걸지 않는다 — 현금영수증처럼 주문을 어느 결제사가 받았는지와 무관하게 구매자에게 하는 일에 쓴다.
 *
 * @since 1.4.0
 */
final readonly class BuyerContact
{
    /**
     * BuyerContact 생성자
     *
     * @param  string|null  $name  구매자명(결제 행 → 주문자)
     * @param  string|null  $email  구매자 이메일(결제 행 → 주문자)
     * @param  string|null  $phone  구매자 전화(결제 행 → 주문자)
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
