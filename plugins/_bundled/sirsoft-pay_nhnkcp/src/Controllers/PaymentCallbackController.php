<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\DepositAfterCancelRecorder;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\BindsMobileVbankSession;
use Plugins\Sirsoft\PayNhnkcp\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayNhnkcp\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNhnkcp\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ReadsKcpApprovedAmount;
use Plugins\Sirsoft\PayNhnkcp\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SendsKcpNotifyResponse;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayNhnkcp\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;
use Plugins\Sirsoft\PayNhnkcp\Support\ShopRedirectUrl;

/**
 * KCP 결제 콜백 컨트롤러
 *
 * KCP Standard Pay는 브라우저 POST 콜백 방식입니다:
 *   1단계: 브라우저가 POST 콜백으로 enc_data, enc_info 등 전달 → authCallback()
 *   2단계: 서버가 KCP CLI 바이너리로 최종 승인 확인 → NhnKcpApiService::approvePayment()
 */
class PaymentCallbackController
{
    use BindsMobileVbankSession;
    use HandlesClaimPaymentCallbacks;
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use ReadsKcpApprovedAmount;
    use RecordsPaymentWindowClosure;
    use ResolvesEasyPayDisplay;
    use SanitizesPgResponse;
    use SendsKcpNotifyResponse;
    use SerializesPaymentCallbacks;

    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const SUCCESS_RES_CD = '0000';

    // 사용자가 결제창을 직접 닫은 취소 코드 — 조용히 체크아웃으로 복귀
    private const CANCEL_RES_CODES = ['3001', '3000', '7777', ''];

    // KCP 공통통보 tx_cd — 그누보드5 settle_kcp_common.php 참고
    private const KCP_TX_CD_VBANK_DEPOSIT = 'TX00';

    // KCP 가상계좌 입금통보 op_cd
    private const KCP_OP_CD_DEPOSIT_COMPLETE = '50';

    private const KCP_OP_CD_DEPOSIT_RESEND = '01';

    private const KCP_OP_CD_DEPOSIT_CANCEL = '13';

    private const AUTH_RESPONSE_KEYS = [
        'res_cd',
        'res_msg',
        'tno',
        'ordr_idxx',
        'good_mny',
        'amount',
        'use_pay_method',
        'app_time',
        'app_no',
        'card_cd',
        'card_name',
        'quota',
        'noinf',
        'escw_yn',
        'bank_code',
        'bank_name',
        'cash_yn',
        'receipt_no',
    ];

    private const VBANK_NOTIFY_RESPONSE_KEYS = [
        'site_cd',
        'tno',
        'order_no',
        'tx_cd',
        'tx_tm',
        'ipgm_mnyx',
        'bank_code',
        'op_cd',
        'noti_id',
    ];

    private const VBANK_ISSUE_RESPONSE_KEYS = [
        'res_cd',
        'res_msg',
        'tno',
        'amount',
        'bankname',
        'bank_name',
        'bankcode',
        'bank_code',
        'va_date',
        'vnbank_expire_date',
        'app_time',
        'use_pay_method',
    ];

    /**
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 조회 서비스
     * @param  NhnKcpApiService  $apiService  NHN KCP API 서비스
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NhnKcpApiService $apiService,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
    ) {}

    /**
     * KCP 결제 승인 콜백
     *
     * POST /plugins/sirsoft-pay_nhnkcp/payment/callback
     * (CSRF 제외 - KCP가 브라우저 통해 POST 전달)
     */
    /**
     * KCP 결제 승인 콜백
     *
     * 1단계: KCP Standard Pay 가 결제창 완료 후 ReturnURL 로 POST 콜백.
     * 2단계: enc_data + enc_info 로 서버 승인 요청. 결제 수단별로 가상계좌(계좌발급)/
     * 카드(즉시완료) 분기. 사용자 취소 / 인증 실패 시 에러 query 로 체크아웃 복귀.
     *
     * @param  AuthCallbackRequest  $request  검증된 콜백 페이로드
     * @return RedirectResponse 성공/실패 URL 로 리다이렉트
     */
    public function authCallback(AuthCallbackRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $resCd = (string) ($validated['res_cd'] ?? '');
        $resMsg = $this->decodeKcpMessage($validated['res_msg'] ?? '');
        $encData = $validated['enc_data'] ?? '';  // 모바일 취소 시 미포함
        $encInfo = $validated['enc_info'] ?? '';  // 모바일 취소 시 미포함
        $ordrIdxx = $validated['ordr_idxx'];
        $goodMny = isset($validated['good_mny']) ? (int) $validated['good_mny'] : 0;
        $custIp = $request->ip() ?? '127.0.0.1';

        Log::info('KCP: authCallback received', [
            'ordr_idxx' => $ordrIdxx,
            'res_cd' => $resCd,
            'good_mny' => $goodMny,
            'has_enc_data' => ! empty($encData),
            'has_enc_info' => ! empty($encInfo),
            'post_keys' => array_keys($request->all()),
        ]);

        // 1단계: KCP 브라우저 결과 코드 확인
        if ($resCd !== self::SUCCESS_RES_CD) {
            $isCancelled = in_array($resCd, self::CANCEL_RES_CODES, true);

            Log::info('KCP: payment result non-success', [
                'ordr_idxx' => $ordrIdxx,
                'res_cd' => $resCd,
                'res_msg' => $resMsg,
                'is_cancelled' => $isCancelled,
            ]);

            // authCallback is a browser-return endpoint without PG-server signature/IP proof.
            // Do not mutate order state from a non-success browser result alone; the
            // authenticated close-report path records real window closures.

            // 클레임 추가결제(CP 채번)는 체크아웃이 아니라 신청서 상세로 돌려보낸다.
            if (ClaimPayment::isClaimPaymentNumber($ordrIdxx)) {
                $claimRedirect = $this->redirectClaimWindowFailure($ordrIdxx, $isCancelled ? null : $resCd);

                if ($claimRedirect !== null) {
                    return $claimRedirect;
                }
            }

            // 사용자 취소는 오류 없이 체크아웃으로 복귀
            if ($isCancelled) {
                return redirect($this->resolveFailUrl());
            }

            return redirect($this->resolveFailUrl([
                'error' => $resCd,
                // 결제 건을 못 찾은 CP 콜백이 여기까지 떨어질 수 있다. PG 원문은 상점 사이트코드·내부
                // 상태를 담고 이 경로는 비인증 브라우저 리다이렉트이므로, CP 번호일 때는 코드만 싣는다.
                'message' => ClaimPayment::isClaimPaymentNumber($ordrIdxx) ? null : $resMsg,
                'orderId' => $ordrIdxx,
            ]));
        }

        // Approve 성공 후 후속 처리 실패 시 PG 측 자동 cancel 을 위한 추적 변수.
        // catch 블록에서 tno 가 set 되어 있으면 KCP 측 승인이 이미 발생한 상태이므로
        // cancelPayment 를 호출해 PG 잔존 승인을 해제한다 (사용자 환불 보장).
        $order = null;
        $approvedTno = null;
        $approvedAmtForCancel = 0;
        $callbackLock = null;

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        // 승인은 모듈 위임(필터 발화 → 이 플러그인의 승인 리스너)이고, 승인 금액 불일치는 KCP
        // 승인이 확정된 뒤의 거절이므로 취소 API 로 보상한다.
        if (ClaimPayment::isClaimPaymentNumber($ordrIdxx)) {
            $claimPayment = $this->findClaimPayment($ordrIdxx);

            if (! $claimPayment) {
                Log::error('KCP: claim payment not found', ['ordr_idxx' => $ordrIdxx]);

                return redirect($this->resolveFailUrl(['error' => 'claim_payment_not_found', 'orderId' => $ordrIdxx]));
            }

            // 주문 경로와 같은 직렬화 락을 건다 — 같은 콜백이 겹쳐 들어오는 것을 PG 의 중복
            // 승인 거절에만 맡기면 방어 층이 주문 경로와 달라진다. 키는 결제번호(CP)다.
            // 락 대기 초과는 500 이 아니라 신청서 상세 복귀로 끝난다(`approveClaimPaymentSerialized`).
            // 승인 뒤 거절의 보상은 보상 클로저 대신 모듈의 `claim_payment.void_approval` 필터(이
            // 플러그인 승인 리스너의 `voidApproval()`)가 맡는다 — API 승인 경로와 같은 한 구현이다.
            return $this->approveClaimPaymentSerialized('authCallback', $claimPayment, $validated + ['cust_ip' => $custIp]);
        }

        try {
            // 2단계: 주문 조회
            $order = $this->orderService->findByOrderNumber($ordrIdxx);

            if (! $order) {
                Log::error('KCP: order not found', ['ordr_idxx' => $ordrIdxx]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $ordrIdxx]));
            }

