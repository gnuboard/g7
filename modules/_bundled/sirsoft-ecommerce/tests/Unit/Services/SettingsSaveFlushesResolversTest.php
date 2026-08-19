<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 설정 서비스 공유 인스턴스화 + 저장 시 리졸버 캐시 무효화 (공개 #116)
 *
 * 형제 서비스 3종(CurrencyConversionService / ShippingPolicyResolver / PaymentMethodResolver)은
 * 싱글톤으로 등록되어 있는데 `EcommerceSettingsService` 만 미등록이라, 주입 지점마다 별개
 * 인스턴스가 만들어지고 각자 자기 설정 캐시를 들고 있었다. 싱글톤 리졸버가 비-싱글톤 설정
 * 서비스를 captive 로 보유하는 비대칭도 함께 생긴다.
 *
 * 싱글톤화만으로는 부족하다 — 리졸버들은 자기 캐시를 따로 들고 있어서, 같은 요청 안에서
 * 설정을 저장해도 이미 해석된 리졸버는 저장 전 카탈로그를 계속 답한다. 저장 지점에서
 * (이미 해석된 경우에만) 무효화를 연동한다.
 */
class SettingsSaveFlushesResolversTest extends ModuleTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * 카드 결제수단을 지정 상태로 만드는 저장 payload 를 만듭니다.
     *
     * @param  bool  $cardActive  card 결제수단 활성 여부
     * @return array order_settings.payment_methods payload
     */
    private function paymentMethods(bool $cardActive): array
    {
        return [
            ['id' => 'card', 'is_active' => $cardActive, 'sort_order' => 1],
            ['id' => 'dbank', 'is_active' => true, 'sort_order' => 2],
        ];
    }

    /**
     * 설정 서비스는 컨테이너에서 공유 인스턴스로 해석된다. (실패-먼저)
     *
     * @scenario resolution=container
     *
     * @effects settings_service_shared_instance
     */
    public function test_settings_service_is_resolved_as_shared_instance(): void
    {
        $this->assertTrue(
            $this->app->bound(EcommerceSettingsService::class),
            '설정 서비스가 컨테이너에 등록되어 있지 않습니다 (형제 3종은 싱글톤 등록).'
        );

        $first = $this->app->make(EcommerceSettingsService::class);
        $second = $this->app->make(EcommerceSettingsService::class);

        $this->assertSame($first, $second, '설정 서비스가 해석할 때마다 새 인스턴스로 만들어집니다.');
    }

    /**
     * 싱글톤 리졸버가 붙든 설정 서비스도 같은 공유 인스턴스다.
     *
     * @scenario resolution=captive_dependency
     *
     * @effects settings_service_shared_instance
     */
    public function test_resolver_holds_the_same_shared_settings_instance(): void
    {
        $resolver = $this->app->make(PaymentMethodResolver::class);

        $reflection = new \ReflectionClass($resolver);
        $prop = $reflection->getProperty('settingsService');
        $prop->setAccessible(true);

        $this->assertSame(
            $this->app->make(EcommerceSettingsService::class),
            $prop->getValue($resolver),
            '싱글톤 리졸버가 별개의 설정 서비스 인스턴스를 붙들고 있습니다.'
        );
    }

    /**
     * 저장 직후 같은 요청에서 결제수단 리졸버가 신값을 답한다. (실패-먼저)
     *
     * @scenario resolution=container
     *
     * @effects payment_resolver_reflects_saved_catalog
     */
    public function test_bulk_save_flushes_payment_method_resolver(): void
    {
        $settings = $this->app->make(EcommerceSettingsService::class);
        $settings->saveSettings(['order_settings' => ['payment_methods' => $this->paymentMethods(false)]]);

        // 저장 전 카탈로그를 리졸버가 선점하도록 강제
        $resolver = $this->app->make(PaymentMethodResolver::class);
        $this->assertFalse($this->isActive($resolver, 'card'));

        $settings->saveSettings(['order_settings' => ['payment_methods' => $this->paymentMethods(true)]]);

        $this->assertTrue(
            $this->isActive($resolver, 'card'),
            '저장 후에도 리졸버가 저장 전 카탈로그를 답했습니다 (캐시 무효화 미연동).'
        );
    }

    /**
     * 단건 저장(setSetting) 경로도 같은 무효화 지점을 지난다. (실패-먼저)
     *
     * @scenario resolution=container
     *
     * @effects payment_resolver_reflects_saved_catalog
     */
    public function test_single_key_save_flushes_payment_method_resolver(): void
    {
        $settings = $this->app->make(EcommerceSettingsService::class);
        $settings->setSetting('order_settings.payment_methods', $this->paymentMethods(false));

        $resolver = $this->app->make(PaymentMethodResolver::class);
        $this->assertFalse($this->isActive($resolver, 'card'));

        $settings->setSetting('order_settings.payment_methods', $this->paymentMethods(true));

        $this->assertTrue(
            $this->isActive($resolver, 'card'),
            '단건 저장 후에도 리졸버가 저장 전 카탈로그를 답했습니다.'
        );
    }

    /**
     * 저장 직후 같은 요청에서 통화 서비스도 신값을 답한다. (실패-먼저)
     *
     * 결제수단 리졸버와 함께 무효화 목록에 있으나, 종전에는 "해석 가드가 있는가" 만
     * 확인하고 실제로 신값이 나오는지는 검증하지 않았다.
     *
     * @scenario resolution=container
     *
     * @effects currency_service_reflects_saved_settings
     */
    public function test_bulk_save_flushes_currency_conversion_service(): void
    {
        $settings = $this->app->make(EcommerceSettingsService::class);
        $settings->saveSettings(['language_currency' => $this->currencySettings('KRW')]);

        // 저장 전 설정을 통화 서비스가 선점하도록 강제
        $currency = $this->app->make(CurrencyConversionService::class);
        $this->assertSame('KRW', $currency->getDefaultCurrency());

        $settings->saveSettings(['language_currency' => $this->currencySettings('USD')]);

        $this->assertSame(
            'USD',
            $currency->getDefaultCurrency(),
            '저장 후에도 통화 서비스가 저장 전 기본 통화를 답했습니다 (캐시 무효화 미연동).'
        );
    }

    /**
     * 단건 저장 경로도 통화 서비스 캐시를 비운다. (실패-먼저)
     *
     * @scenario resolution=container
     *
     * @effects currency_service_reflects_saved_settings
     */
    public function test_single_key_save_flushes_currency_conversion_service(): void
    {
        $settings = $this->app->make(EcommerceSettingsService::class);
        $settings->saveSettings(['language_currency' => $this->currencySettings('KRW')]);

        $currency = $this->app->make(CurrencyConversionService::class);
        $this->assertSame('KRW', $currency->getDefaultCurrency());

        $settings->setSetting('language_currency.default_currency', 'USD');

        $this->assertSame(
            'USD',
            $currency->getDefaultCurrency(),
            '단건 저장 후에도 통화 서비스가 저장 전 기본 통화를 답했습니다.'
        );
    }

    /**
     * 지정한 기본 통화로 language_currency payload 를 만듭니다.
     *
     * @param  string  $defaultCurrency  기본 통화 코드
     * @return array language_currency payload
     */
    private function currencySettings(string $defaultCurrency): array
    {
        return [
            'default_currency' => $defaultCurrency,
            'currencies' => [
                ['code' => 'KRW', 'symbol' => '₩', 'decimal_places' => 0, 'exchange_rate' => 1, 'is_active' => true, 'is_default' => $defaultCurrency === 'KRW'],
                ['code' => 'USD', 'symbol' => '$', 'decimal_places' => 2, 'exchange_rate' => 0.00075, 'is_active' => true, 'is_default' => $defaultCurrency === 'USD'],
            ],
        ];
    }

    /**
     * 아직 해석되지 않은 리졸버는 저장이 강제로 인스턴스화하지 않는다.
     *
     * @scenario resolution=not_resolved
     *
     * @effects unresolved_services_not_instantiated_on_save
     */
    public function test_save_does_not_instantiate_unresolved_resolvers(): void
    {
        $this->app->forgetInstance(PaymentMethodResolver::class);
        $this->app->forgetInstance(CurrencyConversionService::class);

        $this->app->make(EcommerceSettingsService::class)
            ->saveSettings(['order_settings' => ['payment_methods' => $this->paymentMethods(true)]]);

        $this->assertFalse(
            $this->app->resolved(PaymentMethodResolver::class),
            '저장이 미해석 리졸버를 강제로 인스턴스화했습니다.'
        );
        $this->assertFalse(
            $this->app->resolved(CurrencyConversionService::class),
            '저장이 미해석 통화 서비스를 강제로 인스턴스화했습니다.'
        );
    }

    /**
     * 리졸버가 보는 카탈로그에서 결제수단의 활성 여부를 읽습니다.
     *
     * @param  PaymentMethodResolver  $resolver  결제수단 리졸버
     * @param  string  $methodId  결제수단 ID
     * @return bool 활성 여부
     */
    private function isActive(PaymentMethodResolver $resolver, string $methodId): bool
    {
        $reflection = new \ReflectionClass($resolver);
        $method = $reflection->getMethod('catalogValue');
        $method->setAccessible(true);

        return (bool) $method->invoke($resolver, $methodId, 'is_active');
    }
}
