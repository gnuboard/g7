<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature\Listeners;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterPgProviderListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * RegisterPgProviderListener::getClientConfig 확장 테스트.
 *
 * order_sheet_mode / enabled_methods / vbank / use_escrow 프론트 노출을 검증한다.
 *
 * @effects enabled_methods_exposed_with_core_mapping
 */
class RegisterPgProviderClientConfigTest extends PluginTestCase
{
    private RegisterPgProviderListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new RegisterPgProviderListener;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function mockSettings(array $settings): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturn($settings);
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    public function test_order_sheet_off_returns_empty_enabled_methods(): void
    {
        $this->mockSettings([
            'is_test_mode' => true,
            'test_client_key' => 'ck',
            'order_sheet_mode' => false,
            'method_card' => true,
        ]);

        $config = $this->listener->getClientConfig([], 'tosspayments');

        $this->assertFalse($config['order_sheet_mode']);
        $this->assertSame([], $config['enabled_methods']);
    }

    public function test_order_sheet_on_returns_enabled_methods_with_mapping(): void
    {
        $this->mockSettings([
            'is_test_mode' => true,
            'test_client_key' => 'ck',
            'order_sheet_mode' => true,
            'method_card' => true,
            'method_virtual_account' => true,
            'method_kakaopay' => true,
        ]);

        $config = $this->listener->getClientConfig([], 'tosspayments');
        $byId = collect($config['enabled_methods'])->keyBy('id');

        $this->assertTrue($config['order_sheet_mode']);
        $this->assertSame('CARD', $byId['toss_card']['method']);
        $this->assertNull($byId['toss_card']['easy_pay_provider']);
        $this->assertSame('VIRTUAL_ACCOUNT', $byId['toss_virtual_account']['method']);
        $this->assertSame('vbank', $byId['toss_virtual_account']['core_payment_method']);
        // 간편결제 → CARD + easyPay provider
        $this->assertSame('CARD', $byId['toss_kakaopay']['method']);
        $this->assertSame('카카오페이', $byId['toss_kakaopay']['easy_pay_provider']);
        $this->assertSame('card', $byId['toss_kakaopay']['core_payment_method']);
    }

    public function test_exposes_vbank_and_escrow_config(): void
    {
        $this->mockSettings([
            'is_test_mode' => true,
            'test_client_key' => 'ck',
            'vbank_valid_hours' => 48,
            'vbank_cash_receipt_type' => '소득공제',
            'use_escrow' => 'buyer_choice',
        ]);

        $config = $this->listener->getClientConfig([], 'tosspayments');

        $this->assertSame(48, $config['vbank']['valid_hours']);
        $this->assertSame('소득공제', $config['vbank']['cash_receipt_type']);
        $this->assertSame('buyer_choice', $config['use_escrow']);
    }

    public function test_does_not_leak_secret_key(): void
    {
        $this->mockSettings([
            'is_test_mode' => true,
            'test_client_key' => 'ck_public',
            'test_secret_key' => 'sk_secret_should_not_leak',
        ]);

        $config = $this->listener->getClientConfig([], 'tosspayments');

        $flattened = json_encode($config);
        $this->assertStringNotContainsString('sk_secret_should_not_leak', $flattened);
        $this->assertSame('ck_public', $config['client_key']);
    }
}
