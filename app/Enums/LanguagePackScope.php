<?php

namespace App\Enums;

/**
 * 언어팩 적용 대상 분류 Enum.
 *
 * core 는 G7 코어 자체에 적용되며 target_identifier 가 null 이고,
 * module/plugin/template 은 대상 확장 식별자(target_identifier)가 필수입니다.
 */
enum LanguagePackScope: string
{
    /**
     * 코어 (G7 본체) 적용.
     */
    case Core = 'core';

    /**
     * 모듈 적용 (target_identifier = 모듈 식별자).
     */
    case Module = 'module';

    /**
     * 플러그인 적용 (target_identifier = 플러그인 식별자).
     */
    case Plugin = 'plugin';

    /**
     * 템플릿 적용 (target_identifier = 템플릿 식별자).
     */
    case Template = 'template';

    /**
     * 모든 스코프 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 스코프 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 주어진 문자열이 유효한 스코프 값인지 확인합니다.
     *
     * @param  string  $value  검사할 스코프 문자열
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 현재 스코프가 코어인지 확인합니다.
     *
     * @return bool 코어이면 true
     */
    public function isCore(): bool
    {
        return $this === self::Core;
    }

    /**
     * 현재 스코프가 target_identifier 를 필요로 하는지 확인합니다.
     *
     * 코어 외 스코프(module/plugin/template)는 대상 확장 식별자가 필수입니다.
     *
     * @return bool target_identifier 필요 여부
     */
    public function requiresTarget(): bool
    {
        return ! $this->isCore();
    }
}
