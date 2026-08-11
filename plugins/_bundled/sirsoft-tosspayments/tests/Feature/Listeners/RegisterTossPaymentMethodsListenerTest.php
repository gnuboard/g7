<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterTossPaymentMethodsListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * RegisterTossPaymentMethodsListener 테스트.
 *
 * order_sheet_mode / 결제수단 토글 / 삽입 위치 / core_payment_method 선언을 검증한다.
 *
 * @scenario order_sheet_mode=true, payment_method=toss_card
 *
 * @effects enabled_methods_exposed_with_core_mapping
 */
class RegisterTossPaymentMethodsListenerTest extends PluginTestCase
{
    private RegisterTossPaymentMethodsListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new RegisterTossPaymentMethodsListener;
    }

    /**
     * 플러그인 설정을 mock 으로 주입합니다.
     *
     * @param  array<string, mixed>  $settings
     */
    private function mockSettings(array $settings): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn($settings);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * builtin 결제수단 배열 (phone / point 포함).
     *
     * @return array<int, array<string, mixed>>
     */
    private function builtinMethods(): array
    {
        return [
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'point'],
        ];
    }

    public function test_subscribes_as_filter_with_priority_20(): void
    {
        $hooks = RegisterTossPaymentMethodsListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.settings.filter_available_payment_methods', $hooks);
        $hook = $hooks['sirsoft-ecommerce.settings.filter_available_payment_methods'];
        $this->assertSame('filter', $hook['type']);
        $this->assertSame(20, $hook['priority']);
    }

    public function test_order_sheet_mode_off_injects_nothing(): void
    {
        $this->mockSettings(['order_sheet_mode' => false, 'method_card' => true]);

        $result = $this->listener->injectTossMethods($this->builtinMethods());

        $this->assertCount(3, $result);
        $this->assertSame(['card', 'phone', 'point'], array_column($result, 'id'));
    }

    public function test_injects_only_enabled_methods(): void
    {
        $this->mockSettings([
            'order_sheet_mode' => true,
            'method_card' => true,
            'method_virtual_account' => true,
            'method_transfer' => false,
        ]);

        $result = $this->listener->injectTossMethods($this->builtinMethods());
        $ids = array_column($result, 'id');

        $this->assertContains('toss_card', $ids);
        $this->assertContains('toss_virtual_account', $ids);
        $this->assertNotContains('toss_transfer', $ids);
    }

    public function test_inserts_after_phone_before_point(): void
    {
        $this->mockSettings(['order_sheet_mode' => true, 'method_card' => true]);

        $result = $this->listener->injectTossMethods($this->builtinMethods());
        $ids = array_column($result, 'id');

        $phoneIndex = array_search('phone', $ids, true);
        $pointIndex = array_search('point', $ids, true);
        $tossIndex = array_search('toss_card', $ids, true);

        $this->assertGreaterThan($phoneIndex, $tossIndex);
        $this->assertLessThan($pointIndex, $tossIndex);
    }

    public function test_appends_when_phone_absent(): void
    {
        $this->mockSettings(['order_sheet_mode' => true, 'method_card' => true]);

        $result = $this->listener->injectTossMethods([['id' => 'card'], ['id' => 'point']]);
        $ids = array_column($result, 'id');

        // phone 이 없으면 끝에 append
        $this->assertSame('toss_card', end($ids));
    }

    public function test_entry_declares_core_payment_method(): void
    {
        $this->mockSettings([
            'order_sheet_mode' => true,
            'method_virtual_account' => true,
            'method_kakaopay' => true,
        ]);

        $result = $this->listener->injectTossMethods($this->builtinMethods());
        $byId = collect($result)->keyBy('id');

        // 가상계좌 → vbank, 간편결제 → card
        $this->assertSame('vbank', $byId['toss_virtual_account']['defaults']['core_payment_method']);
        $this->assertSame('card', $byId['toss_kakaopay']['defaults']['core_payment_method']);
        // PG 는 토스로 고정된다 (#475 전환). 과거에는 null 이었고 그것이 관리자 화면의
        // 빈 PG 셀렉트와 서버측 "PG 없는 결제수단" 오인의 원인이었다.
        $this->assertSame('tosspayments', $byId['toss_virtual_account']['defaults']['pg_provider']);
    }

    public function test_all_toggles_off_injects_nothing(): void
    {
        $this->mockSettings(['order_sheet_mode' => true]);

        $result = $this->listener->injectTossMethods($this->builtinMethods());

        $this->assertCount(3, $result);
    }

    /**
     * 모든 토스 결제수단은 PG 를 자기 자신으로 고정 선언해야 한다 (#475 전환 누락 회귀).
     *
     * 배경: #475 가 확장 결제수단에 pg_locked/needs_pg/refund_method 선언을 도입할 때
     * 토스는 3일 먼저 들어와 있었음에도 전환 대상에서 빠졌다. 그 결과 pg_provider 가 null 인
     * 채로 needs_pg 만 참이 되어, 관리자 화면이 "PG 고정" 배지 대신 **선택지가 하나도 없는**
     * PG 셀렉트를 그렸다(토스 PG 는 toss_* id 를 supported_methods 로 선언하지 않는다).
     * 서버 쪽으로도 PG 없는 결제수단으로 오인되어 결제 실패 시 임시주문이 삭제될 수 있었다.
     */
    public function test_all_methods_declare_pg_locked_to_self(): void
    {
        $this->mockSettings([
            'order_sheet_mode' => true,
            'method_card' => true,
            'method_virtual_account' => true,
            'method_transfer' => true,
            'method_mobile_phone' => true,
            'method_tosspay' => true,
            'method_kakaopay' => true,
            'method_naverpay' => true,
            'method_payco' => true,
            'method_samsungpay' => true,
        ]);

        $injected = collect($this->listener->injectTossMethods($this->builtinMethods()))
            ->filter(fn ($m) => str_starts_with($m['id'] ?? '', 'toss_'));

        $this->assertGreaterThan(0, $injected->count(), '토스 결제수단이 주입되지 않았다');

        foreach ($injected as $method) {
            $defaults = $method['defaults'] ?? [];
            $id = $method['id'];

            $this->assertSame('tosspayments', $defaults['pg_provider'] ?? null, "{$id}: PG 미고정");
            $this->assertTrue($defaults['pg_locked'] ?? false, "{$id}: pg_locked 미선언 — 관리자가 다른 PG 로 바꿀 수 있게 된다");
            $this->assertTrue($defaults['needs_pg'] ?? false, "{$id}: needs_pg 미선언");
            $this->assertSame('pg', $defaults['refund_method'] ?? null, "{$id}: refund_method 미선언");
        }
    }

    /**
     * 간편결제 수단은 브랜드 마크를 선언해야 한다.
     *
     * 회귀 배경: 토스 수단만 brand_mark 를 선언하지 않아, 이커머스 병합부가
     * `_cached_brand_mark` 를 null 로 채우고 관리자·체크아웃 화면이 브랜드 배지 대신
     * 회색 기본 아이콘을 그렸다. 같은 브랜드가 다른 PG 를 통할 때와 다르게 보였다.
     *
     * 같은 브랜드는 어느 PG 를 통하든 같은 배지여야 하므로 text/class 를 함께 고정한다.
     */
    public function test_easy_pay_methods_declare_brand_mark_consistent_with_other_pg(): void
    {
        $this->mockSettings([
            'order_sheet_mode' => true,
            'method_tosspay' => true,
            'method_kakaopay' => true,
            'method_naverpay' => true,
            'method_payco' => true,
            'method_samsungpay' => true,
        ]);

        $byId = collect($this->listener->injectTossMethods($this->builtinMethods()))->keyBy('id');

        // 다른 PG 플러그인(nicepayments·nhnkcp)이 같은 브랜드에 쓰는 값과 동일해야 한다.
        $expected = [
            'toss_naverpay' => ['text' => 'N', 'class' => 'bg-green-500 text-white'],
            'toss_kakaopay' => ['text' => 'K', 'class' => 'bg-yellow-400 text-gray-950'],
            'toss_samsungpay' => ['text' => 'S', 'class' => 'bg-blue-600 text-white'],
            'toss_payco' => ['text' => 'P', 'class' => 'bg-red-500 text-white'],
            'toss_tosspay' => ['text' => 'T', 'class' => 'bg-blue-500 text-white'],
        ];

        foreach ($expected as $id => $mark) {
            $this->assertArrayHasKey($id, $byId, "{$id} 가 주입되지 않았다");
            $this->assertSame(
                $mark,
                $byId[$id]['brand_mark'] ?? null,
                "{$id} 의 브랜드 마크가 다른 PG 와 다르다 — 같은 브랜드가 PG 마다 달라 보인다"
            );
        }
    }

    /**
     * 카드·가상계좌·계좌이체·휴대폰은 브랜드가 아니라 결제 형태이므로 마크를 두지 않는다.
     * (코어 빌트인 수단과 동일한 표현)
     */
    public function test_non_brand_methods_have_no_brand_mark(): void
    {
        $this->mockSettings([
            'order_sheet_mode' => true,
            'method_card' => true,
            'method_virtual_account' => true,
            'method_transfer' => true,
            'method_mobile_phone' => true,
        ]);

        $byId = collect($this->listener->injectTossMethods($this->builtinMethods()))->keyBy('id');

        foreach (['toss_card', 'toss_virtual_account', 'toss_transfer', 'toss_mobile_phone'] as $id) {
            $this->assertArrayHasKey($id, $byId, "{$id} 가 주입되지 않았다");
            $this->assertArrayNotHasKey('brand_mark', $byId[$id], "{$id} 는 브랜드 수단이 아니다");
        }
    }

    /**
     * 고정한 PG 식별자는 이 플러그인이 등록하는 provider id 와 같아야 한다.
     *
     * 어긋나면 관리자 화면의 PG 고정 배지가 표시명을 찾지 못해 raw id 를 그대로 노출하고,
     * 서버의 결제 진입 핸들러 조회(resolvePgPaymentHandler)도 실패한다. 두 리스너가 문자열을
     * 각자 들고 있으므로 한쪽만 바뀌는 것을 테스트가 막는다.
     *
     * @effects locked_pg_id_matches_registered_provider
     */
    public function test_locked_pg_id_matches_registered_provider_id(): void
    {
        $this->mockSettings(['order_sheet_mode' => true, 'method_card' => true]);

        $registered = (new RegisterPgProviderListener)->registerProvider([]);
        $providerIds = array_column($registered, 'id');

        $result = $this->listener->injectTossMethods($this->builtinMethods());
        $tossCard = collect($result)->keyBy('id')['toss_card'];

        $this->assertContains($tossCard['defaults']['pg_provider'], $providerIds);
    }

    /**
     * 다른 PG 플러그인이 먼저 등록한 결제수단의 선언을 덮지 않는다.
     *
     * 각 플러그인은 자기 수단의 PG 만 고정한다. 훅 체인에서 뒤에 실행되는 플러그인이 앞선
     * 선언을 건드리면 동시 활성 상점에서 한쪽 PG 표시가 조용히 뒤바뀐다.
     *
     * @scenario kg_coexists=true
     *
     * @effects other_plugin_methods_untouched_by_toss_injection
     */
    public function test_does_not_touch_other_plugin_methods(): void
    {
        $this->mockSettings(['order_sheet_mode' => true, 'method_card' => true]);

        $kgEntry = [
            'id' => 'kginicis_naverpay',
            'source' => 'plugin:sirsoft-pay_kginicis',
            'defaults' => ['pg_provider' => 'kginicis', 'pg_locked' => true, 'needs_pg' => true],
        ];

        $result = $this->listener->injectTossMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            $kgEntry,
            ['id' => 'point'],
        ]);

        $byId = collect($result)->keyBy('id');

        $this->assertSame($kgEntry, $byId['kginicis_naverpay']);
        $this->assertSame('tosspayments', $byId['toss_card']['defaults']['pg_provider']);
    }
}
