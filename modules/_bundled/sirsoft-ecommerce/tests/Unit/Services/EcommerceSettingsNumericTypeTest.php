<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 설정 숫자 타입 정규화 테스트
 *
 * 관리자 화면이 HTML number 입력의 문자열 값을 저장하더라도, 조회 시에는
 * defaults.json 스키마의 스칼라 타입(int/float)으로 정규화되어야 한다.
 * 정규화가 없으면 Carbon 등 strict 타입 경계에서 TypeError 가 발생한다.
 *
 * @effects settings_read_returns_schema_scalar_type
 */
class EcommerceSettingsNumericTypeTest extends ModuleTestCase
{
    private EcommerceSettingsService $service;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

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

        parent::tearDown();
    }

    public function test_auto_cancel_days_saved_as_string_is_read_back_as_int(): void
    {
        $this->service->saveSettings(['order_settings' => ['auto_cancel_days' => '5']]);

        $this->assertSame(5, $this->service->getSetting('order_settings.auto_cancel_days'));
    }

    public function test_cart_expiry_days_saved_as_string_is_read_back_as_int(): void
    {
        $this->service->saveSettings(['order_settings' => ['cart_expiry_days' => '15']]);

        $this->assertSame(15, $this->service->getSetting('order_settings.cart_expiry_days'));
    }

    public function test_int_saved_value_stays_int(): void
    {
        $this->service->saveSettings(['order_settings' => ['auto_cancel_days' => 7]]);

        $this->assertSame(7, $this->service->getSetting('order_settings.auto_cancel_days'));
    }

    public function test_boolean_setting_is_not_affected_by_numeric_normalization(): void
    {
        $this->service->saveSettings(['order_settings' => ['auto_cancel_expired' => false]]);

        $this->assertFalse($this->service->getSetting('order_settings.auto_cancel_expired'));
    }

    public function test_string_setting_is_not_converted_to_number(): void
    {
        // defaults 타입이 문자열인 설정은 값이 숫자처럼 보여도 문자열을 유지해야 한다.
        $this->service->saveSettings(['basic_info' => ['business_number' => '0123456789']]);

        $this->assertSame('0123456789', $this->service->getSetting('basic_info.business_number'));
    }
}
