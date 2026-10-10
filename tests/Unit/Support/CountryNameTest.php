<?php

namespace Tests\Unit\Support;

use App\Analytics\GeoLabelTranslator;
use App\Support\CountryName;
use App\Traits\HasCountryAttributes;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 국가 이름을 언어팩이 채우는 계약 테스트 (7.1.0 전수 실측 O-C1 · 결정 P2)
 *
 * 국가 이름은 `config/countries.php` 의 ko·en 두 벌뿐이라, 일본어 화면의 회원 목록·상세 국가 열이
 * 기본 폴백(ko)으로 떨어져 「한국」「미국」 처럼 한국어로 보였다. 코어 내장 로케일(ko·en)은 그대로
 * 설정이 정본이고, 그 밖의 로케일은 언어팩의 백엔드 번역 파일 `countries.php`(키 = 국가 코드)가
 * 국가 이름을 공급한다. 회원 응답(`country_name`)과 방문자 통계 지역 라벨이 같은 해석을 쓴다.
 */
#[Group('core')]
class CountryNameTest extends TestCase
{
    /**
     * 코어 내장 로케일은 설정의 이름을 그대로 쓴다.
     *
     * @scenario locale=builtin, pack_line=absent
     *
     * @effects country_name_builtin_locale_uses_config
     *
     * @return void 반환값 없음
     */
    public function test_builtin_locale_uses_config_name(): void
    {
        $this->assertSame(config('countries.localized_names.ko.US'), CountryName::resolve('US', 'ko'));
        $this->assertSame(config('countries.localized_names.en.US'), CountryName::resolve('us', 'en'));
    }

    /**
     * 내장 이름이 없는 로케일은 언어팩 번역(`countries.{코드}`)을 쓴다.
     *
     * @scenario locale=pack, pack_line=present
     *
     * @effects country_name_pack_locale_uses_language_pack_line
     *
     * @return void 반환값 없음
     */
    public function test_pack_locale_uses_language_pack_translation(): void
    {
        Lang::addLines(['countries.US' => 'テスト米国'], 'ja');

        $this->assertSame('テスト米国', CountryName::resolve('US', 'ja'));
    }

    /**
     * 언어팩에 그 국가가 없으면 기본 폴백 로케일의 설정 이름으로 떨어진다(코드보다 낫다).
     *
     * @scenario locale=pack, pack_line=absent
     *
     * @effects country_name_pack_missing_falls_back_to_fallback_locale
     *
     * @return void 반환값 없음
     */
    public function test_pack_locale_without_line_falls_back(): void
    {
        $fallback = config('app.fallback_locale');

        $this->assertSame(config("countries.localized_names.{$fallback}.ZA"), CountryName::resolve('ZA', 'xx'));
        $this->assertSame('QQ', CountryName::resolve('QQ', 'xx'));
    }

    /**
     * 회원 응답이 쓰는 트레이트가 현재 로케일로 같은 해석을 한다.
     *
     * @scenario locale=pack, pack_line=present
     *
     * @effects country_name_pack_locale_uses_language_pack_line
     *
     * @return void 반환값 없음
     */
    public function test_user_resource_trait_uses_same_resolution(): void
    {
        Lang::addLines(['countries.KR' => 'テスト韓国'], 'ja');
        app()->setLocale('ja');

        $subject = new class
        {
            use HasCountryAttributes;

            /**
             * 보호 메서드를 시험용으로 엽니다.
             *
             * @param  string  $code  국가 코드
             * @return string 국가 이름
             */
            public function name(string $code): string
            {
                return $this->getCountryName($code);
            }
        };

        $this->assertSame('テスト韓国', $subject->name('kr'));
    }

    /**
     * 방문자 통계 지역 라벨도 언어팩 이름을 intl 표기보다 먼저 쓴다(같은 나라가 화면마다 다른 이름이 되지 않게).
     *
     * @scenario locale=pack, pack_line=present
     *
     * @effects country_name_pack_locale_uses_language_pack_line
     *
     * @return void 반환값 없음
     */
    public function test_geo_label_prefers_language_pack_line(): void
    {
        Lang::addLines(['countries.JP' => 'テスト日本'], 'ja');

        $this->assertSame('テスト日本', GeoLabelTranslator::country('JP', 'ja'));
    }

    /**
     * 번들 일본어 코어 언어팩이 설정의 국가 전부에 이름을 준다.
     *
     * @scenario locale=pack, pack_line=present
     *
     * @effects bundled_ja_pack_names_every_configured_country
     *
     * @return void 반환값 없음
     */
    public function test_bundled_ja_pack_covers_every_configured_country(): void
    {
        $path = base_path('lang-packs/_bundled/g7-core-ja/backend/ja/countries.php');

        $this->assertFileExists($path);

        $names = require $path;
        $codes = array_keys(config('countries.localized_names.ko', []));
        $missing = array_values(array_filter($codes, fn (string $code) => ! is_string($names[$code] ?? null) || $names[$code] === ''));

        $this->assertSame([], $missing, '일본어 국가 이름이 없는 코드');
        $this->assertSame('日本', $names['JP']);
    }
}
