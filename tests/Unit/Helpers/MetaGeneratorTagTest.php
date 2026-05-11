<?php

namespace Tests\Unit\Helpers;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * g7_meta_generator_tag() 헬퍼 단위 테스트.
 *
 * 코어 SEO 설정(`seo.generator_enabled`, `seo.generator_content`)에 따라
 * `<meta name="generator">` 태그를 생성하는 동작을 검증합니다.
 */
class MetaGeneratorTagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.version', '7.0.0-test');
    }

    public function test_disabled_returns_empty_string(): void
    {
        Config::set('g7_settings.core.seo.generator_enabled', false);
        Config::set('g7_settings.core.seo.generator_content', 'Anything');

        $this->assertSame('', g7_meta_generator_tag());
    }

    public function test_enabled_with_empty_content_falls_back_to_version_default(): void
    {
        Config::set('g7_settings.core.seo.generator_enabled', true);
        Config::set('g7_settings.core.seo.generator_content', '');

        $this->assertSame(
            '<meta name="generator" content="GnuBoard7 7.0.0-test">',
            g7_meta_generator_tag()
        );
    }

    public function test_enabled_with_custom_content_uses_custom_value(): void
    {
        Config::set('g7_settings.core.seo.generator_enabled', true);
        Config::set('g7_settings.core.seo.generator_content', 'GnuBoard7');

        $this->assertSame(
            '<meta name="generator" content="GnuBoard7">',
            g7_meta_generator_tag()
        );
    }

    public function test_content_is_escaped_to_prevent_xss(): void
    {
        Config::set('g7_settings.core.seo.generator_enabled', true);
        Config::set('g7_settings.core.seo.generator_content', '"><script>alert(1)</script>');

        $tag = g7_meta_generator_tag();

        $this->assertStringNotContainsString('<script>', $tag);
        $this->assertStringContainsString('&lt;script&gt;', $tag);
        $this->assertStringContainsString('&quot;', $tag);
    }

    public function test_default_when_setting_missing_is_enabled_with_version(): void
    {
        // 설정 키 자체가 없을 때 헬퍼는 enabled=true 폴백 + 버전 메시지 출력
        Config::offsetUnset('g7_settings');

        $this->assertSame(
            '<meta name="generator" content="GnuBoard7 7.0.0-test">',
            g7_meta_generator_tag()
        );
    }

    public function test_whitespace_only_content_falls_back_to_default(): void
    {
        Config::set('g7_settings.core.seo.generator_enabled', true);
        Config::set('g7_settings.core.seo.generator_content', '   ');

        $this->assertSame(
            '<meta name="generator" content="GnuBoard7 7.0.0-test">',
            g7_meta_generator_tag()
        );
    }
}
