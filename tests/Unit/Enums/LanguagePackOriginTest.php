<?php

namespace Tests\Unit\Enums;

use App\Enums\LanguagePackOrigin;
use App\Enums\LanguagePackSourceType;
use Tests\PureTestCase;

/**
 * LanguagePackOrigin Enum + LanguagePackSourceType ↔ LanguagePackOrigin 매핑 회귀 테스트.
 */
class LanguagePackOriginTest extends PureTestCase
{
    /**
     * 코어 내장과 확장 동봉 출처가 모두 built_in 그룹으로 매핑되는지 검증한다.
     *
     * @scenario case=source_type_grouping
     *
     * @effects built_in_sources_map_to_built_in
     *
     * @return void 반환값 없음
     */
    public function test_from_source_type_built_in_group(): void
    {
        $this->assertSame(
            LanguagePackOrigin::BuiltIn,
            LanguagePackOrigin::fromSourceType(LanguagePackSourceType::BuiltIn)
        );
        $this->assertSame(
            LanguagePackOrigin::BuiltIn,
            LanguagePackOrigin::fromSourceType(LanguagePackSourceType::BundledWithExtension)
        );
    }

    /**
     * 번들 출처가 bundled 그룹으로 매핑되는지 검증한다.
     *
     * @scenario case=source_type_grouping
     *
     * @effects bundled_source_maps_to_bundled
     *
     * @return void 반환값 없음
     */
    public function test_from_source_type_bundled_group(): void
    {
        $this->assertSame(
            LanguagePackOrigin::Bundled,
            LanguagePackOrigin::fromSourceType(LanguagePackSourceType::Bundled)
        );
    }

    /**
     * github·url·upload·zip 외부 설치 출처가 모두 user_installed 그룹으로 매핑되는지 검증한다.
     *
     * @scenario case=source_type_grouping
     *
     * @effects external_sources_map_to_user_installed
     *
     * @return void 반환값 없음
     */
    public function test_from_source_type_user_installed_group(): void
    {
        foreach ([
            LanguagePackSourceType::Github,
            LanguagePackSourceType::Url,
            LanguagePackSourceType::Upload,
            LanguagePackSourceType::Zip,
        ] as $external) {
            $this->assertSame(
                LanguagePackOrigin::UserInstalled,
                LanguagePackOrigin::fromSourceType($external),
                "{$external->value} 는 user_installed 로 매핑되어야 함"
            );
        }
    }

    /**
     * 문자열 값 변환이 null·알 수 없는 값에는 null 을, 유효한 'bundled' 에는 bundled 그룹을 돌려주는지 검증한다.
     *
     * @scenario case=value_conversion
     *
     * @effects invalid_or_null_value_yields_null, bundled_source_maps_to_bundled
     *
     * @return void 반환값 없음
     */
    public function test_from_source_type_value_handles_null_and_invalid(): void
    {
        $this->assertNull(LanguagePackOrigin::fromSourceTypeValue(null));
        $this->assertNull(LanguagePackOrigin::fromSourceTypeValue('invalid_xxx'));
        $this->assertSame(
            LanguagePackOrigin::Bundled,
            LanguagePackOrigin::fromSourceTypeValue('bundled')
        );
    }

    /**
     * 출처 값 목록이 built_in·bundled·user_installed 세 값을 이 순서로 돌려주는지 검증한다.
     *
     * @scenario case=enum_completeness
     *
     * @effects origin_values_list_three_origins
     *
     * @return void 반환값 없음
     */
    public function test_values_returns_all_3_origins(): void
    {
        $this->assertSame(
            ['built_in', 'bundled', 'user_installed'],
            LanguagePackOrigin::values()
        );
    }

    /**
     * Enum 누락 가드 — `LanguagePackSourceType` 의 모든 case 가 origin 매핑에서
     * 처리되는지 (match 누락 시 UnhandledMatchError) 검증.
     *
     * @scenario case=enum_completeness
     *
     * @effects every_source_type_is_mapped
     *
     * @return void 반환값 없음
     */
    public function test_all_source_types_are_mapped(): void
    {
        foreach (LanguagePackSourceType::cases() as $source) {
            $origin = LanguagePackOrigin::fromSourceType($source);
            $this->assertContains(
                $origin,
                LanguagePackOrigin::cases(),
                "{$source->value} 는 LanguagePackOrigin 의 한 case 로 매핑되어야 함"
            );
        }
    }
}
