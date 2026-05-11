<?php

namespace Tests\Unit\Helpers;

use Tests\TestCase;

/**
 * `localized_label()` / `localized_payload()` helper 단위 테스트.
 *
 * 우선순위 검증:
 *   1. nameKey __() 우선 (registry payload 패턴)
 *   2. value[locale] (settings JSON 패턴)
 *   3. fallbackKey __() (settings JSON 의 lang pack fallback)
 *   4. value[fallback_locale] / value 첫 키 (최종 fallback)
 */
class LocalizedLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 테스트용 lang 라인 등록 (translator 의 in-memory loader 활용)
        app('translator')->addLines([
            'test_helper.foo' => '폴백 키 KO',
            'test_helper.bar' => '네임 키 KO',
        ], 'ko');
        app('translator')->addLines([
            'test_helper.foo' => 'Fallback Key EN',
            'test_helper.bar' => 'Name Key EN',
        ], 'en');
    }

    public function test_name_key_resolves_first_when_present(): void
    {
        app()->setLocale('ko');
        $this->assertSame(
            '네임 키 KO',
            localized_label(
                value: ['ko' => 'value-ko', 'en' => 'value-en'],
                nameKey: 'test_helper.bar',
            ),
        );
    }

    public function test_value_locale_used_when_name_key_absent(): void
    {
        app()->setLocale('en');
        $this->assertSame(
            'value-en',
            localized_label(value: ['ko' => 'value-ko', 'en' => 'value-en']),
        );
    }

    public function test_fallback_key_used_when_value_locale_missing(): void
    {
        app()->setLocale('ko');
        $this->assertSame(
            '폴백 키 KO',
            localized_label(
                value: ['en' => 'only-en'],
                fallbackKey: 'test_helper.foo',
            ),
        );
    }

    public function test_value_fallback_locale_used_when_no_keys(): void
    {
        app()->setLocale('ja');
        config(['app.fallback_locale' => 'ko']);
        $this->assertSame(
            'value-ko',
            localized_label(value: ['ko' => 'value-ko', 'en' => 'value-en']),
        );
    }

    public function test_returns_empty_string_when_all_sources_empty(): void
    {
        app()->setLocale('ko');
        $this->assertSame('', localized_label());
        $this->assertSame('', localized_label(value: null, nameKey: null, fallbackKey: null));
    }

    public function test_localized_payload_with_name_key_field(): void
    {
        app()->setLocale('en');
        $entry = [
            'id' => 'mail',
            'name_key' => 'test_helper.bar',
            'name' => ['ko' => 'fallback-ko', 'en' => 'fallback-en'],
        ];
        $this->assertSame('Name Key EN', localized_payload($entry, 'name'));
    }

    public function test_localized_payload_falls_back_to_value_when_name_key_unresolved(): void
    {
        app()->setLocale('ko');
        $entry = [
            'id' => 'foo',
            'name' => ['ko' => 'value-ko', 'en' => 'value-en'],
        ];
        $this->assertSame('value-ko', localized_payload($entry, 'name'));
    }

    public function test_localized_payload_returns_empty_when_field_absent(): void
    {
        $entry = ['id' => 'foo'];
        $this->assertSame('', localized_payload($entry, 'name'));
    }

    public function test_locale_argument_overrides_app_locale(): void
    {
        app()->setLocale('ko');
        $this->assertSame(
            'Name Key EN',
            localized_label(nameKey: 'test_helper.bar', locale: 'en'),
        );
    }
}
