<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\SignupCurrencyResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 가입 통화 리졸버 — 지역·세 글자 언어 코드 매칭 (#148)
 *
 * 통화 사용 언어에 지역이 붙어 있으면(zh-TW) 정확일치가 먼저이고, 정확일치가 없으면
 * 주 언어가 같은 통화 언어(fil → fil-PH)로 고른다. 종전에는 화면 언어를 두 글자로 잘라 매칭이 실패했다.
 */
class SignupCurrencyResolverRegionLocaleTest extends ModuleTestCase
{
    private string $storagePath;

    /**
     * 모듈 설정 저장 경로를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
    }

    /**
     * locales 매핑이 설정된 통화 목록을 저장합니다.
     *
     * @param  array<int, array<string, mixed>>  $currencies  통화 목록
     * @param  string  $default  기본 통화 코드
     * @return void 반환값 없음
     */
    private function saveCurrencies(array $currencies, string $default = 'KRW'): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/language_currency.json',
            json_encode(['default_currency' => $default, 'currencies' => $currencies], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        app()->forgetInstance(EcommerceSettingsService::class);
    }

    /**
     * 리졸버를 만듭니다.
     *
     * @return SignupCurrencyResolver 리졸버
     */
    private function resolver(): SignupCurrencyResolver
    {
        return app(SignupCurrencyResolver::class);
    }

    /**
     * 통화 사용 언어에 지역이 붙어 있으면 정확일치가 먼저다 (종전 zh-TW → zh 로 잘라 매칭 실패).
     *
     * @scenario shape=region_tagged
     *
     * @effects signup_currency_matches_region_then_primary
     *
     * @return void 반환값 없음
     */
    public function test_region_tagged_locale_matches_exact_currency_locale(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'TWD', 'name' => ['ko' => 'TWD'], 'is_default' => false, 'exchange_rate' => 0.024, 'locales' => ['zh-TW']],
            ['code' => 'CNY', 'name' => ['ko' => 'CNY'], 'is_default' => false, 'exchange_rate' => 0.0053, 'locales' => ['zh-CN']],
        ]);

        $this->assertSame('TWD', $this->resolver()->resolve('zh-TW'));
        $this->assertSame('TWD', $this->resolver()->resolve('zh_tw'));
        $this->assertSame('CNY', $this->resolver()->resolve('zh-CN'));
    }

    /**
     * 정확일치가 없으면 주 언어가 같은 통화 언어로 고른다 (fil 사이트 → fil-PH 통화).
     *
     * @scenario shape=three_letter
     *
     * @effects signup_currency_matches_region_then_primary
     *
     * @return void 반환값 없음
     */
    public function test_three_letter_locale_matches_region_tagged_currency_locale(): void
    {
        $this->saveCurrencies([
            ['code' => 'KRW', 'name' => ['ko' => 'KRW'], 'is_default' => true, 'locales' => ['ko']],
            ['code' => 'PHP', 'name' => ['ko' => 'PHP'], 'is_default' => false, 'exchange_rate' => 0.042, 'locales' => ['fil-PH']],
        ]);

        $this->assertSame('PHP', $this->resolver()->resolve('fil'));
        $this->assertSame('PHP', $this->resolver()->resolve('fil-PH'));
    }
}
