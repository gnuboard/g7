<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 배송 주소 DTO
 *
 * 배송비 계산에 필요한 주소 정보를 담습니다.
 * 도서산간 지역 여부는 우편번호 기반으로 배송정책에서 자동 판별됩니다.
 */
class ShippingAddress
{
    /**
     * @param  string|null  $countryCode  국가 코드 (예: KR, US, JP)
     * @param  string|null  $zipcode  우편번호
     * @param  string|null  $region  지역/도 (예: 서울특별시, 경기도)
     * @param  string|null  $city  시/군/구
     * @param  string|null  $address  상세 주소
     */
    public function __construct(
        public ?string $countryCode = 'KR',
        public ?string $zipcode = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?string $address = null,
    ) {}

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            countryCode: $data['country_code'] ?? $data['countryCode'] ?? 'KR',
            zipcode: $data['zipcode'] ?? $data['zip_code'] ?? null,
            region: $data['region'] ?? $data['state'] ?? $data['province'] ?? null,
            city: $data['city'] ?? null,
            address: $data['address'] ?? $data['address_detail'] ?? null,
        );
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'zipcode' => $this->zipcode,
            'region' => $this->region,
            'city' => $this->city,
            'address' => $this->address,
        ];
    }

    /**
     * 국내 배송 여부 확인
     */
    public function isDomestic(): bool
    {
        return $this->countryCode === 'KR' || $this->countryCode === null;
    }

    /**
     * 해외 배송 여부 확인
     */
    public function isInternational(): bool
    {
        return ! $this->isDomestic();
    }
}
