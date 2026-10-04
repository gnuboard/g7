<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesCbtClaimPayments;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\CbtCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Services\CbtReconciliationService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimApproveToken;
use Plugins\Sirsoft\PayKginicis\Support\CbtCvsResponse;
use Plugins\Sirsoft\PayKginicis\Support\ShopRedirectUrl;

/**
 * KG 이니시스 CBT (Cross Border Trade) 일본 결제 콜백 컨트롤러
 *
 * CBT 결제 흐름:
 *  1. 브라우저가 /cbtauth 로 POST 폼 전송
 *  2. KG 이니시스가 returnUrl 로 sid 를 붙여 리다이렉트 → 이 컨트롤러
 *  3. 서버가 /cbtapprove 로 mid + sid 전송하여 최종 승인
 *  4. 성공 시 주문 완료 처리 후 결제 완료 페이지로 리다이렉트
 */
class CbtCallbackController
{
    use HandlesCbtClaimPayments;
    use HandlesClaimPaymentCallbacks;
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use SerializesPaymentCallbacks;
    use ValidatesCbtOrderContext;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    private const CBT_USER_CANCEL_RESULT_CODES = ['2001', '0021', '0022'];

    private const CBT_USER_CANCEL_MESSAGE_PATTERNS = [
        'ユーザーキャンセル',
        'キャンセル',
        '사용자가 결제를 취소',
        '결제를 취소',
        '사용자가 취소',
        '사용자 취소',
        '취소',
    ];

    private const CBT_PAYPAY_PROCESSING_FAILURE_MESSAGE = 'PayPay 결제를 완료하지 못했습니다. 다시 시도하거나 다른 결제수단을 선택해 주세요.';