            $callbackLock = $this->acquireOrderCallbackLock('authCallback', $ordrIdxx);
            $order = $order->fresh('payment') ?? $order;

            // 취소 주문 재결제 — 여기서는 되살리지 않고 "되살릴 수 있는가" 만 읽는다.
            // 되살리기는 상태 변경이고 이 시점의 입력은 전부 비인증 브라우저 값이라,
            // 여기서 수행하면 주문번호만 아는 제3자가 남의 취소 주문을 결제대기로
            // 되돌릴 수 있다. 실제 되살리기는 승인·발급이 확정된 뒤에 수행한다.
            if ($order->order_status === OrderStatusEnum::CANCELLED
                && ! $this->isRetryableKcpFailure($order, $order->payment)) {
                Log::warning('KCP: cancelled order is not retryable', ['ordr_idxx' => $ordrIdxx]);

                return redirect($this->resolveFailUrl([
                    'error' => 'order_not_retryable',
                    'orderId' => $ordrIdxx,
                ]));
            }

            if (($order->payment?->isPaid() ?? false) || $order->order_status === OrderStatusEnum::PAYMENT_COMPLETE) {
                Log::info('KCP: authCallback already paid order, skipping CLI approval', [
                    'ordr_idxx' => $ordrIdxx,
                    'transaction_id' => $order->payment?->transaction_id,
                ]);

                $this->queueReceiptCookie($ordrIdxx);

                return redirect($this->resolveSuccessUrl($ordrIdxx));
            }

            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'auth_callback', [
                'ordr_idxx' => $ordrIdxx,
                'good_mny' => $goodMny,
            ]);
            if ($expectedAmount === null) {
                return redirect($this->resolveFailUrl([
                    'error' => 'invalid_payment_currency',
                    'orderId' => $ordrIdxx,
                ]));
            }

            if (! $this->isKrwOrder($order)) {
                Log::warning('KCP: unsupported order currency', [
                    'ordr_idxx' => $ordrIdxx,
                    'currency' => $this->orderCurrency($order),
                ]);

                return redirect($this->resolveFailUrl(['error' => 'currency_not_supported', 'orderId' => $ordrIdxx]));
            }

            if ($goodMny > 0 && $goodMny !== $expectedAmount) {
                Log::warning('KCP: browser callback amount mismatch before approval', [
                    'ordr_idxx' => $ordrIdxx,
                    'expected' => $expectedAmount,
                    'actual' => $goodMny,
                ]);

                // 승인 전이다 — 이 시점의 입력은 전부 브라우저가 보낸 값이고 이 엔드포인트는
                // PG 서명도 IP 증명도 없다. 주문번호도 요청자가 고른 값이므로, 금액 불일치를
                // 근거로 주문을 실패 처리하면 남의 주문번호와 임의 금액만으로 그 주문을
                // 취소시킬 수 있다. 상태는 바꾸지 않고 결제창으로 되돌린다.
                // 정당한 결제창 닫힘은 소유권을 검증하는 close-report 가 기록한다.
                return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $ordrIdxx]));
            }

            // 가상계좌: 계좌 발급 완료 처리 (실제 입금은 vbankNotify에서 처리)
            // 분기 판정은 주문에 저장된 결제수단만 본다 — 요청의 use_pay_method 를 함께 보면
            // 비인증 브라우저가 use_pay_method=VCNT 한 줄로 카드 주문을 가상계좌 분기로 몰아
            // 승인 검증이 없는 경로를 고를 수 있다.
            // 결제창은 결제수단을 확정한 뒤 열리므로 정상 흐름은 주문 값만으로 판정된다.
            $isVbank = (bool) $order->payment?->isVirtualAccount();
            if ($isVbank) {
                return $this->handleVbankIssued($validated, $order, $encData, $encInfo, $ordrIdxx, $custIp, $request, $expectedAmount);
            }

            HookManager::doAction('sirsoft-pay_nhnkcp.payment.before_confirm', $order, $validated);

            // 3단계: KCP CLI로 최종 승인 확인. 청구액(ordr_mony)은 주문 저장값에서 산정한 금액을 싣는다 —
            // KCP 가 결제창에서 인증된 금액과 대조해 다르면 승인 자체를 거절한다.
            $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp, $expectedAmount);

            HookManager::doAction('sirsoft-pay_nhnkcp.payment.after_confirm', $order, $pgResponse);

            $pgResCd = $pgResponse['res_cd'] ?? '';

            if ($pgResCd !== self::SUCCESS_RES_CD) {
                Log::warning('KCP: CLI approval failed', [
                    'ordr_idxx' => $ordrIdxx,
                    'res_cd' => $pgResCd,
                    'res_msg' => $pgResponse['res_msg'] ?? '',
                ]);

                // 승인이 성립하지 않았다 — 실제로 결제된 것이 없으므로 주문 상태를 바꾸지 않는다.
                // 이 엔드포인트는 비인증 브라우저 POST 라 "승인 실패" 는 공격자가 남의 주문번호와
                // 위조 암호문을 보내기만 해도 만들어낼 수 있는 상태다. 그것을 근거로 실패 처리하면
                // 타인의 결제대기 주문을 임의로 취소시킬 수 있다.
                // 실제 승인이 일어난 뒤의 실패만 아래 catch 블록에서 주문에 반영한다.
                return redirect($this->resolveFailUrl([
                    'error' => $pgResCd,
                    'message' => $pgResponse['res_msg'] ?? '',
                    'orderId' => $ordrIdxx,
                ]));
            }

            // 거래번호는 PG 승인 응답의 값만 쓴다. 브라우저가 보낸 tno 로 대신 채우면 결제 기록과
            // 이후 취소·환불이 요청자가 고른 거래번호를 향하게 된다.
            $tno = trim((string) ($pgResponse['tno'] ?? ''));

            if ($tno === '') {
                // 승인 성공 응답에 거래번호가 없으면 취소할 거래도 특정할 수 없다. 결제완료로 확정하지
                // 않고 주문 상태도 바꾸지 않은 채 실패로 돌려보낸다 — PG 관리자에서 대사해야 한다.
                Log::error('KCP: CLI approval succeeded without tno — manual reconciliation required', [
                    'ordr_idxx' => $ordrIdxx,
                    'res_cd' => $pgResCd,
                ]);

                return redirect($this->resolveFailUrl([
                    'error' => 'confirm_failed',
                    'message' => __('sirsoft-pay_nhnkcp::messages.errors.payment_failed'),
                    'orderId' => $ordrIdxx,
                ]));
            }

            // Replay 가드: 동일 tno 가 이미 paid 상태면 중복 처리하지 않고 성공 페이지로 복귀
            if ($this->wasAlreadyPaid($tno)) {
                $this->logReplayDetected($tno, $ordrIdxx, 'authCallback (card)');
                $this->queueReceiptCookie($ordrIdxx);

                return redirect($this->resolveSuccessUrl($ordrIdxx));
            }

            // 승인 금액은 PG 승인 응답의 amount 만 근거로 삼는다. 결제 청구액 SSoT(= 결제 통화
            // 환산 실청구액, 마일리지/예치금 차감 후)와 정확히 같아야 결제완료로 확정한다.
            // 브라우저 콜백의 good_mny 는 요청자가 고른 값이라 대체값으로도 쓰지 않는다 — 응답에
            // 금액이 없으면 확인할 수 없는 승인이므로 불일치와 같이 취소한다.
            $pgApprovedAmount = $this->kcpApprovedAmount($pgResponse);

            // PG 측 승인이 확정된 시점 — 후속 처리 실패 시 cancel 필요. catch 에서 참조.
            $approvedTno = $tno;
            $approvedAmtForCancel = $pgApprovedAmount ?? $expectedAmount;

            if ($pgApprovedAmount !== $expectedAmount) {
                // 아래 PaymentAmountMismatchException catch 가 승인분을 자동 취소한다.
                throw new PaymentAmountMismatchException(
                    (float) $expectedAmount,
                    (float) ($pgApprovedAmount ?? 0),
                    [
                        'ordr_idxx' => $ordrIdxx,
                        'tno' => $tno,
                        'source' => 'kcp_approval_response',
                        'response_amount_present' => $pgApprovedAmount !== null,
                    ],
                );
            }

            $approvedAmt = $pgApprovedAmount;

            // 승인이 확정된 뒤에만 취소 주문을 되살린다. 실패하면 예외로 아래 catch 에 넘겨
            // PG 잔존 승인을 자동 취소한다(사용자 환불 보장).
            if ($order->order_status === OrderStatusEnum::CANCELLED) {
                if (! $this->restoreRetryableKcpOrder($order, $approvedAmt)) {
                    throw new \RuntimeException('cancelled order is not retryable after approval');
                }

                $order = $order->fresh('payment') ?? $order;
            }

            $isEscrow = ($pgResponse['escw_yn'] ?? '') === 'Y';
            $easyPayMeta = $this->resolveEasyPayMeta($validated);

            // 4단계: 주문 완료 처리
            $this->orderService->completePayment($order, [
                'transaction_id' => $tno,
                'card_approval_number' => $pgResponse['app_no'] ?? null,
                'card_number_masked' => $pgResponse['card_no'] ?? $pgResponse['account'] ?? null,
                'card_name' => $pgResponse['card_name'] ?? $pgResponse['bank_name'] ?? null,
                'card_installment_months' => (int) ($pgResponse['quota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => $easyPayMeta['provider'] ?? null,
                'receipt_url' => null,
                'payment_meta' => [
                    'res_cd' => $pgResCd,
                    ...$this->currentCredentialMeta(),
                    ...($easyPayMeta === [] ? [] : [
                        'nhnkcp_easy_pay_method' => $easyPayMeta['method'],
                        'nhnkcp_easy_pay_provider' => $easyPayMeta['provider'],
                        'nhnkcp_easy_pay_label' => $easyPayMeta['label'],
                    ]),
                    'use_pay_method' => $validated['use_pay_method'] ?? $pgResponse['use_pay_method'] ?? null,
                    'app_time' => $pgResponse['app_time'] ?? null,
                    'bank_name' => $pgResponse['bank_name'] ?? null,
                    'vnbank_expire_date' => $pgResponse['vnbank_expire_date'] ?? null,
                    'escw_yn' => $pgResponse['escw_yn'] ?? null,
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::AUTH_RESPONSE_KEYS),
                ],
                'payment_device' => DeviceDetector::detect($request),
            ], $approvedAmt);

            // 에스크로 결제인 경우 is_escrow 플래그 저장
            if ($isEscrow) {
                $order->payment()->update(['is_escrow' => true]);
            }

            // KCP가 실제로 결제를 처리했으므로 pg_provider를 nhnkcp로 보정
            // (기본 PG가 타 PG일 때 주문이 해당 PG provider로 생성되는 경우 대비)
            $order->payment()->update(['pg_provider' => 'nhnkcp']);

            $this->queueReceiptCookie($ordrIdxx);

            return redirect($this->resolveSuccessUrl($ordrIdxx));

        } catch (LockTimeoutException $e) {
            Log::warning('KCP: authCallback skipped because callback lock is busy', [
                'ordr_idxx' => $ordrIdxx,
            ]);

            $latestOrder = $this->orderService->findByOrderNumber($ordrIdxx);
            if (($latestOrder?->payment?->isPaid() ?? false)
                || $latestOrder?->order_status === OrderStatusEnum::PAYMENT_COMPLETE) {
                $this->queueReceiptCookie($ordrIdxx);

                return redirect($this->resolveSuccessUrl($ordrIdxx));
            }

            return redirect($this->resolveFailUrl([
                'error' => 'callback_locked',
                'orderId' => $ordrIdxx,
            ]));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KCP: amount mismatch', [
                'ordr_idxx' => $ordrIdxx,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            // Approve 가 이미 KCP 측에서 발생했으면 자동 취소로 PG 잔존 승인 해제
            $this->autoCancelIfApproved($approvedTno, $ordrIdxx, $approvedAmtForCancel, 'amount_mismatch');

            // 주문 상태를 바꾸는 것은 KCP 승인이 실제로 일어난 뒤의 실패뿐이다. tno 가 비어 있으면
            // 승인 전에 터진 예외이고, 그 시점의 입력은 전부 비인증 브라우저가 보낸 값이라
            // 남의 주문을 취소시키는 통로가 된다.
            if ($order instanceof Order && $this->hasApproval($approvedTno)) {
                $failedOrder = $this->orderService->failPayment($order, 'AMOUNT_MISMATCH', $e->getMessage());
                $this->markKcpPaymentFailureRecord(
                    $failedOrder,
                    'AMOUNT_MISMATCH',
                    $e->getMessage(),
                    'amount_mismatch',
                );
            }

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $ordrIdxx]));

        } catch (\Exception $e) {
            Log::error('KCP: confirm exception', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);

            $this->autoCancelIfApproved($approvedTno, $ordrIdxx, $approvedAmtForCancel, 'confirm_failed');

            // 승인 이후의 후속 처리 실패만 주문에 반영한다 (위 amount_mismatch catch 와 동일 기준).
            if ($order instanceof Order && $this->hasApproval($approvedTno)) {
                $failedOrder = $this->orderService->failPayment($order, 'CONFIRM_FAILED', $e->getMessage());
                $this->markKcpPaymentFailureRecord(
                    $failedOrder,
                    'CONFIRM_FAILED',
                    $e->getMessage(),
                    'confirm_failed',
                );
            }

            return redirect($this->resolveFailUrl([
                'error' => 'confirm_failed',
                'message' => __('sirsoft-pay_nhnkcp::messages.errors.payment_failed'),
                'orderId' => $ordrIdxx,
            ]));
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * KCP 가상계좌 입금 통보 처리
     *
     * POST /plugins/sirsoft-pay_nhnkcp/payment/vbank-notify
     * (KCP 서버 → 우리 서버, CSRF 제외)
     *
     * KCP 공식 webhook 페이로드 (그누보드5 settle_kcp_common.php 참고):
     *   tx_cd=TX00 (가상계좌 입금) + op_cd 50/01/13 + ipgm_mnyx + order_no + noti_id
     *
     * 응답: <form><input name="result" value="0000"> HTML 을 KCP 가 파싱.
     * result="0000" 이면 통보 성공, 그 외이면 재통보 (최대 10회).
     *
     * @param  VbankNotifyRequest  $request  검증된 입금통보 페이로드
     * @return Response 200 + KCP 표준 result HTML
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $txCd = (string) ($validated['tx_cd'] ?? self::KCP_TX_CD_VBANK_DEPOSIT);
        $tno = $validated['tno'];
        $orderNo = $validated['order_no'];
        $opCd = (string) ($validated['op_cd'] ?? '');
        $ipgmMny = (int) ($validated['ipgm_mnyx'] ?? 0);
        $notiId = $validated['noti_id'] ?? null;
        $callbackLock = null;

        // tx_cd 가 TX00 가 아니면 본 endpoint 가 처리하지 않음.
        // (KCP 가 단일 webhook URL 에 모든 tx_cd 를 보낼 수도 있어 안전하게 무시 + result=0000)
        if ($txCd !== self::KCP_TX_CD_VBANK_DEPOSIT) {
            Log::info('KCP: vbank-notify ignored non-TX00', [
                'tno' => $tno, 'order_no' => $orderNo, 'tx_cd' => $txCd, 'noti_id' => $notiId,
            ]);

            return $this->kcpNotifyResponse();
        }

        // op_cd 분기 (그누보드5 settle_kcp_common.php 와 동일 정책):
        //   13 (망취소)         → 별도 처리 (입금완료 안 함, 결제 취소 정책 미정 — 로깅만)
        //   그 외 (50/01/18/…)  → 입금완료 처리 (KCP testadmin 모의입금은 op_cd=18 사용 확인됨)
        // KCP 공식 문서가 50/01/13 만 언급하지만 실제 KCP testadmin 발신은 18 등 추가 코드를
        // 사용하므로 그누보드5 의 op_cd 무관 입금처리 정책을 따른다 (망취소만 분리).
        if ($opCd === self::KCP_OP_CD_DEPOSIT_CANCEL) {
            Log::warning('KCP: vbank deposit cancelled (op_cd=13)', [
                'tno' => $tno, 'order_no' => $orderNo, 'noti_id' => $notiId, 'ipgm_mnyx' => $ipgmMny,
            ]);

            return $this->kcpNotifyResponse();
        }

        try {
            $order = $this->orderService->findByOrderNumber($orderNo);

            if (! $order) {
                Log::error('KCP: vbank notify - order not found', [
                    'order_no' => $orderNo, 'tno' => $tno, 'noti_id' => $notiId,
                ]);

                // 영구 실패 — 재시도 의미 없음. result=0000 으로 KCP 재통보 차단.
                return $this->kcpNotifyResponse();
            }

            $callbackLock = $this->acquireOrderCallbackLock('vbankNotify', $orderNo);
            $order = $order->fresh('payment') ?? $order;

            $contextError = $this->validateVbankNotifyContext($order, $validated, $tno, $ipgmMny);

            // 취소된 주문의 입금(P-D1 · Q11) — 주문은 되살리지 않고 기록·운영자 알림만 남긴 뒤 0000 으로 재통보를 멈춘다.
            // 예전에는 9999(재통보 요청)로 답해 같은 입금이 10번 다시 왔고, 어디에도 남지 않았다. 발급 때 받은 거래번호와
            // 맞는 통보만 기록한다(통보 출처는 KCP IP 로 이미 걸렀다).
            if ($contextError === 'order_cancelled'
                && $order->payment !== null
                && hash_equals((string) $order->payment->transaction_id, $tno)) {
                app(DepositAfterCancelRecorder::class)->record($order, (float) $ipgmMny, 'nhnkcp', $tno);

                return $this->kcpNotifyResponse();
            }

            if ($contextError !== null) {
                Log::warning('KCP: vbank notify context mismatch — retry requested', [
                    'reason' => $contextError,
                    'tno' => $tno,
                    'order_no' => $orderNo,
                    'noti_id' => $notiId,
                ]);

                return $this->kcpNotifyRetry();
            }

            // 멱등성 — 이미 결제완료 상태면 재처리 없이 0000 응답 (op_cd=01 재전송 대응)
            if ($order->payment?->isPaid() ?? false) {
                Log::info('KCP: vbank-notify already paid (idempotent)', [
                    'tno' => $tno, 'order_no' => $orderNo, 'noti_id' => $notiId, 'op_cd' => $opCd,
                ]);

                return $this->kcpNotifyResponse();
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tno,
                'payment_meta' => [
                    'tx_cd' => $txCd,
                    'op_cd' => $opCd,
                    'noti_id' => $notiId,
                    ...$this->currentCredentialMeta((string) ($validated['site_cd'] ?? '')),
                    'bank_code' => $validated['bank_code'] ?? null,
                    'ipgm_time' => $validated['tx_tm'] ?? null,
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($validated, self::VBANK_NOTIFY_RESPONSE_KEYS),
                ],
            ], $ipgmMny);

            $order->payment()->update(['pg_provider' => 'nhnkcp']);

            Log::info('KCP: vbank deposit confirmed', [
                'tno' => $tno, 'order_no' => $orderNo, 'ipgm_mnyx' => $ipgmMny,
                'op_cd' => $opCd, 'noti_id' => $notiId,
            ]);

            return $this->kcpNotifyResponse();

        } catch (LockTimeoutException $e) {
            Log::warning('KCP: vbank notify lock timeout — retry requested', [
                'tno' => $tno,
                'order_no' => $orderNo,
                'noti_id' => $notiId,
            ]);

            return $this->kcpNotifyRetry();

        } catch (\Exception $e) {
            Log::error('KCP: vbank notify failed', [
                'tno' => $tno, 'order_no' => $orderNo, 'noti_id' => $notiId,
                'error' => $e->getMessage(),
            ]);

            // 일시적 실패 (DB 등) — result != 0000 으로 KCP 재통보 유도
            return $this->kcpNotifyRetry();
        } finally {
            $this->releaseOrderCallbackLock($callbackLock);
        }
    }

    /**
     * 가상계좌 발급 처리
     *
     * authCallback 에서 use_pay_method=VCNT 일 때 호출.
     *
     * PC Standard Pay: enc_data/enc_info 가 동봉되어 CLI 로 복호화하여 계좌 정보를 얻음.
     * Mobile SmartPhone Pay: enc_data 없이 평문 필드(bankname/account/depositor/va_date)가
     *   콜백 POST 에 직접 포함되므로 CLI 호출을 건너뛰고 요청에서 직접 읽음.
     *
     * 발급 성공 = bankname/account 가 모두 채워진 경우만 (정상 res_cd 는 결제수단별로 다르다).
     * 그 외 (CLI 9502 / 예외 / 평문 필드 결락) 는 발급 실패로 판정하되 **주문 상태는 바꾸지
     * 않고** fail URL 로 되돌려 보낸다 — 이 엔드포인트는 비인증 브라우저 POST 라, 발급 미완을
     * 근거로 실패 처리하면 주문번호만 아는 제3자가 남의 주문을 취소시킬 수 있다
     * 정당한 실패는 소유권을 검증하는 close-report 가 기록한다.
     *
     * PC 분기는 CLI 발급 응답의 금액(`amount`)과 거래번호가 주문 청구액·응답에 맞을 때만 계좌를
     * 기록한다. 금액이 다르거나 비어 있으면 발급된 계좌를 취소하고 아무것도 기록하지 않는다.
     *
     * @param  array  $validated  검증된 콜백·통보 파라미터
     * @param  Order  $order  대상 주문
     * @param  string  $encData  KCP 암호화 결제 데이터
     * @param  string  $encInfo  KCP 암호화 결제 정보
     * @param  string  $ordrIdxx  주문번호
     * @param  string  $custIp  고객 IP
     * @param  Request  $request  HTTP 요청
     * @param  int  $expectedAmount  주문 저장값에서 산정한 결제 청구액
     * @return RedirectResponse 성공/실패 URL 로 리다이렉트
     */
    private function handleVbankIssued(
        array $validated,
        Order $order,
        string $encData,
        string $encInfo,
        string $ordrIdxx,
        string $custIp,
        Request $request,
        int $expectedAmount,
    ): RedirectResponse {
        $tno = $validated['tno'] ?? '';
        $pgResponse = [];
        $isMobile = $encData === '' || $encInfo === '';

        if ($isMobile) {
            // 모바일 분기는 PG 서버 호출이 없어 계좌 정보의 유일한 출처가 브라우저 평문이다.
            // 그래서 이 콜백이 소유자 인증을 거친 결제 세션에서 비롯됐는지를 먼저 확인한다 —
            // 승인키 발급 시점에 실어 보낸 일회성 nonce 가 param_opt_2 로 되돌아와야 한다
            // 불일치/부재면 상태를 전혀 바꾸지 않고 실패 URL 로 돌린다
            // ("브라우저 입력만으로 상태 변경 금지" 기준).
            if (! $this->mobileVbankStateMatches($order, $validated['param_opt_2'] ?? null)) {
                Log::warning('KCP: mobile vbank callback rejected — session state mismatch', [
                    'ordr_idxx' => $ordrIdxx,
                    'has_echoed_state' => ($validated['param_opt_2'] ?? '') !== '',
                ]);

                return redirect($this->resolveFailUrl([
                    'error' => 'vbank_session_mismatch',
                    'message' => __('sirsoft-pay_nhnkcp::messages.errors.payment_failed'),
                    'orderId' => $ordrIdxx,
                ]));
            }

            // 모바일: 콜백 POST 의 평문 필드를 그대로 응답으로 취급
            // KCP 변종 키 모두 대응 (bankname|bank_name, depositor|account_holder, va_date|vnbank_expire_date)
            $pgResponse = [
                'res_cd' => $validated['res_cd'] ?? self::SUCCESS_RES_CD,
                'res_msg' => $validated['res_msg'] ?? '',
                'tno' => $tno,
                'bankname' => $request->input('bankname') ?? $request->input('bank_name'),
                'account' => $request->input('account'),
                'depositor' => $request->input('depositor') ?? $request->input('account_holder'),
                'va_date' => $request->input('va_date') ?? $request->input('vnbank_expire_date'),
            ];
        } else {
            // PC: CLI 호출. 호출 자체 실패(권한 누락 / pub.key 부재 등)는 발급 여부 미확정이므로 안전하게 실패 처리.
            // 발급 요청에도 청구액(ordr_mony)을 실어 KCP 가 결제창 금액과 다르면 발급을 거절하게 한다.
            try {
                $pgResponse = $this->apiService->approvePayment($encData, $encInfo, $ordrIdxx, $custIp, $expectedAmount);
            } catch (\Exception $e) {
                Log::error('KCP: vbank CLI exception — treating as issuance failure', [
                    'ordr_idxx' => $ordrIdxx,
                    'error' => $e->getMessage(),
                ]);

                // 승인이 성립하지 않았다 — 주문 상태를 바꾸지 않는다. 이 엔드포인트는 비인증
                // 브라우저 POST 라, CLI 실패를 근거로 실패 처리하면 남의 주문번호와 위조 입력만으로
                // 그 주문을 취소시킬 수 있다 (카드 경로와 동일 기준).
                // 정당한 결제 실패는 소유권을 검증하는 close-report 가 기록한다.
                return redirect($this->resolveFailUrl([
                    'error' => 'cli_exception',
                    'message' => __('sirsoft-pay_nhnkcp::messages.errors.payment_failed'),
                    'orderId' => $ordrIdxx,
                ]));
            }
        }

        $pgResCd = $pgResponse['res_cd'] ?? '';
        $bankname = $pgResponse['bankname'] ?? null;
        $account = $pgResponse['account'] ?? null;

        // 발급 성공 판정 — 핵심 필드(은행명/계좌번호) 모두 채워진 경우만 성공.
        // KCP 표준결제창은 결제수단별로 정상 res_cd 가 다르다 (card=0000, vbank=V000).
        // res_cd 단일 비교 대신 발급 데이터 존재 여부로 판정해 응답 변종에 견고하게 대응.
        // - V000 + bankname/account 존재 → 정상 발급 (success)
        // - 0000 + bankname/account 존재 → 정상 발급 (success)
        // - 9502 + bankname/account 부재 → 발급 실패 (fail)
        $hasIssuanceData = ($bankname !== null && $bankname !== '')
            && ($account !== null && $account !== '');

        if (! $hasIssuanceData) {
            $resMsg = $this->decodeKcpMessage($pgResponse['res_msg'] ?? '');
            $effectiveCode = $pgResCd !== '' && $pgResCd !== self::SUCCESS_RES_CD
                ? $pgResCd
                : 'vbank_issuance_incomplete';
            $effectiveMsg = $resMsg !== '' ? $resMsg : __('sirsoft-pay_nhnkcp::messages.errors.vbank_issuance_incomplete');

            Log::warning('KCP: vbank issuance failed', [
                'ordr_idxx' => $ordrIdxx,
                'res_cd' => $pgResCd,
                'res_msg' => $resMsg,
                'has_bankname' => $bankname !== null && $bankname !== '',
                'has_account' => $account !== null && $account !== '',
                'is_mobile' => $isMobile,
            ]);

            // 발급이 성립하지 않았다 — 주문 상태를 바꾸지 않는다.
            // 모바일 분기의 $pgResponse 는 콜백 POST 평문을 그대로 옮긴 값이라 PG 서버 호출이
            // 아예 없고, PC 분기의 CLI 실패도 위조 암호문으로 유도할 수 있다. 어느 쪽이든
            // 비인증 브라우저 입력이므로 이를 근거로 실패 처리하면 주문번호만 아는 제3자가
            // 남의 결제대기 주문을 취소시킬 수 있다 (카드 경로와 동일 기준).
            // 정당한 발급 실패는 소유권을 검증하는 close-report 가 기록한다.
            return redirect($this->resolveFailUrl([
                'error' => $effectiveCode,
                'message' => $effectiveMsg,
                'orderId' => $ordrIdxx,
            ]));
        }

        if ($isMobile) {
            // 모바일 분기에는 발급 금액을 대조할 서버 측 출처가 없다(CLI 호출이 없고 콜백은 평문이다).
            // 금액은 소유자 인증을 거친 승인키 발급 시점에 주문 청구액과 대조한 값으로 KCP 에
            // 등록되고, 이 분기는 결제완료가 아니라 계좌 정보만 기록한다. 결제완료 전이는 입금통보
            // (vbankNotify)가 통보 금액을 주문 청구액과 대조한 뒤에만 일어난다.
            Log::info('KCP: vbank account issued via mobile callback (no CLI)', [
                'ordr_idxx' => $ordrIdxx,
                'tno' => $tno,
                'bankname' => $bankname,
                'account' => $account,
                'va_date' => $pgResponse['va_date'] ?? null,
            ]);
        } else {
            // 거래번호와 발급 금액은 CLI 발급 응답의 값만 쓴다 — 브라우저가 보낸 tno·good_mny 는
            // 근거로도 대체값으로도 쓰지 않는다. 금액이 청구액과 다르거나 응답에 없으면 발급된 계좌를
            // 취소하고, 계좌 정보도 주문 상태도 기록하지 않은 채 실패로 돌려보낸다.
            $pgTno = trim((string) ($pgResponse['tno'] ?? ''));
            $pgIssuedAmount = $this->kcpApprovedAmount($pgResponse);

            if ($pgTno === '' || $pgIssuedAmount !== $expectedAmount) {
                Log::error('KCP: vbank issuance rejected — response amount or tno does not match the order', [
                    'ordr_idxx' => $ordrIdxx,
                    'tno' => $pgTno !== '' ? $pgTno : null,
                    'expected' => $expectedAmount,
                    'response_amount' => $pgIssuedAmount,
                ]);

                // 거래번호가 없으면 취소할 대상을 특정할 수 없다 — autoCancelIfApproved 가 건너뛰고,
                // 계좌를 기록하지 않았으므로 입금통보도 이 주문에 매칭되지 않는다.
                $this->autoCancelIfApproved(
                    $pgTno !== '' ? $pgTno : null,
                    $ordrIdxx,
                    $pgIssuedAmount ?? $expectedAmount,
                    'vbank_amount_mismatch',
                );

                return redirect($this->resolveFailUrl([
                    'error' => $pgTno === '' ? 'vbank_issuance_incomplete' : 'amount_mismatch',
                    'orderId' => $ordrIdxx,
                ]));
            }

            $tno = $pgTno;
            Log::info('KCP: vbank account issued via CLI', [
                'ordr_idxx' => $ordrIdxx,
                'tno' => $tno,
                'bankname' => $bankname,
                'account' => $account,
            ]);
        }

        // 취소 주문 재결제 — 되살리기는 상태 변경이므로 PG 서버가 발급을 확인해 준 PC 분기에서만
        // 수행한다. 모바일 분기는 서버 호출이 없어 발급 증거가 콜백 평문뿐이라, 되살리면
        // 주문번호만 아는 제3자가 남의 취소 주문을 되돌릴 수 있다.
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            if ($isMobile || ! $this->restoreRetryableKcpOrder($order, null)) {
                Log::warning('KCP: cancelled vbank order not restored (no server-side issuance proof)', [
                    'ordr_idxx' => $ordrIdxx,
                    'is_mobile' => $isMobile,
                ]);

                return redirect($this->resolveFailUrl([
                    'error' => 'order_not_retryable',
                    'orderId' => $ordrIdxx,
                ]));
            }

            $order = $order->fresh('payment') ?? $order;
        }

        // 가상계좌 발급 정보를 OrderPayment vbank 전용 컬럼에 저장한다. 주문은 PENDING_PAYMENT 를 유지하고
        // 결제행은 입금대기(WAITING_DEPOSIT)로 둔다 — KG·나이스와 같은 상태값. 결제대기(READY)로 남기면
        // 뒤늦은 결제창 닫힘 보고가 발급된 주문을 결제 전 주문으로 보고 취소할 수 있었다.
        // 저장이 실패하면 부분 저장으로 잘못된 계좌가 노출되지 않도록 실패로 되돌린다. 단
        // failPayment 는 PG 서버가 발급을 확인해 준 PC 분기에서만 부른다 (아래 catch 참조).
        try {
            $expireRaw = $pgResponse['va_date'] ?? null;
            $vbankDueAt = null;
            if ($expireRaw) {
                // KCP va_date: YYYYMMDDHHMMSS (14자리) 또는 YYYYMMDD (8자리) — 한국 시각이다. 앱 시간대(UTC)로
                // 그대로 읽으면 기한이 9시간 늦게 저장된다(화면 표시·만료 판정 모두 어긋남).
                try {
                    $vbankDueAt = (strlen($expireRaw) <= 8
                        ? Carbon::createFromFormat('Ymd', $expireRaw, 'Asia/Seoul')->endOfDay()
                        : Carbon::createFromFormat('YmdHis', $expireRaw, 'Asia/Seoul'))->setTimezone(config('app.timezone', 'UTC'));
                } catch (\Exception) {
                    $vbankDueAt = null;
                }
            }

            $order->payment()->update(array_filter([
                'payment_status' => PaymentStatusEnum::WAITING_DEPOSIT,
                'pg_provider' => self::KCP_PROVIDER,
                'transaction_id' => $tno ?: null,
                'vbank_name' => $bankname,
                'vbank_number' => $account,
                'vbank_holder' => $pgResponse['depositor'] ?? null,
                'vbank_due_at' => $vbankDueAt,
                'vbank_issued_at' => now(),
                'payment_device' => $isMobile ? 'mobile' : 'pc',
                'payment_meta' => [
                    'result_code' => $pgResponse['res_cd'] ?? null,
                    'tno' => $tno ?: null,
                    ...$this->currentCredentialMeta(),
                    'bankname' => $bankname,
                    'bankcode' => $pgResponse['bankcode'] ?? $pgResponse['bank_code'] ?? null,
                    'va_date' => $pgResponse['va_date'] ?? $pgResponse['vnbank_expire_date'] ?? null,
                    'app_time' => $pgResponse['app_time'] ?? null,
                    'pg_response_sanitized' => true,
                    'pg_raw_response' => $this->sanitizePgResponse($pgResponse, self::VBANK_ISSUE_RESPONSE_KEYS),
                ],
            ], fn ($v) => $v !== null));
        } catch (\Exception $e) {
            Log::error('KCP: failed to save vbank info to OrderPayment', [
                'ordr_idxx' => $ordrIdxx,
                'error' => $e->getMessage(),
            ]);

            // PG 서버가 발급을 확인해 준 PC 분기에서만 주문에 반영한다. 모바일 분기의 발급 데이터는
            // 콜백 평문이라 위조할 수 있어, 저장 실패를 근거로 주문을 취소시키는 통로가 된다.
            if (! $isMobile) {
                $failedOrder = $this->orderService->failPayment($order, 'vbank_save_failed', $e->getMessage());
                $this->markKcpPaymentFailureRecord(
                    $failedOrder,
                    'vbank_save_failed',
                    $e->getMessage(),
                    'vbank_save_failed',
                );
            }

            return redirect($this->resolveFailUrl([
                'error' => 'vbank_save_failed',
                'message' => __('sirsoft-pay_nhnkcp::messages.errors.payment_failed'),
                'orderId' => $ordrIdxx,
            ]));
        }

        // 계좌를 받은 순간 고객의 주문은 끝났다 — 입금 전이라도 주문한 장바구니 항목을 정리한다(재고는 입금 때 차감).
        $this->orderService->onVbankAccountIssued($order);

        $this->queueReceiptCookie($ordrIdxx);

        return redirect($this->resolveSuccessUrl($ordrIdxx));
    }

    /**
     * KCP webhook 이 주장한 값이 결제창에서 발급받아 저장한 값과 일치하는지 확인한다.
     *
     * KCP 입금통보에는 별도 서명 필드가 없으므로, 주문번호만 믿지 않고 저장된
     * tno/site_cd/가상계좌/금액을 모두 대조해 위조 통보를 차단한다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $validated  검증된 콜백·통보 파라미터
     * @param  string  $tno  KCP 거래번호
     * @param  int  $amount  통보 금액
     * @return string|null 거절 사유 코드 (통과 시 null)
     */
    private function validateVbankNotifyContext(Order $order, array $validated, string $tno, int $amount): ?string
    {
        $payment = $order->payment;
        if (! $payment) {
            return 'payment_not_found';
        }

        // 취소된 주문의 입금통보는 받지 않는다 (코어 P4 와 같은 가드).
        //
        // 고객이 취소한 뒤에 입금해도 PG 통보는 도착한다. 통과시키면 `CANCELLED` 주문이
        // 결제완료로 되살아나 재고·마일리지가 재차감된다. 아래 결제상태 가드만으로는
        // 부족하다 — 미입금 취소가 결제행을 `waiting_deposit` 그대로 두던 시절의 행이
        // 그 가드를 통과하기 때문이다. 입금된 돈은 환불 절차로 돌려주어야 하는 별개 사건이다.
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            return 'order_cancelled';
        }

        if (! $payment->isVirtualAccount()) {
            return 'payment_method_not_vbank';
        }

        $meta = $payment->payment_meta ?? [];
        $storedTno = trim((string) ($payment->transaction_id ?: ($meta['tno'] ?? '')));
        if ($storedTno === '' || ! hash_equals($storedTno, $tno)) {
            return 'tno_mismatch';
        }

        $storedSiteCd = trim((string) ($meta['site_cd'] ?? ''));
        $expectedSiteCd = $storedSiteCd !== '' ? $storedSiteCd : trim($this->apiService->getSiteCd());
        $receivedSiteCd = trim((string) ($validated['site_cd'] ?? ''));
        if ($expectedSiteCd === '' || $receivedSiteCd === '' || ! hash_equals($expectedSiteCd, $receivedSiteCd)) {
            return 'site_cd_mismatch';
        }

        $storedAccount = trim((string) ($payment->vbank_number ?? ''));
        $receivedAccount = trim((string) ($validated['account'] ?? ''));
        if ($storedAccount === '' || $receivedAccount === '' || ! hash_equals($storedAccount, $receivedAccount)) {
            return 'account_mismatch';
        }

        $expectedAmount = $this->expectedVbankNotifyAmount($order);
        if ($expectedAmount === null) {
            return 'invalid_payment_currency';
        }

        if ($amount !== $expectedAmount) {
            return 'amount_mismatch';
        }

        return null;
    }

    /**
     * 입금통보에서 기대하는 금액을 구합니다. (결제완료면 실결제액, 아니면 결제 예정액)
     *
     * @param  Order  $order  대상 주문
     * @return int|null 기대 금액 (산정 불가 시 null)
     */
    private function expectedVbankNotifyAmount(Order $order): ?int
    {
        $payment = $order->payment;
        $paidAmount = (int) round((float) ($payment?->paid_amount_local ?? 0));

        if (($payment?->isPaid() ?? false) && $paidAmount > 0) {
            return $paidAmount;
        }

        return $this->resolveExpectedPaymentPriceOrNull($order, 'vbank_notify', [
            'transaction_id' => $payment?->transaction_id,
        ]);
    }

    /**
     * 결제 당시 KCP 상점 설정을 payment_meta 에 남겨 이후 환불/통보 검증에 사용한다.
     *
     * site_key 같은 비밀값은 저장하지 않고, 공개 식별자인 site_cd 와 test/live 모드만 저장한다.
     *
     * @param  string  $siteCd  결제 당시 사이트 코드 (빈 값이면 현재 설정)
     * @return array{site_cd: string, is_test_mode: bool}
     */
    private function currentCredentialMeta(string $siteCd = ''): array
    {
        return [
            'site_cd' => $siteCd !== '' ? $siteCd : $this->apiService->getSiteCd(),
            'is_test_mode' => $this->apiService->isTestMode(),
        ];
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
     * Approve 가 이미 발생한 후 후속 처리 실패 시 PG 측 cancel 을 시도.
     *
     * KCP CLI 승인이 완료되었으나 우리 서버에서 completePayment 가 실패한 경우
     * 사용자 카드/계좌는 PG 측에서 승인 상태로 잔존 → 환불 불가 위험. 본 메서드는
     * cancelPayment API 로 PG 잔존 승인을 즉시 해제해 사용자 보호를 확보한다.
     *
     * cancel 자체가 실패해도 사용자 응답 흐름은 막지 않음 — 로깅만 수행하고
     * 운영자가 KCP 가맹점 관리자에서 수동 처리하도록 신호.
     */
    /**
     * KCP 승인이 실제로 발생했는지 판정합니다.
     *
     * tno 는 승인 응답이 성공한 뒤에만 채워진다. 비어 있으면 승인 전에 흐름이 끊긴 것이고,
     * 그 시점까지의 입력은 전부 비인증 브라우저가 보낸 값이라 주문 상태 변경의 근거가 될 수 없다.
     *
     * @param  string|null  $approvedTno  승인 확정 시 기록되는 거래번호
     * @return bool 승인이 발생했으면 true
     */
    private function hasApproval(?string $approvedTno): bool
    {
        return $approvedTno !== null && $approvedTno !== '';
    }

    /**
     * KCP 승인이 이미 일어난 뒤의 후속 실패에서 승인분을 조용히 취소합니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  string|null  $tno  승인된 KCP 거래번호 (없으면 아무것도 하지 않음)
     * @param  string  $ordrIdxx  주문번호
     * @param  int  $cancelAmt  취소 금액
     * @param  string  $reason  취소 사유 코드 (로그 문맥)
     * @return void 반환값 없음
     */
    private function autoCancelIfApproved(
        ?string $tno,
        string $ordrIdxx,
        int $cancelAmt,
        string $reason,
    ): void {
        if ($tno === null || $tno === '' || $cancelAmt <= 0) {
            return;
        }

        try {
            $result = $this->apiService->cancelPayment(
                $tno,
                $ordrIdxx,
                $cancelAmt,
                'auto-cancel: '.$reason,
            );

            Log::warning('KCP: auto-cancel after post-approve failure', [
                'tno' => $tno,
                'ordr_idxx' => $ordrIdxx,
                'amount' => $cancelAmt,
                'reason' => $reason,
                'res_cd' => $result['res_cd'] ?? null,
                'res_msg' => $result['res_msg'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('KCP: auto-cancel FAILED — manual reconciliation required', [
                'tno' => $tno,
                'ordr_idxx' => $ordrIdxx,
                'amount' => $cancelAmt,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 결제 실패 리다이렉트 URL 을 만듭니다. (오류 코드가 있으면 메시지 앞에 붙인다)
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

        // error 코드와 message 가 모두 있으면 message 앞에 '[코드] ' 자동 prefix.
        // 체크아웃 페이지에서 사용자가 오류 종류를 즉시 식별할 수 있도록 한다.
        // 예: error=9502, message=연동 모듈 호출 오류 → message=[9502] 연동 모듈 호출 오류
        if (! empty($queryParams['error']) && ! empty($queryParams['message'])) {
            $queryParams['message'] = sprintf('[%s] %s', $queryParams['error'], $queryParams['message']);
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl.$separator.$query;
    }

    /**
     * 주문 통화가 KRW 인지 판정합니다. (KCP 는 KRW 전용)
     *
     * @param  Order  $order  대상 주문
     * @return bool KRW 이면 true
     */
    private function isKrwOrder(Order $order): bool
    {
        return $this->orderCurrency($order) === 'KRW';
    }

    /**
     * 주문 통화를 대문자로 정규화해 돌려줍니다. (빈 값이면 KRW)
     *
     * @param  Order  $order  대상 주문
     * @return string 통화 코드
     */
    private function orderCurrency(Order $order): string
    {
        $currency = strtoupper(trim((string) ($order->currency ?? 'KRW')));

        return $currency !== '' ? $currency : 'KRW';
    }

    /**
     * KCP 모바일 게이트웨이는 EUC-KR(CP949) 인코딩으로 res_msg를 POST.
     * 유효한 UTF-8이 아니면 CP949로 간주하고 변환한다.
     *
     * @param  string  $msg  KCP 결과 메시지 원문
     * @return string UTF-8 메시지
     */
    private function decodeKcpMessage(string $msg): string
    {
        if ($msg === '' || mb_check_encoding($msg, 'UTF-8')) {
            return $msg;
        }

        $converted = mb_convert_encoding($msg, 'UTF-8', 'CP949');

        return $converted !== false ? $converted : $msg;
    }
}
