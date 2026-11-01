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
use Plugins\Sirsoft\PayKginicis\Concerns\SplitsInicisTax;
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
     * 과세 구분이 주어진 부분취소는 매뉴얼 키 `tax`·`taxFree` 로 싣고, 가상계좌 환불(`/vacct`)에는 싣지 않는다(#820).
     *
     * @scenario method=card
     *
     * @effects partial_refund_carries_tax_split_of_split_approval
     *
     * @return void 반환값 없음
     */
    public function test_partial_refund_sends_tax_split_only_on_the_standard_endpoint(): void
    {
        Http::fake([
            'stginiapi.inicis.com/v2/pg/partialRefund/vacct' => Http::response(['resultCode' => '00', 'resultMsg' => '성공'], 200),
            'stginiapi.inicis.com/v2/pg/partialRefund' => Http::response(['resultCode' => '00', 'resultMsg' => '성공'], 200),
        ]);
        $service = $this->makeService();

        $service->cancelPayment('TID_ORIG', 'Card', 10000, '부분취소', 30000, null, ['tax' => 667, 'taxFree' => 2667]);
        $service->cancelPayment('TID_VB', 'VBank', 10000, '부분', 30000, [
            'bank_code' => '004', 'account_number' => '12345678901234', 'holder' => '홍길동',
        ], ['tax' => 667, 'taxFree' => 2667]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/pg/partialRefund')
            && ($request['data']['tax'] ?? null) === '667'
            && ($request['data']['taxFree'] ?? null) === '2667');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/partialRefund/vacct')
            && ! array_key_exists('tax', $request['data'] ?? [])
            && ! array_key_exists('taxFree', $request['data'] ?? []));
    }

    /**
     * 승인 때 과세 구분을 실은 결제(`payment_meta.tax_split`)의 부분취소만 구분을 나눠 싣고, 비과세 소진액을 기록한다.
     * 표식이 없는 결제(면세분 없음·이 표식 이전 결제)는 종전대로 싣지 않는다 — 승인에 없던 구분을 취소에만 실으면 거절된다(#820).
     *
     * @scenario method=card
     *
     * @effects partial_refund_carries_tax_split_of_split_approval
     *
     * @return void 반환값 없음
     */
    public function test_partial_refund_splits_tax_only_for_split_approvals_and_records_usage(): void
    {
        $order = $this->paidOrder(['payment_meta' => ['tax_split' => ['tax_free_amount' => 8000, 'tax_free_cancelled' => 0]]]);
        $order->forceFill(['total_tax_amount' => 22000, 'total_vat_amount' => 2000, 'total_tax_free_amount' => 8000])->save();

        $captured = [];
        $api = $this->createMock(KgInicisApiService::class);
        $api->method('cancelPayment')->willReturnCallback(function (...$args) use (&$captured) {
            $captured[] = $args[6] ?? null;

            return ['resultCode' => '00', 'prtcTid' => 'PRTC_1'];
        });
        $this->app->instance(KgInicisApiService::class, $api);

        $result = app(PaymentRefundListener::class)->processRefund(['success' => false], $order->fresh(), $order->payment, 10000.0, '부분');

        $this->assertTrue($result['success']);
        // 비과세 = 10000 × 8000/30000 = 2667, 부가세 = (10000 − 2667) × 2000/22000 = 667
        $this->assertSame([['tax' => 667, 'taxFree' => 2667]], $captured);
        $this->assertSame(2667, $order->payment->fresh()->payment_meta['tax_split']['tax_free_cancelled']);

        $plain = $this->paidOrder();
        app(PaymentRefundListener::class)->processRefund(['success' => false], $plain, $plain->payment, 10000.0, '부분');
        $this->assertNull($captured[1], '승인 표식이 없으면 과세 구분을 싣지 않는다');
    }

    /**
     * 승인 표식은 결제창이 실은 값과 같은 규칙이다 — 면세분이 있으면 승인액 안으로 자른 면세액을, 없으면 아무것도 남기지 않는다.
     *
     * @scenario method=card
     *
     * @effects split_approval_records_tax_free_amount
     *
     * @return void 반환값 없음
     */
    public function test_approval_records_tax_split_marker_only_with_tax_free(): void
    {
        $splitter = new class
        {
            use SplitsInicisTax;

            /**
             * 비공개 표식 생성기를 시험에서 부릅니다.
             *
             * @param  Order  $order  주문
             * @param  int  $amount  승인 금액
             * @return array 표식
             */
            public function approvalMeta(Order $order, int $amount): array
            {
                return $this->buildTaxSplitApprovalMeta($order, $amount);
            }
        };

        $order = $this->paidOrder();
        $order->forceFill(['total_tax_free_amount' => 8000, 'currency_snapshot' => ['order_currency' => 'KRW']])->save();
        $this->assertSame(['tax_split' => ['tax_free_amount' => 8000, 'tax_free_cancelled' => 0]], $splitter->approvalMeta($order->fresh(), 30000));
        $this->assertSame(['tax_split' => ['tax_free_amount' => 5000, 'tax_free_cancelled' => 0]], $splitter->approvalMeta($order->fresh(), 5000), '승인액으로 자른다');

        $order->forceFill(['total_tax_free_amount' => 0])->save();
        $this->assertSame([], $splitter->approvalMeta($order->fresh(), 30000));
    }

    /**
     * 영세 금액은 승인 때처럼 비과세로 나눈다 — 승인 표식은 면세 + 영세, 부분취소 비과세는 그 비율, 부가세는 영세를 뺀
     * 과세분에서만 뗀다 (이커머스 1.3.3, #612).
     *
     * 주문: 과세표준 22,000(영세 8,000 포함)·부가세 1,273·면세 0. 10,000 부분취소 → 비과세 10000×8000/22000 = 3,636,
     * 부가세 (10000−3636)×1273/14000 = 579.
     *
     * @scenario method=card
     *
     * @effects partial_refund_carries_tax_split_of_split_approval
     *
     * @return void 반환값 없음
     */
    public function test_zero_rated_amount_is_split_as_tax_free(): void
    {
        $order = $this->paidOrder(['payment_meta' => ['tax_split' => ['tax_free_amount' => 8000, 'tax_free_cancelled' => 0]]]);
        $order->forceFill([
            'total_tax_amount' => 22000,
            'total_zero_rated_amount' => 8000,
            'total_vat_amount' => 1273,
            'total_tax_free_amount' => 0,
            'currency_snapshot' => ['order_currency' => 'KRW'],
        ])->save();

        $captured = [];
        $api = $this->createMock(KgInicisApiService::class);
        $api->method('cancelPayment')->willReturnCallback(function (...$args) use (&$captured) {
            $captured[] = $args[6] ?? null;

            return ['resultCode' => '00', 'prtcTid' => 'PRTC_1'];
        });
        $this->app->instance(KgInicisApiService::class, $api);

        $result = app(PaymentRefundListener::class)->processRefund(['success' => false], $order->fresh(), $order->payment, 10000.0, '부분');

        $this->assertTrue($result['success']);
        $this->assertSame([['tax' => 579, 'taxFree' => 3636]], $captured);

        $splitter = new class
        {
            use SplitsInicisTax;

            /**
             * 비공개 표식 생성기를 시험에서 부릅니다.
             *
             * @param  Order  $order  주문
             * @param  int  $amount  승인 금액
             * @return array 표식
             */
            public function approvalMeta(Order $order, int $amount): array
            {
                return $this->buildTaxSplitApprovalMeta($order, $amount);
            }
        };
        $this->assertSame(
            ['tax_split' => ['tax_free_amount' => 8000, 'tax_free_cancelled' => 0]],
            $splitter->approvalMeta($order->fresh(), 22000),
            '영세만 있어도 승인 표식을 남긴다',
        );
    }

    /**
     * 회차별 비과세 몫은 승인 면세액에서 이미 취소한 몫을 뺀 잔여 예산을 넘지 않는다 — 주문 합계는 환불마다 잔여로 줄어
     * 몫의 합이 승인 면세액을 넘을 수 있다(토스 S1-D14 와 같은 상한).
     *
     * @scenario method=card
     *
     * @effects partial_refund_carries_tax_split_of_split_approval
     *
     * @return void 반환값 없음
     */
    public function test_partial_refund_tax_free_share_is_capped_by_remaining_budget(): void
    {
        $order = $this->paidOrder(['payment_meta' => ['tax_split' => ['tax_free_amount' => 8000, 'tax_free_cancelled' => 7000]]]);
        $order->forceFill(['total_tax_amount' => 22000, 'total_vat_amount' => 2000, 'total_tax_free_amount' => 8000])->save();

        $captured = null;
        $api = $this->createMock(KgInicisApiService::class);
        $api->method('cancelPayment')->willReturnCallback(function (...$args) use (&$captured) {
            $captured = $args[6] ?? null;

            return ['resultCode' => '00', 'prtcTid' => 'PRTC_2'];
        });
        $this->app->instance(KgInicisApiService::class, $api);

        app(PaymentRefundListener::class)->processRefund(['success' => false], $order->fresh(), $order->payment, 10000.0, '부분');

        // 비과세 몫 2667 → 잔여 예산 1000 으로 상한, 부가세 = 9000 × 2000/22000 = 818
        $this->assertSame(['tax' => 818, 'taxFree' => 1000], $captured);
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
