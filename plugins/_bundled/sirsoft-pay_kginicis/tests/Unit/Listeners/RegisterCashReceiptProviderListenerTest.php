<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Listeners;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayKginicis\Listeners\RegisterCashReceiptProviderListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * RegisterCashReceiptProviderListener 단위 테스트
 *
 * KG이니시스를 공용 현금영수증 프로바이더 축에 등록하고, 발급/취소를 위임하는지 검증한다.
 */
class RegisterCashReceiptProviderListenerTest extends PluginTestCase
{
    private const PROVIDER_ID = 'kginicis';

    /**
     * KG 테스트 자격증명을 주입합니다.
     *
     * 발급 API 는 식별번호를 AES-128-CBC 로 암호화하므로 INIAPI 키/IV 가 없으면
     * openssl_encrypt 가 실패한다. 실제 API 서비스를 그대로 쓰되 설정만 주입한다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => 'INIpayTest',
            'test_sign_key' => 'SU5JTElURV9UUklQTEVERVNfS0VZU1RS',
            'test_iniapi_key' => 'ItEQKi3rY7uvDS8l',
            'test_iniapi_iv' => 'HYb3yQ4f65QL89==',
        ]);

        $this->app->instance(PluginSettingsService::class, $settings);
        $this->app->forgetInstance(KgInicisApiService::class);
    }

    /**
     * 발급 훅 기본 반환값 (코어 CashReceiptService 와 동형)
     *
     * @return array<string, mixed>
     */
    private function defaultIssueResult(): array
    {
        return [
            'success' => false,
            'error_code' => 'NO_PROVIDER_HANDLED',
            'error_message' => 'no provider',
            'receipt_key' => null,
            'receipt_url' => null,
            'issue_number' => null,
            'raw_response' => null,
        ];
    }

    /**
     * 취소 훅 기본 반환값
     *
     * @return array<string, mixed>
     */
    private function defaultCancelResult(): array
    {
        return [
            'success' => false,
            'error_code' => 'NO_PROVIDER_HANDLED',
            'error_message' => 'no provider',
            'receipt_key' => null,
            'raw_response' => null,
        ];
    }

