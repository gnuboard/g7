<?php

namespace App\Enums;

/**
 * 언어팩 의존성/호환성 검사 실패 사유 코드 Enum.
 *
 * `LanguagePackService::assertDependencies()` / `assertTargetExtensionExists()` 등
 * 도메인 검증 함수가 차단 사유를 반환할 때 사용합니다. 컨트롤러는 이 코드를
 * 다국어 메시지 키로 변환하여 422 응답에 포함합니다.
 */
enum LanguagePackErrorCode: string
{
    /**
     * 동일 locale 의 코어 언어팩이 active 상태가 아니어서 모듈/플러그인/템플릿 팩을 활성화할 수 없음.
     */
    case CoreLocaleMissing = 'core_locale_missing';

    /**
     * 대상 확장(모듈/플러그인/템플릿)이 설치되어 있지 않음.
     */
    case TargetNotInstalled = 'target_not_installed';

    /**
     * 대상 확장이 설치되어 있지만 비활성 상태임.
     */
    case TargetInactive = 'target_inactive';

    /**
     * 대상 확장 버전이 manifest 의 `requires.target_version` 제약 미만.
     */
    case TargetVersionTooOld = 'target_version_too_old';

    /**
     * 대상 확장 버전이 manifest 의 `requires.target_version` 제약과 불일치.
     */
    case TargetVersionMismatch = 'target_version_mismatch';

    /**
     * 일반 의존성 누락 (manifest 의 `requires.dependencies` 등).
     */
    case DependencyMissing = 'dependency_missing';

    /**
     * 언어팩 설치 디렉토리(`lang-packs/`, `lang-packs/_pending/`)에 쓰기 권한이 없음.
     *
     * 웹 서버 사용자(www-data 등)가 디렉토리를 생성/이동할 수 없을 때 발생.
     * 사용자에게 chmod 안내 메시지를 함께 표시한다 (모듈/플러그인/템플릿 install 과 동일 수준).
     */
    case DirectoryNotWritable = 'directory_not_writable';

    /**
     * 다국어 라벨/메시지를 반환합니다.
     *
     * @return string 라벨 (lang/{locale}/language_packs.php 의 error_code 키)
     */
    public function label(): string
    {
        return __('language_packs.error_code.'.$this->value);
    }

    /**
     * 모든 에러 코드 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 에러 코드 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 주어진 문자열이 유효한 에러 코드 값인지 확인합니다.
     *
     * @param  string  $value  검사할 에러 코드 문자열
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
