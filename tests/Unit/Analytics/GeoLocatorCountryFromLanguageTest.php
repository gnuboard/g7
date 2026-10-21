<?php

namespace Tests\Unit\Analytics;

use App\Analytics\GeoLocator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 방문 통계 — 브라우저 언어에서 국가를 추정하는 규칙 테스트 (#148).
 *
 * 지역 하위 태그(2자 알파)가 있을 때만 국가로 읽는다. 문자 표기가 낀 `zh-Hant-TW` 도 TW 이고,
 * 숫자 지역(`es-419`)과 지역 없는 코드(`fil`)는 추정하지 않는다.
 */
#[Group('core')]
class GeoLocatorCountryFromLanguageTest extends TestCase
{
    /**
     * 문자 표기가 낀 지역 태그에서도 국가를 읽는다.
     *
     * @scenario shape=script_tagged
     *
     * @effects geo_country_reads_region_subtag
     *
     * @return void 반환값 없음
     */
    public function test_script_and_region_tag_yields_country(): void
    {
        $this->assertSame('TW', GeoLocator::countryFromLanguage('zh-Hant-TW,zh;q=0.9'));
        $this->assertSame('PH', GeoLocator::countryFromLanguage('fil-PH'));
        $this->assertSame('US', GeoLocator::countryFromLanguage('en_us'));
        $this->assertSame('KR', GeoLocator::countryFromLanguage('ko-KR,ko;q=0.9'));
    }

    /**
     * 숫자 지역·지역 없는 코드·형식 위반은 추정하지 않는다.
     *
     * @scenario shape=numeric_region
     *
     * @effects geo_country_reads_region_subtag
     *
     * @return void 반환값 없음
     */
    public function test_numeric_region_and_bare_language_yield_null(): void
    {
        $this->assertNull(GeoLocator::countryFromLanguage('es-419'));
        $this->assertNull(GeoLocator::countryFromLanguage('fil'));
        $this->assertNull(GeoLocator::countryFromLanguage('ko'));
        $this->assertNull(GeoLocator::countryFromLanguage('*'));
        $this->assertNull(GeoLocator::countryFromLanguage(''));
        $this->assertNull(GeoLocator::countryFromLanguage(null));
    }
}
