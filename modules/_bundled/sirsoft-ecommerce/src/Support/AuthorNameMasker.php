<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 공개 화면에 싣는 작성자 이름 가림 규칙.
 *
 * 상품 문의와 상품 후기가 같은 규칙으로 이름을 가린다 — 규칙을 한 곳에 둔다.
 */
final class AuthorNameMasker
{
    /** 공개 후기 이름 가림 방식: 가운데 가림 */
    public const MODE_MIDDLE = 'middle';

    /** 공개 후기 이름 가림 방식: 가리지 않음 */
    public const MODE_NONE = 'none';

    /**
     * 이름의 가운데를 가립니다.
     *
     * 한 글자는 그대로, 두 글자는 뒤 글자를, 세 글자 이상은 첫 글자와 끝 글자만 남기고 가린다.
     *
     * @param  string|null  $name  원래 이름
     * @return string 가린 이름 (빈 값이면 빈 문자열)
     */
    public static function mask(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }

        $chars = mb_str_split($name);
        $length = count($chars);

        if ($length === 1) {
            return $name;
        }

        if ($length === 2) {
            return $chars[0].'*';
        }

        return $chars[0].str_repeat('*', $length - 2).$chars[$length - 1];
    }

    /**
     * 공개 후기 응답에 적용할 가림 방식을 설정에서 읽습니다.
     *
     * @return string `middle` 또는 `none` (알 수 없는 값은 `middle`)
     */
    public static function reviewMode(): string
    {
        $mode = module_setting('sirsoft-ecommerce', 'review_settings.author_name_masking', self::MODE_MIDDLE);

        return $mode === self::MODE_NONE ? self::MODE_NONE : self::MODE_MIDDLE;
    }

    /**
     * 공개 후기 응답용 이름을 돌려줍니다 (설정이 `none` 이면 그대로).
     *
     * @param  string|null  $name  원래 이름
     * @return string 공개 응답에 실을 이름
     */
    public static function forPublicReview(?string $name): string
    {
        return self::reviewMode() === self::MODE_NONE ? (string) ($name ?? '') : self::mask($name);
    }
}
