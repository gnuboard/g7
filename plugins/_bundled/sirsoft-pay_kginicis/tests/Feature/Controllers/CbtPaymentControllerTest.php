<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature\Controllers;

use Mockery;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Repositories\CbtCvsOperationsRepositoryInterface;
use Plugins\Sirsoft\PayKginicis\Services\CbtCvsOperationsService;
use Plugins\Sirsoft\PayKginicis\Services\CbtCheckoutTokenService;
use Plugins\Sirsoft\PayKginicis\Services\CbtReconciliationService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

class CbtPaymentControllerTest extends PluginTestCase
{
    public function test_cbt_callback_accepts_manual_ok_result_code_and_completes_payment(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-001', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-001')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->with($order, Mockery::on(function (array $paymentData): bool {
                $meta = $paymentData['payment_meta'] ?? [];

                return $paymentData['transaction_id'] === 'CBT_TID_001'
                    && $paymentData['card_approval_number'] === 'APPROVE1'
                    && ($meta['is_cbt'] ?? false) === true
                    && ($meta['cbt_mid'] ?? '') === KgInicisApiService::JAPAN_TEST_MID
                    && ($meta['cbt_sid'] ?? '') === 'SID001'
                    && ($meta['pay_method'] ?? '') === 'CARD'
                    && ($meta['selected_payment_method'] ?? '') === 'card';
            }), 100)
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldNotReceive('refundCbtPayment');
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID001')
            ->andReturn([
                'resultCode' => 'OK',
                'resultMsg' => 'SUCCESS',
                'tid' => 'CBT_TID_001',
                'paymethod' => 'CARD',
                'approve' => 'APPROVE1',
                'amount' => '100',
            ]);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-001',
                'sid' => 'SID001',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'CARD',
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-001/complete');
    }

    public function test_cbt_callback_for_cvs_marks_payment_waiting_deposit(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CVS-001', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-CVS-001')
            ->andReturn($order);
        $orderService->shouldNotReceive('completePayment');

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldNotReceive('refundCbtPayment');
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID-CVS-001')
            ->andReturn([
                'resultCode' => 'OK',
                'resultMsg' => 'SUCCESS',
                'tid' => 'CBT_CVS_TID_001',
                'paymethod' => 'CVS',
                'amount' => 100,
                'convenience' => '00007',
                'confNo' => '999999999999999999',
                'receiptNo' => '1634795292905',
                'paymentTerm' => '20260530235959',
            ]);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-CVS-001',
                'sid' => 'SID-CVS-001',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'CVS',
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-CVS-001/complete');

        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertSame('CBT_CVS_TID_001', $payment->transaction_id);
        $this->assertSame('CVS', $payment->payment_meta['pay_method'] ?? null);
        $this->assertSame('kginicis_japan_cvs', $payment->payment_meta['selected_payment_method'] ?? null);
        $this->assertSame('00007', $payment->payment_meta['cvs_convenience'] ?? null);
        $this->assertSame('999999999999999999', $payment->vbank_number);
    }

    public function test_cbt_callback_user_cancel_returns_to_checkout_without_error_query(): void
    {
        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldNotReceive('findByOrderNumber');
        $orderService->shouldNotReceive('failPayment');

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldNotReceive('approveCbtPayment');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-CANCEL-001',
                'sid' => 'SID-CANCEL-001',
                'resultCode' => 'FAIL',
                'resultMsg' => 'ユーザーキャンセル',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'CARD',
            ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/shop/checkout', $location);
        $this->assertStringNotContainsString('error=', $location, '사용자 취소는 error 쿼리 미부착으로 모달 미노출');
        $this->assertStringNotContainsString('message=', $location, '사용자 취소는 message 쿼리 미부착');
        $this->assertStringNotContainsString('ユーザーキャンセル', $location, '사용자 취소 문구를 체크아웃 URL에 노출하지 않음');
    }

    public function test_cbt_callback_paypay_processing_failure_hides_upstream_message_from_checkout(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-PAYPAY-FAIL-001', 100);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldNotReceive('approveCbtPayment');

        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-PAYPAY-FAIL-001',
                'sid' => 'SID-PAYPAY-FAIL-001',
                'resultCode' => 'processing_failure',
                'resultMsg' => 'Processing failed upstream.',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'PAYpay',
                'selectedPaymentMethod' => 'kginicis_japan_paypay',
            ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $this->assertStringContainsString('/shop/checkout', $location);
        $this->assertSame('paypay_processing_failed', $query['error'] ?? null);
        $this->assertSame('PayPay 결제를 완료하지 못했습니다. 다시 시도하거나 다른 결제수단을 선택해 주세요.', $query['message'] ?? null);
        $this->assertSame('JP-ORDER-PAYPAY-FAIL-001', $query['orderId'] ?? null);
        $this->assertStringNotContainsString('processing_failure', parse_url($location, PHP_URL_QUERY) ?: '');
        $this->assertStringNotContainsString('Processing+failed+upstream', $location);
        $this->assertStringNotContainsString('Processing%20failed%20upstream', $location);

        $order->refresh();
        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $paymentMeta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertArrayNotHasKey('pay_method', $paymentMeta);
        $this->assertArrayNotHasKey('selected_payment_method', $paymentMeta);
        $this->assertArrayNotHasKey('result_code', $paymentMeta);
    }

    public function test_cbt_cvs_notify_completes_waiting_deposit_payment(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CVS-002', 100);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => 'CBT_CVS_TID_002',
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => 'SID-CVS-002',
                'is_test_mode' => true,
                'pay_method' => 'CVS',
            ],
        ]);

        $response = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', [
            'tid' => 'CBT_CVS_TID_002',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'applDt' => '20260521',
            'applTm' => '120000',
            'status' => '00',
            'payNm' => 'CBT',
            'orderId' => 'JP-ORDER-CVS-002',
            'applNo' => 'APP-CVS',
            'sid' => 'SID-CVS-002',
            'convenience' => '00007',
            'confNo' => '999999999999999999',
            'receiptNo' => '1634795292905',
            'paymentTerm' => '20260530235959',
            'amount' => '100',
            'currencyCd' => 'JPY',
        ]);

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());

        $order->refresh();
        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('CVS', $payment->payment_meta['pay_method'] ?? null);
        $this->assertSame('paid', $payment->payment_meta['cvs_status'] ?? null);
        $this->assertSame('CBT_CVS_TID_002', $payment->transaction_id);
        $this->assertSame('confirmed', $payment->payment_meta['cvs_last_notify_result'] ?? null);
        $this->assertSame('deposit_confirmed', $payment->payment_meta['cvs_last_notify_reason'] ?? null);
        $this->assertSame('confirmed', $payment->payment_meta['cvs_notify_history'][0]['result'] ?? null);
        $this->assertSame('deposit_confirmed', $payment->payment_meta['cvs_notify_history'][0]['reason'] ?? null);
    }

    public function test_cbt_cvs_notify_replay_returns_ok_without_double_completion(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CVS-REPLAY-001', 100);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => 'CBT_CVS_TID_REPLAY_001',
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => 'SID-CVS-REPLAY-001',
                'is_test_mode' => true,
                'pay_method' => 'CVS',
            ],
        ]);

        $payload = [
            'tid' => 'CBT_CVS_TID_REPLAY_001',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'applDt' => '20260521',
            'applTm' => '120000',
            'status' => '00',
            'payNm' => 'CBT',
            'orderId' => 'JP-ORDER-CVS-REPLAY-001',
            'applNo' => 'APP-CVS',
            'sid' => 'SID-CVS-REPLAY-001',
            'convenience' => '00007',
            'confNo' => '999999999999999999',
            'receiptNo' => '1634795292905',
            'paymentTerm' => '20260530235959',
            'amount' => '100',
            'currencyCd' => 'JPY',
        ];

        $firstResponse = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', $payload);
        $secondResponse = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', $payload);

        $firstResponse->assertOk();
        $secondResponse->assertOk();
        $this->assertSame('OK', $firstResponse->getContent());
        $this->assertSame('OK', $secondResponse->getContent());

        $order->refresh();
        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $history = $payment->payment_meta['cvs_notify_history'] ?? [];

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('ignored', $payment->payment_meta['cvs_last_notify_result'] ?? null);
        $this->assertSame('already_paid', $payment->payment_meta['cvs_last_notify_reason'] ?? null);
        $this->assertCount(2, $history);
        $this->assertSame('already_paid', $history[0]['reason'] ?? null);
        $this->assertSame('deposit_confirmed', $history[1]['reason'] ?? null);
    }

    public function test_cbt_cvs_notify_rejects_amount_mismatch(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CVS-AMOUNT-001', 100);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => 'CBT_CVS_TID_AMOUNT_001',
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => 'SID-CVS-AMOUNT-001',
                'is_test_mode' => true,
                'pay_method' => 'CVS',
                'cvs_amount' => 100,
            ],
        ]);

        $response = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', [
            'tid' => 'CBT_CVS_TID_AMOUNT_001',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'applDt' => '20260521',
            'applTm' => '120000',
            'status' => '00',
            'payNm' => 'CBT',
            'orderId' => 'JP-ORDER-CVS-AMOUNT-001',
            'applNo' => 'APP-CVS',
            'sid' => 'SID-CVS-AMOUNT-001',
            'convenience' => '00007',
            'confNo' => '999999999999999999',
            'receiptNo' => '1634795292905',
            'paymentTerm' => '20260530235959',
            'amount' => '99',
            'currencyCd' => 'JPY',
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertSame('waiting_deposit', $payment->payment_meta['cvs_status'] ?? 'waiting_deposit');
        $this->assertSame('failed', $payment->payment_meta['cvs_last_notify_result'] ?? null);
        $this->assertSame('amount_mismatch', $payment->payment_meta['cvs_last_notify_reason'] ?? null);
        $this->assertSame('amount_mismatch', $payment->payment_meta['cvs_notify_history'][0]['reason'] ?? null);
    }

    public function test_cbt_cvs_notify_rejects_sid_mismatch(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CVS-SID-001', 100);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => 'CBT_CVS_TID_SID_001',
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => 'SID-CVS-EXPECTED',
                'is_test_mode' => true,
                'pay_method' => 'CVS',
                'cvs_amount' => 100,
            ],
        ]);

        $response = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', [
            'tid' => 'CBT_CVS_TID_SID_001',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'applDt' => '20260521',
            'applTm' => '120000',
            'status' => '00',
            'payNm' => 'CBT',
            'orderId' => 'JP-ORDER-CVS-SID-001',
            'applNo' => 'APP-CVS',
            'sid' => 'SID-CVS-ATTACKER',
            'amount' => '100',
            'currencyCd' => 'JPY',
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());

        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
    }

    public function test_cbt_cvs_notify_rejects_tid_mismatch(): void
    {
        $order = $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-TID-001', 100);

        $response = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', [
            'tid' => 'CBT_CVS_TID_ATTACKER',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'applDt' => '20260521',
            'applTm' => '120000',
            'status' => '00',
            'payNm' => 'CBT',
            'orderId' => $order->order_number,
            'applNo' => 'APP-CVS',
            'sid' => 'SID-JP-ORDER-CVS-TID-001',
            'convenience' => '00007',
            'confNo' => '999999999999999999',
            'receiptNo' => '1634795292905',
            'paymentTerm' => '20260530235959',
            'amount' => '100',
            'currencyCd' => 'JPY',
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());

        $order->refresh();
        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
        $this->assertStringStartsWith('CBT_CVS_TID_JP_ORDER_CVS_TID_001', (string) $payment->transaction_id);
        $this->assertSame('failed', $payment->payment_meta['cvs_last_notify_result'] ?? null);
        $this->assertSame('tid_mismatch', $payment->payment_meta['cvs_last_notify_reason'] ?? null);
    }

    public function test_cbt_cvs_notify_rejects_non_jpy_currency(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CVS-CURRENCY-001', 100);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => 'CBT_CVS_TID_CURRENCY_001',
            'payment_meta' => [
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => 'SID-CVS-CURRENCY-001',
                'is_test_mode' => true,
                'pay_method' => 'CVS',
                'cvs_amount' => 100,
            ],
        ]);

        $response = $this->postJson('/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify', [
            'tid' => 'CBT_CVS_TID_CURRENCY_001',
            'mid' => KgInicisApiService::JAPAN_TEST_MID,
            'applDt' => '20260521',
            'applTm' => '120000',
            'status' => '00',
            'payNm' => 'CBT',
            'orderId' => 'JP-ORDER-CVS-CURRENCY-001',
            'applNo' => 'APP-CVS',
            'sid' => 'SID-CVS-CURRENCY-001',
            'amount' => '100',
            'currencyCd' => 'KRW',
        ]);

        $response->assertOk();
        $this->assertSame('FAIL', $response->getContent());

        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
    }

    public function test_admin_can_view_cbt_cvs_operations_summary(): void
    {
        $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-ADMIN-001', 100);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin);

        $response = $this->getJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-CVS-ADMIN-001/cbt-cvs');

        $response->assertOk()
            ->assertJsonPath('data.is_cbt_cvs', true)
            ->assertJsonPath('data.payment_status', PaymentStatusEnum::WAITING_DEPOSIT->value)
            ->assertJsonPath('data.cbt_mid', KgInicisApiService::JAPAN_TEST_MID)
            ->assertJsonPath('data.cbt_sid', 'SID-JP-ORDER-CVS-ADMIN-001')
            ->assertJsonPath('data.can_simulate_notify', true);

        $this->assertStringContainsString(
            '/plugins/sirsoft-pay_kginicis/payment/cbt/cvs-notify',
            (string) $response->json('data.notify_url'),
        );
    }

    public function test_admin_can_simulate_test_cvs_notify(): void
    {
        $order = $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-SIM-001', 100);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-CVS-SIM-001/cbt-cvs/simulate-notify');

        $response->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatusEnum::PAID->value)
            ->assertJsonPath('data.cvs_status', 'paid')
            ->assertJsonPath('data.last_notify_result', 'confirmed');

        $order->refresh();
        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('admin_simulation', $payment->payment_meta['cvs_notify_history'][0]['source'] ?? null);
    }

    public function test_admin_cannot_simulate_live_cvs_notify(): void
    {
        $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-LIVE-001', 100, [
            'is_test_mode' => false,
        ]);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-CVS-LIVE-001/cbt-cvs/simulate-notify');

        $response->assertStatus(422);

        $payment = OrderPayment::query()
            ->whereHas('order', fn ($query) => $query->where('order_number', 'JP-ORDER-CVS-LIVE-001'))
            ->firstOrFail();
        $this->assertEquals(PaymentStatusEnum::WAITING_DEPOSIT, $payment->payment_status);
    }

    public function test_admin_can_mark_overdue_cvs_payment_expired(): void
    {
        $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-EXP-001', 100, [
            'cvs_payment_term' => now()->subDay()->format('YmdHis'),
        ]);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-CVS-EXP-001/cbt-cvs/expire');

        $response->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatusEnum::EXPIRED->value)
            ->assertJsonPath('data.cvs_status', 'expired')
            ->assertJsonPath('data.expiry_reason', 'payment_term_elapsed');

        $payment = OrderPayment::query()
            ->whereHas('order', fn ($query) => $query->where('order_number', 'JP-ORDER-CVS-EXP-001'))
            ->firstOrFail();

        $this->assertEquals(PaymentStatusEnum::EXPIRED, $payment->payment_status);
        $this->assertSame('expired', $payment->payment_meta['cvs_status'] ?? null);
    }

    public function test_cbt_cvs_expire_rechecks_locked_payment_status_before_marking_expired(): void
    {
        $order = $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-RACE-001', 100, [
            'cvs_payment_term' => now()->subDay()->format('YmdHis'),
        ]);

        $repository = Mockery::mock(CbtCvsOperationsRepositoryInterface::class);
        $repository->shouldReceive('findOrderWithPayment')
            ->once()
            ->with('JP-ORDER-CVS-RACE-001')
            ->andReturn($order->fresh('payment'));
        $repository->shouldReceive('lockPayment')
            ->once()
            ->with(Mockery::type(OrderPayment::class))
            ->andReturnUsing(function (OrderPayment $payment): OrderPayment {
                $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
                $payment->forceFill([
                    'payment_status' => PaymentStatusEnum::PAID,
                    'payment_meta' => array_merge($meta, [
                        'cvs_status' => 'paid',
                    ]),
                ])->save();

                return $payment->fresh();
            });
        $repository->shouldReceive('updatePayment')->never();

        $service = new CbtCvsOperationsService(
            $this->app->make(OrderProcessingService::class),
            $this->app->make(KgInicisApiService::class),
            $repository,
        );

        $result = $service->expireOverdue('JP-ORDER-CVS-RACE-001');

        $this->assertFalse($result['ok']);
        $this->assertSame('messages.cbt_cvs.not_expirable', $result['message_key']);

        $payment = OrderPayment::query()
            ->whereHas('order', fn ($query) => $query->where('order_number', 'JP-ORDER-CVS-RACE-001'))
            ->firstOrFail();

        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('paid', $payment->payment_meta['cvs_status'] ?? null);
    }

    public function test_admin_can_recheck_cbt_cvs_local_status(): void
    {
        $this->createPersistedPendingCbtCvsOrder('JP-ORDER-CVS-RECHECK-001', 100);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-CVS-RECHECK-001/cbt-cvs/recheck');

        $response->assertOk()
            ->assertJsonPath('data.last_recheck_result', 'local_status_checked');

        $payment = OrderPayment::query()
            ->whereHas('order', fn ($query) => $query->where('order_number', 'JP-ORDER-CVS-RECHECK-001'))
            ->firstOrFail();

        $this->assertSame('local_status_checked', $payment->payment_meta['cvs_last_recheck_result'] ?? null);
    }

    public function test_cbt_callback_auto_refunds_approved_payment_when_local_completion_fails(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-003', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-003')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->andThrow(new \RuntimeException('local write failed'));

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID003')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_003',
                'paymethod' => 'CARD',
                'amount' => 100,
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with(
                'CBT_TID_003',
                null,
                Mockery::on(fn (string $msg): bool => str_contains($msg, 'local write failed')),
            )
            ->andReturn(['resultCode' => '00']);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-003',
                'sid' => 'SID003',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
            ]));

        $response->assertRedirect('http://localhost/shop/checkout?error=cbt_failed&orderId=JP-ORDER-003');
    }

    public function test_cbt_callback_skips_auto_refund_when_local_payment_was_already_completed_before_exception(): void
    {
        $order = $this->createPersistedPendingJpyOrder('JP-ORDER-CBT-POST-COMMIT', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-CBT-POST-COMMIT')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->andReturnUsing(function (Order $callbackOrder, array $paymentData, ?int $paidAmount): void {
                DB::transaction(function () use ($callbackOrder, $paymentData, $paidAmount): void {
                    $callbackOrder->forceFill([
                        'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                        'total_paid_amount' => $paidAmount,
                        'total_due_amount' => 0,
                        'paid_at' => now(),
                    ])->save();

                    $callbackOrder->payment()->update([
                        'payment_status' => PaymentStatusEnum::PAID,
                        'transaction_id' => $paymentData['transaction_id'] ?? null,
                        'card_approval_number' => $paymentData['card_approval_number'] ?? null,
                        'payment_meta' => $paymentData['payment_meta'] ?? [],
                        'paid_amount_local' => $paidAmount,
                        'paid_at' => now(),
                    ]);
                });

                throw new \RuntimeException('cbt post commit hook failed');
            });

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID_POST_COMMIT')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_POST_COMMIT',
                'paymethod' => 'CARD',
                'amount' => 100,
            ]);
        $apiService->shouldNotReceive('refundCbtPayment');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-CBT-POST-COMMIT',
                'sid' => 'SID_POST_COMMIT',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-CBT-POST-COMMIT/complete');

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = OrderPayment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertEquals(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame('CBT_TID_POST_COMMIT', $payment->transaction_id);
    }

    public function test_cbt_callback_auto_refunds_when_approved_amount_mismatches_order(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-004', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-004')
            ->andReturn($order);
        $orderService->shouldNotReceive('completePayment');

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID004')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_004',
                'paymethod' => 'CARD',
                'amount' => 99,
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with(
                'CBT_TID_004',
                null,
                Mockery::on(fn (string $msg): bool => str_contains($msg, 'amount mismatch')),
            )
            ->andReturn(['resultCode' => '00']);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-004',
                'sid' => 'SID004',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
            ]));

        $response->assertRedirect('http://localhost/shop/checkout?error=cbt_failed&orderId=JP-ORDER-004');
    }

    public function test_cbt_callback_auto_refunds_when_approved_order_id_mismatches_order(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-012', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-012')
            ->andReturn($order);
        $orderService->shouldNotReceive('completePayment');

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID012')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_012',
                'orderId' => 'JP-ORDER-ATTACKER',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'currencyCd' => 'JPY',
                'paymethod' => 'CARD',
                'amount' => 100,
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with(
                'CBT_TID_012',
                null,
                Mockery::on(fn (string $msg): bool => str_contains($msg, 'order id mismatch')),
            )
            ->andReturn(['resultCode' => '00']);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-012',
                'sid' => 'SID012',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'CARD',
                'selectedPaymentMethod' => 'card',
            ]));

        $response->assertRedirect('http://localhost/shop/checkout?error=cbt_failed&orderId=JP-ORDER-012');
    }

    public function test_cbt_callback_completes_with_order_amount_when_pg_amount_field_absent(): void
    {
        // KG 이니시스 일본 CBT 승인 응답에는 금액 필드가 없다(매뉴얼상 미사용).
        // 카드 승인 응답에 amount/price 가 없어도 PG 가 OK 승인했으면
        // 주문 결제예정액(100)을 승인액으로 신뢰해 결제를 완료해야 한다.
        $this->insertOrderRow('JP-ORDER-013', 100);
        $order = $this->makePendingJpyOrder('JP-ORDER-013', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-013')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->with(
                $order,
                Mockery::on(fn (array $data): bool => ($data['transaction_id'] ?? null) === 'CBT_TID_013'),
                100,
            );

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID013')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_013',
                'orderId' => 'JP-ORDER-013',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'currencyCd' => 'JPY',
                'paymethod' => 'CARD',
            ]);
        // 금액 누락은 정상 동작이므로 자동환불은 호출되지 않아야 한다.
        $apiService->shouldNotReceive('refundCbtPayment');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-013',
                'sid' => 'SID013',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'CARD',
                'selectedPaymentMethod' => 'card',
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-013/complete');
    }

    public function test_cbt_callback_completes_paypay_when_boku_amount_fields_are_blank(): void
    {
        // 실제 일본 PayPay(BOKU) 승인 응답 재현: bokuApplPrice/bokuLocalApplPrice 가
        // 모두 빈 문자열로 오고 amount/price 키 자체가 없다. 이때도 결제예정액(500)으로
        // 완료되어야 한다(회귀: approved amount missing 예외로 정상 결제가 실패 처리되던 버그).
        $this->insertOrderRow('JP-ORDER-PAYPAY-OK', 500);
        $order = $this->makePendingJpyOrder('JP-ORDER-PAYPAY-OK', 500);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-PAYPAY-OK')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->with(
                $order,
                Mockery::on(fn (array $data): bool => ($data['transaction_id'] ?? null) === 'CBT_TID_PAYPAY_OK'),
                500,
            );

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID-PAYPAY-OK')
            ->andReturn([
                'resultCode' => 'OK',
                'errorCode' => '',
                'tid' => 'CBT_TID_PAYPAY_OK',
                'resultMsg' => '決済が完了しました。',
                'paymethod' => 'PAYpay',
                'applDate' => '20260629',
                'applTime' => '223645',
                'bokuApplPrice' => '',
                'bokuLocalApplPrice' => '',
                'bokuApplCurrency' => '',
                'bokuLocalApplCurrency' => '',
                'bokuChargeId' => '',
                'convenience' => '',
                'confNo' => '',
                'receiptNo' => '',
                'paymentTerm' => '',
            ]);
        $apiService->shouldNotReceive('refundCbtPayment');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-PAYPAY-OK',
                'sid' => 'SID-PAYPAY-OK',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'paymethod' => 'PAYpay',
                'selectedPaymentMethod' => 'kginicis_japan_paypay',
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-PAYPAY-OK/complete');
    }

    public function test_cbt_hash_data_rejects_amount_mismatch(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-002', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-002')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-002',
            'price' => 99,
            'timestamp' => date('YmdHis'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Payment amount does not match the order amount.');
    }

    public function test_cbt_hash_data_rejects_order_buyer_mismatch(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-005', 100);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.jp',
            'orderer_phone' => '090-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-005')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);
        $apiService->shouldNotReceive('generateCbtHashData');

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-005',
            'price' => 100,
            'timestamp' => date('YmdHis'),
            'buyer_email' => 'attacker@example.jp',
            'buyer_phone' => '09012345678',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Order buyer verification failed.');
    }

    public function test_cbt_hash_data_accepts_matching_order_buyer_context(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-006', 100);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.jp',
            'orderer_phone' => '090-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-006')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('generateCbtHashData')
            ->with(KgInicisApiService::JAPAN_TEST_MID, Mockery::type('string'), 100, 'JP-ORDER-006')
            ->andReturn('hash-ok');

        $checkoutTokenService = Mockery::mock(CbtCheckoutTokenService::class);
        $checkoutTokenService->shouldReceive('verify')
            ->once()
            ->with('token-ok', 'JP-ORDER-006', 100, 'BUYER@example.jp', '09012345678', Mockery::type(\Illuminate\Http\Request::class))
            ->andReturn(true);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);
        $this->app->instance(CbtCheckoutTokenService::class, $checkoutTokenService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-006',
            'price' => 100,
            'timestamp' => date('YmdHis'),
            'buyer_email' => 'BUYER@example.jp',
            'buyer_phone' => '09012345678',
            'checkout_token' => 'token-ok',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.hash_data', 'hash-ok');
    }

    public function test_cbt_hash_data_rejects_invalid_checkout_token(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-007', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-007')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);
        $apiService->shouldNotReceive('generateCbtHashData');

        $checkoutTokenService = Mockery::mock(CbtCheckoutTokenService::class);
        $checkoutTokenService->shouldReceive('verify')
            ->once()
            ->andReturn(false);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);
        $this->app->instance(CbtCheckoutTokenService::class, $checkoutTokenService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => 'JP-ORDER-007',
            'price' => 100,
            'timestamp' => date('YmdHis'),
            'checkout_token' => 'bad-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'CBT checkout token verification failed.');
    }

    public function test_cbt_checkout_token_endpoint_issues_token_for_matching_order_context(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-008', 100);
        $order->setRelation('shippingAddress', new OrderAddress([
            'address_type' => 'shipping',
            'orderer_email' => 'buyer@example.jp',
            'orderer_phone' => '090-1234-5678',
        ]));

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-008')
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('isJapanEnabled')->andReturn(true);
        $apiService->shouldReceive('isJapanConfigured')->andReturn(true);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token', [
            'oid' => 'JP-ORDER-008',
            'price' => 100,
            'buyer_email' => 'buyer@example.jp',
            'buyer_phone' => '09012345678',
        ]);

        $response->assertOk();
        $this->assertIsString($response->json('data.checkout_token'));
        $this->assertStringContainsString('.', $response->json('data.checkout_token'));
    }

    public function test_cbt_callback_stores_sanitized_pg_response_only(): void
    {
        $order = $this->makePendingJpyOrder('JP-ORDER-009', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-009')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->with($order, Mockery::on(function (array $paymentData): bool {
                $meta = $paymentData['payment_meta'] ?? [];

                return ($meta['pg_response_sanitized'] ?? false) === true
                    && ! isset($meta['pg_raw_auth_response'])
                    && ($meta['pg_auth_response']['sid'] ?? '') === 'SID009'
                    && ! isset($meta['pg_auth_response']['buyerEmail'])
                    && ! isset($meta['pg_raw_response']['cardNum'])
                    && ! isset($meta['pg_approve_response']['buyerEmail']);
            }), 100)
            ->andReturn($order);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldNotReceive('refundCbtPayment');
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID009')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_009',
                'paymethod' => 'CARD',
                'amount' => 100,
                'approve' => 'APPROVE9',
                'cardNum' => '4111111111111111',
                'buyerEmail' => 'buyer@example.jp',
            ]);

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-009',
                'sid' => 'SID009',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
                'buyerEmail' => 'buyer@example.jp',
            ]));

        $response->assertRedirect('http://localhost/shop/orders/JP-ORDER-009/complete');
    }

    public function test_cbt_callback_records_manual_reconciliation_when_auto_refund_fails(): void
    {
        $this->insertOrderRow('JP-ORDER-010', 100);
        $order = $this->makePendingJpyOrder('JP-ORDER-010', 100);

        $orderService = Mockery::mock(OrderProcessingService::class);
        $orderService->shouldReceive('findByOrderNumber')
            ->with('JP-ORDER-010')
            ->andReturn($order);
        $orderService->shouldReceive('completePayment')
            ->once()
            ->andThrow(new \RuntimeException('local write failed'));

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('isTestMode')->andReturn(true);
        $apiService->shouldReceive('approveCbtPayment')
            ->with('SID010')
            ->andReturn([
                'resultCode' => 'OK',
                'tid' => 'CBT_TID_010',
                'paymethod' => 'CARD',
                'amount' => 100,
            ]);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->andThrow(new \RuntimeException('refund timeout'));

        $this->app->instance(OrderProcessingService::class, $orderService);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $this->get('/plugins/sirsoft-pay_kginicis/payment/cbt/callback?'
            . http_build_query([
                'oid' => 'JP-ORDER-010',
                'sid' => 'SID010',
                'resultCode' => 'OK',
                'mid' => KgInicisApiService::JAPAN_TEST_MID,
            ]));

        $meta = $this->orderMeta('JP-ORDER-010');
        $record = $meta[CbtReconciliationService::META_KEY] ?? [];
        $this->assertSame(CbtReconciliationService::STATUS_MANUAL_REFUND_REQUIRED, $record['status'] ?? null);
        $this->assertSame('CBT_TID_010', $record['tid'] ?? null);
        $this->assertSame('refund timeout', $record['refund_error'] ?? null);
    }

    public function test_admin_can_retry_cbt_manual_refund(): void
    {
        $this->insertOrderRow('JP-ORDER-011', 100, [
            CbtReconciliationService::META_KEY => [
                'status' => CbtReconciliationService::STATUS_MANUAL_REFUND_REQUIRED,
                'manual_action_required' => true,
                'tid' => 'CBT_TID_011',
                'amount' => 100,
                'reason' => 'local write failed',
                'refund_error' => 'timeout',
                'is_test_mode' => true,
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'retry_count' => 0,
            ],
        ]);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $this->actingAs($admin);

        $apiService = Mockery::mock(KgInicisApiService::class);
        $apiService->shouldReceive('useStoredCbtCredentials')
            ->once()
            ->with(true, KgInicisApiService::JAPAN_TEST_MID);
        $apiService->shouldReceive('refundCbtPayment')
            ->once()
            ->with('CBT_TID_011', null, '관리자 CBT 자동환불 재시도')
            ->andReturn([
                'resultCode' => '00',
                'resultMsg' => 'OK',
                'tid' => 'CBT_TID_011',
            ]);
        $this->app->instance(KgInicisApiService::class, $apiService);

        $response = $this->postJson('/api/plugins/sirsoft-pay_kginicis/admin/orders/JP-ORDER-011/cbt-reconciliation/refund-retry');

        $response->assertOk()
            ->assertJsonPath('data.status', CbtReconciliationService::STATUS_AUTO_REFUNDED)
            ->assertJsonPath('data.retry_count', 1);

        $record = $this->orderMeta('JP-ORDER-011')[CbtReconciliationService::META_KEY] ?? [];
        $this->assertSame(CbtReconciliationService::STATUS_AUTO_REFUNDED, $record['status'] ?? null);
        $this->assertNull($record['refund_error'] ?? null);
    }

    public function test_cbt_reconciliation_retry_claim_is_single_use(): void
    {
        $this->insertOrderRow('JP-ORDER-CLAIM-001', 100, [
            CbtReconciliationService::META_KEY => [
                'status' => CbtReconciliationService::STATUS_MANUAL_REFUND_REQUIRED,
                'manual_action_required' => true,
                'tid' => 'CBT_TID_CLAIM_001',
                'amount' => 100,
                'reason' => 'local write failed',
                'refund_error' => 'timeout',
                'is_test_mode' => true,
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'retry_count' => 0,
            ],
        ]);

        $service = app(CbtReconciliationService::class);

        $firstClaim = $service->claimRefundRetry('JP-ORDER-CLAIM-001');
        $secondClaim = $service->claimRefundRetry('JP-ORDER-CLAIM-001');

        $this->assertNotNull($firstClaim);
        $this->assertSame(CbtReconciliationService::STATUS_REFUND_RETRYING, $firstClaim['status'] ?? null);
        $this->assertSame(1, $firstClaim['retry_count'] ?? null);
        $this->assertNull($secondClaim);
    }

    private function makePendingJpyOrder(string $orderNumber, int $amount): Order
    {
        $order = new Order();
        $order->order_number = $orderNumber;
        $order->order_status = OrderStatusEnum::PENDING_ORDER;
        $order->currency = 'JPY';
        $order->total_due_amount = $amount;

        return $order;
    }

    private function createPersistedPendingJpyOrder(string $orderNumber, int $amount): Order
    {
        $order = OrderFactory::new()->create([
            'order_number' => $orderNumber,
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'currency' => 'JPY',
            'currency_snapshot' => ['JPY' => 1.0],
            'subtotal_amount' => $amount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $amount,
            'total_tax_amount' => 0,
            'total_tax_free_amount' => 0,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
            'total_due_amount' => $amount,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'total_earned_points_amount' => 0,
            'item_count' => 1,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'kginicis',
            'transaction_id' => null,
            'paid_amount_local' => 0,
            'paid_amount_base' => 0,
            'currency' => 'JPY',
            'currency_snapshot' => ['JPY' => 1.0],
            'paid_at' => null,
        ]);

        return $order->fresh();
    }

    private function createPersistedPendingCbtCvsOrder(string $orderNumber, int $amount, array $metaOverrides = []): Order
    {
        $order = $this->createPersistedPendingJpyOrder($orderNumber, $amount);
        $sid = 'SID-' . $orderNumber;
        $tid = 'CBT_CVS_TID_' . str_replace('-', '_', $orderNumber);

        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => $tid,
            'payment_meta' => array_merge([
                'is_cbt' => true,
                'cbt_type' => 'JPPG',
                'cbt_mid' => KgInicisApiService::JAPAN_TEST_MID,
                'cbt_sid' => $sid,
                'is_test_mode' => true,
                'pay_method' => 'CVS',
                'cvs_status' => 'waiting_deposit',
                'cvs_amount' => $amount,
                'cvs_convenience' => '00007',
                'cvs_conf_no' => '999999999999999999',
                'cvs_receipt_no' => '1634795292905',
                'cvs_payment_term' => now()->addDays(3)->format('YmdHis'),
            ], $metaOverrides),
        ]);

        return $order->fresh();
    }

    private function insertOrderRow(string $orderNumber, int $amount, array $orderMeta = []): void
    {
        DB::table('ecommerce_orders')->insert([
            'order_number' => $orderNumber,
            'order_status' => OrderStatusEnum::PENDING_ORDER->value,
            'currency' => 'JPY',
            'subtotal_amount' => $amount,
            'total_amount' => $amount,
            'total_tax_amount' => $amount,
            'total_due_amount' => $amount,
            'item_count' => 1,
            'ordered_at' => now(),
            'order_meta' => $orderMeta === []
                ? null
                : json_encode($orderMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderMeta(string $orderNumber): array
    {
        $raw = DB::table('ecommerce_orders')
            ->where('order_number', $orderNumber)
            ->value('order_meta');

        $decoded = is_string($raw) ? json_decode($raw, true) : [];

        return is_array($decoded) ? $decoded : [];
    }
}
