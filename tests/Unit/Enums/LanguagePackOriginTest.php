<?php

namespace Tests\Unit\Enums;

use App\Enums\LanguagePackOrigin;
use App\Enums\LanguagePackSourceType;
use Tests\TestCase;

/**
 * LanguagePackOrigin Enum + LanguagePackSourceType ↔ LanguagePackOrigin 매핑 회귀 테스트.
 */
class LanguagePackOriginTest extends TestCase
{
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

    public function test_from_source_type_bundled_group(): void
    {
        $this->assertSame(
            LanguagePackOrigin::Bundled,
            LanguagePackOrigin::fromSourceType(LanguagePackSourceType::Bundled)
        );
    }

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

    public function test_from_source_type_value_handles_null_and_invalid(): void
    {
        $this->assertNull(LanguagePackOrigin::fromSourceTypeValue(null));
        $this->assertNull(LanguagePackOrigin::fromSourceTypeValue('invalid_xxx'));
        $this->assertSame(
            LanguagePackOrigin::Bundled,
            LanguagePackOrigin::fromSourceTypeValue('bundled')
        );
    }

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
