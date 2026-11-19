<?php

namespace App\Support;

use Normalizer;

/**
 * 닉네임 비교용 정규화.
 *
 * 전각과 반각, 대문자와 소문자, 공백 유무만 다른 닉네임을 같은 것으로 본다(사칭 방지).
 * 저장하는 닉네임 자체는 바꾸지 않고, 비교와 중복 검사에만 정규화 값을 쓴다.
 *
 * @since 7.2.0
 */
final class NicknameNormalizer
{
    /**
     * 닉네임을 정규화합니다 (NFKC → 소문자 → 공백 제거).
     *
     * @param  string|null  $nickname  닉네임 원문
     * @return string|null 정규화 값 (비어 있으면 null)
     */
    public static function normalize(?string $nickname): ?string
    {
        if ($nickname === null) {
            return null;
        }

        $value = $nickname;
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            $value = is_string($normalized) ? $normalized : $value;
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);

        return $value === '' ? null : $value;
    }
}
