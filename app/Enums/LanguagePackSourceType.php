<?php

namespace App\Enums;

/**
 * 언어팩 소스 타입 Enum.
 *
 * 언어팩이 어디로부터 등록되었는지를 분류합니다. 보호 정책 결정과 업데이트 우선순위
 * (GitHub 1순위 + bundled 폴백, force 시 bundled 1순위) 결정에 사용됩니다.
 */
enum LanguagePackSourceType: string
{
    /**
     * `lang-packs/_bundled/` 의 독립 패키지 (사용자가 install/uninstall/activate/deactivate 자유).
     */
    case Bundled = 'bundled';

    /**
     * 모듈/플러그인/템플릿 디렉토리의 `lang/{locale}/` 자원 (확장과 한 몸, 부모 lifecycle 종속).
     */
    case BundledWithExtension = 'bundled_with_extension';

    /**
     * 코어/번들 확장의 `lang/{ko,en}/` 가상 보호 행 (DB 등록 없이 디렉토리 스캔으로 합성).
     */
    case BuiltIn = 'built_in';

    /**
     * GitHub 저장소 URL 로부터 설치된 외부 언어팩.
     */
    case Github = 'github';

    /**
     * 임의 URL 로부터 다운로드된 외부 언어팩.
     */
    case Url = 'url';

    /**
     * 사용자가 ZIP 파일 업로드로 설치한 외부 언어팩.
     */
    case Upload = 'upload';

    /**
     * ZIP 파일 (Upload 와 동일한 의미로 사용되는 별칭).
     */
    case Zip = 'zip';

    /**
     * 모든 소스 타입 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> 소스 타입 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 주어진 문자열이 유효한 소스 타입 값인지 확인합니다.
     *
     * @param  string  $value  검사할 소스 타입 문자열
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨 (lang/{locale}/language_packs.php 의 source_type 키)
     */
    public function label(): string
    {
        return __('language_packs.source_type.'.$this->value);
    }

    /**
     * 본 소스 타입이 사용자 직접 설치 출처인지 (ZIP/GitHub/URL/Upload) 판정합니다.
     *
     * @return bool 사용자 외부 설치 출처면 true
     */
    public function isExternal(): bool
    {
        return match ($this) {
            self::Github, self::Url, self::Upload, self::Zip => true,
            default => false,
        };
    }

    /**
     * 본 소스 타입이 보호 대상 (수정/제거 차단) 인지 판정합니다.
     *
     * `BundledWithExtension` 과 `BuiltIn` 은 확장 본체에 종속되므로 보호.
     * `Bundled` 은 사용자가 install/uninstall 자유.
     *
     * @return bool 보호 대상이면 true
     */
    public function isProtectedByDefault(): bool
    {
        return match ($this) {
            self::BundledWithExtension, self::BuiltIn => true,
            default => false,
        };
    }
}
