<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Modules\Sirsoft\Ecommerce\Http\Resources\Concerns\LocalizesCountryName;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 배송 국가 이름이 코어 국가 사전에 없는 언어(일본어 등)에서도 번역되는지 검증한다 (이커머스 전수 실측 S4 — S4-D17)
 *
 * 결함: 주문 목록·주문 상세·배송지·알림의 국가 이름은 코어 사전(config countries.localized_names — ko·en 만)에서
 *   찾아, 일본어 화면에서 「미국」「한국」 같은 한국어로 보였다. 같은 상점의 배송국가 설정(available_countries)에는
 *   운영자가 관리하는 일본어 이름(「アメリカ」)이 있고 헤더 배송국가 선택은 그 이름을 쓴다.
 *   사전에 있는 언어는 종전대로 사전을 쓰고, 사전에 없는 언어만 상점 설정 이름으로 채운다.
 */
class CountryNameLocalizationTest extends ModuleTestCase
{
    /**
     * 상점 배송국가 설정에 일본어 이름을 둡니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국(설정)', 'en' => 'Korea (settings)', 'ja' => '大韓民国'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국(설정)', 'en' => 'USA (settings)', 'ja' => 'アメリカ'], 'is_active' => false],
        ]);
    }

    /**
     * 리소스 공용 트레이트를 부르는 시험용 객체를 만듭니다.
     *
     * @return object getCountryLocalizedName 을 노출하는 객체
     */
    private function localizer(): object
    {
        return new class
        {
            use LocalizesCountryName;

            /**
             * 트레이트의 국가 이름 변환을 그대로 돌려줍니다.
             *
             * @param  string|null  $code  국가 코드
             * @return array<string, string>|null 언어별 국가 이름
             */
            public function names(?string $code): ?array
            {
                return $this->getCountryLocalizedName($code);
            }
        };
    }

    /**
     * 주문·주소 리소스의 국가 이름에 코어 사전에 없는 일본어가 상점 설정 이름으로 채워진다(배송 불가로 내린 국가 포함).
     *
     * @scenario case=resources_ja
     *
     * @effects missing_locale_filled_from_shop_settings
     *
     * @return void 없음
     */
    public function test_resource_country_name_gets_japanese_from_shop_settings(): void
    {
        $names = $this->localizer()->names('US');

        $this->assertSame('アメリカ', $names['ja'] ?? null);
        $this->assertSame('大韓民国', $this->localizer()->names('kr')['ja'] ?? null);
    }

    /**
     * 코어 사전에 있는 언어(ko·en)는 종전대로 사전 이름을 쓴다 — 상점 설정 이름으로 바뀌지 않는다.
     *
     * @scenario case=resources_ko_en
     *
     * @effects dictionary_locales_unchanged
     *
     * @return void 없음
     */
    public function test_dictionary_locales_keep_core_names(): void
    {
        $names = $this->localizer()->names('US');
        $dictionary = config('countries.localized_names');

        $this->assertSame($dictionary['ko']['US'], $names['ko']);
        $this->assertSame($dictionary['en']['US'], $names['en']);
        $this->assertNull($this->localizer()->names(null));
    }

    /**
     * 알림 데이터의 국가 이름도 일본어 로케일에서 상점 설정의 일본어 이름을 쓴다.
     *
     * @scenario case=notification_ja
     *
     * @effects missing_locale_filled_from_shop_settings
     *
     * @return void 없음
     */
    public function test_notification_country_name_uses_japanese_from_shop_settings(): void
    {
        app()->setLocale('ja');
        $method = new ReflectionMethod(EcommerceNotificationDataListener::class, 'localizeCountryName');

        $this->assertSame('アメリカ', $method->invoke(app(EcommerceNotificationDataListener::class), 'US'));

        app()->setLocale('ko');
        $this->assertSame(config('countries.localized_names.ko.US'), $method->invoke(app(EcommerceNotificationDataListener::class), 'US'));
    }

    /**
     * 어느 사전에도 없는 언어면 앱의 폴백 언어(app.fallback_locale) 이름으로 떨어진다 — 영어 고정 폴백이 아니다.
     *
     * @scenario case=notification_missing_locale
     *
     * @effects missing_locale_falls_back_to_app_fallback_locale
     *
     * @return void 없음
     */
    public function test_notification_country_name_falls_back_to_app_fallback_locale(): void
    {
        config(['app.fallback_locale' => 'ko']);
        app()->setLocale('fr');
        $method = new ReflectionMethod(EcommerceNotificationDataListener::class, 'localizeCountryName');

        $this->assertSame(config('countries.localized_names.ko.US'), $method->invoke(app(EcommerceNotificationDataListener::class), 'US'));
    }
}
