<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature;

use App\Contracts\Extension\CacheInterface;
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
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayNicepayments\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

/**
 * 나이스페이먼츠 클레임 결제 경로의 #599 validate P2 결함 회귀 테스트
 *
 * - #8 클라이언트 승인 API 경로에는 승인 뒤 거절의 보상(망취소)이 없었다 — 판정·보상을 승인
 *   리스너로 옮겨 서버 콜백과 같게 한다. 서명 금액(Amt)도 승인 호출 **전에** 청구액과 대조한다.
 * - CP 콜백에 결제번호 단위 직렬화 락이 없었다.
 * - 콜백 컨트롤러가 `app()` 로 Repository 를 꺼냈다.
 */
class ValidateP2ClaimPaymentHardeningTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'test-merchant-key-for-signature';

    private const NEXT_APP_URL = 'https://webapi.nicepay.co.kr/webapi/pay_process.jsp';

    private const NET_CANCEL_URL = 'https://webapi.nicepay.co.kr/webapi/cancel_process.jsp';

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
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_nicepayments');

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn([
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
        ]);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (회원 주문 + 반품 신청서 + KRW 통화 스냅샷, 청구 3000)
     *
     * @return array{return: OrderReturn, payment: ClaimPayment} 신청서·결제 건
     */
    private function makeClaimPayment(): array
    {
        $seq = ++self::$sequence;

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-NICEP2-'.$seq,
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
            'return_number' => 'RT-NICEP2-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-NICEP2-'.$seq,
            'pg_provider' => 'nicepayments',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return ['return' => $return, 'payment' => $payment];
    }

    /**
     * 서명이 맞는 인증 콜백 파라미터를 만듭니다.
     *
     * @param  ClaimPayment  $payment  결제 건 (Moid)
     * @param  int  $amt  서명이 덮는 금액
     * @param  array  $overrides  덮어쓸 값
     * @return array 콜백
     */
    private function signedCallback(ClaimPayment $payment, int $amt = 3000, array $overrides = []): array
    {
        $authToken = 'nice-auth-token-'.$payment->payment_number;
        $signature = bin2hex(hash('sha256', $authToken.self::TEST_MID.(string) $amt.self::TEST_MERCHANT_KEY, true));

        return array_merge([
            'AuthResultCode' => '0000',
            'NextAppURL' => self::NEXT_APP_URL,
            'TxTid' => 'nicepay00m01012609230000000001',
            'AuthToken' => $authToken,
            'PayMethod' => 'CARD',
            'MID' => self::TEST_MID,
            'Moid' => $payment->payment_number,
            'Amt' => $amt,
            'NetCancelURL' => self::NET_CANCEL_URL,
            'Signature' => $signature,
        ], $overrides);
    }

    /**
     * 나이스 승인·망취소 API 를 가짜로 세웁니다.
     *
     * @param  array  $approveResponse  승인 응답
     * @return void 반환값 없음
     */
    private function fakeNice(array $approveResponse): void
    {
        Http::fake([
            self::NEXT_APP_URL => Http::response(json_encode($approveResponse), 200),
            self::NET_CANCEL_URL => Http::response('OK', 200),
        ]);
    }

    /**
     * 승인 리스너를 부릅니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  콜백
     * @return array 승인 결과
     */
    private function approve(ClaimPayment $payment, array $callback): array
    {
        return app(ClaimPaymentApproveListener::class)->processApprove(['handled' => false], $payment, $callback);
    }

    /**
     * 망취소가 불렸는지 판정하는 조건입니다.
     *
     * @return \Closure(HttpRequest): bool 조건
     */
    private function isNetCancel(): \Closure
    {
        return fn (HttpRequest $request) => str_contains($request->url(), 'cancel_process');
    }

    /**
     * 클라이언트 승인 API 경로에서 승인 응답 금액이 서명 금액과 다르면 망취소하고 거절한다.
     *
     * 수정 전: 리스너가 서명 금액(3000)을 `paid_amount` 로 넘겨 모듈 대조를 통과했고, 응답 금액(2500)은
     * 보지 않아 결제 건이 결제완료로 확정됐다. 이 경로에는 보상 클로저도 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects nice_claim_approval_is_reverted_on_either_path_when_the_response_disagrees
     */
    public function test_the_client_approve_api_path_net_cancels_a_response_whose_amount_differs(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeNice(['ResultCode' => '3001', 'TID' => 'NICE-P2-1', 'PayMethod' => 'CARD', 'Amt' => '2500', 'Moid' => $payment->payment_number]);

        try {
            app(UserClaimService::class)->approve($payment, $this->signedCallback($payment));
            $this->fail('응답 금액이 다른 승인이 확정됐다.');
        } catch (OrderClaimException) {
            // 거절이 기대 동작이다.
        }

        Http::assertSent($this->isNetCancel());
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 승인 응답에 승인 금액이 없거나 숫자가 아니면 망취소하고 거절한다. (클라이언트 승인 API 경로 포함)
     *
     * 수정 전: 응답 금액이 숫자가 아니면 대조를 건너뛰고, 브라우저가 보낸 콜백 금액을 `paid_amount` 로 넘겨
     * 결제 건이 결제완료로 확정됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approved_amount_source
     *
     * @effects nice_claim_approval_without_a_pg_amount_is_net_cancelled
     */
    public function test_a_response_without_an_approved_amount_is_net_cancelled_and_refused(): void
    {
        // 가짜 응답은 한 번만 등록한다 — Http::fake 를 거듭 부르면 먼저 등록한 응답이 이긴다.
        $approveResponse = [];
        Http::fake([
            self::NEXT_APP_URL => function () use (&$approveResponse) {
                return Http::response(json_encode($approveResponse), 200);
            },
            self::NET_CANCEL_URL => Http::response('OK', 200),
        ]);

        foreach (['missing' => null, 'empty' => '', 'non_numeric' => 'N/A'] as $label => $responseAmt) {
            // 승인 리스너 직접 호출 경로
            ['payment' => $payment] = $this->makeClaimPayment();
            $approveResponse = $this->approveResponseWithAmount($payment, 'NICE-P2-NOAMT-'.$label, $responseAmt);

            $result = $this->approve($payment, $this->signedCallback($payment));

            $this->assertFalse($result['success'], "case={$label}");
            $this->assertSame('AMOUNT_MISMATCH', $result['error_code'], "case={$label}");
            Http::assertSent($this->isNetCancelFor($payment));

            // 클라이언트 승인 API 경로도 같은 판정으로 거절되고 결제 건은 결제 대기 그대로다.
            ['payment' => $apiPayment] = $this->makeClaimPayment();
            $approveResponse = $this->approveResponseWithAmount($apiPayment, 'NICE-P2-NOAMT-API-'.$label, $responseAmt);

            try {
                app(UserClaimService::class)->approve($apiPayment, $this->signedCallback($apiPayment));
                $this->fail("승인 금액이 없는 승인이 확정됐다. case={$label}");
            } catch (OrderClaimException) {
                // 거절이 기대 동작이다.
            }

            Http::assertSent($this->isNetCancelFor($apiPayment));
            $this->assertSame(ClaimPaymentStatusEnum::PENDING, $apiPayment->fresh()->payment_status, "case={$label}");
        }
    }

    /**
     * 승인 금액 칸만 바꾼 승인 성공 응답을 만듭니다.
     *
     * @param  ClaimPayment  $payment  결제 건 (Moid)
     * @param  string  $tid  거래번호
     * @param  string|null  $amount  승인 금액 (null 이면 칸을 뺀다)
     * @return array 승인 응답
     */
    private function approveResponseWithAmount(ClaimPayment $payment, string $tid, ?string $amount): array
    {
        $response = ['ResultCode' => '3001', 'TID' => $tid, 'PayMethod' => 'CARD', 'Moid' => $payment->payment_number];

        if ($amount !== null) {
            $response['Amt'] = $amount;
        }

        return $response;
    }

    /**
     * 이 결제 건의 인증 재료로 망취소가 불렸는지 판정하는 조건입니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return \Closure(HttpRequest): bool 조건
     */
    private function isNetCancelFor(ClaimPayment $payment): \Closure
    {
        return fn (HttpRequest $request) => str_contains($request->url(), 'cancel_process')
            && ($request->data()['AuthToken'] ?? null) === 'nice-auth-token-'.$payment->payment_number;
    }

    /**
     * 모듈에 넘기는 결제 금액은 PG 승인 응답의 금액이다. (0 으로 채운 숫자 문자열도 정수로 읽는다)
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approved_amount_source
     *
     * @effects nice_claim_paid_amount_comes_from_the_pg_response
     */
    public function test_the_paid_amount_handed_to_the_module_is_the_pg_approved_amount(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeNice(['ResultCode' => '3001', 'TID' => 'NICE-P2-PADDED', 'PayMethod' => 'CARD', 'Amt' => '000000003000', 'Moid' => $payment->payment_number]);

        $result = $this->approve($payment, $this->signedCallback($payment));

        $this->assertTrue($result['success']);
        $this->assertSame(3000, $result['paid_amount']);
        Http::assertNotSent($this->isNetCancel());
    }

    /**
     * 서명 금액이 청구액과 다르면(낡은 결제창) 승인을 부르기 전에 거절한다.
     *
     * 수정 전: 서명 검증만 하고 청구액과 대조하지 않아 옛 금액으로 승인을 불렀다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=pre_approval_amount
     *
     * @effects nice_claim_stale_window_amount_is_refused_before_authorization
     */
    public function test_a_signed_amount_different_from_the_charge_is_refused_before_authorization(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake();

        $result = $this->approve($payment, $this->signedCallback($payment, 9000));

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * 승인 응답의 주문번호가 이 결제 건이 아니면 망취소하고 거절한다.
     *
     * 수정 전: 응답 주문번호를 보지 않아 성공으로 끝났다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=order_binding
     *
     * @effects nice_claim_approval_is_bound_to_the_payment_number
     */
    public function test_a_response_for_another_moid_is_net_cancelled(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->fakeNice(['ResultCode' => '3001', 'TID' => 'NICE-P2-2', 'PayMethod' => 'CARD', 'Amt' => '3000', 'Moid' => 'CP-SOMEONE-ELSE']);

        $result = $this->approve($payment, $this->signedCallback($payment));

        $this->assertFalse($result['success']);
        $this->assertSame('ORDER_ID_MISMATCH', $result['error_code']);
        Http::assertSent($this->isNetCancel());
    }

    /**
     * 콜백이 다른 주문번호(Moid)를 실어 오면 승인을 부르지 않고 거절한다.
     *
     * 수정 전: 콜백 Moid 를 보지 않아 다른 결제창의 인증 재료로 이 결제 건을 승인했다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=order_binding
     *
     * @effects nice_claim_approval_is_bound_to_the_payment_number
     */
    public function test_a_callback_for_another_moid_is_refused_before_authorization(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake();

        $result = $this->approve($payment, $this->signedCallback($payment, 3000, ['Moid' => 'ORD-SOMETHING-ELSE']));

        $this->assertFalse($result['success']);
        $this->assertSame('ORDER_ID_MISMATCH', $result['error_code']);
        Http::assertNothingSent();
    }

    /**
     * 같은 결제번호의 콜백이 처리 중이면(락 보유) 승인을 부르지 않고 재시도 안내로 돌려보낸다.
     *
     * 수정 전: CP 분기에 락이 없어 겹친 콜백이 그대로 승인을 불렀다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_serialization
     *
     * @effects nice_claim_callback_is_serialized_per_payment_number
     */
    public function test_an_overlapping_claim_callback_waits_for_the_lock_and_does_not_authorize_again(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makeClaimPayment();
        $this->fakeNice(['ResultCode' => '3001', 'TID' => 'NICE-P2-3', 'PayMethod' => 'CARD', 'Amt' => '3000']);

        $this->app->bind(PaymentCallbackController::class, fn ($app) => new class($app->make(OrderProcessingService::class), $app->make(PluginSettingsService::class), $app->make(NicePaymentsApiService::class), $app->make(CacheInterface::class), $app->make(ClaimPaymentRepositoryInterface::class), $app->make(GuestOrderAuthService::class)) extends PaymentCallbackController
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

        $held = Cache::lock('sirsoft-pay_nicepayments:callback:authCallback:'.sha1($payment->payment_number), 30);
        $this->assertTrue($held->get());

        try {
            $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $this->signedCallback($payment));
        } finally {
            $held->release();
        }

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=failed&error=callback_locked&claim_number={$return->return_number}");
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'pay_process'));
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 콜백 컨트롤러가 Repository 를 생성자로 주입받고, 공용 트레이트가 로케이터를 쓰지 않는다.
     *
     * 수정 전: `HandlesClaimPaymentCallbacks::findClaimPayment()` 가 `app(ClaimPaymentRepositoryInterface::class)` 였다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=repository_injection
     *
     * @effects nice_repositories_are_constructor_injected
     */
    public function test_the_claim_payment_repository_is_constructor_injected(): void
    {
        $types = array_map(
            fn (\ReflectionParameter $parameter) => (string) $parameter->getType(),
            (new \ReflectionClass(PaymentCallbackController::class))->getConstructor()?->getParameters() ?? [],
        );

        $this->assertContains(ClaimPaymentRepositoryInterface::class, $types);

        $trait = (string) file_get_contents(dirname(__DIR__, 2).'/src/Concerns/HandlesClaimPaymentCallbacks.php');
        $this->assertStringNotContainsString('app(ClaimPaymentRepositoryInterface::class)', $trait);
    }

    /**
     * 모듈이 승인 뒤 거절한 건을 `claim_payment.void_approval` 필터로 되돌린다(거래번호 취소 API).
     *
     * 수정 전: 이 플러그인은 그 필터를 구독하지 않아 `handled=false` 로 끝났다 — 클라이언트 승인 API
     * 경로의 거절에는 보상이 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=void_approval
     *
     * @effects nice_claim_void_approval_cancels_the_authorization
     */
    public function test_the_void_approval_filter_cancels_the_nice_authorization(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        Http::fake([
            'pg-api.nicepay.co.kr/*' => Http::response(json_encode(['ResultCode' => '2001', 'ResultMsg' => 'OK']), 200),
        ]);

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.void_approval',
            ['handled' => false, 'success' => false, 'error_code' => null, 'error_message' => null],
            $payment,
            $this->signedCallback($payment),
            ['transaction_id' => 'NICE-VOID-TID', 'paid_amount' => 3000],
        );

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'cancel_process')
            && ($request->data()['TID'] ?? null) === 'NICE-VOID-TID');
    }
}
