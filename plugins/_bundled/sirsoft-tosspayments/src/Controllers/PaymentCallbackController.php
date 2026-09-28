<?php

namespace Plugins\Sirsoft\Tosspayments\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentCallback;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Plugins\Sirsoft\Tosspayments\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\Tosspayments\Http\Requests\FailCallbackRequest;
use Plugins\Sirsoft\Tosspayments\Http\Requests\SuccessCallbackRequest;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;

/**
 * 토스페이먼츠 결제 콜백 컨트롤러
 *
 * 토스페이먼츠 결제 완료/실패 후 브라우저 리다이렉트를 처리합니다.
 * 성공 시 Confirm API를 호출하고, 주문 상태를 업데이트합니다.
 */
class PaymentCallbackController
{
    use SerializesPaymentCallbacks;

    private const PLUGIN_IDENTIFIER = 'sirsoft-tosspayments';

    /**
     * 카드 발급사 코드 → 이름 매핑
     */
    private const CARD_ISSUER_MAP = [
        '3K' => '기업 BC',
        '46' => '광주',
        '71' => '롯데',
        '30' => '산업',
        '31' => '비씨',
        '51' => '삼성',
        '38' => '새마을금고',
        '41' => '신한',
        '62' => '신협',
        '36' => '씨티',
        '33' => '우리',
        'W1' => '우리',
        '37' => '우체국',
        '39' => '저축',
        '35' => '전북',
        '42' => '제주',
        '15' => '카카오뱅크',
        '3A' => '케이뱅크',
        '24' => '토스뱅크',
        '21' => '하나',
        '61' => '현대',
        '11' => 'KB국민',
        '91' => 'NH농협',
        '34' => 'Sh수협',
    ];

    /**
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 조회 서비스
     * @param  TossPaymentsApiService  $apiService  토스페이먼츠 API 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     */
    public function __construct(
        private OrderProcessingService $orderService,
        private PluginSettingsService $pluginSettingsService,
        private TossPaymentsApiService $apiService,
        private ClaimPaymentRepositoryInterface $claimPaymentRepository,
    ) {}

    /**
     * 토스페이먼츠 결제 성공 콜백
     *
     * GET /plugins/sirsoft-tosspayments/payment/success
     *     ?paymentKey={PK}&orderId={OID}&amount={AMT}
     *
     * @param  SuccessCallbackRequest  $request  검증된 콜백 요청
     * @return RedirectResponse SPA 페이지로 리다이렉트
     */
    public function success(SuccessCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $paymentKey = $validated['paymentKey'];
        $orderId = $validated['orderId'];
        $pgAmount = $validated['amount'];

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        if (ClaimPayment::isClaimPaymentNumber($orderId)) {
            return $this->handleClaimPaymentSuccess($orderId, $validated);
        }

        try {
            // 1. 주문 조회 (주문번호 기반)
            $order = $this->orderService->findByOrderNumber($orderId);

            if (! $order) {
                Log::error('TossPayments: order not found', ['orderId' => $orderId]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $orderId]));
            }

            // 2. TossPayments Confirm API 호출
            HookManager::doAction('sirsoft-tosspayments.payment.before_confirm', $order, $paymentKey, $pgAmount);

            $pgResponse = $this->apiService->confirmPayment($paymentKey, $orderId, $pgAmount);

            HookManager::doAction('sirsoft-tosspayments.payment.after_confirm', $order, $pgResponse);

            // 3-a. 가상계좌 발급 — 아직 입금 전이므로 completePayment 하지 않고 계좌 정보만 저장한다.
            //      실제 결제완료는 입금통보 웹훅(DEPOSIT_CALLBACK, status=DONE)에서 처리한다.
            //      웹훅 secret 은 이 confirm 응답에만 내려오므로 payment_meta 에 저장해 대조에 쓴다.
            if (($pgResponse['status'] ?? null) === 'WAITING_FOR_DEPOSIT') {
                $this->handleVirtualAccountIssued($order, $pgResponse, $paymentKey, $request);

                return redirect($this->resolveSuccessUrl($orderId));
            }

