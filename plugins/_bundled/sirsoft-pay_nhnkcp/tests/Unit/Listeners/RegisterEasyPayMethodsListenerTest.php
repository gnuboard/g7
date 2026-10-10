<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayNhnkcp\Listeners\AdjustEcommercePaymentMethodsLayoutListener;
use Plugins\Sirsoft\PayNhnkcp\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayNhnkcp\Plugin;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * NHN KCP 간편결제 수단 등록 리스너가 결제수단 카탈로그에 넣는 데이터(위치·기본값·표시명·브랜드 마크)를 검증합니다.
 */
class RegisterEasyPayMethodsListenerTest extends PluginTestCase
{
    /**
     * 플러그인 테스트 부팅 뒤 번들 원본의 언어팩을 플러그인 번역 네임스페이스로 다시 지정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace(
            'sirsoft-pay_nhnkcp',
            base_path('plugins/_bundled/sirsoft-pay_nhnkcp/lang')
        );
    }

    /**
     * 간편결제 5종이 휴대폰 결제와 다른 PG 의 간편결제 뒤, 포인트·무통장 앞에 들어가는지 확인합니다.
     *
     * @scenario contract=catalog_position
     *
     * @effects easy_pay_methods_follow_phone_and_other_pg_easy_pay
     *
     * @return void 반환값 없음
     */
    public function test_injects_easy_pay_methods_after_phone_and_existing_easy_pay_methods(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'kginicis_samsung_pay'],
            ['id' => 'kginicis_naverpay'],
            ['id' => 'point'],
            ['id' => 'deposit'],
        ]);

        $this->assertSame([
            'card',
            'phone',
            'kginicis_samsung_pay',
            'kginicis_naverpay',
            'nhnkcp_payco',
            'nhnkcp_naverpay',
            'nhnkcp_naverpay_point',
            'nhnkcp_kakaopay',
            'nhnkcp_applepay',
            'point',
            'deposit',
        ], array_column($methods, 'id'));
    }

    /**
     * 플러그인이 간편결제 등록 리스너와 관리자 결제수단 화면 보정 리스너를 훅 목록에 올리는지 확인합니다.
     *
     * @scenario contract=hook_wiring
     *
     * @effects easy_pay_hook_listeners_registered
     *
     * @return void 반환값 없음
     */
    public function test_plugin_registers_easy_pay_hook_listeners(): void
    {
        $listeners = (new Plugin)->getHookListeners();

        $this->assertContains(RegisterEasyPayMethodsListener::class, $listeners);
        $this->assertContains(AdjustEcommercePaymentMethodsLayoutListener::class, $listeners);
    }

    /**
     * 간편결제 수단이 PG 를 NHN KCP 로 고정 선언하고 설치 직후 비활성·결제 완료 차감 시점을 기본값으로 갖는지 확인합니다.
     *
     * @scenario contract=method_defaults
     *
     * @effects easy_pay_methods_locked_to_own_pg, easy_pay_methods_installed_inactive_with_payment_complete_timing
     *
     * @return void 반환값 없음
     */
    public function test_easy_pay_methods_are_locked_to_own_pg_provider(): void
    {
        // 간편결제는 NHN KCP 결제창을 통해서만 처리되므로 PG 를 자기 자신으로 고정 선언한다.
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
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'nhnkcp_')
        );

        $this->assertCount(5, $easyPayMethods);

        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);

            // PG 고정 — null 이면 코어가 PG 없는 주문으로 오인한다.
            $this->assertSame('nhnkcp', $method['defaults']['pg_provider'] ?? null);
            $this->assertTrue($method['defaults']['pg_locked'] ?? false);
            $this->assertTrue($method['defaults']['needs_pg'] ?? false);
            $this->assertSame('pg', $method['defaults']['refund_method'] ?? null);

            $this->assertFalse($method['defaults']['is_active'] ?? true);
            $this->assertSame('payment_complete', $method['defaults']['stock_deduction_timing'] ?? null);
            $this->assertSame('payment_complete', $method['defaults']['mileage_deduction_timing'] ?? null);
        }
    }

    /**
     * 간편결제 수단의 이름·설명이 관리자 결제수단 화면이 쓰는 언어팩 문구와 일치하는지 확인합니다.
     *
     * @scenario contract=display_labels
     *
     * @effects easy_pay_labels_match_language_pack
     *
     * @return void 반환값 없음
     */
    public function test_easy_pay_method_labels_match_admin_payment_method_names(): void
    {
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'nhnkcp_naverpay');
        $payco = collect($methods)->firstWhere('id', 'nhnkcp_payco');
        $applepay = collect($methods)->firstWhere('id', 'nhnkcp_applepay');

        $this->assertSame('네이버페이 (카드)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('PAYCO', $payco['name']['ko'] ?? null);
        $this->assertSame('Pay by Naver Pay credit card (NHN KCP)', $naverpay['description']['en'] ?? null);
        $this->assertSame(
            '애플페이로 결제 (NHN KCP) · 애플페이는 IOS 기기에 모바일결제만 가능합니다.',
            $applepay['description']['ko'] ?? null,
        );
    }

    /**
     * 네이버페이·카카오페이가 색 배지 형태의 브랜드 마크(text·class)를 등록 데이터로 싣는지 확인합니다.
     *
     * @scenario mark_form=badge, requires_ios=false, device=ios
     *
     * @effects brand_mark_flows_to_cached, badge_renders_text_and_color
     *
     * @return void 반환값 없음
     */
    public function test_easy_pay_methods_carry_badge_brand_mark(): void
    {
        // 브랜드 마크(색 배지)를 카탈로그로 편입 — 과거 checkoutEasyPayInjector 가
        // DOM 후처리로 주입하던 markText/markClassName 을 등록 데이터로 이관.
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'nhnkcp_naverpay');
        $kakaopay = collect($methods)->firstWhere('id', 'nhnkcp_kakaopay');

        $this->assertSame(['text' => 'N', 'class' => 'bg-green-500 text-white'], $naverpay['brand_mark'] ?? null);
        $this->assertSame(['text' => 'K', 'class' => 'bg-yellow-400 text-gray-950'], $kakaopay['brand_mark'] ?? null);
    }

    /**
     * 애플페이만 iOS 전용 노출 플래그(requires_ios)를 갖고 다른 간편결제에는 그 키가 없는지 확인합니다.
     *
     * @scenario mark_form=badge, requires_ios=true, device=ipados_desktop_ua
     *
     * @effects requires_ios_flag_carried
     *
     * @return void 반환값 없음
     */
    public function test_only_apple_pay_requires_ios(): void
    {
        // 애플페이만 iOS 전용 노출 플래그를 가진다(비-iOS 기기에서 체크아웃 레이아웃이 숨김).
        $listener = new RegisterEasyPayMethodsListener;

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $applepay = collect($methods)->firstWhere('id', 'nhnkcp_applepay');
        $naverpay = collect($methods)->firstWhere('id', 'nhnkcp_naverpay');

        $this->assertTrue($applepay['requires_ios'] ?? false);
        $this->assertArrayNotHasKey('requires_ios', $naverpay);
    }

    /**
     * 브랜드 마크를 카탈로그로 편입하면서 체크아웃 DOM 인젝터를 제거했다.
     * 인젝터 파일 부재 + index.ts 에 install 호출 부재를 구조로 확인한다.
     * 결제 시점 방어(applePayDevice) 는 잔존한다.
     *
     * @scenario mark_form=badge, requires_ios=true, device=ios
     *
     * @effects injectors_removed, shared_helpers_preserved
     *
     * @return void 반환값 없음
     */
    public function test_checkout_easy_pay_injector_removed_but_shared_helpers_preserved(): void
    {
        $jsDir = base_path('plugins/_bundled/sirsoft-pay_nhnkcp/resources/js');

        // 브랜드 인젝터 제거됨.
        $this->assertFileDoesNotExist($jsDir.'/checkoutEasyPayInjector.ts');

        // index.ts 에 install 호출 부재.
        $indexTs = file_get_contents($jsDir.'/index.ts');
        $this->assertStringNotContainsString('installCheckoutEasyPayInjector', $indexTs);

        // 결제 시점 방어 헬퍼(applePayDevice)는 잔존 — requestPayment/setPaymentMethod 가 사용.
        $this->assertFileExists($jsDir.'/support/applePayDevice.ts');
    }
}
