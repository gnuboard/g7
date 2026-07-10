<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 모바일 결제 콜백 컨트롤러
 *
 * 모바일 결제 흐름:
 *  1단계: 프론트엔드가 https://mobile.inicis.com/smart/payment/ 로 폼 제출 (페이지 이동)
 *  2단계: KG 이니시스 인증 후 P_NEXT_URL(이 컨트롤러)로 POST 콜백 (manual.inicis.com/pay/stdpay_m.html)
 *  3단계: 서버가 P_REQ_URL로 서버 승인 요청 (P_MID + P_TID)
 *  4단계: 승인 응답(URL-encoded) 파싱 후 주문 완료 처리 (가상계좌는 발급 정보만 저장)
 */
class MobileCallbackController
{
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use ResolvesEasyPaySelection;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;
    use ValidatesCbtOrderContext;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    // KG 이니시스 모바일 표준결제 — 사용자 결제창 닫기 시 P_RMESG1 에 포함되는 패턴.
    // Playwright 운영 재현으로 확인 (P_STATUS=01 은 일반 실패와 공유되므로 메시지 기반 분기).
    private const USER_CANCEL_MESSAGE_PATTERNS = ['사용자가 결제를 취소', '결제를 취소하셨'];

    private const MOBILE_APPROVE_RESPONSE_KEYS = [
        'P_STATUS',
        'P_RMESG1',
        'P_TID',
        'P_OID',
        'P_AMT',
        'P_TYPE',
        'P_AUTH_DT',
        'P_APPL_NUM',
        'P_FN_CD1',
        'P_FN_NM',
        'P_CARD_ISSUER_NAME',
        'P_CARD_QUOTA',
        'P_CARD_INTEREST',
        'P_MID',
        'P_GOODS',
    ];

    private const MOBILE_VBANK_ISSUE_RESPONSE_KEYS = [
        'P_STATUS',
        'P_RMESG1',
        'P_TID',
        'P_OID',
        'P_AMT',
        'P_TYPE',
        'P_AUTH_DT',
        'P_FN_CD1',
        'P_FN_NM',
        'P_VACT_BANK_CODE',
        'P_VACT_BANK_NAME',
        'P_VACT_DATE',
        'P_VACT_TIME',
        'P_MID',
        'P_GOODS',
    ];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * handle
     *
     * @param  MobileCallbackRequest  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handle(MobileCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();
        $selectedEasyPayMethod = $this->resolveSelectedEasyPayMethod($request);

        $pStatus   = $validated['P_STATUS'];
        // KG 이니시스 모바일 메뉴얼(STEP 2) 표준 응답에는 P_OID 가 없으므로 P_NEXT_URL 쿼리스트링의
        // orderId 를 fallback 으로 사용한다. 일부 PG 환경에서 P_OID 를 echo 하면 우선 채택.
        $moid      = $validated['P_OID'] ?? $request->query('orderId') ?? null;
        $pAmt      = $validated['P_AMT'] ?? null;

        Log::info('KG Inicis mobile: callback received', [
            'order_id'  => $moid,
            'P_STATUS'  => $pStatus,
            'idc_name'  => $validated['idc_name'] ?? null,
            'P_REQ_URL' => $validated['P_REQ_URL'] ?? null,
            'input_keys' => array_keys($request->all()),
            'query_keys' => array_keys($request->query()),
            'easy_pay' => $this->buildEasyPayLogContext($selectedEasyPayMethod),
        ]);

        if (! $moid) {
            Log::error('KG Inicis mobile: order id missing — neither P_OID nor query orderId present', [
                'all_keys' => array_keys($request->all()),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'order_id_missing']));
        }

