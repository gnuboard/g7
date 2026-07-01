<?php

namespace Tests\Unit\Seo;

use App\Seo\Concerns\LocalizesSeoValues;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LocalizesSeoValues 트레이트 테스트 (공개#49).
 *
 * site_name 등 다국어 JSON array 설정값을 현재/폴백 로케일 string 으로 정규화하는
 * SSoT. SeoRenderer::buildGlobalContext()의 _global.site_name(F4) 및 OG 경로가
 * 공유하며, array → string 캐스팅 회귀를 차단한다.
 */
class LocalizesSeoValuesTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use LocalizesSeoValues;
        };
    }

    /**
     * 문자열은 그대로 반환됩니다.
     */
    public function test_string_returned_as_is(): void
    {
        $this->assertSame('그누보드7', $this->subject->resolveLocalizedValue('그누보드7'));
    }

    /**
     * 다국어 array 는 현재 로케일 string 으로 추출됩니다.
     */
    public function test_localized_array_extracts_current_locale(): void
    {
        Config::set('app.locale', 'ko');

        $this->assertSame(
            '한글몰',
            $this->subject->resolveLocalizedValue(['ko' => '한글몰', 'en' => 'EngMall']),
        );
    }

    /**
     * 현재 로케일 키 부재 시 폴백 로케일로 추출됩니다.
     */
    public function test_localized_array_falls_back(): void
    {
        Config::set('app.locale', 'ja');
        Config::set('app.fallback_locale', 'en');

        $this->assertSame(
            'EngMall',
            $this->subject->resolveLocalizedValue(['en' => 'EngMall']),
        );
    }

    /**
     * 정규화 결과는 항상 string 이라 Blade e()/htmlspecialchars 에서 TypeError 가 없습니다.
     */
    public function test_result_is_always_string_for_safe_html_escaping(): void
    {
        Config::set('app.locale', 'ko');

        $result = $this->subject->resolveLocalizedValue(['ko' => '한글몰', 'en' => 'EngMall']);

        $this->assertIsString($result);
        // htmlspecialchars 가 TypeError 없이 동작
        $this->assertSame('한글몰', e($result));
    }
}
