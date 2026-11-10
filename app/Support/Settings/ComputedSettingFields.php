<?php

namespace App\Support\Settings;

/**
 * 코어 설정 화면 스키마의 계산 필드(`computed: true`) 판정 — 관리자 설정 서비스와 터미널 설정 명령이 함께 쓰는 한 곳 (#887)
 *
 * 계산 필드는 다른 설정 값에서 만들어 화면에 싣는 값(예: `content.render_policy`)이라 저장하지 않는다. 판정식이 두 곳에 따로 있으면
 * 한쪽만 바뀌어 관리자 저장은 버리는데 터미널은 저장하는 식으로 갈린다. 판정: 필드 스키마가 배열이고 `computed` 가 비어 있지 않다.
 *
 * 확장 설정의 계산 키(`ComputedSettingKeys`)는 출처가 다른 별도 규칙이다.
 *
 * @since 7.1.5
 */
final class ComputedSettingFields
{
    /**
     * 필드 스키마가 계산 필드인지.
     *
     * @param  mixed  $fieldSchema  필드 스키마
     * @return bool 계산 필드면 true
     */
    public static function isComputed(mixed $fieldSchema): bool
    {
        return is_array($fieldSchema) && ! empty($fieldSchema['computed']);
    }

    /**
     * 범주 스키마에서 계산 필드만 고릅니다.
     *
     * @param  mixed  $categorySchema  범주 스키마 (`fields` 를 가진 배열)
     * @return array<string, array<string, mixed>> 필드명 => 필드 스키마
     */
    public static function schemas(mixed $categorySchema): array
    {
        $fields = is_array($categorySchema) && is_array($categorySchema['fields'] ?? null) ? $categorySchema['fields'] : [];

        return array_filter($fields, static fn ($fieldSchema) => self::isComputed($fieldSchema));
    }

    /**
     * 범주 스키마의 계산 필드 — 「필드명 => 화면 키(`frontend_key`, 없으면 필드명)」.
     *
     * @param  mixed  $categorySchema  범주 스키마 (`fields` 를 가진 배열)
     * @return array<string, string> 필드명 => 화면 키
     */
    public static function frontendKeys(mixed $categorySchema): array
    {
        $keys = [];
        foreach (self::schemas($categorySchema) as $field => $fieldSchema) {
            $keys[$field] = (string) ($fieldSchema['frontend_key'] ?? $field);
        }

        return $keys;
    }
}
