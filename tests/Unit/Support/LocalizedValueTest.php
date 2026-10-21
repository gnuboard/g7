<?php

namespace Tests\Unit\Support;

use App\Support\LocalizedValue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 다국어 값 대체 순서 테스트 (#148 D7).
 *
 * 지금 보는 언어 → 사이트 기본 언어(`app.site_locale`) → 영어 → (선택) 아무 언어의 첫 값.
 * 화면 짝 `pickLocalized`(`resources/js/core/support/__tests__/localeTag.test.ts`)와 같은 표다.
 * `app.site_locale` 은 SettingsServiceProvider 가 로컬 설정값으로 채우므로 매번 명시한다.
 */
#[Group('core')]
class LocalizedValueTest extends TestCase
{
    /**
     * 대체 순서는 [현재, 사이트 기본, en] 이고 중복은 지운다.
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_fallback_chain(): void
    {
        config(['app.site_locale' => 'ja']);
        $this->assertSame(['fil', 'ja', 'en'], LocalizedValue::fallbackChain('fil'));

        config(['app.site_locale' => 'en']);
        $this->assertSame(['fil', 'en'], LocalizedValue::fallbackChain('fil'));

        app()->setLocale('ko');
        $this->assertSame(['ko', 'en'], LocalizedValue::fallbackChain());
    }

    /**
     * 빈 문자열·공백만 있는 값은 건너뛰고, 사이트 기본 언어가 영어보다 먼저다.
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_pick_skips_blank_and_prefers_site_locale(): void
    {
        config(['app.site_locale' => 'ja']);

        $value = ['ko' => '한국어', 'en' => 'English', 'ja' => '日本語', 'fil' => '  '];
        $this->assertSame('日本語', LocalizedValue::pick($value, 'fil'));
        $this->assertSame('English', LocalizedValue::pick(['ko' => '한국어', 'en' => 'English', 'ja' => ''], 'fil'));
        $this->assertSame('Filipino', LocalizedValue::pick(['ko' => '한국어', 'fil' => 'Filipino'], 'fil'));
        $this->assertSame('0', LocalizedValue::pick(['fil' => 0], 'fil'), '숫자 0 은 유효값');
    }

    /**
     * 체인에서 못 찾으면 anyAsLast 일 때만 배열 순서의 첫 유효값을 쓴다.
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_any_as_last_flag(): void
    {
        config(['app.site_locale' => 'ja']);

        $value = ['ko' => '', 'de' => 'Deutsch', 'fr' => 'Français'];
        $this->assertSame('Deutsch', LocalizedValue::pick($value, 'fil'));
        $this->assertNull(LocalizedValue::pick($value, 'fil', anyAsLast: false));
    }

    /**
     * 문자열은 그대로, 배열이 아닌 값은 null.
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_string_passthrough_and_non_array_null(): void
    {
        $this->assertSame('plain', LocalizedValue::pick('plain', 'fil'));
        $this->assertSame('', LocalizedValue::pick('', 'fil'));
        $this->assertNull(LocalizedValue::pick(null));
        $this->assertNull(LocalizedValue::pick(12));
        $this->assertNull(LocalizedValue::pick([]));
    }
}
