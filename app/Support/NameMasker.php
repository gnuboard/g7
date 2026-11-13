<?php

namespace App\Support;

/**
 * 이름과 아이디를 가리는 규칙 한 곳.
 *
 * 표시 이름 종류 「가린 이름」「아이디 가림」과 화면 미리보기가 같은 결과를 내도록 가림은 여기서만 한다.
 *
 * | 메서드 | 규칙 | 예 |
 * | --- | --- | --- |
 * | maskName | 1자는 그대로, 2자는 첫 글자 + `*`, 3자 이상은 첫 글자 + 가운데 전부 `*` + 끝 글자 | 홍길동 → 홍*동 |
 * | maskId | 앞 3자(3자 이하면 앞 1자)만 남기고 `***` 를 붙인다. 별표 수는 길이와 무관하다 | gildong → gil*** |
 *
 * @since 7.2.0
 */
class NameMasker
{
    /** 아이디 가림에서 남기는 앞 글자 수 */
    public const ID_VISIBLE_LENGTH = 3;

    /** 아이디 가림 뒤에 붙이는 별표 (길이를 드러내지 않게 고정 길이) */
    public const ID_MASK = '***';

    /**
     * 이름을 가립니다.
     *
     * @param  string|null  $name  이름
     * @return string|null 가린 이름 (비어 있으면 null)
     */
    public static function maskName(?string $name): ?string
    {
        $name = $name === null ? '' : trim($name);
        if ($name === '') {
            return null;
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
     * 아이디(이메일의 `@` 앞부분)를 가립니다.
     *
     * 이메일 전체를 넘겨도 되고 아이디만 넘겨도 된다.
     *
     * @param  string|null  $identifier  이메일 또는 아이디
     * @return string|null 가린 아이디 (비어 있으면 null)
     */
    public static function maskId(?string $identifier): ?string
    {
        $identifier = $identifier === null ? '' : trim($identifier);
        $at = mb_strpos($identifier, '@');
        if ($at !== false) {
            $identifier = mb_substr($identifier, 0, $at);
        }
        if ($identifier === '') {
            return null;
        }

        $length = mb_strlen($identifier);
        $visible = $length > self::ID_VISIBLE_LENGTH ? self::ID_VISIBLE_LENGTH : 1;

        return mb_substr($identifier, 0, $visible).self::ID_MASK;
    }
}
