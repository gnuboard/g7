<?php

namespace App\Enums;

/**
 * 언어팩 행 단위 액션 가능 여부 키 Enum.
 *
 * `LanguagePackResource::abilityMap()` / `LanguagePackCollection::abilityMap()` 가 응답에
 * 노출하는 abilities 객체의 키. UI 의 토글/제거/업데이트 버튼 disabled 분기에 사용됩니다.
 */
enum LanguagePackAbility: string
{
    /**
     * 설치 가능 여부 (미설치 가상 행에서 노출).
     */
    case CanInstall = 'can_install';

    /**
     * 활성화 가능 여부 (status=installed/inactive 에서 가능).
     */
    case CanActivate = 'can_activate';

    /**
     * 비활성화 가능 여부 (status=active 이고 보호 대상 아님).
     */
    case CanDeactivate = 'can_deactivate';

    /**
     * 제거(uninstall) 가능 여부 (DB 행 존재 + 보호 대상 아님).
     */
    case CanUninstall = 'can_uninstall';

    /**
     * 업데이트 가능 여부 (latest_version > version).
     */
    case CanUpdate = 'can_update';

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨 (lang/{locale}/language_packs.php 의 ability 키)
     */
    public function label(): string
    {
        return __('language_packs.ability.'.$this->value);
    }

    /**
     * 모든 ability 키를 문자열 배열로 반환합니다.
     *
     * @return array<int, string> ability 키 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 주어진 문자열이 유효한 ability 키인지 확인합니다.
     *
     * @param  string  $value  검사할 ability 키
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
