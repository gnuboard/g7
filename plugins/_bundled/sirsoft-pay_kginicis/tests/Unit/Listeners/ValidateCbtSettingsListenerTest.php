<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\PayKginicis\Listeners\ValidateCbtSettingsListener;
use Plugins\Sirsoft\PayKginicis\Plugin;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class ValidateCbtSettingsListenerTest extends PluginTestCase
{
    public function test_plugin_registers_cbt_settings_validation_listener(): void
    {
        $this->assertContains(ValidateCbtSettingsListener::class, (new Plugin())->getHookListeners());

        $hooks = ValidateCbtSettingsListener::getSubscribedHooks();
        $this->assertSame('validateBeforeSave', $hooks['core.plugin_settings.before_save']['method'] ?? null);
        $this->assertSame(10, $hooks['core.plugin_settings.before_save']['priority'] ?? null);
        $this->assertTrue($hooks['core.plugin_settings.before_save']['sync'] ?? false);
    }

    public function test_plugin_schema_includes_jpy_payment_method_restriction_option(): void
    {
        $plugin = new Plugin();

        $schema = $plugin->getSettingsSchema();
        $config = $plugin->getConfigValues();

        $this->assertSame('boolean', $schema['japan_restrict_jpy_payment_methods']['type'] ?? null);
        $this->assertFalse($schema['japan_restrict_jpy_payment_methods']['default'] ?? true);
        $this->assertArrayHasKey('japan_restrict_jpy_payment_methods', $config);
        $this->assertFalse($config['japan_restrict_jpy_payment_methods']);
    }

    public function test_plugin_schema_includes_easy_pay_brand_button_option(): void
    {
        $plugin = new Plugin();

        $schema = $plugin->getSettingsSchema();
        $config = $plugin->getConfigValues();

        $this->assertSame('boolean', $schema['easy_pay_show_brand_button']['type'] ?? null);
        $this->assertFalse($schema['easy_pay_show_brand_button']['default'] ?? true);
        $this->assertArrayHasKey('easy_pay_show_brand_button', $config);
        $this->assertTrue($config['easy_pay_show_brand_button']);
    }

    public function test_plugin_config_values_respect_defaults_json_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kginicis-defaults-');
        file_put_contents($path, json_encode([
            'defaults' => [
                'easy_pay_show_brand_button' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        $plugin = new class($path) extends Plugin
        {
            public function __construct(private readonly string $defaultsPath) {}

            public function getSettingsDefaultsPath(): ?string
            {
                return $this->defaultsPath;
            }
        };

        try {
            $this->assertTrue($plugin->getConfigValues()['easy_pay_show_brand_button']);
        } finally {
            @unlink($path);
        }
    }

    public function test_ignores_other_plugins(): void
    {
        $listener = new ValidateCbtSettingsListener();

        $listener->validateBeforeSave('other-plugin', [
            'japan_enabled' => true,
            'is_test_mode' => false,
        ]);

        $this->assertTrue(true);
    }

    public function test_live_japan_payment_requires_live_credentials(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();

        $this->expectException(ValidationException::class);

        $listener->validateBeforeSave('sirsoft-pay_kginicis', [
            'japan_enabled' => true,
            'is_test_mode' => false,
            'live_japan_mid' => '',
            'live_japan_sign_key' => '',
        ]);
    }

    public function test_backend_settings_validation_messages_are_localized(): void
    {
        $this->mockCurrentSettings([]);
        app()->setLocale('ko');

        $listener = new ValidateCbtSettingsListener();

        try {
            $listener->validateBeforeSave('sirsoft-pay_kginicis', [
                'japan_enabled' => true,
                'is_test_mode' => false,
                'live_japan_mid' => '',
                'live_japan_sign_key' => '',
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $message = $e->errors()['live_japan_mid'][0] ?? '';
            $this->assertSame('운영 모드에서 일본 결제(CBT)를 사용하려면 라이브 일본 MID가 필요합니다.', $message);
            $this->assertStringNotContainsString('sirsoft-pay_kginicis::messages', $message);
        }
    }

    public function test_live_japan_payment_rejects_sample_jppg_display_values(): void
    {
        $this->mockCurrentSettings([]);
        app()->setLocale('ko');

        $listener = new ValidateCbtSettingsListener();

        try {
            $listener->validateBeforeSave('sirsoft-pay_kginicis', $this->validLiveSettings([
                'japan_merchant_name' => 'サンプルストア',
                'japan_merchant_name_alphabet' => 'Sample Store',
                'japan_contact_email' => 'support@example.com',
            ]));
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            $this->assertArrayHasKey('japan_contract_info', $errors);
            $this->assertArrayNotHasKey('japan_merchant_name', $errors);
            $this->assertArrayNotHasKey('japan_merchant_name_alphabet', $errors);
            $this->assertArrayNotHasKey('japan_contact_email', $errors);
            $this->assertSame([
                '운영 모드에서는 일본 가맹점 표시 정보의 샘플값을 실제 계약 정보로 변경하세요.',
            ], $errors['japan_contract_info']);
        }
    }

    public function test_live_japan_payment_accepts_real_jppg_values(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();
        $listener->validateBeforeSave('sirsoft-pay_kginicis', $this->validLiveSettings());

        $this->assertTrue(true);
    }

    public function test_test_mode_requires_test_cbt_hash_key_when_japan_enabled(): void
    {
        $this->mockCurrentSettings([]);

        $listener = new ValidateCbtSettingsListener();

        $this->expectException(ValidationException::class);

        $listener->validateBeforeSave('sirsoft-pay_kginicis', [
            'japan_enabled' => true,
            'is_test_mode' => true,
            'test_japan_sign_key' => '',
        ]);
    }

    private function mockCurrentSettings(array $settings): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn($settings);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    private function validLiveSettings(array $overrides = []): array
    {
        return array_merge([
            'japan_enabled' => true,
            'is_test_mode' => false,
            'live_japan_mid' => 'JPLIVE001',
            'live_japan_sign_key' => 'live-secret-key',
            'japan_merchant_name' => '実店舗ストア',
            'japan_merchant_name_kana' => 'ジツテンポストア',
            'japan_merchant_name_alphabet' => 'Real Store',
            'japan_merchant_name_short' => 'リアル',
            'japan_contact_name' => 'Customer Support',
            'japan_contact_email' => 'support@real.example',
            'japan_contact_phone' => '03-1111-2222',
            'japan_contact_opening_hours' => '09:00-18:00',
        ], $overrides);
    }
}
