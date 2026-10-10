<?php

namespace Tests\Unit\Analytics;

use App\Analytics\GeoLabelTranslator;
use App\Analytics\LanguageLabelTranslator;
use Tests\TestCase;

/**
 * 언어 라벨 번역 — 국가 부분이 지역 라벨과 같은 이름을 쓰는지.
 *
 * 방문자 상세는 국가 칸(「한국」)과 브라우저 언어 칸(「한국어(대한민국)」)을 나란히 보인다. 국가
 * 이름은 `config/countries.php` 가 정본인데 언어 이름만 intl 사전의 국가 이름을 따라, 한 화면에
 * 같은 나라가 두 이름으로 섰다(7.1.0 실측 O-E3).
 */
class LanguageLabelTranslatorTest extends TestCase
{
    /**
     * 언어 이름 안의 국가 부분이 국가 칸과 같은 이름인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitor_detail, role=privacy
     *
     * @effects country_name_is_the_same_on_every_analytics_surface
     */
    public function test_the_region_part_uses_the_same_country_name_as_the_country_column(): void
    {
        $country = GeoLabelTranslator::country('KR', 'ko');

        $this->assertSame('한국', $country, '국가 이름 정본(config/countries.php)');
        $this->assertSame('한국어('.$country.')', LanguageLabelTranslator::language('ko-KR', 'ko'));
        $this->assertStringContainsString(GeoLabelTranslator::country('US', 'ko'), (string) LanguageLabelTranslator::language('en-US', 'ko'));
    }

    /**
     * 지역이 없는 언어 코드와 다른 로케일은 그대로인지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=visitor_detail, role=privacy
     *
     * @effects country_name_is_the_same_on_every_analytics_surface
     */
    public function test_plain_languages_and_other_locales_keep_their_names(): void
    {
        $this->assertSame('한국어', LanguageLabelTranslator::language('ko', 'ko'));
        $this->assertSame('Korean (South Korea)', LanguageLabelTranslator::language('ko-KR', 'en'));
        $this->assertSame('xx-YY', LanguageLabelTranslator::language('xx-YY', 'ko'));
    }
}
