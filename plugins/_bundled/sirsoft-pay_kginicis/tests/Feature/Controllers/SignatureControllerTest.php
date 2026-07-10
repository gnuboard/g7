<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class SignatureControllerTest extends PluginTestCase
{
    public function test_pc_signature_accepts_matching_order_context(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-001', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-001')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('hasStandardPaymentCredentials')->andReturnTrue();
        $apiService->shouldReceive('generateSignature')
            ->with('ORD-SIGN-001', 10000, Mockery::type('string'))
            ->andReturn('signature-ok');
        $apiService->shouldReceive('generateVerification')
            ->with('ORD-SIGN-001', 10000, Mockery::type('string'))
            ->andReturn('verification-ok');
        $apiService->shouldReceive('getMKey')->andReturn('mkey-ok');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-001',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
            'buyer_email' => 'BUYER@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.signature', 'signature-ok')
            ->assertJsonPath('data.verification', 'verification-ok')
            ->assertJsonPath('data.mKey', 'mkey-ok');
    }

    public function test_pc_signature_rejects_amount_mismatch(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-002', 10000);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-002')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('generateSignature');
        $apiService->shouldNotReceive('generateVerification');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-002',
            'price' => 9000,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Payment amount does not match the order amount.');
    }

    public function test_pc_signature_rejects_unchargeable_payment_currency_without_server_error(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-CURRENCY-001', 10000, 'KRW', self::unchargeableKrwCurrencySnapshot());

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-CURRENCY-001')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('hasStandardPaymentCredentials');
        $apiService->shouldNotReceive('generateSignature');
        $apiService->shouldNotReceive('generateVerification');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-CURRENCY-001',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Payment currency is not chargeable.');
    }

    public function test_pc_signature_rejects_non_krw_order(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-USD-001', 10000, 'USD');

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-USD-001')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('generateSignature');
        $apiService->shouldNotReceive('generateVerification');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-USD-001',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Standard KG Inicis signature is only available for KRW orders.');
    }

    public function test_pc_signature_rejects_order_buyer_mismatch(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-003', 10000);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-003')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('generateSignature');
        $apiService->shouldNotReceive('generateVerification');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-003',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
            'buyer_email' => 'attacker@example.com',
            'buyer_phone' => '01012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Order buyer verification failed.');
    }

    public function test_pc_signature_rejects_missing_standard_credentials(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-NOCFG-001', 10000);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-NOCFG-001')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('hasStandardPaymentCredentials')->andReturnFalse();
        $apiService->shouldNotReceive('generateSignature');
        $apiService->shouldNotReceive('generateVerification');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-NOCFG-001',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'KG Inicis standard payment credentials are not configured.');
    }

    public function test_mobile_signature_rejects_non_krw_order(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-004', 100, 'JPY');

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-004')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('generateMobileChkfake');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature', [
            'oid' => 'ORD-SIGN-004',
            'price' => 100,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Standard KG Inicis signature is only available for KRW orders.');
    }

    public function test_mobile_signature_rejects_missing_mobile_credentials(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-NOCFG-002', 10000);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-NOCFG-002')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('hasMobilePaymentCredentials')->andReturnFalse();
        $apiService->shouldNotReceive('generateMobileChkfake');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature', [
            'oid' => 'ORD-SIGN-NOCFG-002',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'KG Inicis mobile payment credentials are not configured.');
    }

    public function test_mobile_signature_rejects_unchargeable_payment_currency_without_server_error(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-CURRENCY-002', 10000, 'KRW', self::unchargeableKrwCurrencySnapshot());

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-CURRENCY-002')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('hasMobilePaymentCredentials');
        $apiService->shouldNotReceive('generateMobileChkfake');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature', [
            'oid' => 'ORD-SIGN-CURRENCY-002',
            'price' => 10000,
            'timestamp' => $this->freshEpochMs(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Payment currency is not chargeable.');
    }

    public function test_pc_signature_expects_converted_price_for_non_base_order_currency(): void
    {
        // base=USD, 결제통화=KRW. base $6 → KRW 7058 환산이 검증 기준(price)이어야 한다.
        $order = $this->makePendingOrder('ORD-SIGN-CUR', 6, 'KRW', [
            'base_currency' => 'USD',
            'order_currency' => 'KRW',
            'exchange_rates' => [
                'KRW' => ['rate' => 1176470, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0],
                'USD' => ['rate' => 1, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2],
            ],
        ]);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')->with('ORD-SIGN-CUR')->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        // 표준결제 자격증명 가드(hasStandardPaymentCredentials)와 통화 환산 검증이 같은 흐름에
        // 공존한다. 환산액 경로는 자격 검사까지 도달하므로 mock 이 필요하다.
        $apiService->shouldReceive('hasStandardPaymentCredentials')->andReturnTrue();
        $apiService->shouldReceive('generateSignature')->with('ORD-SIGN-CUR', 7058, Mockery::type('string'))->andReturn('sig');
        $apiService->shouldReceive('generateVerification')->with('ORD-SIGN-CUR', 7058, Mockery::type('string'))->andReturn('ver');
        $apiService->shouldReceive('getMKey')->andReturn('mkey');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        // 환산액(7058) 으로 보내면 통과
        $ok = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-CUR',
            'price' => 7058,
            'timestamp' => $this->freshEpochMs(),
        ]);
        $ok->assertOk()->assertJsonPath('data.signature', 'sig');

        // base 정수(6) 로 보내면 불일치 422 (옛 버그 회귀 차단)
        $bad = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-CUR',
            'price' => 6,
            'timestamp' => $this->freshEpochMs(),
        ]);
        $bad->assertStatus(422);
    }

    private function makePendingOrder(string $orderNumber, int $amount, string $currency = 'KRW', array $currencySnapshot = []): Order
    {
        $order = new Order;
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = $currency;
        $order->total_due_amount = $amount;
        $order->currency_snapshot = $currencySnapshot !== []
            ? $currencySnapshot
            : self::currencySnapshotFor($currency);

        return $order;
    }

    private function freshEpochMs(): string
    {
        return (string) round(microtime(true) * 1000);
    }
}
