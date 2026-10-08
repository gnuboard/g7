<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayKginicis\Concerns\BindsApprovalToOrder;
use Plugins\Sirsoft\PayKginicis\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayKginicis\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayKginicis\Concerns\ResolvesEasyPaySelection;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesCbtOrderContext;
use Plugins\Sirsoft\PayKginicis\Http\Requests\MobileCallbackRequest;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\ShopRedirectUrl;

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
    use BindsApprovalToOrder;
    use HandlesClaimPaymentCallbacks;
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
     * 모바일 결제 인증 콜백을 받아 서버 승인 후 결과 화면으로 보냅니다.
     *
     * @param  MobileCallbackRequest  $request  요청
     * @return RedirectResponse 리다이렉트 응답
     */
    public function handle(MobileCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $selectedEasyPayMethod = $this->resolveSelectedEasyPayMethod($request);

        $pStatus = $validated['P_STATUS'];
        // KG 이니시스 모바일 메뉴얼(STEP 2) 표준 응답에는 P_OID 가 없으므로 P_NEXT_URL 쿼리스트링의
        // orderId 를 fallback 으로 사용한다. 일부 PG 환경에서 P_OID 를 echo 하면 우선 채택.
        $moid = $validated['P_OID'] ?? $request->query('orderId') ?? null;

        Log::info('KG Inicis mobile: callback received', [
            'order_id' => $moid,
            'P_STATUS' => $pStatus,
            'idc_name' => $validated['idc_name'] ?? null,
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
                'P_OID' => $moid,
                'P_STATUS' => $pStatus,
                'P_RMESG1' => $pMesg,
                'is_user_cancel' => $isUserCancel,
            ]);

            // 클레임 추가결제(CP 채번)는 체크아웃이 아니라 신청서 상세로 돌려보낸다.
            if (ClaimPayment::isClaimPaymentNumber($moid)) {
                $claimRedirect = $this->redirectClaimWindowFailure($moid, $isUserCancel ? null : $pStatus);

                if ($claimRedirect !== null) {
                    return $claimRedirect;
                }
            }

            // 사용자가 결제창을 직접 닫은 취소는 오류 모달 없이 체크아웃으로 조용히 복귀
            if ($isUserCancel) {
                return redirect($this->resolveFailUrl());
            }

            return redirect($this->resolveFailUrl([
                'error' => $pStatus,
                // PC 콜백과 같은 이유 — 결제 건을 못 찾은 CP 콜백에는 PG 원문을 싣지 않는다.
                'message' => ClaimPayment::isClaimPaymentNumber($moid) ? null : $pMesg,
                'orderId' => $moid,
            ]));
        }

        $pTid = $validated['P_TID'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        $reqUrl = $validated['P_REQ_URL'] ?? null;

        if (! $pTid || ! $idcName || ! $reqUrl) {
            Log::error('KG Inicis mobile: missing required fields', [
                'P_OID' => $moid,
                'idc_name' => $idcName,
                'P_TID' => $pTid,
                'P_REQ_URL' => $reqUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // P_REQ_URL 화이트리스트 검증 (모바일 IDC URL, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $reqUrl)) {
            Log::error('KG Inicis mobile: P_REQ_URL not in whitelist (possible SSRF attempt)', [
                'P_OID' => $moid,
                'idc_name' => $idcName,
                'received' => $reqUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'req_url_invalid', 'orderId' => $moid]));
        }

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        // 승인은 모듈 위임(필터 발화 → 이 플러그인의 승인 리스너)이고, 승인 금액 불일치는 승인이
        // 확정된 뒤의 거절이므로 취소 API 로 보상한다 (모바일 경로에는 망취소 URL 이 없다).
        if (ClaimPayment::isClaimPaymentNumber($moid)) {
            $payment = $this->findClaimPayment($moid);

            if (! $payment) {
                Log::error('KG Inicis mobile: claim payment not found', ['P_OID' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'claim_payment_not_found', 'orderId' => $moid]));
            }

            // 주문 경로와 같은 직렬화 락을 건다 — 같은 콜백이 겹쳐 들어오는 것을 PG 의 중복
            // 승인 거절에만 맡기면 방어 층이 주문 경로와 달라진다. 키는 결제번호(CP)다.
            // 락 대기 초과는 500 이 아니라 신청서 상세 복귀로 끝난다(`approveClaimPaymentSerialized`).
            // 승인 뒤 거절의 보상은 모듈의 `claim_payment.void_approval` 필터(승인 리스너의
            // `voidApproval()`)가 맡는다 — API 승인 경로와 같은 한 구현이다.
            return $this->approveClaimPaymentSerialized('mobileAuthCallback', $payment, $validated);
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
                    'P_OID' => $moid,
                    'P_STATUS' => $resultStatus,
                    'P_RMESG1' => $result['P_RMESG1'] ?? '',
                ]);

                // 승인이 성립하지 않았다 — 실제로 결제된 것이 없으므로 주문 상태를 바꾸지 않는다.
                // 비인증 브라우저 콜백이라 주문번호(P_OID)와 P_TID 는 요청자가 고른 값이고,
                // 그 조합으로 만든 "승인 실패" 로 타인의 결제대기 주문을 취소시킬 수 있다.
                // 정당한 결제창 닫힘은 소유권을 검증하는 close-report 가 기록한다.
                return redirect($this->resolveFailUrl([
                    'error' => $resultStatus,
                    'message' => $result['P_RMESG1'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid = $result['P_TID'] ?? $pTid;
            // 승인액은 승인 응답의 P_AMT 만 쓴다 — 콜백 P_AMT 는 브라우저를 거친 값이라 대조 근거가 될 수
            // 없다. 응답에 없으면 0 이 되어 아래 대조에서 자동 취소 + 실패로 끝난다 (#599 validate P2).
            $totPrice = (int) ($result['P_AMT'] ?? 0);
            $payType = (string) ($result['P_TYPE'] ?? '');

            // 승인 결과가 돌려준 주문번호가 콜백 주문번호와 같아야 반영한다.
            // 콜백의 주문번호는 쿼리 orderId 에서 오고 P_TID 는 주문번호를 묶지 않으므로, 자기 승인에
            // 남의 주문번호를 붙여 보낼 수 있다. 어긋나면 승인을 취소하고 주문은 건드리지 않는다.
            $pgOrderNumber = $result['P_OID'] ?? null;
            if (! $this->approvalOrderNumberMatches(is_scalar($pgOrderNumber) ? (string) $pgOrderNumber : null, (string) $moid)) {
                Log::warning('KG Inicis mobile: approve result does not match callback order; cancelling', [
                    'P_OID' => $moid,
                    'pg_oid' => $pgOrderNumber,
                    'P_TID' => $tid,
                ]);

                $this->autoCancelIfApproved((string) $tid, $moid, $totPrice, 'order_mismatch');

                return redirect($this->resolveFailUrl(['error' => 'approve_failed', 'orderId' => $moid]));
            }

            // 가상계좌: completePayment 없이 발급 정보만 저장 (입금 통보 시점에 completePayment)
            if (strcasecmp($payType, 'VBank') === 0) {
                // 결제대기 주문에만 발급을 기록한다 — 이미 결제됐거나 다른 가상계좌가 붙은 결제 레코드를
                // 덮어쓰면 환불 대상 거래번호가 오염된다. 같은 발급의 중복 콜백은 성공으로만 처리한다.
                // 발급 금액은 승인 응답의 P_AMT 로만 판정한다 — 응답에 없으면 null 로 넘겨 발급을 거절한다
                // (0 을 "금액 없음" 으로 바꾸지 않는다. 0 원 발급도 청구액과의 불일치로 거절된다).
                $issuedAmount = is_numeric($result['P_AMT'] ?? null) ? (int) $result['P_AMT'] : null;
                $issueDecision = $this->vbankIssueDecision($order, $issuedAmount, (string) $tid);
                if ($issueDecision === 'reject') {
                    Log::warning('KG Inicis mobile: vbank issue rejected (order not payable or amount mismatch); cancelling', [
                        'P_OID' => $moid,
                        'P_TID' => $tid,
                        'order_status' => $order->order_status->value,
                        'pg_amount' => $result['P_AMT'] ?? null,
                    ]);

                    $this->autoCancelIfApproved((string) $tid, $moid, $totPrice, 'vbank_not_payable');

                    return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
                }

                if ($issueDecision === 'issue') {
                    $this->handleVbankIssued($order, $result, $tid);
                } else {
                    $this->logReplayDetected((string) $tid, $moid, 'mobile authCallback (vbank issue)');
                }
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

            // 취소된 주문은 결제완료로 되살리지 않는다 (코어 P4 와 같은 가드).
            //
            // 결제창을 띄운 뒤 다른 탭·관리자 화면에서 주문이 취소될 수 있고, 그 사이 PG 승인은
            // 이미 끝난다. 통과시키면 `CANCELLED` 주문이 결제완료로 되돌아가 재고·마일리지가
            // 재차감된다. 형제 경로(`CbtCallbackController::assertPayableCbtOrder`)와 같은 판정이며,
            // 승인된 돈은 붙들지 않고 즉시 PG 취소로 돌려준다.
            if (! $order->order_status->isBeforePayment()) {
                $this->autoCancelIfApproved($approvedTid, $moid, $approvedTotPrice, 'order_not_payable');

                return redirect($this->resolveFailUrl(['error' => 'order_not_payable', 'orderId' => $moid]));
            }

            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'mobile_auth_callback', [
                'tid' => $tid,
                'received_amount' => $totPrice,
            ]);
            if ($expectedAmount === null) {
                $this->autoCancelIfApproved($approvedTid, $moid, $approvedTotPrice, 'invalid_payment_currency');

                return redirect($this->resolveFailUrl(['error' => 'invalid_payment_currency', 'orderId' => $moid]));
            }
            // 청구액이 0 이어도 대조를 건너뛰지 않는다 — PG 결제가 필요 없는 주문에 승인이 들어온 것 자체가 불일치다.
            if ($totPrice !== $expectedAmount) {
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
                'transaction_id' => $tid,
                'card_approval_number' => $result['P_APPL_NUM'] ?? null,
                'card_number_masked' => $result['P_CARD_NUM'] ?? null,
                'card_name' => $result['P_CARD_ISSUER_NAME'] ?? null,
                'card_installment_months' => (int) ($result['P_CARD_QUOTA'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => $embeddedPgProvider,
                'receipt_url' => null,
                'payment_meta' => array_merge([
                    'result_code' => $resultStatus,
                    'pay_method' => $payType ?: null,
                    'auth_date' => $result['P_AUTH_DT'] ?? null,
                    'mid' => $this->apiService->getMid(),
                    'is_test_mode' => $this->apiService->isTestMode(),
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($result, self::MOBILE_APPROVE_RESPONSE_KEYS),
                ], $this->buildEasyPayPaymentMeta($selectedEasyPayMethod)),
                'payment_device' => 'mobile',
            ], $totPrice);

            // 에스크로를 켠 상점의 결제는 에스크로 MID 로 승인된다 — [구매결정]·에스크로 배송 등록·환불이 이 표시로 판정한다 (S4-D18)
            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $order->payment()->update(['pg_provider' => 'kginicis', 'is_escrow' => $this->apiService->isEscrowEnabled() && $selectedEasyPayMethod === null]);
            $this->queueReceiptCookie($moid);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis mobile: amount mismatch', [
                'P_OID' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
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
                'error' => 'approve_failed',
                'message' => __('sirsoft-pay_kginicis::messages.errors.payment_failed'),
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
     *
     * @param  string|null  $tid  승인된 거래번호 (없으면 아무것도 하지 않음)
     * @param  string  $moid  주문번호
     * @param  int  $totPrice  승인 금액 (결제 통화)
     * @param  string  $reason  취소 사유 코드 (로그 문맥)
     * @return void 반환값 없음
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
                'auto-cancel: '.$reason,
                $totPrice > 0 ? $totPrice : null,
            );

            Log::warning('KG Inicis mobile: auto-cancel after post-approve failure', [
                'tid' => $tid,
                'P_OID' => $moid,
                'amount' => $totPrice,
                'reason' => $reason,
                'pg_result' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('KG Inicis mobile: auto-cancel FAILED — manual reconciliation required', [
                'tid' => $tid,
                'P_OID' => $moid,
                'amount' => $totPrice,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 모바일 가상계좌 발급 처리 (completePayment 없이 계좌 정보만 저장)
     *
     * KG 이니시스 모바일 승인 응답에서 P_TYPE=VBank 로 판별된 경우 호출.
     * 실제 결제 완료(completePayment)는 입금 통보(mobileVbankNotify) 시점에 처리.
     * 응답 필드는 manual.inicis.com/pay/stdpay_m.html 표준에 따른 P_VACT_* 형식.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $result  모바일 승인 응답
     * @param  string  $tid  PG 거래번호
     * @return void 반환값 없음
     */
    private function handleVbankIssued(Order $order, array $result, string $tid): void
    {
        $vactDate = $result['P_VACT_DATE'] ?? null;
        $vactTime = $result['P_VACT_TIME'] ?? '235959';
        $vbankDueAt = null;

        if ($vactDate && strlen((string) $vactDate) === 8) {
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
            'vbank_code' => $result['P_VACT_BANK_CODE'] ?? $result['P_FN_CD1'] ?? null,
            'vbank_name' => $result['P_VACT_BANK_NAME'] ?? $result['P_FN_NM'] ?? null,
            'vbank_number' => $result['P_VACT_NUM'] ?? null,
            'vbank_holder' => $result['P_VACT_NAME'] ?? $result['P_RVACTNM'] ?? null,
            'vbank_due_at' => $vbankDueAt,
            'vbank_issued_at' => now(),
            'payment_device' => 'mobile',
            'payment_meta' => [
                'result_code' => $result['P_STATUS'] ?? '00',
                'pay_method' => 'VBank',
                'auth_date' => $result['P_AUTH_DT'] ?? null,
                'mid' => $this->apiService->getMid(),
                'is_test_mode' => $this->apiService->isTestMode(),
                'pg_response_sanitized' => true,
                'pg_raw_response' => $this->sanitizePgResponse($result, self::MOBILE_VBANK_ISSUE_RESPONSE_KEYS),
            ],
        ], fn ($v) => $v !== null));

        // 계좌를 받은 순간 고객의 주문은 끝났다 — 입금 전이라도 주문한 장바구니 항목을 정리한다(재고는 입금 때 차감).
        $this->orderService->onVbankAccountIssued($order);

        Log::info('KG Inicis mobile: vbank account issued', [
            'P_OID' => $order->order_number,
            'P_TID' => $tid,
            'vbank_name' => $result['P_VACT_BANK_NAME'] ?? $result['P_FN_NM'] ?? null,
            'vbank_number' => $result['P_VACT_NUM'] ?? null,
            'vbank_due_at' => $vbankDueAt?->toDateTimeString(),
        ]);
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

        $query = http_build_query(array_filter($queryParams, fn ($v) => $v !== null && $v !== ''));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$separator.$query;
    }

    /**
     * KG 이니시스 모바일 P_RMESG1 메시지가 사용자 직접 취소 패턴인지 판별.
     *
     * P_STATUS=01 은 일반 실패(예: MX1006)와 사용자 취소가 공유하므로, P_RMESG1
     * 메시지의 한국어 문구로 분기. 시제/존대 변형을 흡수하기 위해 부분 일치.
     *
     * @param  string  $message  PG 결과 메시지
     * @return bool 사용자 취소 메시지이면 true
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
}
