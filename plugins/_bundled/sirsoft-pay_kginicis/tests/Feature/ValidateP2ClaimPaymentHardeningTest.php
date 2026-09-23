<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Cache;
use Mockery;
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
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCheckoutTokenController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtHashDataController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileSignatureController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentSignatureController;
use Plugins\Sirsoft\PayKginicis\Listeners\ClaimPaymentApproveListener;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimApproveToken;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimAuthAmount;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * KG 이니시스 클레임 결제 경로의 #599 validate P2 결함 회귀 테스트
 *
 * - #8 클라이언트 승인 API 경로에는 승인 뒤 거절의 보상(망취소·취소·환불)이 없었다 — 판정·보상을
 *   승인 리스너로 옮겨 서버 콜백과 같게 한다.
 * - 국내 표준결제(PC) 승인액이 응답에 없으면 콜백 `TotPrice` 로 채웠다(대조가 항등식).
 * - CBT 편의점(CVS) 판정이 응답에 결제수단이 비어 오면 콜백 값에 기댔다 — CVS 전용 응답 필드로
 *   응답만으로 판정한다.
 * - CP 분기의 콜백 락 획득이 try 밖이라 대기 초과가 500 으로 끝났다.
 * - 클레임 결제 공용 트레이트가 `app()` 로 Repository 를 꺼냈다.
 */
class ValidateP2ClaimPaymentHardeningTest extends PluginTestCase
{
    private const PC_AUTH_URL = 'https://fcstdpay.inicis.com/api/payAuth';

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
        HookListenerRegistrar::register(ClaimPaymentApproveListener::class, 'sirsoft-pay_kginicis');

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

