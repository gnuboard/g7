<?php

namespace App\Enums;

/**
 * 언어팩 출처(origin) 분류 Enum.
 *
 * `LanguagePackSourceType` 가 7가지 세부 소스 (bundled / bundled_with_extension /
 * built_in / github / url / upload / zip) 를 갖는 반면, UI 배지·필터 등에서는 사용자
 * 관점의 3분류(빌트인 / 번들 / 사용자 설치) 가 더 직관적이다. 본 Enum 은 그 메타
 * 그룹핑을 표현하며 `LanguagePackSourceType::fromSourceType()` 1곳에서 매핑한다.
 *
 * - `built_in`       : 코어/번들 확장의 lang/{locale}/ 가상 보호 행 + bundled_with_extension
 * - `bundled`        : lang-packs/_bundled/{identifier} 독립 패키지
 * - `user_installed` : github / url / upload / zip 출처 외부 설치
 */
enum LanguagePackOrigin: string
{
    /**
     * 코어/번들 확장의 lang/{locale}/ 자원에서 합성된 가상 보호 행 또는 확장과
     * 한 몸으로 등록된 bundled_with_extension 행.
     */
    case BuiltIn = 'built_in';

    /**
     * lang-packs/_bundled/{identifier} 의 독립 번들 패키지 (install/uninstall 자유).
     */
    case Bundled = 'bundled';

    /**
     * 사용자가 GitHub URL / 임의 URL / ZIP 업로드로 설치한 외부 언어팩.
     */
    case UserInstalled = 'user_installed';

    /**
     * `LanguagePackSourceType` 으로부터 origin 을 결정합니다.
     *
     * @param  LanguagePackSourceType  $source  세부 소스 타입
     * @return self 매칭된 origin
     */
    public static function fromSourceType(LanguagePackSourceType $source): self
    {
        return match ($source) {
            LanguagePackSourceType::BuiltIn,
            LanguagePackSourceType::BundledWithExtension => self::BuiltIn,
            LanguagePackSourceType::Bundled              => self::Bundled,
            LanguagePackSourceType::Github,
            LanguagePackSourceType::Url,
            LanguagePackSourceType::Upload,
            LanguagePackSourceType::Zip                  => self::UserInstalled,
        };
    }

    /**
     * 문자열 source_type 값으로부터 origin 을 결정합니다 (직렬화 단계 편의 메서드).
     *
     * @param  string|null  $sourceType  source_type 컬럼 값
     * @return self|null 매칭된 origin (유효하지 않은 값은 null)
     */
    public static function fromSourceTypeValue(?string $sourceType): ?self
    {
        if ($sourceType === null || ! LanguagePackSourceType::isValid($sourceType)) {
            return null;
        }

        return self::fromSourceType(LanguagePackSourceType::from($sourceType));
    }

    /**
     * 모든 origin 값을 문자열 배열로 반환합니다.
     *
     * @return array<int, string> origin 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 라벨 (lang/{locale}/language_packs.php 의 origin 키)
     */
    public function label(): string
    {
        return __('language_packs.origin.'.$this->value);
    }
}
