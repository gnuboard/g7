<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayNicepayments\Listeners\AdjustEcommercePaymentMethodsLayoutListener;
use Plugins\Sirsoft\PayNicepayments\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayNicepayments\Plugin;
use Tests\TestCase;

class RegisterEasyPayMethodsListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace(
            'sirsoft-pay_nicepayments',
            base_path('plugins/_bundled/sirsoft-pay_nicepayments/lang')
        );
    }

    public function test_injects_easy_pay_methods_after_existing_pg_easy_pay_methods(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'kginicis_samsung_pay'],
            ['id' => 'nhnkcp_payco'],
            ['id' => 'point'],
            ['id' => 'deposit'],
        ]);

        $this->assertSame([
            'card',
            'phone',
            'kginicis_samsung_pay',
            'nhnkcp_payco',
            'nicepay_naverpay',
            'nicepay_kakaopay',
            'nicepay_samsungpay',
            'nicepay_applepay',
            'nicepay_payco',
            'nicepay_skpay',
            'nicepay_ssgpay',
            'nicepay_lpay',
            'point',
            'deposit',
        ], array_column($methods, 'id'));
    }

    public function test_plugin_registers_easy_pay_hook_listeners(): void
    {
        $listeners = (new Plugin)->getHookListeners();

        $this->assertContains(RegisterEasyPayMethodsListener::class, $listeners);
        $this->assertContains(AdjustEcommercePaymentMethodsLayoutListener::class, $listeners);
    }

    public function test_easy_pay_methods_are_locked_to_own_pg_provider(): void
    {
        // 간편결제는 나이스페이먼츠 결제창을 통해서만 처리되므로 PG 를 자기 자신으로 고정 선언한다.
        //
        // 과거에는 pg_provider 를 null 로 두었고(= "PG 없는 결제수단"), 그 결과 서버가
        // 간편결제 주문을 PG 결제가 아닌 주문으로 오인해 (a) 결제 실패했는데 관리자에게
        // 신규주문 알림이 발송되고 (b) 임시주문이 즉시 삭제되어 재결제가 불가능해졌다(#475).
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $easyPayMethods = array_filter(
            $methods,
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'nicepay_')
        );

        $this->assertCount(8, $easyPayMethods);

        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);

            // PG 고정 — null 이면 코어가 PG 없는 주문으로 오인한다.
            $this->assertSame('nicepayments', $method['defaults']['pg_provider'] ?? null);
            $this->assertTrue($method['defaults']['pg_locked'] ?? false);
            $this->assertTrue($method['defaults']['needs_pg'] ?? false);
            $this->assertSame('pg', $method['defaults']['refund_method'] ?? null);

            $this->assertFalse($method['defaults']['is_active'] ?? true);
            $this->assertSame('payment_complete', $method['defaults']['stock_deduction_timing'] ?? null);
            $this->assertSame('payment_complete', $method['defaults']['mileage_deduction_timing'] ?? null);
        }
    }

    public function test_easy_pay_method_labels_match_admin_payment_method_names(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'nicepay_naverpay');
        $kakaopay = collect($methods)->firstWhere('id', 'nicepay_kakaopay');
        $payco = collect($methods)->firstWhere('id', 'nicepay_payco');

        $this->assertSame('네이'."\u{200B}".'버페이 (나이스페이먼츠)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('카카'."\u{200B}".'오페이로 결제 (나이스페이먼츠)', $kakaopay['description']['ko'] ?? null);
        $this->assertSame('PAYCO (NicePayments)', $payco['name']['en'] ?? null);
    }

    public function test_easy_pay_method_labels_avoid_other_pg_brand_matchers(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $guardedTexts = [];
        foreach (['nicepay_naverpay', 'nicepay_kakaopay', 'nicepay_samsungpay', 'nicepay_lpay'] as $methodId) {
            $method = collect($methods)->firstWhere('id', $methodId);

            $guardedTexts[] = $method['name']['ko'] ?? '';
            $guardedTexts[] = $method['description']['ko'] ?? '';
            $guardedTexts[] = $method['name']['en'] ?? '';
            $guardedTexts[] = $method['description']['en'] ?? '';
        }

        $joined = implode(' ', $guardedTexts);

        $this->assertStringContainsString("\u{200B}", $joined);
        $this->assertStringNotContainsString('네이버페이', $joined);
        $this->assertStringNotContainsString('카카오페이', $joined);
        $this->assertStringNotContainsString('삼성페이', $joined);
        $this->assertStringNotContainsString('Naver Pay', $joined);
        $this->assertStringNotContainsString('Kakao Pay', $joined);
        $this->assertStringNotContainsString('Samsung Pay', $joined);
        $this->assertStringNotContainsString('L.pay', $joined);
    }

    /**
     * @scenario mark_form=badge, requires_ios=false, device=ipados_desktop_ua
     *
     * @effects brand_mark_flows_to_cached, badge_renders_text_and_color
     */
    public function test_easy_pay_methods_carry_badge_brand_mark(): void
    {
        // 브랜드 마크(색 배지)를 카탈로그로 편입 — 과거 checkoutEasyPayInjector 가
        // DOM 후처리로 주입하던 markText/markClassName 을 등록 데이터로 이관.
        $listener = new RegisterEasyPayMethodsListener;

        $methods = collect($listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]))->keyBy('id');

        $this->assertSame(
            ['text' => 'N', 'class' => 'bg-green-500 text-white'],
            $methods->get('nicepay_naverpay')['brand_mark'] ?? null,
        );
        $this->assertSame(
            ['text' => 'SSG', 'class' => 'bg-red-700 text-white'],
            $methods->get('nicepay_ssgpay')['brand_mark'] ?? null,
        );
    }

    public function test_only_apple_pay_requires_ios(): void
    {
        // 애플페이만 iOS 전용 노출 플래그를 가진다.
        $listener = new RegisterEasyPayMethodsListener;

        $methods = collect($listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]))->keyBy('id');

        $this->assertTrue($methods->get('nicepay_applepay')['requires_ios'] ?? false);
        $this->assertArrayNotHasKey('requires_ios', $methods->get('nicepay_naverpay'));
        $this->assertArrayNotHasKey('requires_ios', $methods->get('nicepay_samsungpay'));
    }
}
