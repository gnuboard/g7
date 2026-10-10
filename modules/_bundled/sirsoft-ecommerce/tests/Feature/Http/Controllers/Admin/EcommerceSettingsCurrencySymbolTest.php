<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화 기호(symbol) 설정 저장·보충 테스트
 *
 * - 통화 설정에 추가된 symbol 필드가 저장·조회되는지 확인
 * - 저장본에 symbol 이 없을 때 defaults 표준 기호로 보충되는지 확인
 *   (array_merge 가 정수키 currencies 를 통째 교체하므로 getAllSettings 가 code 기준 보충)
 */
class EcommerceSettingsCurrencySymbolTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/settings';

    private User $adminUser;

    /**
     * 관리자 설정 API 를 부를 권한을 가진 계정을 세운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);
    }

    /**
     * 관리자가 입력한 통화 기호가 저장·조회된다.
     *
     * @scenario currency=THB
     *
     * @effects currency_symbol_is_persisted_as_entered
     *
     * @return void 반환값 없음
     */
    public function test_saved_currency_symbol_is_persisted(): void
    {
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원', 'en' => 'Won'], 'symbol' => '₩', 'is_default' => true],
                    ['code' => 'THB', 'name' => ['ko' => '바트', 'en' => 'Baht'], 'symbol' => '฿', 'exchange_rate' => 25, 'is_default' => false],
                ],
            ],
        ]);

        $response->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $currencies = $settings->getSetting('language_currency.currencies');

        $thb = collect($currencies)->firstWhere('code', 'THB');
        $this->assertNotNull($thb, 'THB 통화가 저장되어야 합니다.');
        $this->assertSame('฿', $thb['symbol'], '관리자가 입력한 통화 기호가 보존되어야 합니다.');
    }

    /**
     * 저장본에 symbol 이 없으면 defaults 의 표준 기호로 보충된다.
     *
     * @scenario currency=KRW
     *
     * @effects missing_currency_symbol_is_backfilled_from_defaults
     *
     * @return void 반환값 없음
     */
    public function test_missing_symbol_is_backfilled_from_defaults(): void
    {
        // symbol 없이 기본 통화만 저장 (구버전 저장본 시뮬레이션)
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원', 'en' => 'Won'], 'is_default' => true],
                    ['code' => 'USD', 'name' => ['ko' => '달러', 'en' => 'Dollar'], 'exchange_rate' => 0.85, 'is_default' => false],
                ],
            ],
        ]);

        $response->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $currencies = $settings->getSetting('language_currency.currencies');

        $krw = collect($currencies)->firstWhere('code', 'KRW');
        $usd = collect($currencies)->firstWhere('code', 'USD');

        $this->assertSame('₩', $krw['symbol'] ?? null, 'KRW 기호가 defaults 에서 보충되어야 합니다.');
        $this->assertSame('$', $usd['symbol'] ?? null, 'USD 기호가 defaults 에서 보충되어야 합니다.');
    }

    /**
     * CNY(위안)는 JPY(엔)와 구분되는 元 기호로 보충된다.
     *
     * 일본 엔과 중국 위안이 모두 ¥ 기호를 공유하면 다국적 사용자가 가격을 오인할 수 있으므로,
     * 위안화에는 元 기호를 사용해 식별성을 확보한다.
     *
     * @scenario currency=CNY
     *
     * @effects cny_symbol_stays_distinct_from_jpy
     *
     * @return void 반환값 없음
     */
    public function test_cny_symbol_is_yuan_glyph_distinct_from_jpy(): void
    {
        // symbol 없이 JPY/CNY 저장 (표준 매핑 보충 경로 검증)
        $response = $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원', 'en' => 'Won'], 'is_default' => true],
                    ['code' => 'JPY', 'name' => ['ko' => '엔', 'en' => 'Yen'], 'exchange_rate' => 157, 'is_default' => false],
                    ['code' => 'CNY', 'name' => ['ko' => '위안', 'en' => 'Yuan'], 'exchange_rate' => 7.2, 'is_default' => false],
                ],
            ],
        ]);

        $response->assertOk();

        $settings = app(EcommerceSettingsService::class);
        $settings->clearCache();
        $currencies = $settings->getSetting('language_currency.currencies');

        $jpy = collect($currencies)->firstWhere('code', 'JPY');
        $cny = collect($currencies)->firstWhere('code', 'CNY');

        $this->assertSame('¥', $jpy['symbol'] ?? null, 'JPY 기호는 ¥ 여야 합니다.');
        $this->assertSame('元', $cny['symbol'] ?? null, 'CNY 기호는 ¥ 충돌을 피해 元 여야 합니다.');
        $this->assertNotSame($jpy['symbol'], $cny['symbol'], 'JPY 와 CNY 기호는 서로 달라야 합니다.');
    }

    /**
     * 가격 포맷 prefix 도 CNY=元 / JPY=¥ 로 구분된다.
     *
     * 카트·상품 화면의 다통화 가격 라벨은 currency.prefix.{CODE} lang 키로 포맷되므로,
     * 위안화 가격이 元, 엔화 가격이 ¥ 로 렌더되는지 실제 포맷 결과로 검증한다.
     *
     * @scenario currency=CNY
     *
     * @effects cny_symbol_stays_distinct_from_jpy
     *
     * @return void 반환값 없음
     */
    public function test_formatted_price_prefix_distinguishes_cny_from_jpy(): void
    {
        $instance = new class
        {
            use HasMultiCurrencyPrices;

            /**
             * 통화 코드로 가격 문자열을 포맷한다.
             *
             * @param  float|int  $price  금액
             * @param  string  $code  통화 코드
             * @return string 포맷된 금액
             */
            public function format(float|int $price, string $code): string
            {
                return $this->formatCurrencyPrice($price, $code);
            }
        };

        $this->app->setLocale('ko');

        $this->assertSame('¥471,000', $instance->format(471000, 'JPY'), 'JPY 가격은 ¥ 접두사여야 합니다.');
        $this->assertSame('元21,600.00', $instance->format(21600, 'CNY'), 'CNY 가격은 元 접두사여야 합니다.');
        $this->assertStringStartsNotWith('¥', $instance->format(21600, 'CNY'), 'CNY 가격이 ¥ 로 시작하면 안 됩니다.');
    }

    /**
     * 원화 금액은 **어느 로케일에서도 엔화 단위(円)로 표기되지 않는다.**
     *
     * 통화 단위는 「보는 사람의 언어」가 아니라 「그 돈이 무엇인가」로 정해진다. ja 로케일에서
     * KRW 접미사를 `円` 으로 두면 6,000원이 **6,000엔**으로 읽힌다 — 같은 화면의 보조 통화 줄에
     * 실제 엔화(`¥690`)가 나란히 서기 때문에 둘이 모순되고, 구매자는 어느 쪽이 참인지 알 수 없다.
     *
     * 실측(#599 세션A 2026-09-21 · ja 주문서): 주 통화 `6,000円` ↔ 보조 통화 `¥690`.
     *
     * @scenario currency=KRW
     *
     * @effects krw_amount_never_formats_as_yen
     *
     * @return void 반환값 없음
     */
    public function test_krw_price_is_not_formatted_with_the_yen_unit_in_japanese(): void
    {
        // 런타임 번역(`__()`)만 보면 안 된다 — 테스트 DB 에는 ja 언어팩이 설치돼 있지 않아
        // ko 로 폴백하고, 그러면 `円` 이 없으니 **결함이 있는 채로 초록이 된다**(실측으로 확인).
        // 그래서 번역 결과와 **언어팩 파일의 값** 둘 다를 잠근다.
        $packs = [
            'ja' => base_path('lang-packs/g7-module-sirsoft-ecommerce-ja/backend/ja/messages.php'),
            'en' => base_path('modules/sirsoft-ecommerce/src/lang/en/messages.php'),
            'ko' => base_path('modules/sirsoft-ecommerce/src/lang/ko/messages.php'),
        ];

        foreach ($packs as $locale => $path) {
            if (! is_file($path)) {
                continue;
            }

            $messages = require $path;
            $suffix = $messages['currency']['suffix']['KRW'] ?? '';
            $prefix = $messages['currency']['prefix']['KRW'] ?? '';

            $this->assertStringNotContainsString(
                '円',
                (string) $suffix.(string) $prefix,
                "[$locale] 원화(KRW) 단위에 엔화 글자(円)를 쓰면 안 됩니다 — 같은 화면의 실제 엔화(¥)와 모순됩니다."
            );
        }

        $instance = new class
        {
            use HasMultiCurrencyPrices;

            /**
             * 통화 코드로 가격 문자열을 포맷한다.
             *
             * @param  float|int  $price  금액
             * @param  string  $code  통화 코드
             * @return string 포맷된 금액
             */
            public function format(float|int $price, string $code): string
            {
                return $this->formatCurrencyPrice($price, $code);
            }
        };

        foreach (array_keys($packs) as $locale) {
            $this->app->setLocale($locale);

            $this->assertStringNotContainsString(
                '円',
                $instance->format(6000, 'KRW'),
                "[$locale] 원화 금액에 엔화 단위(円)가 붙으면 안 됩니다 — 실제 엔화는 ¥ 로 따로 표기됩니다."
            );
        }
    }
}
