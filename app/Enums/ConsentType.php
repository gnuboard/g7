<?php

namespace App\Enums;

use App\Extension\HookManager;

/**
 * 약관 동의 유형 Enum
 *
 * 사용자 동의 유형을 정의합니다.
 * 플러그인은 core.consent.allowed_types 훅으로 추가 유형을 등록할 수 있습니다.
 */
enum ConsentType: string
{
    /**
     * 이용약관 동의
     */
    case Terms = 'terms';

    /**
     * 개인정보처리방침 동의
     */
    case Privacy = 'privacy';

    /**
     * 모든 유형 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 유형 값인지 확인합니다.
     *
     * @param  string  $value  검증할 유형 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 유형의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 유형 라벨
     */
    public function label(): string
    {
        return __('consent.type.'.$this->value);
    }

    /**
     * 플러그인 확장을 포함한 허용 동의 유형 목록을 반환합니다.
     *
     * core.consent.allowed_types 필터 훅을 통해 플러그인에서 추가 유형을 등록할 수 있습니다.
     *
     * @return array<string> 허용된 동의 유형 값 배열
     */
    public static function allowedTypes(): array
    {
        return HookManager::applyFilters('core.consent.allowed_types', self::values());
    }
}