    /**
     * 결제 대기 클레임 결제 건을 만듭니다. (회원 주문 + 반품 신청서 + 통화 스냅샷)
     *
     * @param  string  $currency  결제 통화 (KRW 청구 3000 / JPY 청구 500)
     * @return array{return: OrderReturn, payment: ClaimPayment} 신청서·결제 건
     */
    private function makeClaimPayment(string $currency = 'KRW'): array
    {
        $seq = ++self::$sequence;
        $amount = $currency === 'JPY' ? 500 : 3000;

        $order = OrderFactory::new()->create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-INIP2-'.$seq,
            'currency' => $currency,
            'currency_snapshot' => [
                'base_currency' => $currency,
                'order_currency' => $currency,
                'exchange_rates' => [
                    $currency => ['rate' => 1.0, 'decimal_places' => 0, 'rounding_unit' => '1', 'rounding_method' => 'round'],
                ],
            ],
        ]);

        $return = OrderReturnFactory::new()->create([
            'order_id' => $order->id,
            'return_number' => 'RT-INIP2-'.$seq,
            'return_status' => ReturnStatusEnum::PAYMENT_PENDING,
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'total_charge_amount' => $amount,
        ]);

        $payment = ClaimPaymentFactory::new()->create([
            'payable_type' => ClaimTypeEnum::RETURN->value,
            'payable_id' => $return->id,
            'order_id' => $order->id,
            'payment_number' => 'CP-INIP2-'.$seq,
            'pg_provider' => 'kginicis',
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'currency_code' => $currency,
            'amount' => $amount,
        ]);

        return ['return' => $return, 'payment' => $payment];
    }

    /**
     * KG 이니시스 API 부분 목을 바인딩합니다. (주소 화이트리스트는 실제 상수를 쓴다)
     *
     * @return Mockery\MockInterface&KgInicisApiService API 서비스 목
     */
    private function inicis(): Mockery\MockInterface
    {
        $api = Mockery::mock(KgInicisApiService::class)->makePartial();
        $api->shouldReceive('getJapanMid')->andReturn(KgInicisApiService::JAPAN_TEST_MID);
        $api->shouldReceive('isTestMode')->andReturn(true);
        $this->app->instance(KgInicisApiService::class, $api);

        return $api;
    }

    /**
     * PC 표준결제 인증 콜백을 만듭니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $overrides  덮어쓸 값
     * @return array 콜백
     */
    private function pcCallback(ClaimPayment $payment, array $overrides = []): array
    {
        return array_merge([
            'resultCode' => '0000',
            'authToken' => 'auth-token-'.$payment->payment_number,
            'authUrl' => self::PC_AUTH_URL,
            'idc_name' => 'fc',
            'MOID' => $payment->payment_number,
        ], $overrides);
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
     * 클라이언트 승인 API 경로에서 PC 승인 응답 금액이 청구액과 다르면 망취소하고 거절한다.
     *
     * 수정 전: 리스너가 응답 TotPrice(4000)를 `paid_amount` 로 넘기고 성공을 돌려줬다. 모듈이 금액
     * 불일치로 거절했지만 이 경로에는 보상 클로저가 없어 망취소가 불리지 않았다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects inicis_claim_approval_is_reverted_on_either_path_when_the_response_disagrees
     */
    public function test_the_client_approve_api_path_net_cancels_a_pc_response_whose_amount_differs(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $api = $this->inicis();
        $api->shouldReceive('authorizePayment')->once()->andReturn([
            'resultCode' => '0000', 'tid' => 'INI-P2-1', 'payMethod' => 'Card', 'TotPrice' => 4000, 'MOID' => $payment->payment_number,
        ]);
        $api->shouldReceive('sendNetCancel')->once();

        try {
            app(UserClaimService::class)->approve($payment, $this->pcCallback($payment));
            $this->fail('청구액과 다른 승인이 확정됐다.');
        } catch (OrderClaimException) {
            // 거절 자체는 수정 전후 같다 — 차이는 보상(망취소)의 유무다.
        }

        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * PC 승인 응답에 TotPrice 가 없으면 콜백 TotPrice 로 채우지 않고 망취소한 뒤 거절한다.
     *
     * 수정 전: `응답 TotPrice ?? 콜백 TotPrice` 로 채워 브라우저 값이 청구액과 대조됐고 성공으로 끝났다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=response_amount_source
     *
     * @effects inicis_claim_pc_amount_comes_only_from_the_response
     */
    public function test_a_pc_response_without_tot_price_is_net_cancelled_instead_of_trusting_the_callback(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $api = $this->inicis();
        $api->shouldReceive('authorizePayment')->once()->andReturn([
            'resultCode' => '0000', 'tid' => 'INI-P2-2', 'payMethod' => 'Card',
        ]);
        $api->shouldReceive('sendNetCancel')->once();

        $result = $this->approve($payment, $this->pcCallback($payment, ['TotPrice' => 3000]));

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
    }

    /**
     * 모바일 승인 응답 금액(P_AMT)이 청구액과 다르면 취소 API 로 되돌리고 거절한다.
     *
     * 수정 전: 응답 금액을 그대로 넘기고 성공을 돌려줬다(보상은 서버 콜백 경로에만 있었다).
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects inicis_claim_approval_is_reverted_on_either_path_when_the_response_disagrees
     */
    public function test_a_mobile_response_whose_amount_differs_is_cancelled(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $reqUrl = (new \ReflectionClass(KgInicisApiService::class))->getConstant('IDC_MOBILE_AUTH_URLS')['fc'];
        $api = $this->inicis();
        $api->shouldReceive('authorizeMobilePayment')->once()->andReturn([
            'P_STATUS' => '00', 'P_TID' => 'INIMX-P2-1', 'P_AMT' => '2500', 'P_TYPE' => 'CARD',
        ]);
        $api->shouldReceive('cancelPayment')->once()->andReturn(['resultCode' => '00']);

        $result = $this->approve($payment, [
            'P_STATUS' => '00', 'P_TID' => 'INIMX-P2-1', 'P_REQ_URL' => $reqUrl, 'idc_name' => 'fc',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
    }

    /**
     * CBT 승인 응답 금액이 청구액과 다르면 환불로 되돌리고 거절한다.
     *
     * 수정 전: 응답 금액(400)을 `paid_amount` 로 넘기고 성공을 돌려줬다 — 클라이언트 승인 API 경로에선
     * 모듈이 거절해도 환불할 주체가 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=approve_path_independence
     *
     * @effects inicis_claim_approval_is_reverted_on_either_path_when_the_response_disagrees
     */
    public function test_a_cbt_response_whose_amount_differs_is_refunded(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment('JPY');
        $api = $this->inicis();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn([
            'resultCode' => 'OK', 'tid' => 'TID-P2-A', 'paymethod' => 'PAYPAY', 'bokuApplPrice' => '400',
        ]);
        $api->shouldReceive('refundCbtPayment')->once()->andReturn(['resultCode' => '00']);

        $result = $this->approve($payment, [
            'cbt_sid' => 'SID-P2-A',
            'cbt_token' => CbtClaimApproveToken::sign($payment->payment_number, 'SID-P2-A'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
    }

    /**
     * CBT 응답에 결제수단이 비어 와도 편의점(CVS) 발급 필드가 있으면 CVS 로 판정해 거절한다.
     *
     * 수정 전: 응답 결제수단이 비면 콜백 paymethod 에 기댔다 — 콜백이 CVS 를 말하지 않으면 입금 전
     * 편의점 결제번호 발급이 결제완료로 확정됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=cbt_cvs_judgement
     *
     * @effects inicis_claim_cbt_cvs_is_judged_by_the_response
     */
    public function test_a_cbt_cvs_issue_is_refused_from_the_response_fields_alone(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment('JPY');
        $api = $this->inicis();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn([
            'resultCode' => 'OK', 'tid' => 'TID-P2-C', 'confNo' => '1234567890', 'paymentTerm' => '20260930235959',
        ]);
        $api->shouldReceive('refundCbtPayment')->once()->andReturn(['resultCode' => '00']);

        $result = $this->approve($payment, [
            'cbt_sid' => 'SID-P2-C',
            'cbt_token' => CbtClaimApproveToken::sign($payment->payment_number, 'SID-P2-C'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', $result['error_code']);
    }

    /**
     * 같은 결제번호의 PC 콜백이 처리 중이면(락 보유) 500 이 아니라 재시도 안내로 돌려보낸다.
     *
     * 수정 전: CP 분기의 락 획득이 try 밖이라 `LockTimeoutException` 이 그대로 새어 500 이 됐다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=callback_serialization
     *
     * @effects inicis_claim_callback_lock_timeout_redirects_instead_of_500
     */
    public function test_a_busy_claim_callback_lock_redirects_instead_of_failing_with_500(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makeClaimPayment();
        $api = $this->inicis();
        $api->shouldNotReceive('authorizePayment');

        $this->app->bind(PaymentCallbackController::class, fn ($app) => new class($app->make(OrderProcessingService::class), $app->make(PluginSettingsService::class), $app->make(KgInicisApiService::class), $app->make(ClaimPaymentRepositoryInterface::class)) extends PaymentCallbackController
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

        $held = Cache::lock('sirsoft-pay_kginicis:callback:authCallback:'.sha1($payment->payment_number), 30);
        $this->assertTrue($held->get());

        try {
            $response = $this->post('/plugins/sirsoft-pay_kginicis/payment/callback', $this->pcCallback($payment));
        } finally {
            $held->release();
        }

        $response->assertRedirect("/mypage/claims/{$return->return_number}?claim_payment=failed&error=callback_locked&claim_number={$return->return_number}");
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $payment->fresh()->payment_status);
    }

    /**
     * 클레임 결제 공용 트레이트를 쓰는 컨트롤러가 Repository 를 생성자로 주입받는다.
     *
     * 수정 전: `HandlesClaimPaymentCallbacks::findClaimPayment()` 가 `app(ClaimPaymentRepositoryInterface::class)` 였다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=repository_injection
     *
     * @effects inicis_repositories_are_constructor_injected
     */
    public function test_the_claim_payment_repository_is_constructor_injected(): void
    {
        $controllers = [
            PaymentCallbackController::class,
            MobileCallbackController::class,
            CbtCallbackController::class,
            PaymentSignatureController::class,
            MobileSignatureController::class,
            CbtHashDataController::class,
            CbtCheckoutTokenController::class,
        ];

        foreach ($controllers as $controller) {
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
     * 모듈이 승인 뒤 거절한 국내 표준결제 건을 `claim_payment.void_approval` 필터로 되돌린다(취소 API).
     *
     * 수정 전: 이 플러그인은 그 필터를 구독하지 않아 `handled=false` 로 끝났다 — 클라이언트 승인 API
     * 경로의 거절에는 보상이 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=void_approval
     *
     * @effects inicis_claim_void_approval_reverts_the_approval
     */
    public function test_the_void_approval_filter_cancels_a_domestic_approval(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment();
        $api = $this->inicis();
        $api->shouldReceive('cancelPayment')->once()->with('INI-VOID-TID', 'Card', null, Mockery::any())->andReturn(['resultCode' => '00']);

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.void_approval',
            ['handled' => false, 'success' => false, 'error_code' => null, 'error_message' => null],
            $payment,
            $this->pcCallback($payment),
            ['transaction_id' => 'INI-VOID-TID', 'paid_amount' => 3000],
        );

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
    }

    /**
     * 모듈이 승인 뒤 거절한 CBT 건은 CBT 환불 API 로 되돌린다.
     *
     * 수정 전: 필터를 구독하지 않아 보상이 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=void_approval
     *
     * @effects inicis_claim_void_approval_reverts_the_approval
     */
    public function test_the_void_approval_filter_refunds_a_cbt_approval(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment('JPY');
        $api = $this->inicis();
        $api->shouldReceive('refundCbtPayment')->once()->with('TID-VOID-CBT', null, Mockery::any())->andReturn(['resultCode' => '00']);
        $api->shouldNotReceive('cancelPayment');

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.void_approval',
            ['handled' => false, 'success' => false, 'error_code' => null, 'error_message' => null],
            $payment,
            ['cbt_sid' => 'SID-VOID'],
            ['transaction_id' => 'TID-VOID-CBT', 'paid_amount' => 500],
        );

        $this->assertTrue($result['handled']);
        $this->assertTrue($result['success']);
    }

    /**
     * CBT 응답에 금액이 없으면 해시 발급 때 기록한 인증요청 금액을 승인액으로 보고한다.
     *
     * 수정 전: `paid_amount` 가 null 이었다 — 모듈이 이제 그 값을 불일치로 거절하므로 CBT 클레임 결제가
     * 전부 실패한다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=cbt_auth_amount
     *
     * @effects inicis_claim_cbt_reports_the_auth_request_amount
     */
    public function test_a_cbt_response_without_an_amount_reports_the_recorded_auth_amount(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment('JPY');
        app(CbtClaimAuthAmount::class)->remember($payment->payment_number, 500);
        $api = $this->inicis();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn(['resultCode' => 'OK', 'tid' => 'TID-P2-AUTH', 'paymethod' => 'CARD']);
        $api->shouldNotReceive('refundCbtPayment');

        $result = $this->approve($payment, [
            'cbt_sid' => 'SID-P2-AUTH',
            'cbt_token' => CbtClaimApproveToken::sign($payment->payment_number, 'SID-P2-AUTH'),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(500, $result['paid_amount']);
    }

    /**
     * 결제창을 연 뒤 청구액이 바뀌었으면(기록된 인증요청 금액 ≠ 청구액) 환불하고 거절한다.
     *
     * 수정 전: 응답에 금액이 없으면 대조를 생략해 옛 금액 승인이 새 청구액으로 확정될 수 있었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=cbt_auth_amount
     *
     * @effects inicis_claim_cbt_reports_the_auth_request_amount
     */
    public function test_a_stale_cbt_auth_amount_is_refunded(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment('JPY');
        app(CbtClaimAuthAmount::class)->remember($payment->payment_number, 400);
        $api = $this->inicis();
        $api->shouldReceive('approveCbtPayment')->once()->andReturn(['resultCode' => 'OK', 'tid' => 'TID-P2-STALE', 'paymethod' => 'CARD']);
        $api->shouldReceive('refundCbtPayment')->once()->andReturn(['resultCode' => '00']);

        $result = $this->approve($payment, [
            'cbt_sid' => 'SID-P2-STALE',
            'cbt_token' => CbtClaimApproveToken::sign($payment->payment_number, 'SID-P2-STALE'),
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('AMOUNT_MISMATCH', $result['error_code']);
    }

    /**
     * CBT 해시 발급이 그 인증요청 금액을 결제 건 단위로 기록한다.
     *
     * 수정 전: 기록하지 않아 승인 단계에서 인증요청 금액을 알 수 없었다.
     *
     * @return void 반환값 없음
     *
     * @scenario concern=cbt_auth_amount
     *
     * @effects inicis_claim_cbt_reports_the_auth_request_amount
     */
    public function test_cbt_hash_issuance_records_the_auth_request_amount(): void
    {
        ['payment' => $payment] = $this->makeClaimPayment('JPY');
        $api = $this->inicis();
        $api->shouldReceive('isJapanEnabled')->andReturn(true);
        $api->shouldReceive('isJapanConfigured')->andReturn(true);
        $api->shouldReceive('generateCbtHashData')->andReturn('HASH-DATA-P2');

        $token = $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/checkout-token', [
            'oid' => $payment->payment_number,
            'price' => 500,
        ])->json('data.checkout_token');

        $this->postJson('/api/plugins/sirsoft-pay_kginicis/payment/cbt/hash-data', [
            'oid' => $payment->payment_number,
            'price' => 500,
            'timestamp' => date('YmdHis'),
            'checkout_token' => $token,
        ])->assertOk();

        $this->assertSame(500, app(CbtClaimAuthAmount::class)->recall($payment->payment_number));
    }
}
