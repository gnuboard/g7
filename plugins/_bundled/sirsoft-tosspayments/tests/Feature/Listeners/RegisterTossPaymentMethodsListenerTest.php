<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Listeners;

use App\Services\PluginSettingsService;
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
        // PG 선택 불필요
        $this->assertNull($byId['toss_virtual_account']['defaults']['pg_provider']);
    }

    public function test_all_toggles_off_injects_nothing(): void
    {
        $this->mockSettings(['order_sheet_mode' => true]);

        $result = $this->listener->injectTossMethods($this->builtinMethods());

        $this->assertCount(3, $result);
    }
}
