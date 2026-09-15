<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNicepayments\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNicepayments\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayNicepayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNicepayments\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\SignDataRequest;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\PayNicepayments\Support\UrlHelper;

/**
 * 나이스페이먼츠 결제 콜백 컨트롤러
 *
 * 나이스페이먼츠 결제는 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 인증 토큰 전달 → authCallback()
 *  2단계: 서버가 NextAppURL로 최종 승인 요청 → NicePaymentsApiService::authorizePayment()
 */
class PaymentCallbackController
{
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use RecordsPaymentWindowClosure;
    use ResolvesEasyPayDisplay;
    use SanitizesPgResponse;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

    /** 성공 결제 방법 ResultCode 목록 */
    private const SUCCESS_RESULT_CODES = ['3001', '4000', '4100', 'A000', '7001'];

    private const AUTH_RESPONSE_KEYS = [
        'ResultCode',
        'ResultMsg',
        'TID',
        'Moid',
        'MID',
        'Amt',
        'PayMethod',
        'AppNo',
        'AuthDate',
        'AuthCode',
        'CardCode',
        'CardName',
        'IssuCardCode',
        'IssuCardName',
        'AcquCardCode',
        'AcquCardName',
        'CardQuota',
        'CardInterest',
        'CardCl',
        'CcPartCl',
        'CardNoInterest',
        'RcptTID',
        'EscrowYN',
        'MallReserved',
        'MallReserved1',
        'Currency',
    ];

    private const VBANK_ISSUE_RESPONSE_KEYS = [
        'ResultCode',
        'ResultMsg',
        'TID',
        'Moid',
        'MID',
        'Amt',
        'PayMethod',
        'AuthDate',
        'AuthCode',
        'VbankBankCode',
        'VbankBankName',
        'VbankExpDate',
        'VbankExpTime',
        'RcptTID',
        'EscrowYN',
        'MallReserved',
        'MallReserved1',
    ];

