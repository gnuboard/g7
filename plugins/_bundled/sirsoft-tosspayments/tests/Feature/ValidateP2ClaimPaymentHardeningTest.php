<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\Tosspayments\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\Tosspayments\Listeners\PaymentRefundListener;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스페이먼츠 클레임 결제·환불 경로의 #599 validate P2 결함 회귀 테스트
 *
 * - #8 클라이언트 승인 API 경로(`respondApprove` → `UserClaimService::approve`)에는 승인 뒤 거절의
 *   보상(취소)이 없었다 — 서버 콜백만 `ClaimPaymentCallback` 클로저로 보상했다. 판정·보상을
 *   승인 리스너로 옮겨 두 경로를 같게 한다.
 * - CP 콜백에 결제번호 단위 직렬화 락이 없었다(형제 kginicis·nhnkcp 는 있다).
 * - 환불의 취소 장부 동기화(`syncCancelSnapshot`) 실패가 이미 성립한 토스 취소를 실패로 바꿨다.
 * - 콜백 컨트롤러·환불 리스너가 `app()` 로 Repository 를 꺼냈다.
 */
class ValidateP2ClaimPaymentHardeningTest extends PluginTestCase
{
    private static int $sequence = 0;

    /**
     * 테스트 DB 를 다시 만들지 않고 트랜잭션으로만 격리합니다. (DatabaseTransactions 와 같은 격리 —
     * 공유 테스트 DB 를 migrate:fresh 로 지우지 않는다)
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 승인 리스너를 훅에 다시 걸고 플러그인 설정을 테스트 모드 고정값으로 대체합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-tosspayments');

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_secret_key' => 'test_sk_mock_key',
            'redirect_success_url' => ShopRedirectUrl::DEFAULT_SUCCESS_URL,
            'redirect_fail_url' => ShopRedirectUrl::DEFAULT_FAIL_URL,
        ]);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (회원 주문 + 반품 신청서 + KRW 통화 스냅샷, 청구 3000)
     *
     * @param  array  $paymentOverrides  결제 건 덮어쓸 속성
     * @return array{order: Order, return: OrderReturn, payment: ClaimPayment} 주문·신청서·결제 건
     */
    private function makeClaimPayment(array $paymentOverrides = []): array
    {
        $seq = ++self::$sequence;

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-TOSSP2-'.$seq,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'exchange_rates' => [
                    'KRW' => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                ],
            ],
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-TOSSP2-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create(array_merge([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-TOSSP2-'.$seq,
            'pg_provider' => 'tosspayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ], $paymentOverrides));

        return ['order' => $order, 'return' => $return, 'payment' => $payment];
    }

    /**
     * 토스 Confirm·취소 API 를 가짜로 세웁니다.
     *
     * @param  array  $confirmResponse  Confirm 응답
     * @return void 반환값 없음
     */
    private function fakeToss(array $confirmResponse): void
    {
        Http::fake([
            'api.tosspayments.com/v1/payments/confirm' => Http::response($confirmResponse, 200),
            'api.tosspayments.com/v1/payments/*/cancel' => Http::response(['cancels' => [['transactionKey' => 'txc']]], 200),
        ]);
    }

    /**
     * 취소 API 가 불렸는지 판정하는 조건입니다.
     *
     * @return \Closure(HttpRequest): bool 조건
     */
    private function isCancelCall(): \Closure
    {
        return fn (HttpRequest $request) => str_contains($request->url(), '/cancel');
    }

    /**
     * 클라이언트 승인 API 경로에서 승인 응답 금액이 청구액과 다르면 토스 승인을 취소하고 거절한다.
     *
     * 수정 전: 리스너가 응답 금액(4000)을 그대로 `paid_amount` 로 넘기고 성공을 돌려줬다. 모듈이
     * 금액 불일치로 거절했지만 이 경로에는 보상 클로저가 없어 취소 API 가 한 번도 불리지 않았다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects toss_claim_approval_is_cancelled_on_either_path_when_the_response_disagrees
     */
    public function test_the_client_approve_api_path_cancels_a_confirmation_whose_amount_differs(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeToss(['paymentKey' => 'pk_api', 'orderId' => $payment->payment_number, 'status' => 'DONE', 'totalAmount' => 4000]);

        try {
            app(UserClaimService::class)->approve($payment, [
                'paymentKey' => 'pk_api', 'orderId' => $payment->payment_number, 'amount' => 3000,
            ]);
            $this->fail('청구액과 다른 승인이 확정됐다.');
        } catch (OrderClaimException) {
            // 거절 자체는 수정 전후 같다 — 차이는 보상(취소)의 유무다.
        }

        Http::assertSent($this->isCancelCall());
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 승인 응답에 금액이 없으면 콜백 금액으로 채우지 않고 취소한 뒤 거절한다.
     *
     * 수정 전: `totalAmount ?? 콜백 amount` 로 채워 청구액과 대조가 항등식이 됐고 성공으로 끝났다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=response_amount_source
     *
     * @effects toss_claim_paid_amount_comes_only_from_the_response
     */
    public function test_a_confirmation_without_an_amount_is_cancelled_instead_of_trusting_the_callback(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeToss(['paymentKey' => 'pk_noamt', 'orderId' => $payment->payment_number, 'status' => 'DONE']);

        $result = app(ClaimPaymentApproveListener::class)->processApprove(['handled' => false], $payment, [
            'paymentKey' => 'pk_noamt', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
        Http::assertSent($this->isCancelCall());
    }

    /**
     * 승인 응답의 주문번호가 이 결제 건이 아니면 취소하고 거절한다.
     *
     * 수정 전: 응답 주문번호를 보지 않아 성공으로 끝났다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects toss_claim_approval_is_cancelled_on_either_path_when_the_response_disagrees
     */
    public function test_a_confirmation_for_another_order_id_is_cancelled(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeToss(['paymentKey' => 'pk_other', 'orderId' => 'CP-SOMEONE-ELSE', 'status' => 'DONE', 'totalAmount' => 3000]);

        $result = app(ClaimPaymentApproveListener::class)->processApprove(['handled' => false], $payment, [
            'paymentKey' => 'pk_other', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('ORDER_ID_MISMATCH', $result['error_code']);
        Http::assertSent($this->isCancelCall());
    }

    /**
     * 깨끗한 승인은 그대로 확정된다(비회귀).
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects toss_claim_clean_confirmation_is_unchanged
     */
    public function test_a_clean_confirmation_is_still_approved(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeToss(['paymentKey' => 'pk_ok', 'orderId' => $payment->payment_number, 'status' => 'DONE', 'totalAmount' => 3000]);

        $result = app(ClaimPaymentApproveListener::class)->processApprove(['handled' => false], $payment, [
            'paymentKey' => 'pk_ok', 'orderId' => $payment->payment_number, 'amount' => 3000,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(3000, $result['paid_amount']);
        Http::assertNotSent($this->isCancelCall());
    }

    /**
     * 콜백 대기 시간을 0초로 줄인 컨트롤러를 라우트에 묶습니다. (락 대기 초과를 즉시 재현)
     *
     * @return void 반환값 없음
     */
    private function bindImpatientCallbackController(): void
    {
        $this->app->bind(PaymentCallbackController::class, fn ($app) => new class($app->make(OrderProcessingService::class), $app->make(PluginSettingsService::class), $app->make(TossPaymentsApiService::class), $app->make(ClaimPaymentRepositoryInterface::class)) extends PaymentCallbackController
        {
            /**
             * 락 대기 시간을 0초로 줄여 대기 초과를 즉시 재현합니다.
             *
             * @return int 초
             */
            protected function callbackLockWaitSeconds(): int
            {
                return 0;
            }
        });
    }

    /**
     * 같은 결제번호의 콜백이 처리 중이면(락 보유) 승인을 부르지 않고 재시도 안내로 돌려보낸다.
     *
     * 수정 전: CP 분기에 락이 없어 겹친 콜백이 그대로 Confirm 을 불렀다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_serialization
     *
     * @effects toss_claim_callback_is_serialized_per_payment_number
     */
    public function test_an_overlapping_claim_callback_waits_for_the_lock_and_does_not_confirm_again(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makeClaimPayment();
        $this->fakeToss(['paymentKey' => 'pk_dup', 'orderId' => $payment->payment_number, 'status' => 'DONE', 'totalAmount' => 3000]);
        $this->bindImpatientCallbackController();

        $held = Cache::lock('sirsoft-tosspayments:callback:success:'.sha1($payment->payment_number), 30);
        $this->assertTrue($held->get());

        try {
            $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
                'paymentKey' => 'pk_dup',
                'orderId' => $payment->payment_number,
                'amount' => 3000,
            ]));
        } finally {
            $held->release();
        }

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=failed&error=callback_locked&claim_number={$return->return_number}");
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/confirm'));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 락을 쥔 앞 요청이 이미 결제를 확정했으면 성공으로 돌려보낸다.
     *
     * 락 대기 초과를 500 대신 정상 응답으로 끝내는 분기의 성공 축이다(수정 전에는 락 자체가 없어
     * 이미 결제된 건을 모듈이 그대로 돌려줘 같은 결과였다 — 회귀 방지용).
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_serialization
     *
     * @effects toss_claim_callback_is_serialized_per_payment_number
     */
    public function test_an_overlapping_claim_callback_after_the_holder_paid_redirects_to_success(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makeClaimPayment([
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'transaction_id' => 'pk_paid_'.uniqid(),
            'paid_at' => now(),
        ]);
        Http::fake();
        $this->bindImpatientCallbackController();

        $held = Cache::lock('sirsoft-tosspayments:callback:success:'.sha1($payment->payment_number), 30);
        $this->assertTrue($held->get());

        try {
            $response = $this->get('/plugins/sirsoft-tosspayments/payment/success?'.http_build_query([
                'paymentKey' => 'pk_dup2',
                'orderId' => $payment->payment_number,
                'amount' => 3000,
            ]));
        } finally {
            $held->release();
        }

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=success&claim_number={$return->return_number}");
        Http::assertNothingSent();
    }

    /**
     * 토스 취소가 성립한 뒤 취소 장부 저장이 실패해도 환불 결과는 성공이다.
     *
     * 수정 전: 장부 동기화가 취소와 같은 try 안이라, 저장 예외가 `success=false` · `PG_API_ERROR` 로
     * 바뀌어 돈이 나간 환불이 실패로 기록되고 재시도가 같은 금액을 다시 취소하러 갔다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=refund_ledger_sync
     *
     * @effects toss_refund_success_is_independent_of_the_cancel_ledger_sync
     */
    public function test_a_refund_whose_cancel_ledger_sync_fails_still_reports_success(): void
    {
        Http::fake([
            'api.tosspayments.com/*' => Http::response(['cancels' => [['transactionKey' => 'TXN_LEDGER_1']]], 200),
        ]);

        $repository = $this->createMock(OrderPaymentRepositoryInterface::class);
        $repository->expects($this->once())->method('savePendingChanges')->willThrowException(new \RuntimeException('db down'));
        $this->app->instance(OrderPaymentRepositoryInterface::class, $repository);

        $order = new Order;
        $order->forceFill(['total_tax_free_amount' => 0, 'total_tax_amount' => 10000]);

        $payment = new OrderPayment;
        $payment->forceFill([
            'pg_provider' => 'tosspayments',
            'transaction_id' => 'pk_ledger',
            'paid_amount_local' => 10000,
            'cancelled_amount' => 0,
            'currency' => 'KRW',
            'is_escrow' => false,
            'payment_meta' => ['pg_raw_response' => ['taxFreeAmount' => 0]],
        ]);

        $result = app(PaymentRefundListener::class)->processRefund(
            ['success' => false],
            $order,
            $payment,
            10000.0,
            '테스트 환불',
        );

        $this->assertTrue($result['success']);
        $this->assertSame('TXN_LEDGER_1', $result['transaction_id']);
    }

    /**
     * 콜백 컨트롤러와 환불 리스너가 Repository 를 생성자로 주입받는다(서비스 로케이터 없음).
     *
     * 수정 전: 두 클래스 모두 `app(...RepositoryInterface::class)` 로 꺼냈다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=repository_injection
     *
     * @effects toss_repositories_are_constructor_injected
     */
    public function test_repositories_are_constructor_injected(): void
    {
        $this->assertConstructorTakes(PaymentCallbackController::class, ClaimPaymentRepositoryInterface::class);
        $this->assertConstructorTakes(PaymentRefundListener::class, OrderPaymentRepositoryInterface::class);
    }

    /**
     * 클래스 생성자가 주어진 타입의 인자를 받는지 확인합니다.
     *
     * @param  class-string  $class  대상 클래스
     * @param  class-string  $type  기대 인자 타입
     * @return void 반환값 없음
     */
    private function assertConstructorTakes(string $class, string $type): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $types = array_map(
            fn (\ReflectionParameter $parameter) => (string) $parameter->getType(),
            $constructor?->getParameters() ?? [],
        );

        $this->assertContains($type, $types, "{$class} 가 {$type} 를 생성자로 받지 않는다.");
    }

    /**
     * 모듈이 승인 뒤 거절한 건을 `claim_payment.void_approval` 필터로 되돌린다(토스 취소).
     *
     * 수정 전: 이 플러그인은 그 필터를 구독하지 않아 `handled=false` 로 끝났다 — 클라이언트 승인 API
     * 경로의 거절에는 보상이 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=void_approval
     *
     * @effects toss_claim_void_approval_cancels_the_confirmation
     */
    public function test_the_void_approval_filter_cancels_the_toss_confirmation(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake([
            'api.tosspayments.com/v1/payments/*/cancel' => Http::response(['cancels' => []], 200),
        ]);

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.void_approval',
            ['handled' => false, 'success' => false, 'error_code' => null, 'error_message' => null],
            $payment,
            ['paymentKey' => 'pk_void', 'orderId' => $payment->payment_number, 'amount' => 3000],
            ['transaction_id' => 'pk_void', 'paid_amount' => 3000],
        );

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/payments/pk_void/cancel'));
    }
}
