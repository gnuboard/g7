<?php

namespace Plugins\Sirsoft\Tosspayments\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
use Plugins\Sirsoft\Tosspayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\Tosspayments\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\Tosspayments\Concerns\VerifiesTossApprovedAmount;
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
    use RecordsPaymentWindowClosure;
    use SerializesPaymentCallbacks;
    use VerifiesTossApprovedAmount;

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
        // 브라우저가 쿼리스트링으로 보낸 금액 — 승인 전 대조에만 쓰고 승인·결제완료의 근거로는 쓰지 않는다.
        $callbackAmount = (int) $validated['amount'];

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        if (ClaimPayment::isClaimPaymentNumber($orderId)) {
            return $this->handleClaimPaymentSuccess($orderId, $validated);
        }

        // 토스 승인이 성립했는지 — 승인 뒤의 거절은 토스 취소로 되돌려야 한다.
        $confirmed = false;

        try {
            // 1. 주문 조회 (주문번호 기반)
            $order = $this->orderService->findByOrderNumber($orderId);

            if (! $order) {
                Log::error('TossPayments: order not found', ['orderId' => $orderId]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $orderId]));
            }

            // 같은 결제 키로 이미 반영된 주문의 새로고침·재전송은 승인을 다시 부르지 않고 결과 화면으로 보낸다.
            if ($this->isAlreadyRecordedWith($order, $paymentKey)) {
                Log::info('TossPayments: success callback for an already recorded payment', [
                    'orderId' => $orderId,
                ]);

                return redirect($this->resolveSuccessUrl($orderId));
            }

            // 2. 승인 전 대조 — 여기서 거절하면 승인도 상태 변경도 일어나지 않는다.
            //
            // 결제 가능 상태가 아닌 주문(이미 결제완료·취소)은 승인 자체를 부르지 않는다. 승인한 뒤
            // completePayment 가 조용히 되돌아가면 토스 승인만 남아 고객 돈이 빠져나간 채가 된다.
            if (! $this->isOrderPayable($order)) {
                Log::warning('TossPayments: order is not payable — confirm skipped', [
                    'orderId' => $orderId,
                    'order_status' => $order->order_status?->value,
                    'payment_status' => $order->payment?->payment_status?->value,
                ]);

                return redirect($this->resolveFailUrl([
                    'error' => 'confirm_failed',
                    'message' => __('sirsoft-tosspayments::messages.errors.payment_failed'),
                    'orderId' => $orderId,
                ]));
            }

            // 승인 금액의 기준은 주문에 저장된 청구액(결제 통화)이다. 콜백 금액이 그와 다르면
            // 결제창 요청이 변조됐거나 결제창을 연 사이 청구액이 바뀐 것이므로 승인하지 않는다.
            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'success', [
                'orderId' => $orderId,
                'callback_amount' => $callbackAmount,
            ]);

            if ($expectedAmount === null || $callbackAmount !== $expectedAmount) {
                Log::warning('TossPayments: callback amount does not match the order charge — confirm skipped', [
                    'orderId' => $orderId,
                    'callback_amount' => $callbackAmount,
                    'expected' => $expectedAmount,
                ]);

                return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $orderId]));
            }

            // 3. TossPayments Confirm API 호출 — 청구액으로 승인을 요청한다.
            HookManager::doAction('sirsoft-tosspayments.payment.before_confirm', $order, $paymentKey, $expectedAmount);

            $pgResponse = $this->apiService->confirmPayment($paymentKey, $orderId, $expectedAmount);
            $confirmed = true;

            // 가상계좌 웹훅 검증값(secret)은 훅 인자에서 뺀다 — 큐 구독자가 있으면 작업 표에 사본이 남는다.
            // 웹훅 대조는 결제행 payment_meta.toss_secret 으로 한다.
            HookManager::doAction('sirsoft-tosspayments.payment.after_confirm', $order, Arr::except($pgResponse, ['secret']));

            // 4. 승인 후 대조 — 토스가 실제로 승인(가상계좌는 발급)한 금액이 청구액과 같아야 한다.
            //    응답에 금액이 없으면 검증할 수 없으므로 불일치와 같게 취소한다.
            $approvedAmount = $this->tossApprovedAmount($pgResponse);
            $responseOrderId = (string) ($pgResponse['orderId'] ?? '');

            if ($approvedAmount !== $expectedAmount || ($responseOrderId !== '' && $responseOrderId !== $orderId)) {
                Log::error('TossPayments: approved amount does not match the order charge — cancelling approval', [
                    'orderId' => $orderId,
                    'expected' => $expectedAmount,
                    'approved_amount' => $approvedAmount,
                    'response_order_id' => $responseOrderId,
                    'pg_status' => $pgResponse['status'] ?? null,
                ]);

                $this->cancelApprovalQuietly($paymentKey, $orderId);

                return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $orderId]));
            }

            // 승인 왕복 사이에 주문이 결제 불가 상태가 됐으면(관리자 취소 등) 결과를 쓰지 않고 되돌린다.
            // 결제행은 여기서 다시 읽은 상태로만 갱신한다 — 결제완료·취소된 행을 덮어쓰지 않는다.
            $order = $order->fresh(['payment']) ?? $order;

            if (! $this->isOrderPayable($order)) {
                if ($this->isAlreadyRecordedWith($order, $paymentKey)) {
                    return redirect($this->resolveSuccessUrl($orderId));
                }

                Log::error('TossPayments: order became not payable during confirm — cancelling approval', [
                    'orderId' => $orderId,
                    'order_status' => $order->order_status?->value,
                ]);

                $this->cancelApprovalQuietly($paymentKey, $orderId);

                return redirect($this->resolveFailUrl([
                    'error' => 'confirm_failed',
                    'message' => __('sirsoft-tosspayments::messages.errors.payment_failed'),
                    'orderId' => $orderId,
                ]));
            }

            // 5-a. 가상계좌 발급 — 아직 입금 전이므로 completePayment 하지 않고 계좌 정보만 저장한다.
            //      실제 결제완료는 입금통보 웹훅(DEPOSIT_CALLBACK, status=DONE)에서 처리한다.
            //      웹훅 secret 은 이 confirm 응답에만 내려오므로 payment_meta 에 저장해 대조에 쓴다.
            if (($pgResponse['status'] ?? null) === 'WAITING_FOR_DEPOSIT') {
                $this->handleVirtualAccountIssued($order, $pgResponse, $paymentKey, $approvedAmount, $request);

                return redirect($this->resolveSuccessUrl($orderId));
            }

            // 5-b. 즉시 결제완료(카드/계좌이체/휴대폰/간편결제) — 금액 근거는 대조를 마친 청구액이다.
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
                    'toss_approved_amount' => $approvedAmount,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => DeviceDetector::detect($request),
            ], $expectedAmount);

            // 6. SPA 주문 완료 페이지로 redirect
            return redirect($this->resolveSuccessUrl($orderId));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('TossPayments: amount mismatch', [
                'orderId' => $orderId,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
                'context' => $e->getContext(),
            ]);

            // 모듈이 승인 뒤에 금액을 거절했다 — 결제완료로 가지 않으므로 토스 승인도 되돌린다.
            if ($confirmed) {
                $this->cancelApprovalQuietly($paymentKey, $orderId);
            }

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
     * 주문이 결제를 받을 수 있는 상태인지 확인합니다.
     *
     * 결제 전 주문 상태이고 결제행이 아직 결제완료가 아니어야 한다.
     *
     * @param  Order  $order  대상 주문
     * @return bool 결제 가능 여부
     */
    private function isOrderPayable(Order $order): bool
    {
        if (! $order->order_status?->isBeforePayment()) {
            return false;
        }

        return ! ($order->payment?->isPaid() ?? false);
    }

    /**
     * 같은 결제 키로 결제완료 또는 가상계좌 발급이 이미 반영됐는지 확인합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  string  $paymentKey  토스 결제 키
     * @return bool 이미 반영됐으면 true
     */
    private function isAlreadyRecordedWith(Order $order, string $paymentKey): bool
    {
        $payment = $order->payment;

        if (! $payment || $paymentKey === '' || $payment->transaction_id !== $paymentKey) {
            return false;
        }

        return $payment->isPaid() || $payment->isWaitingDeposit();
    }

    /**
     * 확정된 토스 승인을 조용히 취소합니다. (보상 — 실패해도 흐름은 지속, 운영자 대사용 로그를 남긴다)
     *
     * 카드 등은 전액 취소가 되고, 입금 전 가상계좌는 발급이 취소된다.
     *
     * @param  string  $paymentKey  토스 결제 키
     * @param  string  $orderId  주문번호 (로그 문맥)
     * @return bool 취소 요청이 성공했으면 true
     */
    private function cancelApprovalQuietly(string $paymentKey, string $orderId): bool
    {
        try {
            $this->apiService->cancelPayment(
                $paymentKey,
                __('sirsoft-tosspayments::messages.errors.approval_verification_cancel_reason'),
            );

            return true;
        } catch (\Exception $e) {
            Log::error('TossPayments: compensating cancel failed — manual cancel required', [
                'orderId' => $orderId,
                'paymentKey' => $paymentKey,
                'error' => $e->getMessage(),
            ]);

            return false;
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
                    ? redirect(SiteUrl::absolute(ClaimPaymentRedirect::cancelled($payment)))
                    : redirect(SiteUrl::absolute(ClaimPaymentRedirect::fail($payment, $code)));
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
            return redirect(SiteUrl::absolute($this->redirectBusyClaimCallback($payment)));
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
     * @param  int  $approvedAmount  청구액과 대조를 마친 발급 금액 (입금통보 반영의 근거)
     * @param  Request  $request  HTTP 요청 (디바이스 판별용)
     * @return void 반환값 없음
     */
    private function handleVirtualAccountIssued(Order $order, array $pgResponse, string $paymentKey, int $approvedAmount, Request $request): void
    {
        $vbank = $pgResponse['virtualAccount'] ?? [];

        $dueAt = null;
        $dueDate = $vbank['dueDate'] ?? null;
        if (is_string($dueDate) && $dueDate !== '') {
            try {
                // dueDate 는 오프셋(+09:00)이 붙은 한국 시각이다 — 앱 시간대(UTC)로 바꿔 저장하지 않으면 datetime 캐스트가
                // 한국 시각 숫자를 그대로 UTC 칸에 넣어 기한이 9시간 늦게 저장된다(KCP·KG·나이스와 같은 처리).
                $dueAt = Carbon::parse($dueDate)->setTimezone(config('app.timezone', 'UTC'));
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
                // 발급 시점에 청구액과 대조한 금액 — 입금통보 웹훅이 결제완료 금액 근거로 쓴다.
                'toss_approved_amount' => $approvedAmount,
                'pg_raw_response' => $pgResponse,
            ],
        ], fn ($v) => $v !== null));

        // 계좌를 받은 순간 고객의 주문은 끝났다 — 입금 전이라도 주문한 장바구니 항목을 정리한다(재고는 입금 때 차감).
        $this->orderService->onVbankAccountIssued($order);

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

        // 보안: 상대 경로를 redirect() 에 넘기면 요청 Host 로 절대화된다 — 사이트 주소로 만든다.
        return SiteUrl::absolute(ShopRedirectUrl::resolve($urlTemplate, ['{orderId}' => $orderId]));
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
        $baseUrl = SiteUrl::absolute(ShopRedirectUrl::resolve($settings['redirect_fail_url'] ?? ShopRedirectUrl::DEFAULT_FAIL_URL));

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$separator.$query;
    }
}
