<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 이커머스 본문 미디어 소유 종류 (#883 4단계)
 */
enum ContentMediaOwnerType: string
{
    /** 상품 설명 */
    case Product = 'product';

    /** 상품 공통 정보 */
    case CommonInfo = 'common_info';

    /**
     * 값 목록.
     *
     * @return array<int, string> 값
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