            // 3-b. 즉시 결제완료(카드/계좌이체/휴대폰/간편결제) — 기존 경로
            $this->orderService->completePayment($order, [
                'transaction_id' => $pgResponse['paymentKey'] ?? null,
                'card_approval_number' => $pgResponse['card']['approveNo'] ?? null,
                'card_number_masked' => $pgResponse['card']['number'] ?? null,
                'card_name' => $this->resolveCardIssuer($pgResponse['card']['issuerCode'] ?? null),
                'card_installment_months' => $pgResponse['card']['installmentPlanMonths'] ?? 0,
                'is_interest_free' => $pgResponse['card']['isInterestFree'] ?? false,
                'embedded_pg_provider' => $pgResponse['easyPay']['provider'] ?? null,
                'receipt_url' => $pgResponse['receipt']['url'] ?? null,
                'payment_meta' => [
                    'method' => $pgResponse['method'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => DeviceDetector::detect($request),
            ], $pgAmount);

            // 4. SPA 주문 완료 페이지로 redirect
            return redirect($this->resolveSuccessUrl($orderId));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('TossPayments: amount mismatch', [
                'orderId' => $orderId,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
                'context' => $e->getContext(),
            ]);

            return redirect($this->resolveFailUrl([
                'error' => 'amount_mismatch',
                'orderId' => $orderId,
            ]));

        } catch (\Exception $e) {
            Log::error('TossPayments: confirm failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return redirect($this->resolveFailUrl([
                'error' => 'confirm_failed',
                'message' => __('sirsoft-tosspayments::messages.errors.payment_failed'),
                'orderId' => $orderId,
            ]));
        }
    }

    /**
     * 토스페이먼츠 결제 실패 콜백
     *
     * GET /plugins/sirsoft-tosspayments/payment/fail
     *     ?code={ERR}&message={MSG}&orderId={OID}
     *
     * @param  FailCallbackRequest  $request  검증된 콜백 요청
     * @return RedirectResponse SPA 체크아웃 페이지로 리다이렉트
     */
    public function fail(FailCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $code = $validated['code'] ?? 'UNKNOWN';
        $message = $validated['message'] ?? '';
        $orderId = $validated['orderId'] ?? null;

        Log::warning('TossPayments: payment failed', [
            'code' => $code,
            'message' => $message,
            'orderId' => $orderId,
        ]);

        // 이 엔드포인트는 인증도 서명도 없는 GET 이고, orderId·code·message 는 전부 쿼리스트링에서
        // 온다. 즉 "실패했다" 는 주장 자체를 누구나 만들 수 있고 대상 주문도 마음대로 고를 수 있다.
        // 그 주장만으로 주문을 실패 처리하면 링크 하나로 남의 결제대기 주문을 취소시킬 수 있으므로,
        // 여기서는 주문 상태를 바꾸지 않고 기록과 안내만 한다.
        // 실제 결제 성립은 success() 의 서버 confirm 이, 결제완료 후 취소는 서명 검증된 웹훅이 담당한다.

        // 클레임 추가결제(CP 채번)도 같은 원칙이다 — 결제 건의 상태를 바꾸지 않고 신청서 상세로
        // 돌려보내기만 한다. 결제 건은 결제 대기로 남아 신청서 상세에서 재시도한다.
        if ($orderId && ClaimPayment::isClaimPaymentNumber($orderId)) {
            $payment = $this->claimPaymentRepository->findByPaymentNumber($orderId);

            if ($payment) {
                // 사용자가 결제창을 스스로 닫은 중단은 실패가 아니다 — 오류 안내 없이 복귀.
                return $code === 'PAY_PROCESS_CANCELED'
                    ? redirect(ClaimPaymentRedirect::cancelled($payment))
                    : redirect(ClaimPaymentRedirect::fail($payment, $code));
            }

            return redirect($this->resolveFailUrl(['error' => $code, 'orderId' => $orderId]));
        }

        return redirect($this->resolveFailUrl([
            'error' => $code,
            'message' => $message,
            'orderId' => $orderId,
        ]));
    }

    /**
     * 클레임 추가결제(CP 채번) 성공 콜백을 처리합니다.
     *
     * PG 승인은 직접 수행하지 않고 모듈의 `approvePgPayment()` 에 위임한다 — 그 안에서
     * `claim_payment.approve` 필터가 발화해 이 플러그인의 승인 리스너가 Confirm 을 수행하므로,
     * 서버 콜백 경로와 API 승인 경로가 같은 구현을 탄다.
     *
     * @param  string  $paymentNumber  클레임 결제번호 (CP 채번)
     * @param  array<string, mixed>  $callback  검증된 콜백 파라미터
     * @return RedirectResponse 신청서 상세로 리다이렉트
     */
    private function handleClaimPaymentSuccess(string $paymentNumber, array $callback): RedirectResponse
    {
        $payment = $this->claimPaymentRepository->findByPaymentNumber($paymentNumber);

        if (! $payment) {
            Log::error('TossPayments: claim payment not found', ['orderId' => $paymentNumber]);

            return redirect($this->resolveFailUrl(['error' => 'claim_payment_not_found', 'orderId' => $paymentNumber]));
        }

        // 주문 경로와 형제 플러그인(kginicis·nhnkcp)의 CP 분기처럼 결제번호 단위로 직렬화한다 —
        // 같은 콜백이 겹쳐 들어오면(새로고침·뒤로가기 재전송) 두 요청이 모두 결제 대기를 보고 각자
        // Confirm 을 부른다. 뒤 요청은 토스의 중복 승인 거절을 받아 이미 결제완료된 행에 실패 코드를
        // 남긴다. 락을 잡으면 뒤 요청은 앞 요청이 끝난 뒤 모듈에서 「이미 결제완료」로 끝난다.
        //
        // 락 획득은 try **안**이다 — 대기 시간을 넘긴 `LockTimeoutException` 을 밖으로 흘리면 콜백이
        // 500 으로 끝나 구매자는 결제 결과를 볼 수 없다.
        $lock = null;

        try {
            $lock = $this->acquireOrderCallbackLock('success', $paymentNumber);

            // 승인 뒤 거절의 보상(토스 취소)은 보상 클로저를 넘기지 않고 모듈의
            // `claim_payment.void_approval` 필터에 맡긴다 — 이 플러그인의 승인 리스너가 그 필터를
            // 구현하므로, 서버 콜백 경로와 클라이언트 승인 API 경로의 보상이 한 구현이 된다.
            return ClaimPaymentCallback::approveAndRedirect($payment, $callback);
        } catch (LockTimeoutException) {
            return redirect($this->redirectBusyClaimCallback($payment));
        } finally {
            $this->releaseOrderCallbackLock($lock);
        }
    }

    /**
     * 콜백 락을 얻지 못한 클레임 결제의 복귀 주소를 정합니다.
     *
     * 락을 쥔 앞 요청이 이미 결제를 확정했으면 성공으로, 아니면 재시도 안내(`callback_locked`)로
     * 보낸다. 결제 건 상태만 읽고 아무것도 바꾸지 않는다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return string 리다이렉트 URL
     */
    private function redirectBusyClaimCallback(ClaimPayment $payment): string
    {
        $latest = $this->claimPaymentRepository->findByPaymentNumber($payment->payment_number) ?? $payment;

        Log::warning('TossPayments: claim payment callback skipped because the callback lock is busy', [
            'payment_number' => $payment->payment_number,
            'payment_status' => $latest->payment_status?->value,
        ]);

        return $latest->isPaid()
            ? ClaimPaymentRedirect::success($latest)
            : ClaimPaymentRedirect::fail($latest, 'callback_locked');
    }

    /**
     * 가상계좌 발급 처리 — completePayment 없이 계좌 정보와 웹훅 secret 만 저장한다.
     *
     * 토스 confirm 응답의 status 가 WAITING_FOR_DEPOSIT 이면 호출된다. 실제 결제완료는
     * 입금통보 웹훅(deposit)에서 처리한다. secret 은 이 응답에만 내려오므로 payment_meta 에
     * 저장해 웹훅 위조 방지 대조(hash_equals)에 사용한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, mixed>  $pgResponse  토스 confirm 응답
     * @param  string  $paymentKey  토스 결제 키
     * @param  Request  $request  HTTP 요청 (디바이스 판별용)
     * @return void 반환값 없음
     */
    private function handleVirtualAccountIssued(Order $order, array $pgResponse, string $paymentKey, Request $request): void
    {
        $vbank = $pgResponse['virtualAccount'] ?? [];

        $dueAt = null;
        $dueDate = $vbank['dueDate'] ?? null;
        if (is_string($dueDate) && $dueDate !== '') {
            try {
                $dueAt = Carbon::parse($dueDate);
            } catch (\Exception) {
                $dueAt = null;
            }
        }

        // 에스크로 여부는 결제 시점 스냅샷 — 응답의 useEscrow 를 그대로 저장한다.
        $isEscrow = (bool) ($vbank['useEscrow'] ?? $pgResponse['useEscrow'] ?? false);

        $order->payment()->update(array_filter([
            'pg_provider' => 'tosspayments',
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT->value,
            'transaction_id' => $paymentKey ?: null,
            'vbank_code' => $vbank['bankCode'] ?? null,
            'vbank_name' => $vbank['bank'] ?? ($vbank['bankCode'] ?? null),
            'vbank_number' => $vbank['accountNumber'] ?? null,
            'vbank_holder' => $vbank['customerName'] ?? null,
            'vbank_due_at' => $dueAt,
            'vbank_issued_at' => Carbon::now(),
            'is_escrow' => $isEscrow,
            'payment_device' => DeviceDetector::detect($request),
            'payment_meta' => [
                'status' => 'WAITING_FOR_DEPOSIT',
                'method' => $pgResponse['method'] ?? null,
                // 웹훅 secret — DEPOSIT_CALLBACK 대조용 (이 응답에만 존재)
                'toss_secret' => $pgResponse['secret'] ?? null,
                'pg_raw_response' => $pgResponse,
            ],
        ], fn ($v) => $v !== null));

        Log::info('TossPayments: virtual account issued', [
            'orderId' => $order->order_number,
            'paymentKey' => $paymentKey,
            'vbank_number' => $vbank['accountNumber'] ?? null,
            'vbank_due_at' => $dueAt?->toDateTimeString(),
            'is_escrow' => $isEscrow,
        ]);
    }

    /**
     * 카드 발급사 코드 → 이름 변환
     *
     * @param  string|null  $issuerCode  발급사 코드
     * @return string|null 카드사명
     */
    private function resolveCardIssuer(?string $issuerCode): ?string
    {
        if ($issuerCode === null) {
            return null;
        }

        return self::CARD_ISSUER_MAP[$issuerCode] ?? $issuerCode;
    }

    /**
     * 결제 성공 리다이렉트 URL 생성
     *
     * @param  string  $orderId  주문번호
     * @return string 리다이렉트 URL
     */
    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? ShopRedirectUrl::DEFAULT_SUCCESS_URL;

        return ShopRedirectUrl::resolve($urlTemplate, ['{orderId}' => $orderId]);
    }

    /**
     * 결제 실패 리다이렉트 URL 생성
     *
     * @param  array  $queryParams  쿼리 파라미터
     * @return string 리다이렉트 URL
     */
    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = ShopRedirectUrl::resolve($settings['redirect_fail_url'] ?? ShopRedirectUrl::DEFAULT_FAIL_URL);

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$separator.$query;
    }
}
