<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\DepositAfterCancelRecorder;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\BindsApprovalToOrder;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileVbankNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\ShopRedirectUrl;

/**
 * KG 이니시스 결제 콜백 컨트롤러
 *
 * KG 이니시스 표준결제창은 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 authToken + authUrl 전달 → authCallback()
 *  2단계: 서버가 authUrl로 최종 승인 요청 → KgInicisApiService::authorizePayment()
 */
class PaymentCallbackController
{
    use BindsApprovalToOrder;
    use HandlesClaimPaymentCallbacks;
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use ResolvesEasyPaySelection;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;
    use ValidatesCbtOrderContext;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';

    /**
     * 사용자가 결제창을 X / '취소' 로 종료했을 때 KG 이니시스가 전달하는 resultCode 목록.
     *
     * 사용자 취소는 결제 실패가 아니라 의도된 중단이므로 에러 query 없이 체크아웃으로
     * 조용히 복귀시킨다 (NHN KCP 의 CANCEL_RES_CODES 패턴과 동일).
     */
    private const CANCEL_RES_CODES = ['2001', '0021', '0022', ''];

    private const PC_AUTH_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'payMethod',
        'applNum',
        'applDate',
        'applTime',
        'TotPrice',
        'totPrice',
        'MOID',
        'moid',
        'mid',
        'MID',
        'cardCode',
        'cardName',
        'cardQuota',
        'cardInterest',
        'goodName',
        'goodsName',
        'currency',
        'currencyCode',
    ];

    private const PC_VBANK_ISSUE_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'tid',
        'payMethod',
        'applDate',
        'applTime',
        'MOID',
        'moid',
        'mid',
        'MID',
        'TotPrice',
        'totPrice',
        'VACT_BankCode',
        'VACT_BankName',
        'vactBankName',
        'VACT_Date',
        'VACT_Time',
        'VACT_Status',
        'goodName',
        'goodsName',
    ];

    private const PC_VBANK_NOTIFY_RESPONSE_KEYS = [
        'no_tid',
        'no_oid',
        'id_merchant',
        'dt_trans',
        'tm_trans',
        'cd_bank',
        'amt_input',
        'nm_inputbank',
    ];

    private const MOBILE_VBANK_NOTIFY_RESPONSE_KEYS = [
        'P_STATUS',
        'P_TYPE',
        'P_TID',
        'P_OID',
        'P_AMT',
        'P_AUTH_DT',
        'P_FN_CD1',
        'P_FN_NM',
    ];

    /**
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 조회 서비스
     * @param  KgInicisApiService  $apiService  KG 이니시스 API 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
    ) {}

    /**
     * KG 이니시스 표준결제 인증 콜백을 받아 서버 승인 후 결과 페이지로 리다이렉트합니다.
     *
     * @param  AuthCallbackRequest  $request  결제 인증 콜백 요청
     * @return RedirectResponse 성공/실패 결과 페이지 리다이렉트
     */
    public function authCallback(AuthCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $selectedEasyPayMethod = $this->resolveSelectedEasyPayMethod($request);

        $resultCode = $validated['resultCode'];

        // 주문번호: 구버전 MOID, 신버전 orderNumber 모두 지원
        $moid = $validated['MOID'] ?? $validated['orderNumber'] ?? null;

        // 콜백의 결제금액(TotPrice)은 브라우저를 거친 값이라 쓰지 않는다 — 승인액은 서버 승인 응답의
        // TotPrice 가 권위다(아래 승인 뒤 대조).

        Log::info('KG Inicis: callback received', [
            'moid' => $moid,
            'result_code' => $resultCode,
            'idc_name' => $validated['idc_name'] ?? null,
            'auth_url' => $validated['authUrl'] ?? null,
            'all_fields' => array_keys($request->all()),
            'easy_pay' => $this->buildEasyPayLogContext($selectedEasyPayMethod),
        ]);

        if (! $moid) {
            Log::error('KG Inicis: order number missing from callback', ['input' => array_keys($request->all())]);

            return redirect($this->resolveFailUrl(['error' => 'invalid_params']));
        }

        // 결제 실패/취소인 경우: authToken/authUrl 없이 올 수 있으므로 먼저 처리
        if ($resultCode !== '0000') {
            $resultMsg = (string) ($validated['resultMsg'] ?? '');

            // 사용자 취소는 결제 실패가 아니라 의도된 중단 — 에러 query 없이 조용히 복귀.
            //  1) resultCode 가 KG 이니시스 표준 cancel 코드 목록에 포함되거나
            //  2) resultMsg 에 '취소' 또는 '사용자' 키워드가 포함된 경우 (버전별 코드 차이 대응)
            $isUserCancel = in_array($resultCode, self::CANCEL_RES_CODES, true)
                || str_contains($resultMsg, '취소')
                || str_contains($resultMsg, '사용자');

            Log::info('KG Inicis: auth result non-success', [
                'moid' => $moid,
                'result_code' => $resultCode,
                'result_msg' => $resultMsg,
                'is_user_cancel' => $isUserCancel,
            ]);

            // 클레임 추가결제(CP 채번)는 체크아웃이 아니라 신청서 상세로 돌려보낸다.
            if (ClaimPayment::isClaimPaymentNumber($moid)) {
                $claimRedirect = $this->redirectClaimWindowFailure($moid, $isUserCancel ? null : $resultCode);

                if ($claimRedirect !== null) {
                    return $claimRedirect;
                }
            }

            if ($isUserCancel) {
                // 에러 query 미부착 → 체크아웃 페이지가 toast/error 분기를 타지 않음
                return redirect($this->resolveFailUrl());
            }

            return redirect($this->resolveFailUrl([
                'error' => $resultCode,
                // 결제 건을 못 찾은 CP 콜백이 여기까지 떨어질 수 있다. PG 원문은 상점 MID·내부 상태를
                // 담고 이 경로는 비인증 브라우저 리다이렉트이므로, CP 번호일 때는 코드만 싣는다.
                'message' => ClaimPayment::isClaimPaymentNumber($moid) ? null : $resultMsg,
                'orderId' => $moid,
            ]));
        }

        // 결제 성공(0000) 이후: authToken, authUrl, idc_name 필수
        $authToken = $validated['authToken'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        // authUrl 또는 checkAckUrl (버전에 따라 다름)
        $receivedAuthUrl = $validated['authUrl'] ?? $validated['checkAckUrl'] ?? null;
        $receivedNetCancelUrl = $validated['netCancelUrl'] ?? null;

        if (! $authToken || ! $idcName || ! $receivedAuthUrl) {
            Log::error('KG Inicis: missing required fields on success callback', [
                'moid' => $moid,
                'idc_name' => $idcName,
                'auth_url' => $receivedAuthUrl,
                'has_token' => (bool) $authToken,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // idc_name + authUrl 화이트리스트 검증 (PC/모바일 URL 모두 허용, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $receivedAuthUrl)) {
            Log::error('KG Inicis: authUrl not in whitelist (possible SSRF attempt)', [
                'moid' => $moid,
                'idc_name' => $idcName,
                'received' => $receivedAuthUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'auth_url_invalid', 'orderId' => $moid]));
        }

        // 화이트리스트 검증 통과 → 수신된 URL을 그대로 사용 (PC/모바일 자동 대응)
        $authUrl = $receivedAuthUrl;
        $netCancelUrl = $this->apiService->resolveIdcNetCancelUrl($idcName);
        $tid = '';
        $callbackLock = null;

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        // 승인은 모듈 위임(필터 발화 → 이 플러그인의 승인 리스너)이고, 승인 금액 불일치는 KG
        // 승인이 확정된 뒤의 거절이므로 망취소로 보상한다.
        if (ClaimPayment::isClaimPaymentNumber($moid)) {
            $payment = $this->findClaimPayment($moid);

            if (! $payment) {
                Log::error('KG Inicis: claim payment not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'claim_payment_not_found', 'orderId' => $moid]));
            }

            // 주문 경로와 같은 직렬화 락을 건다 — 같은 콜백이 겹쳐 들어오는 것을 PG 의 중복
            // 승인 거절에만 맡기면 방어 층이 주문 경로와 달라진다. 키는 결제번호(CP)다.
            // 락 대기 초과는 500 이 아니라 신청서 상세 복귀로 끝난다(`approveClaimPaymentSerialized`).
            // 승인 뒤 거절의 보상은 보상 클로저 대신 모듈의 `claim_payment.void_approval` 필터(이
            // 플러그인 승인 리스너의 `voidApproval()`)가 맡는다 — API 승인 경로와 같은 한 구현이다.
            return $this->approveClaimPaymentSerialized('authCallback', $payment, $validated);
        }

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: order not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            $callbackLock = $this->acquireOrderCallbackLock('authCallback', $moid);

            HookManager::doAction('sirsoft-pay_kginicis.payment.before_authorize', $order, $validated);

            $pgResponse = $this->apiService->authorizePayment($authUrl, $authToken);

            HookManager::doAction('sirsoft-pay_kginicis.payment.after_authorize', $order, $pgResponse);

            $pgResultCode = $pgResponse['resultCode'] ?? '';

            if ($pgResultCode !== '0000') {
                Log::warning('KG Inicis: authorize failed', [
                    'moid' => $moid,
                    'result_code' => $pgResultCode,
                    'result_msg' => $pgResponse['resultMsg'] ?? '',
                ]);

                // 승인이 성립하지 않았다 — 실제로 결제된 것이 없으므로 주문 상태를 바꾸지 않는다.
                // 이 엔드포인트는 PG 서명도 IP 증명도 없는 비인증 브라우저 POST 이고 주문번호(moid)도
                // 요청자가 고른 값이라, "승인 실패" 는 남의 주문번호와 위조 authToken 을 보내기만 해도
                // 만들어낼 수 있는 상태다. 그것을 근거로 실패 처리하면 타인의 결제대기 주문이 취소된다.
                // 정당한 결제창 닫힘은 소유권을 검증하는 close-report 가 기록한다.
                return redirect($this->resolveFailUrl([
                    'error' => $pgResultCode,
                    'message' => $pgResponse['resultMsg'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid = (string) ($pgResponse['tid'] ?? '');

            // 승인 응답이 돌려준 주문번호가 이 콜백의 주문과 같아야 반영한다.
            // authToken 은 주문번호를 묶지 않으므로, 싼 주문의 승인 토큰에 비싼 주문번호와 그 금액을 붙여
            // 보내면 비싼 주문이 싼 금액으로 결제완료될 수 있었다. 금액은 PG 가 승인한 값만 기준이다 — 콜백
            // TotPrice 는 브라우저를 거친 값이라 대조에 쓰면 대조가 항등식이 된다(아래 카드 경로와 같은 규칙).
            $pgOrderNumber = $pgResponse['MOID'] ?? $pgResponse['moid'] ?? null;
            $pgTotPrice = $pgResponse['TotPrice'] ?? $pgResponse['totPrice'] ?? null;
            $pgApprovedAmount = is_numeric($pgTotPrice) ? (int) $pgTotPrice : null;
            if (! $this->approvalOrderNumberMatches(is_scalar($pgOrderNumber) ? (string) $pgOrderNumber : null, (string) $moid)) {
                Log::warning('KG Inicis: authorize response does not match callback order; net cancelling', [
                    'moid' => $moid,
                    'pg_moid' => $pgOrderNumber,
                    'pg_price' => $pgTotPrice,
                    'tid' => $tid,
                ]);

                $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                return redirect($this->resolveFailUrl(['error' => 'authorize_failed', 'orderId' => $moid]));
            }

            // 가상계좌: completePayment 없이 계좌 정보만 저장 (입금 통보 시 completePayment)
            if (($pgResponse['payMethod'] ?? '') === 'VBank') {
                // 결제대기 주문에만 발급을 기록한다 — 이미 결제됐거나 다른 가상계좌가 붙은 결제 레코드를
                // 덮어쓰면 환불 대상 거래번호가 오염된다. 같은 발급의 중복 콜백은 성공으로만 처리한다.
                // 승인 응답에 금액이 없거나 청구액과 다르면 발급을 기록하지 않는다.
                $issueDecision = $this->vbankIssueDecision($order, $pgApprovedAmount, $tid);
                if ($issueDecision === 'reject') {
                    Log::warning('KG Inicis: vbank issue rejected (order not payable or amount mismatch); net cancelling', [
                        'moid' => $moid,
                        'tid' => $tid,
                        'order_status' => $order->order_status->value,
                        'pg_price' => $pgTotPrice,
                    ]);

                    $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                    return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
                }

                if ($issueDecision === 'issue') {
                    $this->handleVbankIssued($order, $pgResponse, $tid, $request);
                } else {
                    $this->logReplayDetected($tid, $moid, 'PC authCallback (vbank issue)');
                }
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            // Replay 가드: 동일 tid 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'PC authCallback (card)');
                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            // 결제 전 주문에만 승인을 반영한다 (모바일 콜백·가상계좌 발급과 같은 판정).
            //
            // 같은 거래번호의 재전송은 바로 위에서 성공으로 돌려보냈으므로, 여기까지 온 승인은 이 주문에
            // 처음 도착한 새 승인이다. 이미 결제완료됐거나 취소된 주문이면 결제완료 처리가 조용히 건너뛰어져
            // 승인된 돈이 PG 에 남고, 결제 레코드의 PG 사 표기만 바뀐다. 주문은 건드리지 않고 망취소로 돌려준다.
            if (! $order->order_status->isBeforePayment()) {
                Log::warning('KG Inicis: approval arrived for non-payable order; net cancelling', [
                    'moid' => $moid,
                    'tid' => $tid,
                    'order_status' => $order->order_status->value,
                ]);

                $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
            }

            // 승인액은 **PG 승인 응답**의 TotPrice 만 쓴다. 콜백 TotPrice 를 먼저 쓰면 결제창 금액을 바꾼
            // 요청이 청구액을 적어 보내는 것만으로 대조를 통과한다(대조가 항등식이 된다). 응답에 금액이
            // 없으면 0 이 되어 아래 대조에서 망취소 + 실패로 끝난다 (#599 validate P2, 클레임 경로와 같은 규칙).
            $totPrice = is_numeric($pgResponse['TotPrice'] ?? $pgResponse['totPrice'] ?? null)
                ? (int) ($pgResponse['TotPrice'] ?? $pgResponse['totPrice'])
                : 0;

            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'auth_callback', [
                'tid' => $tid,
                'received_amount' => $totPrice,
            ]);
            if ($expectedAmount === null) {
                $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                return redirect($this->resolveFailUrl(['error' => 'invalid_payment_currency', 'orderId' => $moid]));
            }
            // 청구액이 0 이어도 대조를 건너뛰지 않는다 — PG 결제가 필요 없는 주문에 승인이 들어온 것 자체가 불일치다.
            if ($totPrice !== $expectedAmount) {
                $this->apiService->sendNetCancel($netCancelUrl, $authToken);

                return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));
            }

            $embeddedPgProvider = $this->resolveEmbeddedPgProvider($selectedEasyPayMethod);

            Log::info('KG Inicis: completing card payment', [
                'moid' => $moid,
                'tid' => $tid,
                'pg_pay_method' => $pgResponse['payMethod'] ?? null,
                'easy_pay' => $this->buildEasyPayLogContext($selectedEasyPayMethod),
            ]);

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['applNum'] ?? null,
                'card_number_masked' => $pgResponse['cardNum'] ?? null,
                'card_name' => $pgResponse['cardName'] ?? null,
                'card_installment_months' => (int) ($pgResponse['cardQuota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => $embeddedPgProvider,
                'receipt_url' => null,
                'payment_meta' => array_merge([
                    'result_code' => $pgResultCode,
                    'pay_method' => $pgResponse['payMethod'] ?? null,
                    'auth_date' => $pgResponse['applDate'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::PC_AUTH_RESPONSE_KEYS),
                ], $this->buildEasyPayPaymentMeta($selectedEasyPayMethod)),
                'payment_device' => DeviceDetector::detect($request),
            ], $totPrice);

            // 에스크로를 켠 상점의 결제는 에스크로 MID 로 승인된다 — [구매결정]·에스크로 배송 등록·환불이 이 표시로 판정한다 (S4-D18)
            // PC 결제창은 간편결제에도 acceptmethod 의 useescrow 를 그대로 두고 에스크로 MID 로 결제한다 —
            // 간편결제도 에스크로로 표시해야 이후 조회·취소가 같은 MID 자격증명을 쓴다(S4-D21).
            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $order->payment()->update(['pg_provider' => 'kginicis', 'is_escrow' => $this->apiService->isEscrowEnabled()]);
            $this->queueReceiptCookie($moid);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis: amount mismatch', [
                'moid' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('KG Inicis: authorize exception', [
                'moid' => $moid,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            if ($this->wasAlreadyPaid($tid)) {
                Log::warning('KG Inicis: local payment already completed, net cancel skipped after exception', [
                    'moid' => $moid,
                    'tid' => $tid,
                    'error' => $e->getMessage(),
                ]);

                $this->queueReceiptCookie($moid);

                return redirect($this->resolveSuccessUrl($moid));
            }

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'message' => __('sirsoft-pay_kginicis::messages.errors.payment_failed'),
                'orderId' => $moid,
            ]));
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * PC 가상계좌 입금통보를 받아 입금대기 주문을 결제완료로 전환합니다.
     *
     * @param  VbankNotifyRequest  $request  가상계좌 입금통보 요청
     * @return Response KG 이니시스에 반환할 통보 처리 결과 응답
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid = (string) $validated['no_tid'];
        $moid = (string) $validated['no_oid'];
        $amt = (int) $validated['amt_input'];

        Log::info('KG Inicis: PC vbank deposit notify received', [
            'tid' => $tid,
            'moid' => $moid,
            'amt' => $amt,
            'bank' => $validated['nm_inputbank'] ?? null,
        ]);

        $callbackLock = null;

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: PC vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $callbackLock = $this->acquireOrderCallbackLock('vbankNotify', $moid);

            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'PC vbankNotify');

                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            $notifyContext = [
                'tid' => $tid,
                'mid' => (string) ($validated['id_merchant'] ?? ''),
                'amount' => $amt,
                'account' => (string) ($validated['no_vacct'] ?? ''),
                'source' => 'PC vbankNotify',
            ];

            // 취소된 주문의 입금(P-D1 · Q11) — 주문은 되살리지 않고 기록·운영자 알림만 남긴 뒤 OK 로 재통보를 멈춘다.
            if ($this->recordDepositOnCancelledOrder($order, $notifyContext)) {
                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            if (! $this->validateVbankNotifyContext($order, $notifyContext)) {
                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => [
                    'vbank_num' => $validated['no_vacct'] ?? null,
                    'vbank_name' => $validated['nm_inputbank'] ?? null,
                    'depositor_name' => $validated['nm_input'] ?? null,
                    'deposit_date' => ($validated['dt_trans'] ?? '').($validated['tm_trans'] ?? ''),
                    'bank_code' => $validated['cd_bank'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($validated, self::PC_VBANK_NOTIFY_RESPONSE_KEYS),
                ],
            ], $amt);

            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $order->payment()->update(['pg_provider' => 'kginicis']);

            Log::info('KG Inicis: PC vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $amt]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: PC vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 모바일 가상계좌 입금통보를 받아 입금대기 주문을 결제완료로 전환합니다.
     *
     * @param  MobileVbankNotifyRequest  $request  모바일 가상계좌 입금통보 요청
     * @return Response KG 이니시스에 반환할 통보 처리 결과 응답
     */
    public function mobileVbankNotify(MobileVbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $pStatus = (string) $validated['P_STATUS'];
        $pType = (string) $validated['P_TYPE'];
        $tid = (string) $validated['P_TID'];
        $moid = (string) $validated['P_OID'];
        $amt = (int) $validated['P_AMT'];

        // P_STATUS == "02" (입금통보) + P_TYPE == "VBANK" 만 처리
        if ($pStatus !== '02' || $pType !== 'VBANK') {
            Log::info('KG Inicis: mobile vbank notify - not a deposit, ignored', [
                'tid' => $tid,
                'P_STATUS' => $pStatus,
                'P_TYPE' => $pType,
            ]);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        Log::info('KG Inicis: mobile vbank deposit notify received', [
            'tid' => $tid,
            'moid' => $moid,
            'amt' => $amt,
            'bank' => $validated['P_FN_NM'] ?? null,
        ]);

        $callbackLock = null;

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: mobile vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $callbackLock = $this->acquireOrderCallbackLock('mobileVbankNotify', $moid);

            if ($this->wasAlreadyPaid($tid)) {
                $this->logReplayDetected($tid, $moid, 'mobile vbankNotify');

                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            $notifyContext = [
                'tid' => $tid,
                'mid' => (string) ($validated['P_MID'] ?? ''),
                'amount' => $amt,
                'account' => $this->extractMobileVbankAccount((string) ($validated['P_RMESG1'] ?? '')),
                'source' => 'mobile vbankNotify',
            ];

            // 취소된 주문의 입금(P-D1 · Q11) — 주문은 되살리지 않고 기록·운영자 알림만 남긴 뒤 OK 로 재통보를 멈춘다.
            if ($this->recordDepositOnCancelledOrder($order, $notifyContext)) {
                return response('OK', 200)->header('Content-Type', 'text/plain');
            }

            if (! $this->validateVbankNotifyContext($order, $notifyContext)) {
                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => [
                    'vbank_name' => $validated['P_FN_NM'] ?? null,
                    'depositor_name' => $validated['P_UNAME'] ?? null,
                    'deposit_date' => $validated['P_AUTH_DT'] ?? null,
                    'bank_code' => $validated['P_FN_CD1'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($validated, self::MOBILE_VBANK_NOTIFY_RESPONSE_KEYS),
                ],
                'payment_device' => 'mobile',
            ], $amt);

            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $order->payment()->update(['pg_provider' => 'kginicis']);

            Log::info('KG Inicis: mobile vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $amt]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: mobile vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 취소된 주문의 입금통보를 기록합니다. (P-D1 · Q11)
     *
     * 발급 때 받은 거래번호·상점 아이디와 맞는 통보만 기록한다 — 통보 위조로 운영자 알림이 남발되지 않게 한다.
     * 주문을 되살리지 않고 자동 환불도 하지 않는다. 기록·알림은 같은 거래면 한 번이다.
     *
     * @param  Order  $order  주문
     * @param  array{tid: string, mid: string, amount: int, account: string, source: string}  $notify  통보 값
     * @return bool 취소 주문의 입금으로 기록했으면 true (그 밖은 false — 종전 검증으로 넘어간다)
     */
    private function recordDepositOnCancelledOrder(Order $order, array $notify): bool
    {
        if ($this->enumValue($order->order_status) !== OrderStatusEnum::CANCELLED->value) {
            return false;
        }

        $payment = $order->payment()->first();
        if (! $payment || $this->enumValue($payment->payment_method) !== PaymentMethodEnum::VBANK->value) {
            return false;
        }

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $expectedTid = trim((string) $payment->transaction_id);
        $expectedMid = trim((string) ($meta['mid'] ?? $this->apiService->getMid()));

        if ($expectedTid === '' || ! hash_equals($expectedTid, $notify['tid'])
            || ($expectedMid !== '' && ! hash_equals($expectedMid, $notify['mid']))) {
            return false;
        }

        app(DepositAfterCancelRecorder::class)->record($order, (float) $notify['amount'], 'kginicis', $notify['tid']);

        return true;
    }

    /**
     * 입금통보가 실제로 이 주문에서 발급한 가상계좌 결제 건인지 확인한다.
     *
     * KG 이니시스 PC 노티는 별도 서명 필드를 제공하지 않으므로, IP 화이트리스트에 더해
     * 결제행에 저장된 발급 시점 TID/MID/계좌/금액을 대조해 위조 통보 표면을 줄인다.
     *
     * @param  Order  $order  대상 주문
     * @param  array{tid:string,mid:string,amount:int,account:string,source:string}  $notify  입금통보 값
     * @return bool 이 주문의 가상계좌 결제 건이면 true
     */
    private function validateVbankNotifyContext(Order $order, array $notify): bool
    {
        $payment = $order->payment()->first();
        $meta = is_array($payment?->payment_meta) ? $payment->payment_meta : [];
        $source = $notify['source'];

        if (! $payment) {
            Log::warning('KG Inicis: vbank notify rejected - payment missing', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
            ]);

            return false;
        }

        // 취소된 주문의 입금통보는 받지 않는다 (코어 P4 와 같은 가드).
        //
        // 고객이 취소한 뒤에 입금해도 PG 통보는 도착한다. 통과시키면 `CANCELLED` 주문이
        // 결제완료로 되살아나 재고·마일리지가 재차감된다. 아래 결제상태 가드만으로는
        // 부족하다 — 미입금 취소가 결제행을 `waiting_deposit` 그대로 두던 시절의 행이
        // 그 가드를 통과하기 때문이다. 입금된 돈은 환불 절차로 돌려주어야 하는 별개 사건이다.
        if ($this->enumValue($order->order_status) === OrderStatusEnum::CANCELLED->value) {
            Log::warning('KG Inicis: vbank notify rejected - order is cancelled', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
            ]);

            return false;
        }

        $paymentStatus = $this->enumValue($payment->payment_status);
        if ($paymentStatus !== PaymentStatusEnum::WAITING_DEPOSIT->value) {
            Log::warning('KG Inicis: vbank notify rejected - payment is not waiting deposit', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'payment_status' => $paymentStatus,
            ]);

            return false;
        }

        $paymentMethod = $this->enumValue($payment->payment_method);
        if ($paymentMethod !== PaymentMethodEnum::VBANK->value) {
            Log::warning('KG Inicis: vbank notify rejected - payment method mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'payment_method' => $paymentMethod,
            ]);

            return false;
        }

        $expectedTid = trim((string) $payment->transaction_id);
        if ($expectedTid === '' || ! hash_equals($expectedTid, $notify['tid'])) {
            Log::warning('KG Inicis: vbank notify rejected - tid mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'received_tid' => $notify['tid'],
                'expected_tid' => $expectedTid,
            ]);

            return false;
        }

        $expectedMid = trim((string) ($meta['mid'] ?? $this->apiService->getMid()));
        if ($expectedMid !== '' && ! hash_equals($expectedMid, $notify['mid'])) {
            Log::warning('KG Inicis: vbank notify rejected - mid mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'received_mid' => $notify['mid'],
                'expected_mid' => $expectedMid,
            ]);

            return false;
        }

        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액 (buildPgPaymentData 와 동일 기준).
        // base≠결제 통화에서 PG 통보 금액(환산 통화)과 단위가 일치하도록 한다.
        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'vbank_notify', [
            'source' => $source,
            'tid' => $notify['tid'],
            'received_amount' => $notify['amount'],
        ]);
        if ($expectedAmount === null) {
            return false;
        }

        // 청구액이 0 이어도 대조를 건너뛰지 않는다 — 승인 콜백·가상계좌 발급과 같은 규칙.
        if ($notify['amount'] !== $expectedAmount) {
            Log::warning('KG Inicis: vbank notify rejected - amount mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'received_amount' => $notify['amount'],
                'expected_amount' => $expectedAmount,
            ]);

            return false;
        }

        $expectedAccount = preg_replace('/\D+/', '', (string) $payment->vbank_number);
        $receivedAccount = preg_replace('/\D+/', '', $notify['account']);
        if ($expectedAccount !== '' && $receivedAccount !== '' && ! hash_equals($expectedAccount, $receivedAccount)) {
            Log::warning('KG Inicis: vbank notify rejected - account mismatch', [
                'source' => $source,
                'moid' => $order->order_number,
                'tid' => $notify['tid'],
                'received_account_suffix' => substr($receivedAccount, -4),
                'expected_account_suffix' => substr($expectedAccount, -4),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 모바일 입금통보 메시지에서 가상계좌 번호를 꺼냅니다. (`|` 구분 첫 칸)
     *
     * @param  string  $message  PG 결과 메시지
     * @return string 가상계좌 번호
     */
    private function extractMobileVbankAccount(string $message): string
    {
        $parts = explode('|', $message);

        return trim((string) ($parts[0] ?? ''));
    }

    /**
     * 열거형이면 그 값을, 아니면 문자열로 바꿔 돌려줍니다.
     *
     * @param  mixed  $value  열거형 또는 스칼라 값
     * @return string 문자열 값
     */
    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    /**
     * 결제 성공 리다이렉트 URL 을 만듭니다.
     *
     * @param  string  $orderId  주문번호
     * @return string 절대 URL
     */
    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? ShopRedirectUrl::DEFAULT_SUCCESS_URL;

        return SiteUrl::absolute(ShopRedirectUrl::resolve($urlTemplate, ['{orderId}' => $orderId]));
    }

    /**
     * 결제 실패 리다이렉트 URL 을 만듭니다.
     *
     * @param  array  $queryParams  리다이렉트에 붙일 쿼리 파라미터
     * @return string 절대 URL
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

    /**
     * 가상계좌 발급 처리 (completePayment 없이 계좌 정보만 저장)
     *
     * KG 이니시스 authCallback 에서 payMethod=VBank 로 판별된 경우 호출.
     * 실제 결제 완료(completePayment)는 입금 통보(vbankNotify) 시점에 처리.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $pgResponse  PG 승인 응답
     * @param  string  $tid  PG 거래번호
     * @param  Request  $request  HTTP 요청
     * @return void 반환값 없음
     */
    private function handleVbankIssued(Order $order, array $pgResponse, string $tid, Request $request): void
    {
        $vactDate = $pgResponse['VACT_Date'] ?? null;
        $vactTime = $pgResponse['VACT_Time'] ?? '235959';
        $vbankDueAt = null;

        if ($vactDate && strlen($vactDate) === 8) {
            try {
                // 입금 기한은 한국 시각이다 — 앱 시간대(UTC)로 그대로 읽으면 9시간 늦게 저장된다.
                $vbankDueAt = Carbon::createFromFormat('YmdHis', $vactDate.$vactTime, 'Asia/Seoul')->setTimezone(config('app.timezone', 'UTC'));
            } catch (\Exception) {
                $vbankDueAt = null;
            }
        }

        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $order->payment()->update(array_filter([
            'pg_provider' => 'kginicis',
            // 에스크로를 켠 상점의 가상계좌는 에스크로 MID 로 발급된다 (S4-D18)
            'is_escrow' => $this->apiService->isEscrowEnabled(),
            'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
            'transaction_id' => $tid ?: null,
            'vbank_code' => $pgResponse['VACT_BankCode'] ?? null,  // KG 이니시스 은행코드 (e.g. 89=케이뱅크)
            'vbank_name' => $pgResponse['vactBankName'] ?? null,  // 입금은행명
            'vbank_number' => $pgResponse['VACT_Num'] ?? null,       // 가상계좌번호
            'vbank_holder' => $pgResponse['VACT_Name'] ?? null,      // 예금주명
            'vbank_due_at' => $vbankDueAt,
            'vbank_issued_at' => now(),
            'payment_device' => DeviceDetector::detect($request),
            'payment_meta' => [
                'result_code' => '0000',
                'pay_method' => 'VBank',
                'auth_date' => $pgResponse['applDate'] ?? null,
                'mid' => $this->apiService->getMid(),
                'is_test_mode' => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::PC_VBANK_ISSUE_RESPONSE_KEYS),
            ],
        ], fn ($v) => $v !== null));

        // 계좌를 받은 순간 고객의 주문은 끝났다 — 입금 전이라도 주문한 장바구니 항목을 정리한다(재고는 입금 때 차감).
        $this->orderService->onVbankAccountIssued($order);

        Log::info('KG Inicis: vbank account issued', [
            'moid' => $order->order_number,
            'tid' => $tid,
            'vbank_name' => $pgResponse['vactBankName'] ?? null,
            'vbank_number' => $pgResponse['VACT_Num'] ?? null,
            'vbank_due_at' => $vbankDueAt?->toDateTimeString(),
        ]);
    }
}
