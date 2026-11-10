<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 관련 상품(상품 상세 「이 상품은 어떠세요?」) 단계 안 순서 Enum
 *
 * 관련 상품은 대표 분류 → 그 상위 분류 → … 단계로 채우고, 같은 단계 안에서는 이 순서를 쓴다.
 * 환경설정 `product_display.related_order` 의 허용값이다.
 */
enum RelatedProductOrder: string
{
    case RANDOM = 'random';     // 무작위 (기본값)
    case SALES = 'sales';       // 판매 많은 순
    case REVIEWS = 'reviews';   // 리뷰 많은 순
    case LATEST = 'latest';     // 최신 등록 순

    /**
     * 허용값 목록을 반환합니다.
     *
     * @return array<int, string> 순서 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 설정값을 해석합니다. 알 수 없는 값이면 기본값(무작위)입니다.
     *
     * @param  mixed  $value  설정값
     * @return self 순서
     */
    public static function fromSetting(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::RANDOM;
    }
}
