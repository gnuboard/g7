<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature;

use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayKginicis\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG이니시스 취소 요청을 공식 사양(https://manual.inicis.com/pay/cancel.html)에 맞춘다 — #747 C8
 *
 * - P-D7: 부분취소를 거절당할 결제(에스크로, 달이 바뀐 휴대폰 — 「당월 거래만 취소 가능」)는 접수 단계에서 먼저 알린다.
 * - P-D8: 부분취소의 과세 필드는 선택이고 키는 `tax`·`taxFree` 다. `tax='0'`·`taxfree='0'` 고정값을 보내지 않는다.
 * - 가상계좌 환불은 `/v2/pg/refund/vacct`·`/v2/pg/partialRefund/vacct` 로 보내고 `refundAcctNum` 은 AES 암호화 대상이다.
 * - P-D11: 부분취소 응답의 회차 거래번호는 `prtcTid` 다.
 *
 * 시나리오 매니페스트: `tests/scenarios/refund-spec-conformance.yaml`
 */
class RefundSpecConformanceTest extends PluginTestCase
{
    private const TEST_MID = 'INIpayTest';

    private const TEST_INIAPI_KEY = 'ItEQKi3rY7uvDS8l';

    private const TEST_INIAPI_IV = 'HYb3yQ4f65QL89==';

    /**
     * 테스트 상점 설정으로 API 서비스를 만듭니다.
     *
     * @return KgInicisApiService API 서비스
     */
    private function makeService(): KgInicisApiService
    {
        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => self::TEST_INIAPI_KEY,
            'test_iniapi_iv' => self::TEST_INIAPI_IV,
            'live_mid' => '',
            'live_sign_key' => '',
            'live_iniapi_key' => '',
            'live_iniapi_iv' => '',
        ]);

        return new KgInicisApiService($settings);
    }

    /**
     * KG이니시스 결제완료 주문을 만듭니다.
     *
     * @param  array<string, mixed>  $paymentOverrides  결제행 덮어쓰기
     * @return Order 주문
     */
    private function paidOrder(array $paymentOverrides = []): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-KGC8-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'total_amount' => 30000,
            'total_paid_amount' => 30000,
        ]);

        OrderPaymentFactory::new()->create(array_merge([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::PAID,
            'payment_method' => 'card',
            'pg_provider' => 'kginicis',
            'transaction_id' => 'StdpayCARDINIpayTest'.$order->id,
            'paid_amount_local' => 30000,
            'paid_amount_base' => 30000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
            'vbank_number' => null,
            'paid_at' => now(),
        ], $paymentOverrides));

        return $order->fresh(['payment']);
    }

    /**
     * 에스크로·지난달 휴대폰은 부분취소를 지원하지 않고, 카드·이번 달 휴대폰·입금된 가상계좌는 지원한다.
     *
     * @scenario method=escrow
     *
     * @effects partial_cancel_support_follows_kg_constraints
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancel_support_follows_kg_constraints(): void
    {
        $listener = app(PaymentRefundListener::class);

        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder()->payment));
        $this->assertFalse($listener->supportsPartialCancel(true, $this->paidOrder(['is_escrow' => true])->payment), '에스크로 부분취소는 막는다');
        $this->assertFalse($listener->supportsPartialCancel(true, $this->paidOrder(['payment_method' => 'phone', 'paid_at' => now('Asia/Seoul')->subMonthNoOverflow()->startOfMonth()->addDays(2)])->payment), '당월 거래만 취소된다');
        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder(['payment_method' => 'phone', 'paid_at' => now()])->payment));
        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder(['payment_method' => 'vbank', 'vbank_number' => '1234567890'])->payment), '입금된 가상계좌는 partialRefund/vacct 로 부분환불된다');
        $this->assertTrue($listener->supportsPartialCancel(true, $this->paidOrder(['pg_provider' => 'other_pg', 'is_escrow' => true])->payment), '다른 PG 결제는 판정하지 않는다');
        $this->assertFalse($listener->supportsPartialCancel(false, $this->paidOrder()->payment));
    }

    /**
     * 부분취소 요청에 `tax`·`taxfree` 고정값을 싣지 않는다(선택 필드, 문서 키는 `taxFree`).
     *
     * @scenario method=card
     *
     * @effects partial_refund_omits_fixed_tax_fields
     *
     * @return void 반환값 없음
     */
    public function test_partial_refund_does_not_send_fixed_tax_fields(): void
    {
        Http::fake(['stginiapi.inicis.com/v2/pg/partialRefund' => Http::response(['resultCode' => '00', 'resultMsg' => '성공'], 200)]);

        $this->makeService()->cancelPayment('TID_ORIG', 'Card', 10000, '부분취소', 30000);

        Http::assertSent(function ($request) {
            $data = $request['data'] ?? [];

            return $request['type'] === 'partialRefund'
                && $data['price'] === '10000'
                && $data['confirmPrice'] === '20000'
                && ! array_key_exists('tax', $data)
                && ! array_key_exists('taxfree', $data);
        });
    }

    /**
     * 입금된 가상계좌 전액·부분 환불은 `/vacct` 경로로 보내고 환불계좌번호는 AES-128-CBC + base64 로 암호화한다.
     *
     * @scenario method=vbank
     *
     * @effects vbank_refund_uses_vacct_endpoint_with_encrypted_account
     *
     * @return void 반환값 없음
     */
    public function test_vbank_refund_uses_vacct_endpoint_with_encrypted_account(): void
    {
        Http::fake([
            'stginiapi.inicis.com/v2/pg/refund/vacct' => Http::response(['resultCode' => '00', 'resultMsg' => '성공'], 200),
            'stginiapi.inicis.com/v2/pg/partialRefund/vacct' => Http::response(['resultCode' => '00', 'resultMsg' => '성공'], 200),
            '*' => Http::response(['resultCode' => '01', 'resultMsg' => '잘못된 경로'], 200),
        ]);
        $account = ['bank_code' => '004', 'account_number' => '12345678901234', 'holder' => '홍길동'];
        $expected = base64_encode(openssl_encrypt('12345678901234', 'aes-128-cbc', self::TEST_INIAPI_KEY, OPENSSL_RAW_DATA, self::TEST_INIAPI_IV));

        $service = $this->makeService();
        $service->cancelPayment('TID_VB', 'VBank', null, '전액', null, $account);
        $service->cancelPayment('TID_VB', 'VBank', 10000, '부분', 30000, $account);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        $this->assertStringEndsWith('/v2/pg/refund/vacct', $sent[0]->url());
        $this->assertSame('refund', $sent[0]['type']);
        $this->assertStringEndsWith('/v2/pg/partialRefund/vacct', $sent[1]->url());
        $this->assertSame('partialRefund', $sent[1]['type']);
        foreach ($sent as $request) {
            $data = $request['data'];
            $this->assertSame($expected, $data['refundAcctNum'], '환불계좌번호는 암호화해 보낸다');
            $this->assertSame('004', $data['refundBankCode']);
            $this->assertSame('홍길동', $data['refundAcctName']);
            $detailJson = str_replace('\\/', '/', json_encode($data, JSON_UNESCAPED_UNICODE));
            $this->assertSame(hash('sha512', self::TEST_INIAPI_KEY.self::TEST_MID.$request['type'].$request['timestamp'].$detailJson), $request['hashData']);
        }
    }

    /**
     * 입금 전 가상계좌 발급 취소(계좌 없음)는 일반 전액취소 경로 그대로다.
     *
     * @scenario method=vbank
     *
     * @effects unpaid_vbank_close_keeps_plain_refund_endpoint
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_vbank_close_keeps_plain_refund_endpoint(): void
    {
        Http::fake(['stginiapi.inicis.com/v2/pg/refund' => Http::response(['resultCode' => '00', 'resultMsg' => '성공'], 200)]);

        $this->makeService()->cancelPayment('TID_VB', 'VBank', null, '발급 취소');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/pg/refund') && ! isset($request['data']['refundAcctNum']));
    }

    /**
     * 부분취소 결과의 거래 ID 는 응답의 회차 거래번호(`prtcTid`)다.
     *
     * @scenario method=card
     *
     * @effects partial_refund_transaction_id_is_prtc_tid
     *
     * @return void 반환값 없음
     */
    public function test_partial_refund_transaction_id_is_prtc_tid(): void
    {
        $order = $this->paidOrder();
        $tid = $order->payment->transaction_id;
        $api = $this->createMock(KgInicisApiService::class);
        $api->method('cancelPayment')->willReturn(['resultCode' => '00', 'tid' => $tid, 'prtcTid' => 'PRTC_ROUND_2']);
        $this->app->instance(KgInicisApiService::class, $api);

        $result = app(PaymentRefundListener::class)->processRefund(['success' => false], $order, $order->payment, 10000.0, '부분');

        $this->assertTrue($result['success']);
        $this->assertSame('PRTC_ROUND_2', $result['transaction_id']);
    }
}
