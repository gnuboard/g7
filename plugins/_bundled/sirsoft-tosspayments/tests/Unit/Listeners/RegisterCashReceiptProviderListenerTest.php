<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\Tosspayments\Listeners\RegisterCashReceiptProviderListener;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * RegisterCashReceiptProviderListener 단위 테스트
 *
 * 현금영수증 프로바이더 등록 + 발급/취소 위임 + orderId 회차 접미를 검증한다.
 */
class RegisterCashReceiptProviderListenerTest extends PluginTestCase
{
    private const PROVIDER_ID = 'tosspayments';

    /**
     * 발급 훅의 기본 반환값 (코어 CashReceiptService::defaultIssueResult 와 동형)
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
     * 취소 훅의 기본 반환값
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
     * 코어가 전달하는 발급 페이로드를 생성합니다.
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
            'amount' => 10000,
            'tax_free_amount' => 0,
            'order_name' => '테스트 상품',
            'order_number' => 'ORD20260712001',
            'issue_sequence' => 1,
        ], $overrides);
    }

    /**
     * 토스 현금영수증 발급 API 를 성공 응답으로 가짜 처리합니다.
     */
    private function fakeIssueApi(): void
    {
        Http::fake([
            'api.tosspayments.com/v1/cash-receipts' => Http::response([
                'receiptKey' => 'RK_123',
                'receiptUrl' => 'https://dashboard.tosspayments.com/receipt/RK_123',
                'issueNumber' => 'ISSUE_999',
            ], 200),
            'api.tosspayments.com/*' => Http::response([], 200),
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
     * 프로바이더 등록: 기존 목록을 덮어쓰지 않고 자기 항목을 추가하는지 확인
     *
     * @effects provider_registered_without_overwriting_others
     */
    public function test_register_provider_appends_without_overwriting(): void
    {
        $existing = [['id' => 'kginicis', 'name' => 'KG이니시스']];

        $result = (new RegisterCashReceiptProviderListener)->registerProvider($existing);

        $this->assertCount(2, $result);
        $this->assertEquals('kginicis', $result[0]['id']);
        $this->assertEquals(self::PROVIDER_ID, $result[1]['id']);
        $this->assertNotEmpty($result[1]['name_key']);
    }

    /**
     * 발급: 타 프로바이더 요청은 건드리지 않고 그대로 통과시키는지 확인
     *
     * @effects other_provider_request_passes_through_untouched
     */
    public function test_issue_early_returns_for_other_provider(): void
    {
        Http::fake();
        $order = Order::factory()->create();
        $default = $this->defaultIssueResult();

        $result = (new RegisterCashReceiptProviderListener)->issue($default, $order, 'kginicis', $this->issuePayload());

        $this->assertSame($default, $result);
        Http::assertNothingSent();
    }

    /**
     * 발급: 성공 시 코어가 기대하는 키(receipt_key/receipt_url/issue_number)를 반환하는지 확인
     *
     * @effects issue_response_mapped_to_receipt_key_and_issue_number
     */
    public function test_issue_returns_receipt_fields_on_success(): void
    {
        $this->fakeIssueApi();
        $order = Order::factory()->create();

        $result = (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload()
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('RK_123', $result['receipt_key']);
        $this->assertEquals('https://dashboard.tosspayments.com/receipt/RK_123', $result['receipt_url']);
        $this->assertEquals('ISSUE_999', $result['issue_number']);
        $this->assertNull($result['error_code']);
    }

    /**
     * 발급: 코어 enum(income/expense) 을 토스가 요구하는 한글 type 으로 변환하는지 확인
     *
     * 프로바이더 종속 표현(한글)은 리스너 내부에서만 다룬다.
     *
     * @effects receipt_type_converted_to_provider_specific_value
     */
    public function test_issue_converts_type_to_korean_for_toss(): void
    {
        $this->fakeIssueApi();
        $order = Order::factory()->create();
        $listener = new RegisterCashReceiptProviderListener;

        $listener->issue($this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload(['type' => 'income']));
        Http::assertSent(fn ($request) => ($request->data()['type'] ?? null) === '소득공제');

        Http::fake([
            'api.tosspayments.com/v1/cash-receipts' => Http::response(['receiptKey' => 'RK_2'], 200),
        ]);
        $listener->issue($this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload(['type' => 'expense']));
        Http::assertSent(fn ($request) => ($request->data()['type'] ?? null) === '지출증빙');
    }

    /**
     * 발급: orderId 에 발급 회차를 접미하는지 확인 (재발급 시 중복 거부 회피)
     *
     * @effects order_id_suffixed_with_issue_sequence
     */
    public function test_issue_appends_sequence_suffix_to_order_id(): void
    {
        $this->fakeIssueApi();
        $order = Order::factory()->create();

        (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(),
            $order,
            self::PROVIDER_ID,
            $this->issuePayload(['order_number' => 'ORD20260712001', 'issue_sequence' => 3])
        );

        Http::assertSent(fn ($request) => ($request->data()['orderId'] ?? null) === 'ORD20260712001-cr3');
    }

    /**
     * 발급: orderId 가 토스 제약(6~64자, 영숫자/-/_)을 벗어나면 API 호출 전에 실패하는지 확인
     *
     * @effects order_id_constraint_violation_blocked_before_api_call
     */
    public function test_issue_rejects_order_id_violating_toss_constraints(): void
    {
        Http::fake();
        $order = Order::factory()->create();

        $result = (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(),
            $order,
            self::PROVIDER_ID,
            // 허용되지 않는 문자(한글) 포함
            $this->issuePayload(['order_number' => '주문번호!!'])
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_ORDER_ID', $result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * 발급: 면세 금액이 있으면 taxFreeAmount 를 동반 전달하는지 확인
     *
     * @effects tax_free_amount_sent_with_issue
     */
    public function test_issue_sends_tax_free_amount(): void
    {
        $this->fakeIssueApi();
        $order = Order::factory()->create();

        (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(),
            $order,
            self::PROVIDER_ID,
            $this->issuePayload(['amount' => 10000, 'tax_free_amount' => 3000])
        );

        Http::assertSent(fn ($request) => ($request->data()['taxFreeAmount'] ?? null) === 3000
            && ($request->data()['amount'] ?? null) === 10000
            && ($request->data()['customerIdentityNumber'] ?? null) === '01012345678');
    }

    /**
     * 발급: API 오류 시 실패 결과를 반환하는지 확인 (예외를 밖으로 던지지 않는다)
     *
     * @effects issue_failure_returns_result_without_throwing
     */
    public function test_issue_returns_failure_on_api_error(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['message' => '발급 불가'], 400),
        ]);
        $order = Order::factory()->create();

        $result = (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(), $order, self::PROVIDER_ID, $this->issuePayload()
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('PG_API_ERROR', $result['error_code']);
        $this->assertNotEmpty($result['error_message']);
    }

    /**
     * 발급: 멱등키(Idempotency-Key)를 부착하는지 확인
     *
     * 네트워크 재시도로 같은 발급 요청이 두 번 도달하면 현금영수증이 이중 발급되고
     * 국세청 신고가 틀어진다. 발급 회차가 포함된 orderId 는 재시도 간에 안정적이므로
     * 그대로 멱등키로 쓴다.
     *
     * @effects issue_request_carries_idempotency_key
     */
    public function test_issue_sends_idempotency_key(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['receiptKey' => 'RK', 'issueNumber' => '1'], 200),
        ]);
        $order = Order::factory()->create();

        (new RegisterCashReceiptProviderListener)->issue(
            $this->defaultIssueResult(), $order, self::PROVIDER_ID,
            $this->issuePayload(['order_number' => 'ORD-20260712', 'issue_sequence' => 2])
        );

        Http::assertSent(function ($request) {
            $key = $request->header('Idempotency-Key')[0] ?? null;

            // 발급 회차가 반영된 orderId 와 동일해야 재시도 간 안정적이다.
            return $key === 'ORD-20260712-cr2';
        });
    }

    /**
     * 취소: 멱등키(Idempotency-Key)를 부착하는지 확인
     *
     * 재시도로 취소가 두 번 도달하면 이미 취소된 영수증을 다시 취소하려 해 실패하거나
     * 이력이 어긋난다. 취소 대상 영수증 키는 재시도 간에 안정적이다.
     *
     * @effects cancel_request_carries_idempotency_key
     */
    public function test_cancel_sends_idempotency_key(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['receiptKey' => 'c_RK_123'], 200),
        ]);
        $order = Order::factory()->create();

        (new RegisterCashReceiptProviderListener)->cancel(
            $this->defaultCancelResult(), $order, self::PROVIDER_ID, 'RK_123'
        );

        Http::assertSent(function ($request) {
            $key = $request->header('Idempotency-Key')[0] ?? null;

            return $key === 'cr-cancel-RK_123';
        });
    }

    /**
     * 취소: 타 프로바이더 요청은 그대로 통과시키는지 확인
     *
     * @effects other_provider_request_passes_through_untouched
     */
    public function test_cancel_early_returns_for_other_provider(): void
    {
        Http::fake();
        $order = Order::factory()->create();
        $default = $this->defaultCancelResult();

        $result = (new RegisterCashReceiptProviderListener)->cancel($default, $order, 'kginicis', 'RK_123');

        $this->assertSame($default, $result);
        Http::assertNothingSent();
    }

    /**
     * 취소: 전액취소로 위임하는지 확인 (본문에 amount 를 넣지 않는다 — D7)
     *
     * cancelReason 은 토스가 요구하는 필수 파라미터다. 빠뜨리면 실서버가
     * 400 INVALID_REQUEST("필수 파라미터가 누락되었습니다.") 로 거부한다.
     *
     * @effects cancel_delegated_as_full_cancel_without_amount
     * @effects cancel_sends_required_cancel_reason
     */
    public function test_cancel_delegates_as_full_cancel_without_amount(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['receiptKey' => 'RK_123'], 200),
        ]);
        $order = Order::factory()->create();

        $result = (new RegisterCashReceiptProviderListener)->cancel(
            $this->defaultCancelResult(), $order, self::PROVIDER_ID, 'RK_123'
        );

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/v1/cash-receipts/RK_123/cancel')
                && ! array_key_exists('amount', $data)
                && ! empty($data['cancelReason']);
        });
    }

    /**
     * 취소: 취소 결과의 receipt_key 가 "취소된 영수증"의 키여야 하는지 확인
     *
     * 토스는 취소 응답으로 'c_' 접두 키(취소 거래 자체의 키)를 돌려준다. 그 값을 그대로
     * 저장하면 코어의 OrderCashReceipt::filterActive() 가 취소 행을 발급 행과 매칭하지
     * 못해(키가 달라서) 취소된 영수증이 영원히 "활성"으로 남고, 금액 변동 시 재발급이
     * 일어나지 않는다.
     *
     * @effects cancel_uses_issue_tid_as_receipt_key
     */
    public function test_cancel_returns_cancelled_receipt_key_not_cancel_transaction_key(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['receiptKey' => 'c_RK_123'], 200),
        ]);
        $order = Order::factory()->create();

        $result = (new RegisterCashReceiptProviderListener)->cancel(
            $this->defaultCancelResult(), $order, self::PROVIDER_ID, 'RK_123'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'RK_123',
            $result['receipt_key'],
            '취소 행은 취소된 영수증의 키를 보관해야 합니다 (취소 거래 키 c_* 가 아니라).'
        );
    }

    /**
     * 취소: API 오류 시 실패 결과를 반환하는지 확인
     */
    public function test_cancel_returns_failure_on_api_error(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['message' => '취소 불가'], 400),
        ]);
        $order = Order::factory()->create();

        $result = (new RegisterCashReceiptProviderListener)->cancel(
            $this->defaultCancelResult(), $order, self::PROVIDER_ID, 'RK_123'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals('PG_API_ERROR', $result['error_code']);
    }
}
