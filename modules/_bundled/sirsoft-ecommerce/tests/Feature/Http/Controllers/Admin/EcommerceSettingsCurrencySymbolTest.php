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
     */
    public function test_formatted_price_prefix_distinguishes_cny_from_jpy(): void
    {
        $instance = new class
        {
            use HasMultiCurrencyPrices;

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
}
