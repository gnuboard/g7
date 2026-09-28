<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Cache;
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
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Plugins\Sirsoft\PayNhnkcp\Controllers\MobileApprovalController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayNhnkcp\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * NHN KCP 클레임 결제 경로의 #599 validate P2 결함 회귀 테스트
 *
 * - #8 클라이언트 승인 API 경로에는 승인 뒤 거절의 보상(취소)이 없었다 — 판정·보상을 승인
 *   리스너로 옮겨 서버 콜백과 같게 한다. 결제창 금액(good_mny)도 승인 호출 **전에** 청구액과 대조한다.
 * - CP 분기의 콜백 락 획득이 try 밖이라 대기 초과가 500 으로 끝났다.
 * - 콜백·승인키 컨트롤러가 `app()` 로 Repository 를 꺼냈다.
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
     * 승인 리스너를 훅에 다시 겁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_nhnkcp');
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
            'order_number' => 'ORD-KCPP2-'.$seq,
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
            'return_number' => 'RT-KCPP2-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => 3000,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-KCPP2-'.$seq,
            'payment_method' => 'card',
            'pg_provider' => 'nhnkcp',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'amount' => 3000,
        ]);

        return ['return' => $return, 'payment' => $payment];
    }

    /**
     * KCP API 목을 바인딩합니다.
     *
     * @param  array|null  $approveResponse  승인 응답 (null 이면 승인 호출이 없어야 한다)
     * @param  bool  $expectCancel  취소가 한 번 있어야 하면 true
     * @return NhnKcpApiService API 서비스 목
     */
    private function kcp(?array $approveResponse, bool $expectCancel = false): NhnKcpApiService
    {
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->method('getSiteCd')->willReturn('T0000');
        $mock->method('isTestMode')->willReturn(true);

        if ($approveResponse === null) {
            $mock->expects($this->never())->method('approvePayment');
        } else {
            $mock->method('approvePayment')->willReturn($approveResponse);
        }

        $mock->expects($expectCancel ? $this->once() : $this->never())->method('cancelPayment')->willReturn(['res_cd' => '0000']);

        $this->app->instance(NhnKcpApiService::class, $mock);

        return $mock;
    }

    /**
     * 결제창 콜백 파라미터를 만듭니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $overrides  덮어쓸 값
     * @return array 콜백
     */
    private function windowCallback(ClaimPayment $payment, array $overrides = []): array
    {
        return array_merge([
            'res_cd' => '0000',
            'enc_data' => 'enc-data',
            'enc_info' => 'enc-info',
            'ordr_idxx' => $payment->payment_number,
            'good_mny' => 3000,
            'use_pay_method' => '100000000000',
            'cust_ip' => '127.0.0.1',
        ], $overrides);
    }

    /**
     * 클라이언트 승인 API 경로에서 KCP 응답 금액이 청구액과 다르면 승인분을 취소하고 거절한다.
     *
     * 수정 전: 리스너가 응답 금액(2500)을 `paid_amount` 로 넘기고 성공을 돌려줬다. 모듈이 금액
     * 불일치로 거절했지만 이 경로에는 보상 클로저가 없어 취소 API 가 불리지 않았다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects kcp_claim_approval_is_cancelled_on_either_path_when_the_response_disagrees
     */
    public function test_the_client_approve_api_path_cancels_a_response_whose_amount_differs(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->kcp(['res_cd' => '0000', 'tno' => 'KCP-P2-1', 'use_pay_method' => '100000000000', 'amount' => '2500'], expectCancel: true);

        try {
            app(UserClaimService::class)->approve($payment, $this->windowCallback($payment));
            $this->fail('청구액과 다른 승인이 확정됐다.');
        } catch (OrderClaimException) {
            // 거절 자체는 수정 전후 같다 — 차이는 보상(취소)의 유무다.
        }

        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 결제창 금액(good_mny)이 청구액과 다르면(낡은 결제창) 승인을 부르기 전에 거절한다.
     *
     * 수정 전: 승인 전 금액 대조가 없어 옛 금액으로 승인을 불렀다(응답에 금액이 없으면 그대로 확정).
     *
     * @return void 반환값 없음
     *
     * @scenario concern=pre_approval_amount
     *
     * @effects kcp_claim_stale_window_amount_is_refused_before_approval
     */
    public function test_a_window_amount_different_from_the_charge_is_refused_before_approval(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $this->kcp(null);

        $result = app(ClaimPaymentApproveListener::class)->processApprove(
            ['handled' => false],
            $payment,
            $this->windowCallback($payment, ['good_mny' => 9000]),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
    }

    /**
     * 같은 결제번호의 콜백이 처리 중이면(락 보유) 500 이 아니라 재시도 안내로 돌려보낸다.
     *
     * 수정 전: CP 분기의 락 획득이 try 밖이라 `LockTimeoutException` 이 그대로 새어 500 이 됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_serialization
     *
     * @effects kcp_claim_callback_lock_timeout_redirects_instead_of_500
     */
    public function test_a_busy_claim_callback_lock_redirects_instead_of_failing_with_500(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makeClaimPayment();
        $this->kcp(null);

        $this->app->bind(PaymentCallbackController::class, fn ($app) => new class($app->make(OrderProcessingService::class), $app->make(PluginSettingsService::class), $app->make(NhnKcpApiService::class), $app->make(ClaimPaymentRepositoryInterface::class)) extends PaymentCallbackController
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

        $held = Cache::lock('sirsoft-pay_nhnkcp:callback:authCallback:'.sha1($payment->payment_number), 30);
        $this->assertTrue($held->get());

        try {
            $response = $this->post('/plugins/sirsoft-pay_nhnkcp/payment/callback', $this->windowCallback($payment));
        } finally {
            $held->release();
        }

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=failed&error=callback_locked&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 콜백·승인키 컨트롤러가 Repository 를 생성자로 주입받고, 공용 트레이트가 로케이터를 쓰지 않는다.
     *
     * 수정 전: `HandlesClaimPaymentCallbacks::findClaimPayment()` 가 `app(ClaimPaymentRepositoryInterface::class)` 였다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=repository_injection
     *
     * @effects kcp_repositories_are_constructor_injected
     */
    public function test_the_claim_payment_repository_is_constructor_injected(): void
    {
        foreach ([PaymentCallbackController::class, MobileApprovalController::class] as $controller) {
            $types = array_map(
                fn (\ReflectionParameter $parameter) => (string) $parameter->getType(),
                (new \ReflectionClass($controller))->getConstructor()?->getParameters() ?? [],
            );

            $this->assertContains(ClaimPaymentRepositoryInterface::class, $types, $controller);
        }

        $trait = (string) file_get_contents(dirname(__DIR__, 2).'/src/Concerns/HandlesClaimPaymentCallbacks.php');
        $this->assertStringNotContainsString('app(ClaimPaymentRepositoryInterface::class)', $trait);
    }

    /**
     * 모듈이 승인 뒤 거절한 건을 `claim_payment.void_approval` 필터로 되돌린다(KCP 취소).
     *
     * 수정 전: 이 플러그인은 그 필터를 구독하지 않아 `handled=false` 로 끝났다 — 클라이언트 승인 API
     * 경로의 거절에는 보상이 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=void_approval
     *
     * @effects kcp_claim_void_approval_cancels_the_approval
     */
    public function test_the_void_approval_filter_cancels_the_kcp_approval(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $mock = $this->createMock(NhnKcpApiService::class);
        $mock->expects($this->once())
            ->method('cancelPayment')
            ->with('KCP-VOID-TNO', $payment->payment_number, 3000, $this->anything())
            ->willReturn(['res_cd' => '0000']);
        $this->app->instance(NhnKcpApiService::class, $mock);

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.void_approval',
            ['handled' => false, 'success' => false, 'error_code' => null, 'error_message' => null],
            $payment,
            $this->windowCallback($payment),
            ['transaction_id' => 'KCP-VOID-TNO', 'paid_amount' => 3000],
        );

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
    }
}
