<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 할인코드 적용 상세 DTO
 */
class DiscountCodeApplication
{
    /**
     * @param  int  $codeId  할인코드 ID
     * @param  string  $code  할인코드
     * @param  string  $name  할인코드명
     * @param  string  $discountType  할인 타입 (fixed, rate)
     * @param  float  $discountValue  할인값
     * @param  int  $totalDiscount  총 할인금액
     * @param  array|null  $appliedItems  적용 상품 [{product_option_id, discount_amount}]
     */
    public function __construct(
        public int $codeId = 0,
        public string $code = '',
        public string $name = '',
        public string $discountType = '',
        public float $discountValue = 0,
        public int $totalDiscount = 0,
        public ?array $appliedItems = null,
    ) {}

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'code_id' => $this->codeId,
            'code' => $this->code,
            'name' => $this->name,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'total_discount' => $this->totalDiscount,
            'applied_items' => $this->appliedItems,
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            codeId: $data['code_id'] ?? 0,
            code: $data['code'] ?? '',
            name: $data['name'] ?? '',
            discountType: $data['discount_type'] ?? '',
            discountValue: $data['discount_value'] ?? 0,
            totalDiscount: $data['total_discount'] ?? 0,
            appliedItems: $data['applied_items'] ?? null,
        );
    }
}