        // 인증 실패
        if ($pStatus !== '00') {
            $pMesg = (string) ($validated['P_RMESG1'] ?? '');
            $isUserCancel = $this->isUserCancelMessage($pMesg);

            Log::info('KG Inicis mobile: auth not success', [
                'P_OID'         => $moid,
                'P_STATUS'      => $pStatus,
                'P_RMESG1'      => $pMesg,
                'is_user_cancel' => $isUserCancel,
            ]);

            // 사용자가 결제창을 직접 닫은 취소는 오류 모달 없이 체크아웃으로 조용히 복귀
            if ($isUserCancel) {
                return redirect($this->resolveFailUrl());
            }

            return redirect($this->resolveFailUrl([
                'error'   => $pStatus,
                'message' => $pMesg,
                'orderId' => $moid,
            ]));
        }

        $pTid    = $validated['P_TID'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        $reqUrl  = $validated['P_REQ_URL'] ?? null;

        if (! $pTid || ! $idcName || ! $reqUrl) {
            Log::error('KG Inicis mobile: missing required fields', [
                'P_OID'    => $moid,
                'idc_name' => $idcName,
                'P_TID'    => $pTid,
                'P_REQ_URL' => $reqUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // P_REQ_URL 화이트리스트 검증 (모바일 IDC URL, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $reqUrl)) {
            Log::error('KG Inicis mobile: P_REQ_URL not in whitelist (possible SSRF attempt)', [
                'P_OID'    => $moid,
                'idc_name' => $idcName,
                'received' => $reqUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'req_url_invalid', 'orderId' => $moid]));
        }

        // Approve 성공 후 후속 처리 실패 시 PG 측 자동 cancel 을 위한 추적 변수.
        // catch 블록에서 set 되어 있으면 KG 이니시스 측 승인이 이미 발생한 상태이므로
        // cancelPayment 를 호출해 PG 잔존 승인을 해제한다 (사용자 환불 보장).
        $approvedTid = null;
        $approvedTotPrice = 0;
        $callbackLock = null;

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis mobile: order not found', ['P_OID' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            $callbackLock = $this->acquireOrderCallbackLock('mobileAuthCallback', $moid);

            // 서버 승인 요청: POST P_REQ_URL with P_MID + P_TID
            $result = $this->apiService->authorizeMobilePayment($reqUrl, $pTid);

            $resultStatus = $result['P_STATUS'] ?? '';

            if ($resultStatus !== '00') {
                Log::warning('KG Inicis mobile: server approve failed', [
                    'P_OID'    => $moid,
                    'P_STATUS' => $resultStatus,
                    'P_RMESG1' => $result['P_RMESG1'] ?? '',
                ]);

                $this->orderService->failPayment($order, $resultStatus, $result['P_RMESG1'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error'   => $resultStatus,
                    'message' => $result['P_RMESG1'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid      = $result['P_TID'] ?? $pTid;
            $totPrice = (int) ($result['P_AMT'] ?? $pAmt ?? 0);
            $payType  = (string) ($result['P_TYPE'] ?? '');

            // 가상계좌: completePayment 없이 발급 정보만 저장 (입금 통보 시점에 completePayment)
            if (strcasecmp($payType, 'VBank') === 0) {
                $this->handleVbankIssued($order, $result, $tid);
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'mobile authCallback (card)');
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            // PG 측 승인이 확정된 시점 — 후속 처리 실패 시 cancel 필요. catch 에서 참조.
            $approvedTid = $tid;
            $approvedTotPrice = $totPrice;
            $embeddedPgProvider = $this->resolveEmbeddedPgProvider($selectedEasyPayMethod);

            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'mobile_auth_callback', [
                'tid' => $tid,
                'received_amount' => $totPrice,
            ]);
            if ($expectedAmount === null) {
                $this->autoCancelIfApproved($approvedTid, $moid, $approvedTotPrice, 'invalid_payment_currency');

                return redirect($this->resolveFailUrl(['error' => 'invalid_payment_currency', 'orderId' => $moid]));
            }
            if ($expectedAmount > 0 && $totPrice !== $expectedAmount) {
                $this->autoCancelIfApproved($approvedTid, $moid, $approvedTotPrice, 'amount_mismatch');

                return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));
            }

            Log::info('KG Inicis mobile: completing card payment', [
                'P_OID' => $moid,
                'P_TID' => $tid,
                'P_TYPE' => $payType ?: null,
                'easy_pay' => $this->buildEasyPayLogContext($selectedEasyPayMethod),
            ]);

            $this->orderService->completePayment($order, [
                'transaction_id'          => $tid,
                'card_approval_number'    => $result['P_APPL_NUM'] ?? null,
                'card_number_masked'      => $result['P_CARD_NUM'] ?? null,
                'card_name'               => $result['P_CARD_ISSUER_NAME'] ?? null,
                'card_installment_months' => (int) ($result['P_CARD_QUOTA'] ?? 0),
                'is_interest_free'        => false,
                'embedded_pg_provider'    => $embeddedPgProvider,
                'receipt_url'             => null,
                'payment_meta'            => array_merge([
                    'result_code'    => $resultStatus,
                    'pay_method'     => $payType ?: null,
                    'auth_date'      => $result['P_AUTH_DT'] ?? null,
                    'mid'            => $this->apiService->getMid(),
                    'is_test_mode'   => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($result, self::MOBILE_APPROVE_RESPONSE_KEYS),
                ], $this->buildEasyPayPaymentMeta($selectedEasyPayMethod)),
                'payment_device'          => 'mobile',
            ], $totPrice);

            $order->payment()->update(['pg_provider' => 'kginicis']);
            $this->queueReceiptCookie($moid);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis mobile: amount mismatch', [
                'P_OID'    => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual'   => $e->getActualAmount(),
            ]);

            $this->autoCancelIfApproved($approvedTid, $moid, $approvedTotPrice, 'amount_mismatch');

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('KG Inicis mobile: approve exception', [
                'P_OID' => $moid,
                'P_TID' => $approvedTid,
                'error' => $e->getMessage(),
            ]);

            if ($this->wasAlreadyPaid($approvedTid)) {
                Log::warning('KG Inicis mobile: local payment already completed, auto-cancel skipped after exception', [
                    'P_OID' => $moid,
                    'P_TID' => $approvedTid,
                    'error' => $e->getMessage(),
                ]);

                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            $this->autoCancelIfApproved($approvedTid, $moid, $approvedTotPrice, 'approve_failed');

            return redirect($this->resolveFailUrl([
                'error'   => 'approve_failed',
                'message' => $e->getMessage(),
                'orderId' => $moid,
            ]));
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * Approve 가 이미 발생한 후 후속 처리 실패 시 KG 이니시스 측 cancel 을 시도.
     *
     * KG 이니시스 모바일 승인이 완료되었으나 우리 서버에서 completePayment 가
     * 실패한 경우 사용자 카드는 PG 측에서 승인 상태로 잔존 → 환불 불가 위험.
     * 본 메서드는 cancelPayment API (/v2/pg/refund) 로 PG 잔존 승인을 즉시 해제.
     *
     * cancel 자체가 실패해도 사용자 응답 흐름은 막지 않음 — 로깅만 수행하고
     * 운영자가 KG 이니시스 가맹점 관리자에서 수동 처리하도록 신호.
     */
    private function autoCancelIfApproved(
        ?string $tid,
        string $moid,
        int $totPrice,
        string $reason,
    ): void {
        if ($tid === null || $tid === '') {
            return;
        }

        try {
            $result = $this->apiService->cancelPayment(
                $tid,
                'Card',
                null,
                'auto-cancel: ' . $reason,
                $totPrice > 0 ? $totPrice : null,
            );

            Log::warning('KG Inicis mobile: auto-cancel after post-approve failure', [
                'tid'      => $tid,
                'P_OID'    => $moid,
                'amount'   => $totPrice,
                'reason'   => $reason,
                'pg_result' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('KG Inicis mobile: auto-cancel FAILED — manual reconciliation required', [
                'tid'    => $tid,
                'P_OID'  => $moid,
                'amount' => $totPrice,
                'reason' => $reason,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모바일 가상계좌 발급 처리 (completePayment 없이 계좌 정보만 저장)
     *
     * KG 이니시스 모바일 승인 응답에서 P_TYPE=VBank 로 판별된 경우 호출.
     * 실제 결제 완료(completePayment)는 입금 통보(mobileVbankNotify) 시점에 처리.
     * 응답 필드는 manual.inicis.com/pay/stdpay_m.html 표준에 따른 P_VACT_* 형식.
     */
    private function handleVbankIssued(Order $order, array $result, string $tid): void
    {
        $vactDate = $result['P_VACT_DATE'] ?? null;
        $vactTime = $result['P_VACT_TIME'] ?? '235959';
        $vbankDueAt = null;

        if ($vactDate && strlen((string) $vactDate) === 8) {
            try {
                $vbankDueAt = Carbon::createFromFormat('YmdHis', $vactDate . $vactTime);
            } catch (\Exception) {
                $vbankDueAt = null;
            }
        }

        $order->payment()->update(array_filter([
            'pg_provider'     => 'kginicis',
            'payment_status'  => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id'  => $tid ?: null,
            'vbank_code'      => $result['P_VACT_BANK_CODE'] ?? $result['P_FN_CD1'] ?? null,
            'vbank_name'      => $result['P_VACT_BANK_NAME'] ?? $result['P_FN_NM'] ?? null,
            'vbank_number'    => $result['P_VACT_NUM'] ?? null,
            'vbank_holder'    => $result['P_VACT_NAME'] ?? $result['P_RVACTNM'] ?? null,
            'vbank_due_at'    => $vbankDueAt,
            'vbank_issued_at' => now(),
            'payment_device'  => 'mobile',
            'payment_meta'    => [
                'result_code'     => $result['P_STATUS'] ?? '00',
                'pay_method'      => 'VBank',
                'auth_date'       => $result['P_AUTH_DT'] ?? null,
                'mid'             => $this->apiService->getMid(),
                'is_test_mode'    => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_raw_response' => $this->sanitizePgResponse($result, self::MOBILE_VBANK_ISSUE_RESPONSE_KEYS),
            ],
        ], fn ($v) => $v !== null));

        Log::info('KG Inicis mobile: vbank account issued', [
            'P_OID'        => $order->order_number,
            'P_TID'        => $tid,
            'vbank_name'   => $result['P_VACT_BANK_NAME'] ?? $result['P_FN_NM'] ?? null,
            'vbank_number' => $result['P_VACT_NUM'] ?? null,
            'vbank_due_at' => $vbankDueAt?->toDateTimeString(),
        ]);
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return $this->absolutize(str_replace('{orderId}', $orderId, $urlTemplate));
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $this->absolutize($settings['redirect_fail_url'] ?? '/shop/checkout');

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams, fn ($v) => $v !== null && $v !== ''));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * KG 이니시스 모바일 P_RMESG1 메시지가 사용자 직접 취소 패턴인지 판별.
     *
     * P_STATUS=01 은 일반 실패(예: MX1006)와 사용자 취소가 공유하므로, P_RMESG1
     * 메시지의 한국어 문구로 분기. 시제/존대 변형을 흡수하기 위해 부분 일치.
     */
    private function isUserCancelMessage(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (self::USER_CANCEL_MESSAGE_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 상대 경로면 APP_URL 기준으로 절대 URL 화.
     *
     * PG가 브라우저 POST 로 콜백을 보내는 동안 Apache 가 ProxyPreserveHost Off 등
     * 으로 Host 헤더를 localhost 로 바꿔서 PHP 에 전달하는 경우, Laravel 의
     * redirect('/path') 가 http://localhost/path 를 생성해버린다. config('app.url')
     * (.env 의 APP_URL)을 명시적 base 로 사용하여 도메인을 보존한다.
     */
    private function absolutize(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');
        $path = $url === '' ? '/' : ($url[0] === '/' ? $url : '/' . $url);

        return $base . $path;
    }
}
