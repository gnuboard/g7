<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class RegisterEasyPayMethodsListenerTest extends PluginTestCase
{
    public function test_injects_easy_pay_methods_between_phone_and_point(): void
    {
        $this->mockSettings([
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => false,
            'japan_enabled' => true,
        ]);

        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'point'],
            ['id' => 'deposit'],
        ]);

        $this->assertSame([
            'card',
            'phone',
            'kginicis_samsung_pay',
            'kginicis_naverpay',
            'kginicis_lpay',
            'kginicis_kakaopay',
            'kginicis_japan_paypay',
            'kginicis_japan_cvs',
            'point',
            'deposit',
        ], array_column($methods, 'id'));
    }

    public function test_easy_pay_methods_do_not_require_pg_provider_in_saved_defaults(): void
    {
        $this->mockSettings([
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => false,
            'japan_enabled' => true,
        ]);

        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $easyPayMethods = array_filter(
            $methods,
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'kginicis_')
        );

        $this->assertCount(6, $easyPayMethods);

        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);
            $this->assertNull($method['defaults']['pg_provider'] ?? null);
            $this->assertFalse($method['defaults']['is_active'] ?? true);
        }
    }

    public function test_naverpay_uses_legacy_description_without_brand_button_setting(): void
    {
        $this->mockSettings([
            'easy_pay_naverpay' => true,
            'easy_pay_show_brand_button' => false,
        ]);

        // 테스트처럼 플러그인 설정이 아직 주입되지 않은 fallback 환경에서는
        // 기존 긴 설명을 유지한다. 브랜드 버튼 설정이 켜진 경우는 아래 테스트에서 별도 검증.
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'kginicis_naverpay');

        $this->assertSame('네이버페이 (KG이니시스)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('네이버페이로 결제 — KG 이니시스를 통해 처리', $naverpay['description']['ko'] ?? null);
    }

    public function test_brand_button_option_uses_short_checkout_description_for_naverpay(): void
    {
        $this->mockSettings([
            'easy_pay_naverpay' => true,
            'easy_pay_show_brand_button' => true,
        ]);

        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'kginicis_naverpay');

        $this->assertSame('네이버페이 (KG이니시스)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('네이버페이로 결제', $naverpay['description']['ko'] ?? null);
        $this->assertSame('Pay with Naver Pay', $naverpay['description']['en'] ?? null);
        $this->assertSame('wallet', $naverpay['icon'] ?? null);
    }

    public function test_disabled_easy_pay_settings_are_injected_as_inactive_for_admin_toggle(): void
    {
        $this->mockSettings([
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => false,
            'japan_enabled' => false,
        ]);

        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $this->assertSame([
            'phone',
            'kginicis_samsung_pay',
            'kginicis_naverpay',
            'kginicis_lpay',
            'kginicis_kakaopay',
            'point',
        ], array_column($methods, 'id'));

        foreach ($methods as $method) {
            if (str_starts_with((string) ($method['id'] ?? ''), 'kginicis_')) {
                $this->assertFalse($method['defaults']['is_active'] ?? true);
            }
        }
    }

    public function test_legacy_easy_pay_settings_are_used_as_default_active_state(): void
    {
        $this->mockSettings([
            'easy_pay_samsung_pay' => true,
            'easy_pay_naverpay' => false,
            'easy_pay_lpay' => true,
            'easy_pay_kakaopay' => false,
            'japan_enabled' => false,
        ]);

        $listener = new RegisterEasyPayMethodsListener();

        $methods = collect($listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]))->keyBy('id');

        $this->assertTrue($methods->get('kginicis_samsung_pay')['defaults']['is_active'] ?? false);
        $this->assertFalse($methods->get('kginicis_naverpay')['defaults']['is_active'] ?? true);
        $this->assertTrue($methods->get('kginicis_lpay')['defaults']['is_active'] ?? false);
        $this->assertFalse($methods->get('kginicis_kakaopay')['defaults']['is_active'] ?? true);
    }

    private function mockSettings(array $settings): void
    {
        $settingsService = $this->createMock(PluginSettingsService::class);
        $settingsService->method('get')
            ->willReturnCallback(function (string $identifier, ?string $key = null, mixed $default = null) use ($settings): mixed {
                if ($identifier !== 'sirsoft-pay_kginicis') {
                    return $default;
                }

                if ($key === null) {
                    return $settings;
                }

                return $settings[$key] ?? $default;
            });

        $this->app->instance(PluginSettingsService::class, $settingsService);
    }
}
