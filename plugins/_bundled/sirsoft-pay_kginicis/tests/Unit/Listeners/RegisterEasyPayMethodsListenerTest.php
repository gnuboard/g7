<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Arr;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class RegisterEasyPayMethodsListenerTest extends PluginTestCase
{
    /**
     * 간편결제 4종과 일본 결제 2종이 휴대폰 결제 뒤, 포인트·무통장 앞에 순서대로 들어가는지 검증한다.
     *
     * @scenario contract=catalog_position
     *
     * @effects easy_pay_methods_follow_phone_before_point
     *
     * @return void 반환값 없음
     */
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

    /**
     * 간편결제 수단이 PG 를 KG이니시스로 고정 선언하고 설치 직후 비활성·결제 완료 시점 차감으로 등록되는지 검증한다.
     *
     * @scenario contract=method_defaults
     *
     * @effects easy_pay_methods_locked_to_own_pg, easy_pay_methods_installed_inactive_with_payment_complete_timing
     *
     * @return void 반환값 없음
     */
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
        // 신규주문 알림이 발송되고 (b) 임시주문이 즉시 삭제되어 재결제가 불가능해졌다.
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

    /**
     * 네이버페이 브랜드 단추 설정이 꺼져 있으면 네이버페이 설명이 종전의 긴 문구로 나오는지 검증한다.
     *
     * @scenario contract=display_labels
     *
     * @effects naverpay_description_follows_brand_button_setting
     *
     * @return void 반환값 없음
     */
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

    /**
     * 네이버페이 브랜드 단추 설정이 켜져 있으면 네이버페이 설명이 주문서용 짧은 문구로 나오는지 검증한다.
     *
     * @scenario contract=display_labels
     *
     * @effects naverpay_description_follows_brand_button_setting
     *
     * @return void 반환값 없음
     */
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

    /**
     * 플러그인 설정에서 끈 간편결제도 관리자가 켤 수 있도록 비활성 상태로 목록에 들어가는지(일본 결제는 끄면 빠지는지) 검증한다.
     *
     * @scenario contract=activation_state
     *
     * @effects disabled_easy_pay_injected_inactive_for_admin_toggle
     *
     * @return void 반환값 없음
     */
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
     * 네이버페이·카카오페이가 SVG 로고 형태의 브랜드 마크를 등록 데이터로 싣고, 애플페이가 없으므로 iOS 전용 플래그는 싣지 않는지 검증한다.
     *
     * @scenario mark_form=svg, requires_ios=false, device=ipados_desktop_ua
     *
     * @effects brand_mark_flows_to_cached, svg_renders_inline_logo, shared_helpers_preserved
     *
     * @return void 반환값 없음
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

    /**
     * 예전 플러그인 설정의 간편결제 켬/끔 값이 결제수단 기본 활성 상태로 이어지는지 검증한다.
     *
     * @scenario contract=activation_state
     *
     * @effects legacy_easy_pay_settings_seed_default_active_state
     *
     * @return void 반환값 없음
     */
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

    /**
     * 간편결제·일본 결제 이름·설명이 ko·en 두 언어로 고정되지 않고 지원 언어(활성 언어팩) 전부로 채워지는지 검증한다.
     *
     * 종전에는 두 언어만 실려 일본어 언어팩을 켠 사이트의 일본어 화면(쇼핑몰 환경설정 결제수단 목록·주문서)에
     * 언어팩 번역이 있는데도 한국어 이름이 보였다.
     *
     * @scenario contract=display_labels
     *
     * @effects easy_pay_labels_cover_supported_locales
     *
     * @return void 반환값 없음
     */
    public function test_easy_pay_method_labels_cover_every_supported_locale(): void
    {
        $this->mockSettings([
            'easy_pay_samsung_pay' => true,
            'easy_pay_naverpay' => true,
            'easy_pay_lpay' => true,
            'easy_pay_kakaopay' => true,
            'japan_enabled' => true,
        ]);
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
        $this->app['translator']->addLines(
            Arr::dot(['payment_methods' => require base_path('lang-packs/_bundled/g7-plugin-sirsoft-pay_kginicis-ja/backend/ja/payment_methods.php')]),
            'ja',
            'sirsoft-pay_kginicis',
        );
        $localeBefore = app()->getLocale();

        $methods = collect((new RegisterEasyPayMethodsListener)->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]))->filter(fn (array $method): bool => str_starts_with((string) $method['id'], 'kginicis_'));

        $this->assertCount(6, $methods);
        foreach ($methods as $method) {
            $this->assertSame(['ko', 'en', 'ja'], array_keys($method['name']), $method['id']);
            $this->assertSame(['ko', 'en', 'ja'], array_keys($method['description']), $method['id']);
        }

        $kakaopay = $methods->firstWhere('id', 'kginicis_kakaopay');
        $this->assertSame('Kakao Pay (KGイニシス)', $kakaopay['name']['ja']);
        $this->assertSame('Kakao Payで決済 — KGイニシスを通じて処理', $kakaopay['description']['ja']);
        $this->assertSame($localeBefore, app()->getLocale(), '번역하는 동안 바꾼 앱 언어는 되돌려야 한다');
    }

    /**
     * 플러그인 설정 서비스를 주어진 설정 값으로 바꿔 끼운다.
     *
     * @param  array<string, mixed>  $settings  KG이니시스 플러그인 설정 값
     * @return void 반환값 없음
     */
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