    private const VBANK_NOTIFY_RESPONSE_KEYS = [
        'PG',
        'PayMethod',
        'MID',
        'MOID',
        'TID',
        'Amt',
        'ResultCode',
        'ResultMsg',
        'AuthDate',
        'AuthCode',
        'StateCd',
        'VbankName',
        'FnCd',
        'FnName',
        'CancelDate',
        'CancelMOID',
        'MallReserved',
        'MallReserved1',
        'TransType',
        'CartCnt',
        'RcptTID',
    ];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NicePaymentsApiService $apiService,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 나이스페이먼츠 결제 승인 콜백
     *
     * POST /plugins/sirsoft-pay_nicepayments/payment/callback
     * (CSRF 제외 - 나이스페이먼츠가 브라우저 통해 POST 전달)
     */
    /**
     * 나이스페이먼츠 결제 승인 콜백
     *
     * 1단계: 브라우저가 POST 콜백으로 인증 토큰 전달 → 서명 검증 + 서버 승인 호출.
     * 2단계: NextAppURL 호출해 최종 승인. 결제 수단별로 가상계좌(계좌발급)/카드(즉시완료) 분기.
     * 사용자 취소(AuthResultCode != '0000') 는 에러 query 없이 체크아웃으로 복귀.
     *
     * @param  AuthCallbackRequest  $request  검증된 콜백 페이로드
     * @return RedirectResponse 성공/실패 URL 로 리다이렉트
     */
    public function authCallback(AuthCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $authResultCode = $validated['AuthResultCode'];
        $authResultMsg = $validated['AuthResultMsg'] ?? '';
        $moid = $validated['Moid'] ?? '';

        // 1단계: 인증 결과 코드 확인 (실패/취소 케이스는 여기서 종료)
        //
        // 인증 단계 실패는 NextAppURL 호출 (실제 결제 승인) 이전이라 사용자가 아직 결제를
        // 완료하지 않은 상태. 코드/메시지로 사용자 취소 vs PG 시스템 오류를 구분하려 해도
        // NicePay 가 보내는 패턴이 다양해 (모바일 결제창 종료 시 '0021 - 결제창을 종료하셨습니다',
        // 영문 cancel, 코드만 있고 메시지가 비어있는 경우 등) 정확히 분류하기 어렵다.
        //
        // 사용자 입장에서 인증단계 실패는 어떤 코드든 "다시 시도" 외에 할 수 있는 액션이 없고
        // 결제도 일어나지 않았으므로 generic 에러 toast 를 띄우면 오히려 혼란을 준다.
        // 따라서 인증단계 실패는 모두 silent redirect 로 통일 — 체크아웃에 그대로 머물러
        // 결제수단을 다시 선택하거나 정보를 수정해 재시도 가능.
        //
        // 운영 가시성은 Log 로 충분히 확보 (auth_result_code / auth_result_msg 모두 보존).
        if ($authResultCode !== '0000') {
            Log::info('NicePayments: auth phase did not complete', [
                'moid' => $moid,
                'auth_result_code' => $authResultCode,
                'auth_result_msg' => $authResultMsg,
                'ip' => $request->ip(),
            ]);

            return redirect($this->resolveFailUrl([]));
        }

        // 인증 성공 케이스 — 추가 필드 추출
        $nextAppUrl = $validated['NextAppURL'];
        $txTid = $validated['TxTid'];
        $authToken = $validated['AuthToken'];
        $mid = $validated['MID'];
        $amt = (int) $validated['Amt'];
        $netCancelUrl = $validated['NetCancelURL'];
        $signature = $validated['Signature'];

        // AuthToken freshness 가드 — NicePay 콜백 페이로드에 명시적 timestamp 가 없어
        // 동일 AuthToken 으로 짧은 시간 안에 중복 콜백 시도되는 stale replay 가능.
        // Cache 기반 60초 윈도우 디듀프로 보조 방어 (HIGH 2 의 transaction_id replay 가드
        // 외에 추가 계층). transaction_id 까지 도달하기 전 인증 단계 자체에서 차단.
        $replayCacheKey = 'nicepay_auth_token_seen:'.hash('sha256', $mid.':'.$authToken.':'.$txTid);
        if ($this->cache->has($replayCacheKey)) {
            Log::warning('NicePayments: duplicate authCallback within freshness window — replay suspected', [
                'moid' => $moid,
                'txTid' => $txTid,
                'ip' => $request->ip(),
            ]);

            $this->queueReceiptCookie($moid);

            return redirect($this->resolveSuccessUrl($moid));
        }
        $this->cache->put($replayCacheKey, true, 60);

        // 2단계: MID 일치 확인
        if ($mid !== $this->apiService->getMid()) {
            Log::error('NicePayments: MID mismatch', [
                'received_mid' => $mid,
                'config_mid' => $this->apiService->getMid(),
                'moid' => $moid,
                'ip' => $request->ip(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'mid_mismatch', 'orderId' => $moid]));
        }

        // 3단계: 서명 검증
        if (! $this->apiService->verifyCallbackSignature($authToken, $mid, $amt, $signature)) {
            Log::error('NicePayments: signature verification failed', ['moid' => $moid, 'ip' => $request->ip()]);

            return redirect($this->resolveFailUrl(['error' => 'signature_mismatch', 'orderId' => $moid]));
        }

        try {
            // 주문 조회
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('NicePayments: order not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            if (! $order->order_status->isBeforePayment()) {
                Log::warning('NicePayments: callback ignored for non-payable order', [
                    'moid' => $moid,
                    'order_status' => $order->order_status->value,
                    'payment_status' => $order->payment?->payment_status?->value,
                ]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
            }

            HookManager::doAction('sirsoft-pay_nicepayments.payment.before_authorize', $order, $validated);

            // 4단계: 서버 승인 API 호출
            $pgResponse = $this->apiService->authorizePayment($nextAppUrl, $txTid, $authToken, $amt);

            HookManager::doAction('sirsoft-pay_nicepayments.payment.after_authorize', $order, $pgResponse);

            $resultCode = $pgResponse['ResultCode'] ?? '';

            if (! in_array($resultCode, self::SUCCESS_RESULT_CODES, true)) {
                Log::warning('NicePayments: authorize failed', [
                    'moid' => $moid,
                    'result_code' => $resultCode,
                    'result_msg' => $pgResponse['ResultMsg'] ?? '',
                ]);

                $failureMessage = $pgResponse['ResultMsg'] ?? '';
                $failedOrder = $this->orderService->failPayment($order, $resultCode, $failureMessage);
                $this->markNicePaymentFailed($failedOrder, $resultCode, $failureMessage);

                // authorize 실패 — NextAppURL 호출이 거부됐어도 PG 측에 잔존 승인이 있을
                // 수 있어 net cancel 호출. NicePay 가 미승인 상태이면 net cancel API 가
                // safe-no-op 으로 동작하므로 모든 실패 경로에서 호출해 안전.
                $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

                return redirect($this->resolveFailUrl([
                    'error' => 'authorize_failed',
                    'orderId' => $moid,
                ]));
            }

            // 5단계: 결제 수단별 처리
            $payMethod = $pgResponse['PayMethod'] ?? '';
            $isEscrow = ($pgResponse['EscrowYN'] ?? 'N') === 'Y'
                || $this->apiService->isEscrowEnabled();

            if ($payMethod === 'VBANK') {
                $issueStatus = $this->recordVbankIssueWithLock($moid, $pgResponse, $resultCode, $payMethod, $isEscrow, $txTid);
                if ($issueStatus === 'already_waiting') {
                    $this->logReplayDetected((string) ($pgResponse['TID'] ?? $txTid), $moid, 'authCallback (vbank issue)');
                    $this->queueReceiptCookie($moid);

                    return redirect($this->resolveSuccessUrl($moid));
                }

                if ($issueStatus !== 'recorded') {
                    Log::warning('NicePayments: vbank issue ignored after locked state check', [
                        'moid' => $moid,
                        'status' => $issueStatus,
                    ]);

                    $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

                    return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
                }

                Log::info('NicePayments: vbank account issued', [
                    'moid' => $moid,
                    'vbank_name' => $pgResponse['VbankBankName'] ?? null,
                    'vbank_number_last4' => $this->lastFour((string) ($pgResponse['VbankNum'] ?? '')),
                ]);
            } else {
                $effectiveTid = (string) ($pgResponse['TID'] ?? $txTid);
                $completionStatus = $this->completeAuthorizedPaymentWithLock($moid, $pgResponse, $txTid, $amt, $isEscrow, $request, $validated);

                if ($completionStatus === 'already_paid') {
                    $this->logReplayDetected($effectiveTid, $moid, 'authCallback (card)');
                    $this->queueReceiptCookie($moid);

                    return redirect($this->resolveSuccessUrl($moid));
                }

                if ($completionStatus === 'paid_with_other_tid') {
                    Log::warning('NicePayments: order already paid with another TID; net cancelling current authorization', [
                        'moid' => $moid,
                        'tid' => $effectiveTid,
                    ]);

                    $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);
                    $this->queueReceiptCookie($moid);

                    return redirect($this->resolveSuccessUrl($moid));
                }

                if ($completionStatus !== 'completed') {
                    Log::warning('NicePayments: authorized payment ignored after locked state check', [
                        'moid' => $moid,
                        'tid' => $effectiveTid,
                        'status' => $completionStatus,
                    ]);

                    $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

                    return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
                }
            }

            $this->queueReceiptCookie($moid);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('NicePayments: amount mismatch', [
                'moid' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            if (isset($order)) {
                $failedOrder = $this->orderService->failPayment($order, 'AMOUNT_MISMATCH', $e->getMessage());
                $this->markNicePaymentFailed($failedOrder, 'AMOUNT_MISMATCH', $e->getMessage());
            }

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (InvalidArgumentException $e) {
            Log::error('NicePayments: invalid payment currency during authorization', [
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            if (isset($order)) {
                $failureMessage = $this->invalidPaymentCurrencyFailureMessage($e);
                $failedOrder = $this->orderService->failPayment($order, 'INVALID_PAYMENT_CURRENCY', $failureMessage);
                $this->markNicePaymentFailed($failedOrder, 'INVALID_PAYMENT_CURRENCY', $failureMessage);
            }

            return redirect($this->resolveFailUrl([
                'error' => 'invalid_payment_currency',
                'orderId' => $moid,
            ]));

        } catch (\Exception $e) {
            Log::error('NicePayments: authorize exception', [
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            $approvedTid = $pgResponse['TID'] ?? $txTid;
            if ($this->wasAlreadyPaid((string) $approvedTid)) {
                $this->logReplayDetected((string) $approvedTid, $moid, 'authCallback exception');
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            if (isset($order)) {
                try {
                    $failedOrder = $this->orderService->failPayment($order, 'AUTHORIZE_EXCEPTION', $e->getMessage());
                    $this->markNicePaymentFailed($failedOrder, 'AUTHORIZE_EXCEPTION', $e->getMessage());
                } catch (\Throwable $recordingError) {
                    Log::warning('NicePayments: failed to record authorize exception as payment failure', [
                        'moid' => $moid,
                        'error' => $recordingError->getMessage(),
                    ]);
                }
            }

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'orderId' => $moid,
            ]));
        }
    }

    private function recordVbankIssueWithLock(
        string $moid,
        array $pgResponse,
        string $resultCode,
        string $payMethod,
        bool $isEscrow,
        string $txTid,
    ): string {
        return DB::transaction(function () use ($moid, $pgResponse, $resultCode, $payMethod, $isEscrow, $txTid): string {
            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $lockedOrder = Order::query()
                ->where('order_number', $moid)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return 'order_not_found';
            }

            $payment = $lockedOrder->payment()->lockForUpdate()->first();
            if (! $payment || ! $lockedOrder->order_status->isBeforePayment()) {
                return 'not_payable';
            }

            $issuedTid = (string) ($pgResponse['TID'] ?? $txTid);
            $meta = $payment->payment_meta ?? [];
            $storedTid = (string) ($meta['vbank_tid'] ?? $payment->transaction_id ?? '');

            if ($payment->payment_status === PaymentStatusEnum::WAITING_DEPOSIT
                && $storedTid !== ''
                && hash_equals($storedTid, $issuedTid)) {
                return 'already_waiting';
            }

            if ($payment->payment_status !== PaymentStatusEnum::READY) {
                return 'not_payable';
            }

            $vbankDueAt = null;
            if (isset($pgResponse['VbankExpDate'])) {
                $dateStr = $pgResponse['VbankExpDate'].($pgResponse['VbankExpTime'] ?? '235959');
                $vbankDueAt = Carbon::createFromFormat('YmdHis', $dateStr);
            }

            $payment->payment_status = PaymentStatusEnum::WAITING_DEPOSIT;
            $payment->pg_provider = 'nicepayments';
            $payment->vbank_name = $pgResponse['VbankBankName'] ?? null;
            $payment->vbank_number = $pgResponse['VbankNum'] ?? null;
            $payment->vbank_due_at = $vbankDueAt;
            $payment->vbank_issued_at = now();
            $payment->is_escrow = $isEscrow;
            $payment->payment_meta = [
                'result_code' => $resultCode,
                'pay_method' => $payMethod,
                'auth_date' => $pgResponse['AuthDate'] ?? null,
                'mid' => $this->apiService->getMid(),
                'vbank_tid' => $issuedTid,
                'vbank_num' => $pgResponse['VbankNum'] ?? null,
                'vbank_name' => $pgResponse['VbankBankName'] ?? null,
                'vbank_exp_date' => isset($pgResponse['VbankExpDate'])
                    ? $pgResponse['VbankExpDate'].($pgResponse['VbankExpTime'] ?? '235959')
                    : null,
                'is_test_mode' => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::VBANK_ISSUE_RESPONSE_KEYS),
            ];
            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $payment->save();

            return 'recorded';
        });
    }

    private function completeAuthorizedPaymentWithLock(
        string $moid,
        array $pgResponse,
        string $txTid,
        int $amt,
        bool $isEscrow,
        Request $request,
        array $callbackPayload,
    ): string {
        return DB::transaction(function () use ($moid, $pgResponse, $txTid, $amt, $isEscrow, $request, $callbackPayload): string {
            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $lockedOrder = Order::query()
                ->where('order_number', $moid)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder) {
                return 'order_not_found';
            }

            $payment = $lockedOrder->payment()->lockForUpdate()->first();
            if (! $payment) {
                return 'payment_not_found';
            }

            $effectiveTid = (string) ($pgResponse['TID'] ?? $txTid);
            if ($payment->isPaid() && hash_equals((string) $payment->transaction_id, $effectiveTid)) {
                return 'already_paid';
            }

            if ($payment->isPaid()) {
                return 'paid_with_other_tid';
            }

            if (! $lockedOrder->order_status->isBeforePayment()
                || $payment->payment_status !== PaymentStatusEnum::READY) {
                return 'not_payable';
            }

            $lockedOrder->setRelation('payment', $payment);
            $easyPayMeta = $this->resolveEasyPayMeta(array_merge($pgResponse, $callbackPayload));

            $this->orderService->completePayment($lockedOrder, [
                'transaction_id' => $effectiveTid,
                'card_approval_number' => $pgResponse['AppNo'] ?? null,
                'card_number_masked' => $pgResponse['CardNum'] ?? null,
                'card_name' => $pgResponse['IssuCardName'] ?? $pgResponse['CardName'] ?? null,
                'card_installment_months' => (int) ($pgResponse['CardQuota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => $easyPayMeta['provider'] ?? null,
                'receipt_url' => 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID='.rawurlencode($effectiveTid),
                'payment_meta' => [
                    'result_code' => $pgResponse['ResultCode'] ?? null,
                    ...($easyPayMeta === [] ? [] : [
                        'nicepay_easy_pay_method' => $easyPayMeta['method'],
                        'nicepay_easy_pay_provider' => $easyPayMeta['provider'],
                        'nicepay_easy_pay_label' => $easyPayMeta['label'],
                        'embedded_pg_provider' => $easyPayMeta['provider'],
                        'embedded_pg_provider_label' => $easyPayMeta['label'],
                    ]),
                    'pay_method' => $pgResponse['PayMethod'] ?? null,
                    'auth_date' => $pgResponse['AuthDate'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'rcpt_tid' => $pgResponse['RcptTID'] ?? null,
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::AUTH_RESPONSE_KEYS),
                ],
                'payment_device' => DeviceDetector::detect($request),
            ], $amt);

            $lockedOrder->refresh();
            $pgUpdates = ['pg_provider' => 'nicepayments'];
            if ($isEscrow) {
                $pgUpdates['is_escrow'] = true;
            }
            $lockedOrder->payment?->update($pgUpdates);

            return 'completed';
        });
    }

    /**
     * 결제 요청 SignData 생성
     *
     * POST /plugins/sirsoft-pay_nicepayments/payment/sign-data
     */
    /**
     * 결제 요청 SignData 생성
     *
     * 클라이언트가 결제창 호출 직전에 EdiDate + SignData 를 발급받기 위해 호출.
     * 비회원 결제도 호출하므로 라우트 인증에는 의존하지 않는다.
     * MOID, 주문 상태, 나이스페이 결제 레코드, Amt 를 우리 DB 와 비교 검증하여
     * 클라이언트 측 주문/금액 조작을 차단한다.
     *
     * @param  SignDataRequest  $request  amt + moid (형식 검증은 FormRequest — 종전 수동 검사 400 → 표준 422)
     * @return JsonResponse ediDate / signData / mid 또는 422
     */
    public function signData(SignDataRequest $request): JsonResponse
    {
        $amt = (int) $request->validated('amt');
        $moid = (string) $request->validated('moid');

        $rateLimitKey = $this->signDataRateLimitKey($request, $moid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 429);
        }
        RateLimiter::hit($rateLimitKey, 60);

        // 주문 금액 검증: 클라이언트가 임의 금액으로 SignData를 요청하는 조작 방지
        $order = $this->orderService->findByOrderNumber($moid);

        if (! $order) {
            Log::warning('NicePayments: SignData - order not found', [
                'moid' => $moid,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.order_not_found')], 422);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            Log::warning('NicePayments: SignData buyer verification failed', [
                'moid' => $moid,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 403);
        }

        $payment = $order->payment()->first();

        if (! $payment || $payment->pg_provider !== 'nicepayments') {
            Log::warning('NicePayments: SignData rejected for non-payable order', [
                'moid' => $moid,
                'order_status' => $order->order_status->value,
                'payment_status' => $payment?->payment_status?->value,
                'pg_provider' => $payment?->pg_provider,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 422);
        }

        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액 (마일리지/예치금 차감 후 실청구액).
        // 클라이언트 SignData 요청액(amt = pg_payment_data.amount = resolveOrderPaymentChargeAmount)·
        // 코어 최종 승인 검증과 동일 기준. base≠결제 통화(예: base JPY, 결제 KRW)에서도 단위가 일치한다.
        $expectedChargeAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'sign_data', [
            'moid' => $moid,
            'requested_amt' => $amt,
            'ip' => $request->ip(),
        ]);
        if ($expectedChargeAmount === null) {
            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 422);
        }

        if ($expectedChargeAmount !== $amt) {
            Log::warning('NicePayments: SignData amount mismatch', [
                'moid' => $moid,
                'requested_amt' => $amt,
                'actual_amt' => $expectedChargeAmount,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_amount')], 422);
        }

        if (! $order->order_status->isBeforePayment() || $payment->payment_status !== PaymentStatusEnum::READY) {
            if (! $this->restoreRetryableNicepayOrder($order, $payment)) {
                Log::warning('NicePayments: SignData rejected for non-payable order', [
                    'moid' => $moid,
                    'order_status' => $order->order_status->value,
                    'payment_status' => $payment->payment_status?->value,
                    'pg_provider' => $payment->pg_provider,
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 422);
            }
        }

        $mid = trim($this->apiService->getMid());
        if ($mid === '') {
            Log::error('NicePayments: SignData rejected because MID is not configured', [
                'moid' => $moid,
                'is_test_mode' => $this->apiService->isTestMode(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 422);
        }

        $ediDate = $this->apiService->generateEdiDate();
        $signData = $this->apiService->generateSignData($ediDate, $amt);

        return response()->json([
            'ediDate' => $ediDate,
            'signData' => $signData,
            'mid' => $mid,
        ]);
    }

    private function signDataRateLimitKey(Request $request, string $moid): string
    {
        return 'sirsoft-pay_nicepayments:sign-data:'.sha1($request->ip().'|'.$moid);
    }

    /**
     * 가상계좌 입금 통보 처리
     *
     * POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify
     * 공식 매뉴얼: https://developers.nicepay.co.kr/manual-noti.php
     *
     * 동작:
     *   - ResultCode === '4110' 입금완료 통보만 결제완료 처리
     *   - 그 외 ResultCode (계좌발급, 입금취소 등) 는 로깅만 하고 OK 응답
     *   - 어떤 결과든 200 + 정확히 "OK" (text/plain) 를 돌려줘야 NicePay 가 재시도하지 않음
     *   - 한글 인코딩(EUC-KR) 은 VbankNotifyRequest::prepareForValidation 에서 UTF-8 로 변환됨
     *
     * 공식 spec 에 입금통보 Signature 가 없어 위변조 검증은 하지 않으며, 대신:
     *   - 발송 IP 화이트리스트 (VbankNotifyRequest::authorize)
     *   - TID/MOID/Amt 가 우리 DB 의 임시 발급 정보와 일치하는지 비교
     *   - 동일 TID 중복 처리 방지 (행 잠금)
     *   세 단계로 위변조/재처리 방어.
     */
    /**
     * 가상계좌 입금 통보 처리
     *
     * NicePay 서버가 직접 호출하는 입금 확인 웹훅. ResultCode 4110(입금완료) 만
     * 결제완료 처리하고 그 외 코드는 payment_meta 에 timeline 으로 누적.
     * 어떤 결과든 200 + 정확히 "OK" 응답으로 NicePay 재시도 차단.
     *
     * @param  VbankNotifyRequest  $request  검증된 입금통보 페이로드 (EUC-KR → UTF-8 변환됨)
     * @return Response 항상 200 + "OK" (text/plain)
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid = (string) $validated['TID'];
        $moid = (string) $validated['MOID'];
        $amt = (int) $validated['Amt'];
        $resultCode = (string) $validated['ResultCode'];

        // 입금완료 통보 (4110) 만 결제완료 처리.
        // 4100/계좌발급은 authCallback 에서 이미 처리됐고, 그 외 코드는 입금취소/오류 등.
        $isDeposited = $resultCode === '4110';
        $isCancellation = ! empty($validated['CancelDate'])
            || in_array((string) ($validated['StateCd'] ?? ''), ['1', '2'], true);

        // 통보 종류 라벨 (어드민/로그용)
        $notiType = $isDeposited ? 'deposited' : ($isCancellation ? 'cancelled' : 'other');

        if (! $isDeposited) {
            Log::info(
                'NicePayments: vbank notify '.($isCancellation ? 'cancellation' : 'non-deposit'),
                [
                    'tid' => $tid,
                    'moid' => $moid,
                    'result_code' => $resultCode,
                    'state_cd' => $validated['StateCd'] ?? null,
                    'cancel_date' => $validated['CancelDate'] ?? null,
                    'result_msg' => $validated['ResultMsg'] ?? null,
                ]
            );

            // 입금완료가 아니어도 어드민이 통보 시점/내용을 확인할 수 있도록 이력 저장.
            // 4100(계좌발급)·취소·재통보 모두 누적되어 어드민 패널에서 timeline 으로 보임.
            $this->recordVbankNotification($moid, $tid, $amt, $resultCode, $notiType, $validated);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('NicePayments: vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $alreadyProcessed = false;
            $contextError = null;

            DB::transaction(function () use ($order, $tid, $amt, $validated, &$alreadyProcessed, &$contextError): void {
                // 동시 입금 통보 중복 처리 방지: 행 단위 잠금
                $payment = $order->payment()->lockForUpdate()->first();

                if (! $payment) {
                    $contextError = 'payment_not_found';

                    return;
                }

                if ($payment->isPaid() && hash_equals((string) $payment->transaction_id, $tid)) {
                    $alreadyProcessed = true;

                    return;
                }

                $contextError = $this->validateVbankNotifyContext($order, $payment, $validated, $tid, $amt);
                if ($contextError !== null) {
                    return;
                }

                // 기존 통보 이력 보존 — completePayment 가 payment_meta 를 통째로 교체할 수 있어
                // 미리 머지한 메타를 만들어 전달
                $existingNotifications = is_array($payment?->payment_meta['vbank_notifications'] ?? null)
                    ? $payment->payment_meta['vbank_notifications']
                    : [];

                $newEntry = $this->buildVbankNotificationEntry('4110', $amt, 'deposited', $validated);
                $allNotifications = array_merge($existingNotifications, [$newEntry]);

                $this->orderService->completePayment($order, [
                    'transaction_id' => $tid,
                    'receipt_url' => 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID='.rawurlencode($tid),
                    'payment_meta' => [
                        'result_code' => '4110',
                        'auth_date' => $validated['AuthDate'] ?? null,
                        'auth_code' => $validated['AuthCode'] ?? null,
                        'mid' => $validated['MID'] ?? $this->apiService->getMid(),
                        'vbank_tid' => $tid,
                        'vbank_num' => $validated['VbankNum'] ?? null,
                        'vbank_name' => $validated['VbankName'] ?? null,
                        'vbank_input_name' => $validated['VbankInputName'] ?? null,
                        'fn_cd' => $validated['FnCd'] ?? null,
                        'fn_name' => $validated['FnName'] ?? null,
                        'is_test_mode' => $this->apiService->isTestMode(),
                        'rcpt_tid' => $validated['RcptTID'] ?? null,
                        'pg_response_sanitized' => true,
                        'pg_raw_response' => $this->sanitizePgResponse($validated, self::VBANK_NOTIFY_RESPONSE_KEYS),
                        // 어드민 표시용 통보 이력 (전체 누적)
                        'vbank_notifications' => $allNotifications,
                        'vbank_notification_summary' => $this->buildNotificationSummary($allNotifications),
                    ],
                ], $amt);

                // pg_provider 명시 설정 (completePayment가 이 필드를 업데이트하지 않을 수 있음)
                $order->refresh();
                $order->payment?->update(['pg_provider' => 'nicepayments']);
            });

            if ($contextError !== null) {
                Log::warning('NicePayments: vbank notify context mismatch', [
                    'reason' => $contextError,
                    'tid' => $tid,
                    'moid' => $moid,
                    'amt' => $amt,
                ]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            } elseif ($alreadyProcessed) {
                Log::info('NicePayments: vbank notify - already processed', ['tid' => $tid, 'moid' => $moid]);
            } else {
                Log::info('NicePayments: vbank deposit confirmed', [
                    'tid' => $tid,
                    'moid' => $moid,
                    'amt' => $amt,
                    'auth_date' => $validated['AuthDate'] ?? null,
                ]);
            }

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('NicePayments: vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        }
    }

    private function validateVbankNotifyContext(
        Order $order,
        OrderPayment $payment,
        array $validated,
        string $tid,
        int $amount,
    ): ?string {
        if (! $payment->isVirtualAccount()) {
            return 'payment_method_not_vbank';
        }

        if ($payment->payment_status !== PaymentStatusEnum::WAITING_DEPOSIT) {
            return 'payment_not_waiting_deposit';
        }

        $meta = $payment->payment_meta ?? [];
        $storedTid = trim((string) ($payment->transaction_id ?: ($meta['vbank_tid'] ?? '')));
        if ($storedTid === '' || ! hash_equals($storedTid, $tid)) {
            return 'tid_mismatch';
        }

        $storedMid = trim((string) ($meta['mid'] ?? ''));
        $expectedMid = $storedMid !== '' ? $storedMid : $this->apiService->getMid();
        $receivedMid = trim((string) ($validated['MID'] ?? ''));
        if ($expectedMid === '' || $receivedMid === '' || ! hash_equals($expectedMid, $receivedMid)) {
            return 'mid_mismatch';
        }

        $storedAccount = trim((string) ($payment->vbank_number ?: ($meta['vbank_num'] ?? '')));
        $receivedAccount = trim((string) ($validated['VbankNum'] ?? ''));
        if ($storedAccount === '' || $receivedAccount === '' || ! hash_equals($storedAccount, $receivedAccount)) {
            return 'vbank_account_mismatch';
        }

        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'vbank_notify', [
            'tid' => $tid,
            'received_amt' => $amount,
        ]);
        if ($expectedAmount === null) {
            return 'invalid_payment_currency';
        }

        if ($amount !== $expectedAmount) {
            return 'amount_mismatch';
        }

        return null;
    }

    private function restoreRetryableNicepayOrder(Order $order, OrderPayment $payment): bool
    {
        if (! $this->isRetryableNicepayFailure($order, $payment)) {
            return false;
        }

        DB::transaction(function () use ($order, $payment) {
            $now = now()->toIso8601String();
            $orderMeta = $order->order_meta ?? [];
            $paymentMeta = $payment->payment_meta ?? [];

            $previousFailure = array_filter([
                'code' => $orderMeta['payment_failure_code'] ?? $paymentMeta['failure_code'] ?? null,
                'message' => $orderMeta['payment_failure_message'] ?? $paymentMeta['failure_message'] ?? null,
                'failed_at' => $orderMeta['payment_failed_at'] ?? $paymentMeta['failed_at'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($previousFailure !== []) {
                $history = $orderMeta['nicepayments_retry_failures'] ?? [];
                $history = is_array($history) ? $history : [];
                $history[] = $previousFailure;
                $orderMeta['nicepayments_retry_failures'] = array_slice($history, -5);
            }

            unset(
                $orderMeta['payment_failure_code'],
                $orderMeta['payment_failure_message'],
                $orderMeta['payment_failed_at'],
                $paymentMeta['failure_code'],
                $paymentMeta['failure_message'],
                $paymentMeta['failed_at'],
                $paymentMeta['failure_source'],
            );

            $orderMeta['nicepayments_retry_count'] = (int) ($orderMeta['nicepayments_retry_count'] ?? 0) + 1;
            $orderMeta['nicepayments_retry_started_at'] = $now;
            $paymentMeta['retry_count'] = (int) ($paymentMeta['retry_count'] ?? 0) + 1;
            $paymentMeta['retry_started_at'] = $now;

            $order->update([
                'order_status' => OrderStatusEnum::PENDING_ORDER,
                'order_meta' => $orderMeta,
            ]);

            $payment->update([
                'payment_status' => PaymentStatusEnum::READY,
                'payment_meta' => $paymentMeta,
                'paid_at' => null,
                'cancelled_at' => null,
            ]);
        });

        Log::info('NicePayments: retryable failed order restored for SignData', [
            'moid' => $order->order_number,
            'payment_id' => $payment->id,
        ]);

        return true;
    }

    private function isRetryableNicepayFailure(Order $order, OrderPayment $payment): bool
    {
        $paymentMeta = $payment->payment_meta ?? [];
        $failureCode = (string) ($paymentMeta['failure_code'] ?? $order->order_meta['payment_failure_code'] ?? '');

        return $order->order_status === OrderStatusEnum::CANCELLED
            && $payment->pg_provider === 'nicepayments'
            && $payment->payment_status === PaymentStatusEnum::FAILED
            && ($paymentMeta['failure_source'] ?? null) === 'nicepayments'
            && $failureCode !== 'AMOUNT_MISMATCH'
            && blank($payment->transaction_id)
            && blank($payment->card_approval_number)
            && $payment->paid_at === null;
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? ShopRedirectUrl::DEFAULT_SUCCESS_URL;
        $url = ShopRedirectUrl::resolve($urlTemplate, ['{orderId}' => $orderId]);

        return UrlHelper::toAbsolute($url);
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = ShopRedirectUrl::resolve($settings['redirect_fail_url'] ?? ShopRedirectUrl::DEFAULT_FAIL_URL);

        if (! empty($queryParams)) {
            $query = http_build_query(array_filter($queryParams));
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $baseUrl = $baseUrl.$separator.$query;
        }

        return UrlHelper::toAbsolute($baseUrl);
    }

    /**
     * 입금통보 1건의 어드민 표시용 entry 생성.
     *
     * 어드민 패널에서 timeline 형태로 보여줄 핵심 필드만 추려둠. raw 는 전체 보존.
     */
    private function buildVbankNotificationEntry(
        string $resultCode,
        int $amt,
        string $type,
        array $validated
    ): array {
        return [
            'received_at' => now()->toIso8601String(),
            'type' => $type, // 'deposited' | 'cancelled' | 'other'
            'result_code' => $resultCode,
            'result_msg' => $validated['ResultMsg'] ?? null,
            'state_cd' => $validated['StateCd'] ?? null,
            'amt' => $amt,
            'tid' => $validated['TID'] ?? null,
            'auth_date' => $validated['AuthDate'] ?? null,
            'auth_code' => $validated['AuthCode'] ?? null,
            'depositor' => $validated['VbankInputName'] ?? null,
            'vbank_num' => $validated['VbankNum'] ?? null,
            'vbank_name' => $validated['VbankName'] ?? null,
            'cancel_date' => $validated['CancelDate'] ?? null,
            'raw_sanitized' => true,
            'raw' => $this->sanitizePgResponse($validated, self::VBANK_NOTIFY_RESPONSE_KEYS),
        ];
    }

    /**
     * 입금완료가 아닌 통보 (계좌발급/취소/오류/재통보) 를 payment_meta 에 누적.
     *
     * 어드민이 "언제 어떤 통보가 왔는지" 추적할 수 있도록 모든 이벤트를 기록.
     * 주문/결제가 없으면 조용히 skip (위변조 방어 — 우리 DB 에 없는 주문은 무시).
     */
    private function recordVbankNotification(
        string $moid,
        string $tid,
        int $amt,
        string $resultCode,
        string $type,
        array $validated
    ): void {
        try {
            DB::transaction(function () use ($moid, $amt, $resultCode, $type, $validated): void {
                $order = $this->orderService->findByOrderNumber($moid);
                if (! $order) {
                    return;
                }

                $payment = $order->payment()->lockForUpdate()->first();
                if (! $payment) {
                    return;
                }

                $existing = is_array($payment->payment_meta['vbank_notifications'] ?? null)
                    ? $payment->payment_meta['vbank_notifications']
                    : [];

                $entry = $this->buildVbankNotificationEntry($resultCode, $amt, $type, $validated);
                $existing[] = $entry;

                $payment->payment_meta = array_merge($payment->payment_meta ?? [], [
                    'vbank_notifications' => $existing,
                    'vbank_notification_summary' => $this->buildNotificationSummary($existing),
                ]);
                // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
                $payment->save();
            });
        } catch (\Throwable $e) {
            // 통보 이력 기록 실패가 OK 응답 자체를 막아 NicePay 재시도를 유발하지 않도록 swallow.
            Log::warning('NicePayments: failed to record vbank notification entry', [
                'moid' => $moid,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 어드민 패널 한 줄 요약용 데이터.
     *
     * - first_received_at / last_received_at: 첫·마지막 통보 시각
     * - count: 누적 통보 횟수 (재통보 포함)
     * - last_type / last_result_code: 마지막 통보 종류
     * - deposited_at / cancelled_at: 입금완료·입금취소가 있었던 경우의 시각
     */
    private function buildNotificationSummary(array $notifications): array
    {
        if (empty($notifications)) {
            return [];
        }

        $first = $notifications[0];
        $last = end($notifications);
        reset($notifications);

        $depositedAt = null;
        $cancelledAt = null;
        foreach ($notifications as $n) {
            if (($n['type'] ?? '') === 'deposited' && $depositedAt === null) {
                $depositedAt = $n['received_at'] ?? null;
            }
            if (($n['type'] ?? '') === 'cancelled') {
                $cancelledAt = $n['received_at'] ?? null;
            }
        }

        return [
            'count' => count($notifications),
            'first_received_at' => $first['received_at'] ?? null,
            'last_received_at' => $last['received_at'] ?? null,
            'last_type' => $last['type'] ?? null,
            'last_result_code' => $last['result_code'] ?? null,
            'deposited_at' => $depositedAt,
            'cancelled_at' => $cancelledAt,
            'last_depositor' => $last['depositor'] ?? null,
            'last_amt' => $last['amt'] ?? null,
        ];
    }

    private function lastFour(string $value): ?string
    {
        $digits = $this->digitsOnly($value);
        if ($digits === '') {
            return null;
        }

        return substr($digits, -4);
    }
}
