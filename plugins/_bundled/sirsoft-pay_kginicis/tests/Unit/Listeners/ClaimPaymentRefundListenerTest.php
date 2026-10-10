<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayKginicis\Listeners\ClaimPaymentRefundListener;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 클레임 추가결제 환급 리스너 단위 테스트
 *
 * 전액(refund)/부분(partialRefund) 분기와 부분취소 잔액(confirmPrice) 산출,
 * 필터 계약을 검증한다.
 */
class ClaimPaymentRefundListenerTest extends PluginTestCase
{
    private function mockPluginSettings(): void
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => '2IgsAQSbMqHkAkj3',
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
    }

    private function makePaidPayment(array $overrides = []): ClaimPayment
    {
        return ClaimPaymentFactory::new()->create(array_merge([
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'amount' => 3000,
            'transaction_id' => 'StdpayCARDINIpayTest20260824000000001',
            'raw_response' => ['payMethod' => 'Card', 'mid' => 'INIpayTest'],
            'paid_at' => now(),
        ], $overrides));
    }

    private function fakeCancelApi(): void
    {
        Http::fake([
            'stginiapi.inicis.com/*' => Http::response(['resultCode' => '00', 'resultMsg' => 'OK', 'tid' => 'CANCEL-TID-1'], 200),
            'iniapi.inicis.com/*' => Http::response(['resultCode' => '00', 'resultMsg' => 'OK', 'tid' => 'CANCEL-TID-1'], 200),
        ]);
    }

    private function defaultFilterValue(): array
    {
        return ['success' => false, 'handled' => false, 'transaction_id' => null, 'error_code' => null, 'error_message' => null];
    }

    public function test_get_subscribed_hooks_returns_a_filter_mapping(): void
    {
        $hooks = ClaimPaymentRefundListener::getSubscribedHooks();

        $this->assertArrayHasKey('sirsoft-ecommerce.claim_payment.refund', $hooks);
        $this->assertSame('filter', $hooks['sirsoft-ecommerce.claim_payment.refund']['type']);
    }

    public function test_skips_payments_of_other_providers(): void
    {
        $payment = $this->makePaidPayment(['pg_provider' => 'nicepayments']);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['handled']);
    }

    public function test_missing_tid_is_rejected(): void
    {
        $payment = $this->makePaidPayment(['transaction_id' => null]);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-1-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('MISSING_TID', $result['error_code']);
    }

    public function test_full_refund_calls_the_refund_endpoint(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        $payment = $this->makePaidPayment();

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, '철회 환급', 'g7-claimpay-9-0-300000');

        $this->assertTrue($result['success']);
        $this->assertSame('CANCEL-TID-1', $result['transaction_id']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/pg/refund'));
    }

    public function test_partial_refund_calls_the_partial_endpoint_with_the_remaining_balance(): void
    {
        $this->mockPluginSettings();
        $this->fakeCancelApi();
        // 이미 500 환급된 3,000 결제 건의 1,000 부분 환급 — 취소 후 잔액(confirmPrice)은
        // (3000 − 500) − 1000 = 1500 이어야 한다.
        $payment = $this->makePaidPayment(['refunded_amount' => 500]);

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 1000.0, '차액 환급', 'g7-claimpay-9-50000-100000');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/pg/partialRefund')) {
                return false;
            }

            $data = $request->data()['data'] ?? [];

            return ($data['price'] ?? null) === '1000' && ($data['confirmPrice'] ?? null) === '1500';
        });
    }

    public function test_pg_api_error_returns_a_failure_result(): void
    {
        $this->mockPluginSettings();
        Http::fake([
            'stginiapi.inicis.com/*' => Http::response(['resultCode' => '01', 'resultMsg' => '취소 불가'], 200),
            'iniapi.inicis.com/*' => Http::response(['resultCode' => '01', 'resultMsg' => '취소 불가'], 200),
        ]);
        $payment = $this->makePaidPayment();

        $result = (new ClaimPaymentRefundListener)->processRefund($this->defaultFilterValue(), $payment, 3000.0, null, 'g7-claimpay-9-0-300000');

        $this->assertFalse($result['success']);
        $this->assertSame('PG_API_ERROR', $result['error_code']);
    }
}
