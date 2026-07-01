<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 상품 이미지 컬렉션 Enum
 */
enum ProductImageCollection: string
{
    case MAIN = 'main';             // 대표 이미지
    case DETAIL = 'detail';         // 상세 이미지
    case ADDITIONAL = 'additional'; // 추가 이미지

    /**
     * 컬렉션당 허용 이미지 최대 개수 (프론트 FileUploader maxFiles 와 SSoT 일치)
     */
    public const MAX_IMAGES_PER_COLLECTION = 20;

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 컬렉션 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.image_collection.'.$this->value);
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array 컬렉션 value 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array value/label 옵션 목록
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
