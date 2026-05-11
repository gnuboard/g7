<?php

namespace App\Enums;

/**
 * 언어팩 상태 Enum.
 *
 * 슬롯(scope, target_identifier, locale) 당 active 상태는 1개만 허용됩니다.
 * 동일 슬롯에 다른 벤더 언어팩이 존재할 때 active 외의 후보는 inactive/installed 상태로 보관됩니다.
 */
enum LanguagePackStatus: string
{
    /**
     * 설치 완료 상태 (슬롯의 다른 후보가 이미 active 이거나 활성화 대기 중).
     */
    case Installed = 'installed';

    /**
     * 활성 상태 (슬롯당 1개만 허용).
     */
    case Active = 'active';

    /**
     * 비활성 상태 (파일 보존, 번역 미적용).
     */
    case Inactive = 'inactive';

    /**
     * 업데이트 진행 중 상태.
     */
    case Updating = 'updating';

    /**
     * 오류 상태 (manifest 검증 실패, 대상 확장 미존재 등).
     */
    case Error = 'error';

    /**
     * 미설치 상태 (`lang-packs/_bundled/{identifier}` 에만 존재하고 DB 레코드 없음).
     *
     * 모듈/플러그인 관리의 `not_installed` 와 동일한 개념으로, 목록 행에서 "설치"
     * 버튼을 노출하기 위한 가상 상태. DB 컬럼 enum 후보가 아니라 응답 전용 값입니다.
     */
    case Uninstalled = 'uninstalled';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 상태 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 주어진 문자열이 유효한 상태 값인지 확인합니다.
     *
     * @param  string  $value  검사할 상태 문자열
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
