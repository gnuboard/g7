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

        $listener = new RegisterEasyPayMethodsListener;

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

    public function test_easy_pay_methods_are_locked_to_own_pg_provider(): void
    {
        $this->mockSettings([
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => false,
            'japan_enabled' => true,
        ]);

        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $easyPayMethods = array_filter(
            $methods,
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'kginicis_')
        );

        $this->assertCount(6, $easyPayMethods);

        // 간편결제는 KG 이니시스 결제창을 통해서만 처리되므로 PG 를 자기 자신으로 고정 선언한다.
        //
        // 과거에는 pg_provider 를 null 로 두었고(= "PG 없는 결제수단"), 그 결과 서버가
        // 간편결제 주문을 PG 결제가 아닌 주문으로 오인해 (a) 결제 실패했는데 관리자에게
        // 신규주문 알림이 발송되고 (b) 임시주문이 즉시 삭제되어 재결제가 불가능해졌다(#475).
        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);

            // PG 고정 — null 이면 코어가 PG 없는 주문으로 오인한다.
            $this->assertSame('kginicis', $method['defaults']['pg_provider'] ?? null);
            $this->assertTrue($method['defaults']['pg_locked'] ?? false);
            $this->assertTrue($method['defaults']['needs_pg'] ?? false);
            $this->assertSame('pg', $method['defaults']['refund_method'] ?? null);

            $this->assertFalse($method['defaults']['is_active'] ?? true);
            $this->assertSame('payment_complete', $method['defaults']['stock_deduction_timing'] ?? null);
            $this->assertSame('payment_complete', $method['defaults']['mileage_deduction_timing'] ?? null);
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
        $listener = new RegisterEasyPayMethodsListener;

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

        $listener = new RegisterEasyPayMethodsListener;

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

        $listener = new RegisterEasyPayMethodsListener;

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

    /**
     * @scenario mark_form=svg, requires_ios=false, device=ipados_desktop_ua
     *
     * @effects brand_mark_flows_to_cached, svg_renders_inline_logo, shared_helpers_preserved
     */
    public function test_easy_pay_methods_carry_svg_brand_mark(): void
    {
        // 브랜드 SVG 로고를 카탈로그로 편입 — 과거 checkoutNaverpayBrandButton 이
        // DOM 후처리로 주입하던 markSvg 를 등록 데이터(brand_mark.svg)로 이관.
        $this->mockSettings([
            'easy_pay_samsung_pay' => false,
            'easy_pay_naverpay' => false,
            'easy_pay_lpay' => false,
            'easy_pay_kakaopay' => false,
            'japan_enabled' => true,
        ]);

        $listener = new RegisterEasyPayMethodsListener;

        $methods = collect($listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]))->keyBy('id');

        $naverpay = $methods->get('kginicis_naverpay');
        $kakaopay = $methods->get('kginicis_kakaopay');

        $this->assertIsArray($naverpay['brand_mark'] ?? null);
        $this->assertArrayHasKey('svg', $naverpay['brand_mark']);
        $this->assertStringContainsString('<svg', $naverpay['brand_mark']['svg']);
        $this->assertStringContainsString('#03C75A', $naverpay['brand_mark']['svg']);

        $this->assertStringContainsString('#FEE500', $kakaopay['brand_mark']['svg'] ?? '');

        // KG 이니시스는 애플페이 수단이 없으므로 requires_ios 플래그도 없어야 한다.
        foreach ($methods as $method) {
            $this->assertArrayNotHasKey('requires_ios', $method);
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

        $listener = new RegisterEasyPayMethodsListener;

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