    /**
     * 코어가 전달하는 발급 페이로드
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 키
     * @return array<string, mixed>
     */
    private function issuePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'income',
            'identifier' => '01012345678',
            'identifier_type' => 'phone',
            'amount' => 11000,
            'tax_free_amount' => 0,
            'order_name' => '테스트 상품',
            'order_number' => 'ORD20260712001',
            'issue_sequence' => 1,
        ], $overrides);
    }

    /**
     * KG 결제가 달린 주문을 생성합니다.
     */
    private function createKgOrder(): Order
    {
        $order = Order::factory()->create();

        OrderPayment::factory()->create([
            'order_id' => $order->id,
            'pg_provider' => self::PROVIDER_ID,
            'transaction_id' => 'KG_TID_1',
            'buyer_name' => '홍길동',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '01099998888',
        ]);

        return $order->fresh(['payment']);
    }

    /**
     * 발급 성공 응답 (INIAPI: tid = 현금영수증 발급 거래번호, authNo = 승인번호)
     */
    private function fakeIssueApi(): void
    {
        Http::fake([
            '*/v2/pg/receipt' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '정상',
                'tid' => 'CR_TID_1',
                'authNo' => 'AUTH_555',
            ], 200),
        ]);
    }

    /**
     * 구독 훅 매핑이 3개 훅을 filter 로 선언하는지 확인
     */
    public function test_get_subscribed_hooks_declares_three_filter_hooks(): void
    {
        $hooks = RegisterCashReceiptProviderListener::getSubscribedHooks();

        foreach ([
            'sirsoft-ecommerce.cash_receipt.registered_providers',
            'sirsoft-ecommerce.cash_receipt.issue',
            'sirsoft-ecommerce.cash_receipt.cancel',
        ] as $hook) {
            $this->assertArrayHasKey($hook, $hooks);
            $this->assertEquals('filter', $hooks[$hook]['type'], "{$hook} 은 filter 여야 합니다");
        }
    }

    /**
     * 프로바이더 등록: 기존 목록(토스 등)을 덮어쓰지 않고 자기 항목만 추가하는지 확인
     *
     * @effects provider_registered_without_overwriting_others
     */
    public function test_register_provider_appends_without_overwriting(): void
    {
        $existing = [['id' => 'tosspayments', 'name' => '토스페이먼츠']];

        $result = (new RegisterCashReceiptProviderListener)->registerProvider($existing);

        $this->assertCount(2, $result);
        $this->assertEquals('tosspayments', $result[0]['id']);
        $this->assertEquals(self::PROVIDER_ID, $result[1]['id']);
    }

    /**
     * 발급: 타 프로바이더 요청은 그대로 통과시키는지 확인
     *
     * @effects other_provider_request_passes_through_untouched
     */
    public function test_issue_early_returns_for_other_provider(): void
    {
        Http::fake();
        $order = $this->createKgOrder();
        $default = $this->defaultIssueResult();

        $result = (new RegisterCashReceiptProviderListener)->issue(
            $default, $order, 'tosspayments', $this->issuePayload()
        );

        $this->assertSame($default, $result);
        Http::assertNothingSent();
    }

    /**
     * 발급: 성공 시 취소용 TID 를 receipt_key 로, 승인번호를 issue_number 로 반환하는지 확인
     *
     * INIAPI 발급 응답의 tid 가 "현금영수증발급 거래번호" 이며 취소 시 이 값을 쓴다.
     *
     * @effects issue_response_mapped_to_receipt_key_and_issue_number
     */
    public function test_issue_maps_tid_to_receipt_key_and_auth_no_to_issue_number(): void
    {
        $this->fakeIssueApi();
        $order = $this->createKgOrder();

        $result = (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload()
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('CR_TID_1', $result['receipt_key'], '취소에 쓸 TID 가 receipt_key 여야 합니다');
        $this->assertEquals('AUTH_555', $result['issue_number']);
        $this->assertNull($result['error_code']);
    }

    /**
     * 발급: 코어 enum(income/expense) 을 KG issueType(0/1) 으로 변환하는지 확인
     *
     * @effects receipt_type_converted_to_provider_specific_value
     */
    public function test_issue_converts_type_to_kg_issue_type(): void
    {
        $this->fakeIssueApi();
        $order = $this->createKgOrder();
        $listener = new RegisterCashReceiptProviderListener;

        $listener->issue($this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload(['type' => 'income']));
        Http::assertSent(fn ($request) => ($request->data()['data']['issueType'] ?? null) === '0');

        $this->fakeIssueApi();
        $listener->issue($this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload(['type' => 'expense']));
        Http::assertSent(fn ($request) => ($request->data()['data']['issueType'] ?? null) === '1');
    }

    /**
     * 발급: 면세금액을 반영해 공급가액/부가세를 산출하는지 확인
     *
     * 과세분 11000 중 면세 1000 → 과세대상 10000 → 부가세 909, 공급가액 10091 (총액 보존)
     *
     * @effects tax_free_amount_sent_with_issue
     */
    public function test_issue_computes_supply_price_and_tax_with_tax_free_amount(): void
    {
        $this->fakeIssueApi();
        $order = $this->createKgOrder();

        (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(),
            $order,
            self::PROVIDER_ID,
            $this->issuePayload(['amount' => 11000, 'tax_free_amount' => 1000])
        );

        Http::assertSent(function ($request) {
            $d = $request->data()['data'] ?? [];
            $price = (int) $d['price'];
            $supply = (int) $d['supplyPrice'];
            $tax = (int) $d['tax'];

            // 총액 = 공급가액 + 부가세 (면세분은 공급가액에 포함)
            return $price === 11000 && ($supply + $tax) === 11000 && $tax === 909;
        });
    }

    /**
     * 발급: PG 가 실패코드를 주면 실패 결과를 반환하는지 확인 (예외 전파 없음)
     *
     * @effects issue_failure_returns_result_without_throwing
     */
    public function test_issue_returns_failure_on_pg_result_code(): void
    {
        Http::fake([
            '*/v2/pg/receipt' => Http::response([
                'resultCode' => '01',
                'resultMsg' => '발급 불가',
            ], 200),
        ]);
        $order = $this->createKgOrder();

        $result = (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload()
        );

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error_message']);
    }

    /**
     * 취소: 타 프로바이더 요청은 그대로 통과시키는지 확인
     *
     * @effects other_provider_request_passes_through_untouched
     */
    public function test_cancel_early_returns_for_other_provider(): void
    {
        Http::fake();
        $order = $this->createKgOrder();
        $default = $this->defaultCancelResult();

        $result = (new RegisterCashReceiptProviderListener)->cancel($default, $order, 'tosspayments', 'CR_TID_1');

        $this->assertSame($default, $result);
        Http::assertNothingSent();
    }

    /**
     * 취소: 발급 TID 로 refund 엔드포인트를 호출하는지 확인
     *
     * KG 는 현금영수증 취소도 결제취소와 같은 refund 엔드포인트를 쓰며,
     * 현금영수증 발행건이면 응답에 취소승인번호(cshrCancelNum)가 내려온다.
     *
     * @effects cancel_uses_issue_tid_as_receipt_key
     */
    public function test_cancel_calls_refund_endpoint_with_receipt_tid(): void
    {
        Http::fake([
            '*/v2/pg/refund' => Http::response([
                'resultCode' => '00',
                'resultMsg' => '정상',
                'cshrCancelNum' => 'CANCEL_777',
            ], 200),
        ]);
        $order = $this->createKgOrder();

        $result = (new RegisterCashReceiptProviderListener)->cancel(
            $this->defaultCancelResult(), $order, self::PROVIDER_ID, 'CR_TID_1'
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/pg/refund')
                && ($request->data()['data']['tid'] ?? null) === 'CR_TID_1';
        });
    }

    /**
     * 취소: PG 실패 시 실패 결과를 반환하는지 확인
     */
    public function test_cancel_returns_failure_on_pg_error(): void
    {
        Http::fake([
            '*/v2/pg/refund' => Http::response([
                'resultCode' => '01',
                'resultMsg' => '취소 불가',
            ], 200),
        ]);
        $order = $this->createKgOrder();

        $result = (new RegisterCashReceiptProviderListener)->cancel(
            $this->defaultCancelResult(), $order, self::PROVIDER_ID, 'CR_TID_1'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('PG_API_ERROR', $result['error_code']);
    }
}
