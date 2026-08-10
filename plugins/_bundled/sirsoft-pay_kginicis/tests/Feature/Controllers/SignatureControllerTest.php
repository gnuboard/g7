<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileSignatureRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\PaymentCloseReportRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\SignatureRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\PaymentLimits;
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

    /**
     * 결제 금액 하한이 세 요청 경로에서 동일한지 확인합니다 (#493 E9).
     *
     * 같은 필드에 PC 는 min:100, 모바일은 min:1 이 걸려 있어, 모바일에서 성립한 결제가 PC 에서는
     * 거부됐습니다. 하한이 다시 갈라지면 이 테스트가 먼저 깨집니다.
     *
     * @scenario channel=all, price_boundary=rule_parity
     *
     * @effects price_lower_bound_identical_across_request_paths
     */
    public function test_price_lower_bound_is_shared_across_signature_requests(): void
    {
        $expected = 'min:'.PaymentLimits::MIN_PRICE;

        foreach ([SignatureRequest::class, MobileSignatureRequest::class, PaymentCloseReportRequest::class] as $class) {
            $rules = (new $class)->rules()['price'];

            $this->assertContains(
                $expected,
                $rules,
                $class.' 의 price 하한이 공용 상수를 읽지 않습니다 — 경로마다 하한이 갈라집니다.'
            );
        }
    }

    /**
     * 최소 화폐단위(1원) 결제도 PC 서명 요청이 받아들이는지 확인합니다 (#493 E9).
     *
     * @scenario channel=pc, price_boundary=minimum
     *
     * @effects minimum_price_accepted_on_pc
     */
    public function test_pc_signature_accepts_minimum_price(): void
    {
        $order = $this->makePendingOrder('ORD-SIGN-MIN', PaymentLimits::MIN_PRICE);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.com',
            'orderer_phone' => '010-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-SIGN-MIN')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('hasStandardPaymentCredentials')->andReturnTrue();
        $apiService->shouldReceive('generateSignature')->andReturn('signature-ok');
        $apiService->shouldReceive('generateVerification')->andReturn('verification-ok');
        $apiService->shouldReceive('getMKey')->andReturn('mkey-ok');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-MIN',
            'price' => PaymentLimits::MIN_PRICE,
            'timestamp' => $this->freshEpochMs(),
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ])->assertOk();
    }

    /**
     * 최소 화폐단위(1원) 결제를 모바일 서명 요청도 받아들이는지 확인합니다 (#493 E9).
     *
     * 하한 통일의 근거가 "모바일에서 이미 1원 결제가 성립 중" 이었다. 그 전제를 PC 왕복만
     * 으로 가드하면, 모바일 경로가 조용히 100 원으로 되돌아가도 아무 테스트도 red 가 되지
     * 않는다 — 통일로 지키려던 바로 그 결제가 깨진다.
     *
     * @scenario channel=mobile, price_boundary=minimum
     *
     * @effects minimum_price_accepted_on_mobile
     */
    public function test_mobile_signature_accepts_minimum_price(): void
    {
        $order = $this->makePendingOrder('ORD-MSIGN-MIN', PaymentLimits::MIN_PRICE);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('ORD-MSIGN-MIN')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('hasMobilePaymentCredentials')->andReturnTrue();
        $apiService->shouldReceive('generateMobileChkfake')->andReturn('chkfake-ok');
        $apiService->shouldReceive('getMobilePaymentUrl')->andReturn('https://mobile.inicis.test/pay');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature', [
            'oid' => 'ORD-MSIGN-MIN',
            'price' => PaymentLimits::MIN_PRICE,
            'timestamp' => $this->freshEpochMs(),
        ])->assertOk();
    }

    /**
     * 모바일 서명 요청도 같은 하한 경계를 실제 요청에서 지키는지 확인합니다 (#493 E9).
     *
     * 규칙 문자열 대조(위 parity 테스트)는 세 Request 가 같은 상수를 읽는 것까지만 보장한다.
     * 그 규칙이 모바일 라우트에도 실제로 걸리는지는 왕복으로만 알 수 있다.
     *
     * @scenario channel=mobile, price_boundary=zero
     *
     * @effects zero_price_rejected_422
     */
    public function test_mobile_signature_rejects_zero_price(): void
    {
        $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/mobile/signature', [
            'oid' => 'ORD-MSIGN-ZERO',
            'price' => 0,
            'timestamp' => $this->freshEpochMs(),
        ])->assertStatus(422);
    }

    /**
     * 0 원 결제 요청은 거부되는지 확인합니다 (하한 통일이 하한 제거가 아님).
     *
     * @scenario channel=pc, price_boundary=zero
     *
     * @effects zero_price_rejected_422
     */
    public function test_pc_signature_rejects_zero_price(): void
    {
        $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/signature', [
            'oid' => 'ORD-SIGN-ZERO',
            'price' => 0,
            'timestamp' => $this->freshEpochMs(),
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01012345678',
        ])->assertStatus(422);
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
