<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use ReflectionClass;
use Tests\TestCase;

/**
 * 결제수단별 마일리지 차감 시점 설정 테스트 (마일리지/MP06)
 *
 * EcommerceSettingsService::getMileageDeductionTiming(string) 의 결제수단별 반환과
 * 미설정 기본값(무통장=order_placed / 그 외=payment_complete)을 검증한다.
 */
class EcommerceSettingsMileageTimingTest extends TestCase
{
    private EcommerceSettingsService $service;

    private string $storagePath;

    private array $originalFilters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        $ref = new ReflectionClass(HookManager::class);
        $prop = $ref->getProperty('filters');
        $this->originalFilters = $prop->getValue();
        $prop->setValue(null, []);

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->service = new EcommerceSettingsService;
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $ref = new ReflectionClass(HookManager::class);
        $prop = $ref->getProperty('filters');
        $prop->setValue(null, $this->originalFilters);

        parent::tearDown();
    }

    /**
     * 결제수단별 설정값이 있으면 그 값을 반환한다.
     */
    public function test_returns_per_method_timing_when_set(): void
    {
        $this->service->saveSettings([
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'sort_order' => 1, 'is_active' => true, 'min_order_amount' => 0, 'mileage_deduction_timing' => 'order_placed'],
                    ['id' => 'card', 'sort_order' => 2, 'is_active' => true, 'min_order_amount' => 0, 'mileage_deduction_timing' => 'payment_complete'],
                ],
            ],
        ]);
        $this->service->clearCache();

        $this->assertEquals('order_placed', $this->service->getMileageDeductionTiming('dbank'));
        $this->assertEquals('payment_complete', $this->service->getMileageDeductionTiming('card'));
    }

    /**
     * 무통장 계열 결제수단의 미설정 기본값은 order_placed 다.
     */
    public function test_default_for_bank_methods_is_order_placed(): void
    {
        $this->assertEquals('order_placed', $this->service->getMileageDeductionTiming('vbank'));
        $this->assertEquals('order_placed', $this->service->getMileageDeductionTiming('dbank'));
    }

    /**
     * 그 외(미정의 포함) 결제수단의 미설정 기본값은 payment_complete 다.
     */
    public function test_default_for_other_methods_is_payment_complete(): void
    {
        $this->assertEquals('payment_complete', $this->service->getMileageDeductionTiming('card'));
        $this->assertEquals('payment_complete', $this->service->getMileageDeductionTiming('nonexistent_method'));
    }

    /**
     * 기본 카탈로그 defaults: 무통장은 order_placed, 카드는 payment_complete.
     */
    public function test_catalog_defaults_per_method(): void
    {
        $this->service->clearCache();
        $settings = $this->service->getSettings('order_settings');
        $methods = collect($settings['payment_methods']);

        $dbank = $methods->firstWhere('id', 'dbank');
        $card = $methods->firstWhere('id', 'card');
        $vbank = $methods->firstWhere('id', 'vbank');

        $this->assertEquals('order_placed', $dbank['mileage_deduction_timing']);
        $this->assertEquals('order_placed', $vbank['mileage_deduction_timing']);
        $this->assertEquals('payment_complete', $card['mileage_deduction_timing']);
    }
}
