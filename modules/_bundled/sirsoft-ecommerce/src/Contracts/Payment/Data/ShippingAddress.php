<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

use Modules\Sirsoft\Ecommerce\Models\OrderAddress;

/**
 * 주문 배송지 행의 주문자와 수령인 정보 (공개 계약 DTO)
 *
 * 이커머스 내부 `DTO\ShippingAddress` 와 이름이 같지만 다른 클래스다(공개 계약 전용).
 *
 * @since 1.4.0
 */
final readonly class ShippingAddress
{
    /**
     * ShippingAddress 생성자
     *
     * @param  string|null  $ordererName  주문자명
     * @param  string|null  $ordererEmail  주문자 이메일
     * @param  string|null  $ordererPhone  주문자 전화
     * @param  string|null  $recipientName  수령인명
     * @param  string|null  $recipientPhone  수령인 전화
     * @param  string|null  $zipcode  우편번호
     * @param  string|null  $address  주소
     * @param  string|null  $addressDetail  상세 주소
     */
    public function __construct(
        public ?string $ordererName = null,
        public ?string $ordererEmail = null,
        public ?string $ordererPhone = null,
        public ?string $recipientName = null,
        public ?string $recipientPhone = null,
        public ?string $zipcode = null,
        public ?string $address = null,
        public ?string $addressDetail = null,
    ) {}

    /**
     * 주소 Model 에서 DTO 를 만듭니다.
     *
     * @internal 이커머스 계약 구현만 쓴다.
     *
     * @param  OrderAddress  $address  배송지 행
     * @return self DTO
     */
    public static function fromModel(OrderAddress $address): self
    {
        $string = static fn ($value): ?string => $value === null ? null : (string) $value;

        return new self(
            ordererName: $string($address->orderer_name),
            ordererEmail: $string($address->orderer_email),
            ordererPhone: $string($address->orderer_phone),
            recipientName: $string($address->recipient_name),
            recipientPhone: $string($address->recipient_phone),
            zipcode: $string($address->zipcode),
            address: $string($address->address),
            addressDetail: $string($address->address_detail),
        );
    }
}