    private const CBT_AUTH_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'orderID',
        'orderId',
        'oid',
        'mid',
        'sid',
        'paymethod',
        'selectedPaymentMethod',
    ];

    private const CBT_APPROVE_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'code',
        'message',
        'tid',
        'transactionId',
        'paymethod',
        'payMethod',
        'amount',
        'price',
        'currency',
        'approve',
        'applDate',
        'applTime',
        'installMonth',
        'cardCode',
        'cardName',
        'applNo',
        'convenience',
        'confNo',
        'receiptNo',
        'paymentTerm',
        'currencyCd',
        'currencyCode',
        'mid',
        'MID',
        'oid',
        'orderId',
        'orderID',
    ];

    private const CBT_REFUND_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'cancelDate',
        'cancelTime',
        'prtcCode',
    ];

    /**
     * CBT 콜백 처리에 필요한 서비스를 주입받습니다.
     *
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 조회 서비스
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     * @param  CbtReconciliationService  $reconciliationService  CBT 대사 상태 기록 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
        private readonly CbtReconciliationService $reconciliationService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
    ) {}

    /**
     * CBT(일본 엔화) 결제 인증 콜백을 받아 승인 처리 후 결과 페이지로 리다이렉트합니다.
     *
     * @param  CbtCallbackRequest  $request  CBT 인증 콜백 요청
     * @return RedirectResponse 성공/실패 결과 페이지 리다이렉트
     */
    public function handle(CbtCallbackRequest $request): RedirectResponse
    {
        $sid = (string) $request->input('sid', '');
        $oid = $this->resolveOrderId($request);
        $authResultCode = (string) $request->input('resultCode', '');
        $authResultMsg = (string) $request->input('resultMsg', '');
        $authMid = (string) $request->input('mid', '');

        if ($authResultCode !== '' && $authResultCode !== 'OK' && $this->isCbtAuthUserCancel($authResultCode, $authResultMsg)) {
            Log::info('KG Inicis CBT: auth cancelled by user', [
                'oid' => $oid,
                'result_code' => $authResultCode,
                'result_msg' => $authResultMsg,
            ]);

            // 클레임 추가결제는 체크아웃이 아니라 신청서 상세로 돌려보낸다 (국내 경로와 동일).
            if (ClaimPayment::isClaimPaymentNumber($oid)) {
                $claimRedirect = $this->redirectClaimWindowFailure($oid, null);

                if ($claimRedirect !== null) {
                    return $claimRedirect;
                }
            }

            return redirect($this->resolveFailUrl());
        }

        if ($sid === '' || $oid === '') {
            Log::warning('KG Inicis CBT: missing sid or oid', ['oid' => $oid, 'sid' => $sid]);

            return redirect($this->resolveFailUrl(['error' => 'invalid_params', 'orderId' => $oid]));
        }

        if ($authResultCode !== '' && $authResultCode !== 'OK') {
            // 브라우저가 전달한 CBT 인증 실패값은 무인증 콜백이므로 주문 상태 변경에는 사용하지 않는다.
            Log::warning('KG Inicis CBT: auth failed', [
                'oid' => $oid,
                'result_code' => $authResultCode,
                'result_msg' => $authResultMsg,
            ]);

            // 결제창 단계 실패도 클레임은 신청서 상세로 돌려보낸다 — PG 원문 메시지는 싣지 않고
            // 코드만 전달한다(이 경로는 비인증 브라우저 리다이렉트다).
            if (ClaimPayment::isClaimPaymentNumber($oid)) {
                $claimRedirect = $this->redirectClaimWindowFailure(
                    $oid,
                    $authResultCode !== '' ? $authResultCode : 'cbt_auth_failed',
                );

                if ($claimRedirect !== null) {
                    return $claimRedirect;
                }
            }

            return redirect($this->resolveFailUrl($this->buildCbtFailureRedirectParams(
                $authResultCode !== '' ? $authResultCode : 'cbt_auth_failed',
                $authResultMsg,
                (string) $request->input('paymethod', ''),
                $oid,
            )));
        }

        if ($authMid !== '' && $authMid !== $this->apiService->getJapanMid()) {
            Log::warning('KG Inicis CBT: callback MID mismatch', [
                'oid' => $oid,
                'received_mid' => $authMid,
                'expected_mid' => $this->apiService->getJapanMid(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'mid_mismatch', 'orderId' => $oid]));
        }

        // Approve 성공 후 후속 처리 실패 시 PG 자동 cancel 추적 변수.
        $approvedTid = null;
        $approvedAmount = 0;
        $callbackLock = null;

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        // 승인은 모듈 위임(필터 발화 → 이 플러그인의 승인 리스너)이고, 승인 뒤의 거절은 모듈의
        // `claim_payment.void_approval` 필터(승인 리스너가 CBT 환불 API 로 되돌림)가 보상한다.
        // 주문 경로와 같은 직렬화 락을 건다.
        if (ClaimPayment::isClaimPaymentNumber($oid)) {
            $claimPayment = $this->resolveApprovableCbtClaimPayment($oid);

            if (! $claimPayment) {
                Log::error('KG Inicis CBT: claim payment not approvable', ['oid' => $oid]);

                $claimRedirect = $this->redirectClaimWindowFailure($oid, 'claim_payment_not_approvable');

                return $claimRedirect ?? redirect($this->resolveFailUrl([
                    'error' => 'claim_payment_not_found',
                    'orderId' => $oid,
                ]));
            }

            // 락 대기 초과는 500 이 아니라 신청서 상세 복귀로 끝난다(`approveClaimPaymentSerialized`).
            return $this->approveClaimPaymentSerialized(
                'cbtAuthCallback',
                $claimPayment,
                // 승인 재료(sid)를 서버가 넘겼다는 서명 — 클라이언트 승인 API 는 이것을 만들 수 없다.
                $request->except(['_token', 'cbt_token']) + [
                    'cbt_sid' => $sid,
                    'cbt_token' => CbtClaimApproveToken::sign($oid, $sid),
                ],
            );
        }

        try {
            $order = $this->orderService->findByOrderNumber($oid);

            if (! $order) {
                Log::error('KG Inicis CBT: order not found', ['oid' => $oid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $oid]));
            }

            $callbackLock = $this->acquireOrderCallbackLock('cbtAuthCallback', $oid);

            $this->assertPayableCbtOrder($order);

            $pgResponse = $this->apiService->approveCbtPayment($sid);

            $resultCode = $pgResponse['resultCode'] ?? ($pgResponse['code'] ?? '');

            if (! $this->isCbtSuccessCode((string) $resultCode)) {
                $resultMsg = $pgResponse['resultMsg'] ?? ($pgResponse['message'] ?? 'CBT approve failed');
                Log::warning('KG Inicis CBT: approve failed', [
                    'oid' => $oid,
                    'result_code' => $resultCode,
                    'result_msg' => $resultMsg,
                ]);

                // 승인이 성립하지 않았다 — 실제로 결제된 것이 없으므로 주문 상태를 바꾸지 않는다.
                // 브라우저 실패(authResultCode)는 이미 무변경으로 하드닝되어 있으나, 서버 승인
                // 실패 분기는 여전히 비인증 입력(sid + oid)으로 도달할 수 있어 같은 통로가 열려 있었다.
                return redirect($this->resolveFailUrl($this->buildCbtFailureRedirectParams(
                    (string) $resultCode,
                    (string) $resultMsg,
                    (string) ($pgResponse['paymethod'] ?? $request->input('paymethod', '')),
                    $oid,
                )));
            }

            $tid = $pgResponse['tid'] ?? ($pgResponse['transactionId'] ?? '');
            if ($tid === '') {
                throw new \RuntimeException('KG Inicis CBT approve response missing tid.');
            }

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $oid, 'CBT authCallback');
                $this->queueReceiptCookie($oid);

                return redirect($this->resolveSuccessUrl($oid));
            }

            // 편의점(CVS) 판정은 **승인 응답** 기준이다(`CbtCvsResponse` — 클레임 경로와 같은 판정).
            // 응답에 결제수단이 비어 오면 종전에는 브라우저 콜백의 paymethod 로 채웠는데, 그 값을 CARD 로
            // 바꿔 보내면 입금 전 편의점 결제번호 발급이 결제완료로 확정됐다. 콜백 값은 응답에 결제수단이
            // 없을 때 기록용으로만 쓰고, 그 값이 CVS 라도 응답이 CVS 가 아니면 CVS 로 인정하지 않는다
            // (그러면 아래 결제수단 대조가 어긋나 승인분을 환불한다).
            $isCvs = CbtCvsResponse::matches($pgResponse);
            $payMethod = CbtCvsResponse::responsePayMethod($pgResponse);

            if ($payMethod === '') {
                $callbackPayMethod = trim((string) $request->input('paymethod', ''));
                $payMethod = $isCvs
                    ? 'CVS'
                    : ($callbackPayMethod !== '' && strtoupper($callbackPayMethod) !== 'CVS' ? $callbackPayMethod : 'CBT');
            }

            if (! $isCvs) {
                // PG 승인이 확정된 직후부터는 어떤 후속 예외라도 자동 취소 대상이다.
                $approvedTid = $tid;
            }

            $this->assertCbtApproveResponseMatchesOrder($order, $pgResponse, $request, $payMethod);
            $approvedAmount = $this->resolveApprovedAmount($pgResponse, $order);
            $authResponse = $this->sanitizePgResponse($request->except(['_token']), self::CBT_AUTH_RESPONSE_KEYS);
            $approveResponse = $this->sanitizePgResponse($pgResponse, self::CBT_APPROVE_RESPONSE_KEYS);
            $selectedPaymentMethod = $this->resolveSelectedCbtPaymentMethod($request, $payMethod);
            $selectionMeta = $this->buildCbtSelectionPaymentMeta($selectedPaymentMethod);

            if ($isCvs) {
                $this->handleCbtCvsIssued($order, $pgResponse, $tid, $sid, $approvedAmount, $authResponse, $approveResponse, $selectedPaymentMethod);
                $this->queueReceiptCookie($oid);

                Log::info('KG Inicis CBT: CVS payment issued', ['oid' => $oid, 'tid' => $tid]);

                return redirect($this->resolveSuccessUrl($oid));
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['approve'] ?? null,
                'card_installment_months' => $this->normalizeInstallmentMonths($pgResponse['installMonth'] ?? null),
                'payment_meta' => array_merge([
                    'result_code' => $resultCode,
                    'pay_method' => $payMethod,
                    'cbt_type' => 'JPPG',
                    'cbt_mid' => $this->apiService->getJapanMid(),
                    'cbt_sid' => $sid,
                    'mid' => $this->apiService->getJapanMid(),
                    'currency' => 'JPY',
                    'is_cbt' => true,
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_auth_response' => $authResponse,
                    'pg_approve_response' => $approveResponse,
                    'pg_raw_response' => $approveResponse,
                ], $selectionMeta),
            ], $approvedAmount);

            $this->queueReceiptCookie($oid);

            Log::info('KG Inicis CBT: payment completed', ['oid' => $oid, 'tid' => $tid]);

            return redirect($this->resolveSuccessUrl($oid));

        } catch (\Exception $e) {
            Log::error('KG Inicis CBT: callback exception', [
                'oid' => $oid,
                'tid' => $approvedTid,
                'error' => $e->getMessage(),
            ]);

            if ($this->wasAlreadyPaid($approvedTid)) {
                Log::warning('KG Inicis CBT: local payment already completed, auto-refund skipped after exception', [
                    'oid' => $oid,
                    'tid' => $approvedTid,
                    'error' => $e->getMessage(),
                ]);

                $this->queueReceiptCookie($oid);

                return redirect($this->resolveSuccessUrl($oid));
            }

            $this->refundApprovedCbtPaymentOrFlagManualReconciliation(
                $approvedTid,
                $oid,
                $approvedAmount,
                $e->getMessage(),
            );

            return redirect($this->resolveFailUrl(['error' => 'cbt_failed', 'orderId' => $oid]));
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 콜백 요청에서 orderID·orderId·oid 순으로 주문번호를 꺼냅니다.
     *
     * @param  CbtCallbackRequest  $request  CBT 인증 콜백 요청
     * @return string 주문번호 (없으면 빈 문자열)
     */
    private function resolveOrderId(CbtCallbackRequest $request): string
    {
        return (string) (
            $request->input('orderID')
            ?: $request->input('orderId')
            ?: $request->input('oid')
            ?: ''
        );
    }

    /**
     * CBT 결과 코드가 성공(OK·00·0000)인지 판정합니다.
     *
     * @param  string  $resultCode  CBT 결과 코드
     * @return bool 성공 코드이면 true
     */
    private function isCbtSuccessCode(string $resultCode): bool
    {
        return in_array($resultCode, ['OK', '00', '0000'], true);
    }

    /**
     * 인증 결과가 사용자 취소인지 결과 코드와 메시지 패턴으로 판정합니다.
     *
     * @param  string  $resultCode  CBT 결과 코드
     * @param  string  $resultMsg  CBT 결과 메시지
     * @return bool 사용자 취소이면 true
     */
    private function isCbtAuthUserCancel(string $resultCode, string $resultMsg): bool
    {
        if (in_array($resultCode, self::CBT_USER_CANCEL_RESULT_CODES, true)) {
            return true;
        }

        foreach (self::CBT_USER_CANCEL_MESSAGE_PATTERNS as $pattern) {
            if ($resultMsg !== '' && str_contains($resultMsg, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 실패 결과 페이지로 넘길 쿼리 파라미터를 만듭니다. PayPay 처리 실패는 전용 오류로 바꿉니다.
     *
     * @param  string  $resultCode  CBT 결과 코드
     * @param  string  $resultMsg  CBT 결과 메시지
     * @param  string  $payMethod  결제수단
     * @param  string  $orderId  주문번호
     * @return array<string,string> 실패 리다이렉트 쿼리 파라미터
     */
    private function buildCbtFailureRedirectParams(
        string $resultCode,
        string $resultMsg,
        string $payMethod,
        string $orderId,
    ): array {
        if ($this->isPayPayProcessingFailure($resultCode, $resultMsg, $payMethod)) {
            return [
                'error' => 'paypay_processing_failed',
                'message' => self::CBT_PAYPAY_PROCESSING_FAILURE_MESSAGE,
                'orderId' => $orderId,
            ];
        }

        return [
            'error' => $resultCode,
            'message' => $resultMsg,
            'orderId' => $orderId,
        ];
    }

    /**
     * PayPay 결제의 상류 처리 실패(processing_failure)인지 판정합니다.
     *
     * @param  string  $resultCode  CBT 결과 코드
     * @param  string  $resultMsg  CBT 결과 메시지
     * @param  string  $payMethod  결제수단
     * @return bool PayPay 처리 실패이면 true
     */
    private function isPayPayProcessingFailure(string $resultCode, string $resultMsg, string $payMethod): bool
    {
        $normalizedPayMethod = strtoupper($payMethod);
        $normalizedResultCode = strtolower($resultCode);
        $normalizedResultMsg = strtolower($resultMsg);
        $isPayPay = str_contains($normalizedPayMethod, 'PAYPAY');

        return $isPayPay
            && (
                $normalizedResultCode === 'processing_failure'
                || str_contains($normalizedResultMsg, 'processing failed upstream')
            );
    }

    /**
     * 주문이 결제 전 상태이고 JPY 통화인지 확인하고, 아니면 예외를 던집니다.
     *
     * @param  Order  $order  결제 대상 주문
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 결제할 수 없는 주문이거나 JPY 주문이 아닐 때
     */
    private function assertPayableCbtOrder(Order $order): void
    {
        if (! $order->order_status->isBeforePayment()) {
            throw new \RuntimeException('Order is not payable.');
        }

        if ((string) $order->currency !== 'JPY') {
            throw new \RuntimeException('CBT payment is only available for JPY orders.');
        }
    }

    /**
     * CBT 승인액을 확정합니다.
     *
     * KG 이니시스 일본 CBT 승인 응답(/cbtapprove)에는 신뢰할 금액 필드가 없다.
     * 공식 매뉴얼(manual.inicis.com/jppay) 기준 승인 응답 파라미터는 결제수단별로
     *  - 신용카드: cardCode/approve/payType/installMonth (금액 없음)
     *  - 편의점(CVS): convenience/confNo/receiptNo/paymentTerm (금액 없음)
     *  - PayPay(BOKU): bokuApplPrice/bokuApplCurrency (모두 "일본결제 미사용" → 항상 공란)
     * 이며 amount/price 키 자체가 존재하지 않는다. 금액의 권위는 인증요청(cbtauth)
     * 시 가맹점이 보낸 금액(= 주문 결제예정액)과 NOTI(입금통보)의 amount 뿐이다.
     *
     * 따라서 승인 응답에 금액 필드가 채워져 오면 그 값으로 위변조를 검증하고,
     * 비어 있으면(일본 CBT 정상 동작) 주문 결제예정액(order_currency=JPY 환산액)을
     * 승인액으로 신뢰한다.
     *
     * @param  array<string,mixed>  $pgResponse  CBT 승인 응답
     * @param  Order  $order  결제 대상 주문
     * @return int 확정된 승인 금액 (결제 통화 단위)
     */
    private function resolveApprovedAmount(array $pgResponse, Order $order): int
    {
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액 (buildPgPaymentData 와 동일 기준).
        // CBT 는 order_currency=JPY 강제이나 base 통화는 다를 수 있어(예: base KRW, 결제 JPY)
        // total_due_amount(base) 직접 비교 시 PG 승인액(JPY)과 단위가 어긋난다.
        $pgAmount = $this->firstNonEmptyString($pgResponse, ['amount', 'price', 'bokuApplPrice', 'bokuLocalApplPrice']);
        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'cbt_approve', [
            'tid' => $pgResponse['tid'] ?? ($pgResponse['transactionId'] ?? null),
            'received_amount' => $pgAmount,
        ]);
        if ($expectedAmount === null) {
            throw new \RuntimeException('KG Inicis CBT payment currency is not chargeable.');
        }

        // 일본 CBT 승인 응답은 금액 필드가 비어 온다(매뉴얼상 미사용). 금액이 없으면
        // 주문 결제예정액을 승인액으로 신뢰한다(승인 권위 = resultCode OK + tid).
        if ($pgAmount === null) {
            return $expectedAmount;
        }

        // 라이브/타 결제수단에서 금액이 채워져 오면 위변조 검증을 유지한다.
        $approvedAmount = (int) $pgAmount;
        if ($approvedAmount !== $expectedAmount) {
            throw new \RuntimeException('KG Inicis CBT approved amount mismatch.');
        }

        return $approvedAmount;
    }

    /**
     * 승인 응답의 주문번호·MID·통화·결제수단이 주문과 요청에 맞는지 확인하고, 어긋나면 예외를 던집니다.
     *
     * @param  Order  $order  결제 대상 주문
     * @param  array<string,mixed>  $pgResponse  CBT 승인 응답
     * @param  CbtCallbackRequest  $request  CBT 인증 콜백 요청
     * @param  string  $approvedPayMethod  승인 응답의 결제수단
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 승인 응답 값이 주문과 어긋날 때
     */
    private function assertCbtApproveResponseMatchesOrder(
        Order $order,
        array $pgResponse,
        CbtCallbackRequest $request,
        string $approvedPayMethod,
    ): void {
        $receivedOrderId = $this->firstNonEmptyString($pgResponse, ['orderId', 'orderID', 'oid']);
        if ($receivedOrderId !== null && $receivedOrderId !== (string) $order->order_number) {
            throw new \RuntimeException('KG Inicis CBT approved order id mismatch.');
        }

        $receivedMid = $this->firstNonEmptyString($pgResponse, ['mid', 'MID']);
        $expectedMid = $this->apiService->getJapanMid();
        if ($receivedMid !== null && $receivedMid !== $expectedMid) {
            throw new \RuntimeException('KG Inicis CBT approved MID mismatch.');
        }

        $receivedCurrency = strtoupper((string) $this->firstNonEmptyString($pgResponse, ['currencyCd', 'currencyCode', 'currency']));
        if ($receivedCurrency !== '' && $receivedCurrency !== 'JPY') {
            throw new \RuntimeException('KG Inicis CBT approved currency mismatch.');
        }

        $expectedPayMethod = $this->resolveExpectedCbtPayMethod($request);
        $receivedPayMethod = $this->normalizeCbtPayMethod($approvedPayMethod);
        if ($expectedPayMethod !== null && $receivedPayMethod !== '' && $receivedPayMethod !== $expectedPayMethod) {
            throw new \RuntimeException('KG Inicis CBT approved paymethod mismatch.');
        }
    }

    /**
     * 지정한 키 순서대로 찾아 처음으로 비어 있지 않은 값을 trim 한 문자열로 돌려줍니다.
     *
     * @param  array<string,mixed>  $source  조회할 배열
     * @param  array<int,string>  $keys  우선순위 순 키 목록
     * @return string|null 처음 발견한 비어 있지 않은 값 (없으면 null)
     */
    private function firstNonEmptyString(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * 요청의 선택 결제수단(없으면 인증 paymethod)으로 승인 응답에서 기대할 CBT 결제수단을 정합니다.
     *
     * @param  CbtCallbackRequest  $request  CBT 인증 콜백 요청
     * @return string|null 기대 결제수단 (CARD·PAYPAY·CVS 등, 알 수 없으면 null)
     */
    private function resolveExpectedCbtPayMethod(CbtCallbackRequest $request): ?string
    {
        $selectedPaymentMethod = (string) $request->input('selectedPaymentMethod', '');
        $expectedBySelectedMethod = [
            'card' => 'CARD',
            'kginicis_japan_paypay' => 'PAYPAY',
            'kginicis_japan_cvs' => 'CVS',
        ];

        if ($selectedPaymentMethod !== '' && isset($expectedBySelectedMethod[$selectedPaymentMethod])) {
            return $expectedBySelectedMethod[$selectedPaymentMethod];
        }

        $authPayMethod = $this->normalizeCbtPayMethod((string) $request->input('paymethod', ''));

        return $authPayMethod !== '' ? $authPayMethod : null;
    }

    /**
     * 허용된 선택 결제수단을 돌려주고, 없으면 CBT 결제수단을 쇼핑몰 결제수단 키로 바꿉니다.
     *
     * @param  CbtCallbackRequest  $request  CBT 인증 콜백 요청
     * @param  string  $payMethod  CBT 결제수단
     * @return string|null 쇼핑몰 결제수단 키 (매핑할 수 없으면 null)
     */
    private function resolveSelectedCbtPaymentMethod(CbtCallbackRequest $request, string $payMethod): ?string
    {
        $selectedPaymentMethod = (string) $request->input('selectedPaymentMethod', '');
        $allowedSelectedMethods = [
            'card',
            'kginicis_japan_paypay',
            'kginicis_japan_cvs',
        ];

        if (in_array($selectedPaymentMethod, $allowedSelectedMethods, true)) {
            return $selectedPaymentMethod;
        }

        return match ($this->normalizeCbtPayMethod($payMethod)) {
            'CARD' => 'card',
            'PAYPAY' => 'kginicis_japan_paypay',
            'CVS' => 'kginicis_japan_cvs',
            default => null,
        };
    }

    /**
     * 선택 결제수단을 결제 메타에 넣을 배열로 만듭니다.
     *
     * @param  string|null  $selectedPaymentMethod  쇼핑몰 결제수단 키
     * @return array<string,string> selected_payment_method 메타 (값이 없으면 빈 배열)
     */
    private function buildCbtSelectionPaymentMeta(?string $selectedPaymentMethod): array
    {
        if ($selectedPaymentMethod === null || $selectedPaymentMethod === '') {
            return [];
        }

        return [
            'selected_payment_method' => $selectedPaymentMethod,
        ];
    }

    /**
     * CBT 결제수단 문자열의 공백을 없애고 대문자로 맞춥니다.
     *
     * @param  string  $payMethod  CBT 결제수단
     * @return string 정규화한 결제수단
     */
    private function normalizeCbtPayMethod(string $payMethod): string
    {
        return strtoupper(trim($payMethod));
    }

    /**
     * 할부 개월 값을 정수로 바꿉니다. 비어 있으면 null 입니다.
     *
     * @param  mixed  $value  승인 응답의 할부 개월 값
     * @return int|null 할부 개월 수 (값이 없으면 null)
     */
    private function normalizeInstallmentMonths(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * 편의점(CVS) 결제번호 발급 결과를 주문 결제에 입금 대기 상태로 기록합니다.
     *
     * @param  Order  $order  결제 대상 주문
     * @param  array<string,mixed>  $pgResponse  CBT 승인 응답
     * @param  string  $tid  PG 거래번호
     * @param  string  $sid  CBT 세션 ID
     * @param  int  $amount  결제 금액 (결제 통화 단위)
     * @param  array<string,mixed>  $authResponse  정제한 인증 응답
     * @param  array<string,mixed>  $approveResponse  정제한 승인 응답
     * @param  string|null  $selectedPaymentMethod  쇼핑몰 결제수단 키
     * @return void 반환값 없음
     */
    private function handleCbtCvsIssued(
        Order $order,
        array $pgResponse,
        string $tid,
        string $sid,
        int $amount,
        array $authResponse,
        array $approveResponse,
        ?string $selectedPaymentMethod,
    ): void {
        $paymentTerm = (string) ($pgResponse['paymentTerm'] ?? '');
        $dueAt = null;

        if (preg_match('/^\d{14}$/', $paymentTerm) === 1) {
            try {
                $dueAt = Carbon::createFromFormat('YmdHis', $paymentTerm, 'Asia/Tokyo')
                    ->setTimezone(config('app.timezone', 'UTC'));
            } catch (\Throwable) {
                $dueAt = null;
            }
        }

        $order->payment()->update(array_filter([
            'pg_provider' => 'kginicis',
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => $tid,
            'vbank_code' => $pgResponse['convenience'] ?? null,
            'vbank_name' => 'CVS',
            'vbank_number' => $pgResponse['confNo'] ?? ($pgResponse['receiptNo'] ?? null),
            'vbank_due_at' => $dueAt,
            'vbank_issued_at' => now(),
            'payment_meta' => array_merge([
                'result_code' => $pgResponse['resultCode'] ?? 'OK',
                'pay_method' => 'CVS',
                'cbt_type' => 'JPPG',
                'cbt_mid' => $this->apiService->getJapanMid(),
                'cbt_sid' => $sid,
                'mid' => $this->apiService->getJapanMid(),
                'currency' => 'JPY',
                'is_cbt' => true,
                'is_test_mode' => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_auth_response' => $authResponse,
                'pg_approve_response' => $approveResponse,
                'pg_raw_response' => $approveResponse,
                'cvs_status' => 'waiting_deposit',
                'cvs_amount' => $amount,
                'cvs_convenience' => $pgResponse['convenience'] ?? null,
                'cvs_conf_no' => $pgResponse['confNo'] ?? null,
                'cvs_receipt_no' => $pgResponse['receiptNo'] ?? null,
                'cvs_payment_term' => $paymentTerm !== '' ? $paymentTerm : null,
            ], $this->buildCbtSelectionPaymentMeta($selectedPaymentMethod)),
        ], fn ($value) => $value !== null));
    }

    /**
     * 로컬 처리에 실패한 CBT 승인 건을 환불하고 대사 상태를 기록합니다. 환불이 실패하면 수동 환불 필요로 남깁니다.
     *
     * @param  string|null  $tid  PG 거래번호 (없으면 아무것도 하지 않음)
     * @param  string  $oid  주문번호
     * @param  int  $amount  승인 금액 (결제 통화 단위)
     * @param  string  $reason  로컬 처리 실패 사유
     * @return void 반환값 없음
     */
    private function refundApprovedCbtPaymentOrFlagManualReconciliation(
        ?string $tid,
        string $oid,
        int $amount,
        string $reason,
    ): void {
        if ($tid === null || $tid === '') {
            return;
        }

        try {
            $refundResult = $this->apiService->refundCbtPayment(
                $tid,
                null,
                'CBT approved but local payment completion failed: '.mb_substr($reason, 0, 80),
            );

            $this->recordCbtReconciliationStatus($oid, [
                'status' => CbtReconciliationService::STATUS_AUTO_REFUNDED,
                'manual_action_required' => false,
                'tid' => $tid,
                'amount' => $amount,
                'reason' => $reason,
                'refund_error' => null,
                'refund_result' => $this->sanitizePgResponse($refundResult, self::CBT_REFUND_RESPONSE_KEYS),
                'is_test_mode' => $this->apiService->isTestMode(),
                'cbt_mid' => $this->apiService->getJapanMid(),
                'retry_count' => 0,
            ]);

            Log::warning('KG Inicis CBT: approved payment auto-refunded after local failure', [
                'tid' => $tid,
                'oid' => $oid,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return;
        } catch (\Throwable $refundException) {
            Log::error('KG Inicis CBT: auto refund after local failure failed', [
                'tid' => $tid,
                'oid' => $oid,
                'amount' => $amount,
                'reason' => $reason,
                'refund_error' => $refundException->getMessage(),
            ]);

            $this->recordCbtReconciliationStatus($oid, [
                'status' => CbtReconciliationService::STATUS_MANUAL_REFUND_REQUIRED,
                'manual_action_required' => true,
                'tid' => $tid,
                'amount' => $amount,
                'reason' => $reason,
                'refund_error' => $refundException->getMessage(),
                'refund_result' => null,
                'is_test_mode' => $this->apiService->isTestMode(),
                'cbt_mid' => $this->apiService->getJapanMid(),
                'retry_count' => 0,
            ]);
        }

        Log::error('KG Inicis CBT: post-approve failure — MANUAL CANCEL REQUIRED on KG Inicis JP merchant admin', [
            'tid' => $tid,
            'oid' => $oid,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    /**
     * 주문의 CBT 대사 상태를 대사 서비스에 기록합니다.
     *
     * @param  string  $oid  주문번호
     * @param  array<string,mixed>  $attributes  기록할 대사 속성
     * @return void 반환값 없음
     */
    private function recordCbtReconciliationStatus(string $oid, array $attributes): void
    {
        $this->reconciliationService->record($oid, $attributes);
    }

    /**
     * PG 응답에서 허용 키만 남기고, 스칼라가 아닌 값은 JSON 문자열로 바꿉니다.
     *
     * @param  array<string,mixed>  $response  PG 응답
     * @param  array<int,string>  $allowedKeys  남길 키 목록
     * @return array<string,mixed> 정제한 응답
     */
    private function sanitizePgResponse(array $response, array $allowedKeys): array
    {
        $allowed = array_flip($allowedKeys);
        $sanitized = [];

        foreach ($response as $key => $value) {
            if (! isset($allowed[$key])) {
                continue;
            }

            $sanitized[$key] = is_scalar($value) || $value === null
                ? $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $sanitized;
    }

    /**
     * 플러그인 설정의 성공 URL 템플릿에 주문번호를 넣어 절대 URL 로 만듭니다.
     *
     * @param  string  $orderId  주문번호
     * @return string 결제 성공 페이지 절대 URL
     */
    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? ShopRedirectUrl::DEFAULT_SUCCESS_URL;

        return SiteUrl::absolute(ShopRedirectUrl::resolve($urlTemplate, ['{orderId}' => $orderId]));
    }

    /**
     * 플러그인 설정의 실패 URL 을 절대 URL 로 만들고, 비어 있지 않은 쿼리 파라미터를 붙입니다.
     *
     * @param  array<string,mixed>  $queryParams  실패 페이지에 붙일 쿼리 파라미터
     * @return string 결제 실패 페이지 절대 URL
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
