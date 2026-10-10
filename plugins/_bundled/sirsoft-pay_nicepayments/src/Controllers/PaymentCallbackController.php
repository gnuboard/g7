<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use App\Support\SiteUrl;
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
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\DepositAfterCancelRecorder;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Plugins\Sirsoft\PayNicepayments\Concerns\HandlesClaimPaymentCallbacks;
use Plugins\Sirsoft\PayNicepayments\Concerns\IssuesReceiptCookie;
use Plugins\Sirsoft\PayNicepayments\Concerns\PreventsReplayCallback;
use Plugins\Sirsoft\PayNicepayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNicepayments\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayNicepayments\Concerns\SerializesPaymentCallbacks;
use Plugins\Sirsoft\PayNicepayments\Concerns\VerifiesOrderOwnership;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\SignDataRequest;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Support\ShopRedirectUrl;

/**
 * 나이스페이먼츠 결제 콜백 컨트롤러
 *
 * 나이스페이먼츠 결제는 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 인증 토큰 전달 → authCallback()
 *  2단계: 서버가 NextAppURL로 최종 승인 요청 → NicePaymentsApiService::authorizePayment()
 */
class PaymentCallbackController
{
    use HandlesClaimPaymentCallbacks;
    use IssuesReceiptCookie;
    use PreventsReplayCallback;
    use RecordsPaymentWindowClosure;
    use ResolvesEasyPayDisplay;
    use SanitizesPgResponse;
    use SerializesPaymentCallbacks;
    use VerifiesOrderOwnership;

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

    /**
     * @param  OrderProcessingService  $orderService  주문 결제 처리 서비스
     * @param  PluginSettingsService  $pluginSettingsService  플러그인 설정 조회 서비스
     * @param  NicePaymentsApiService  $apiService  나이스페이먼츠 API 서비스
     * @param  CacheInterface  $cache  콜백 재전송 감지용 캐시
     * @param  ClaimPaymentRepositoryInterface  $claimPaymentRepository  클레임 결제 Repository
     * @param  GuestOrderAuthService  $guestOrderAuthService  비회원 조회 토큰 검증 서비스
     */
    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NicePaymentsApiService $apiService,
        private readonly CacheInterface $cache,
        private readonly ClaimPaymentRepositoryInterface $claimPaymentRepository,
        private readonly GuestOrderAuthService $guestOrderAuthService,
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

            // 클레임 추가결제(CP 채번)는 체크아웃이 아니라 신청서 상세로 돌려보낸다. 인증 단계
            // 실패는 사용자 취소와 구분할 수 없어 주문 경로처럼 오류 안내 없이 복귀시킨다.
            if ($moid !== '' && ClaimPayment::isClaimPaymentNumber($moid)) {
                $claimPayment = $this->findClaimPayment($moid);

                if ($claimPayment) {
                    return redirect(SiteUrl::absolute(ClaimPaymentRedirect::cancelled($claimPayment)));
                }
            }

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

            // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 완료 화면으로 보내면 결제번호를
            // 주문번호로 읽어 빈 화면이 뜨고, 영수증 쿠키도 존재하지 않는 주문 앞으로 발급된다.
            if (ClaimPayment::isClaimPaymentNumber($moid)) {
                $claimPayment = $this->findClaimPayment($moid);

                if ($claimPayment !== null) {
                    return redirect(SiteUrl::absolute(ClaimPaymentRedirect::success($claimPayment)));
                }

                // 결제 건을 못 찾아도 주문 경로로 흘려보내지 않는다 — CP 번호를 주문번호로 실은
                // 완료 화면과 영수증 쿠키가 나가는 것이 애초에 이 분기가 막으려던 결함이다.
                Log::error('NicePayments: claim payment not found on duplicate authCallback', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'claim_payment_not_found']));
            }

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

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 주문 분기를 태우면 order_not_found 로 끝난다.
        // 승인은 모듈 위임(필터 발화 → 이 플러그인의 승인 리스너)이고, 승인 금액 불일치는 승인이
        // 확정된 뒤의 거절이므로 망취소로 보상한다.
        if (ClaimPayment::isClaimPaymentNumber($moid)) {
            $claimPayment = $this->findClaimPayment($moid);

            if (! $claimPayment) {
                Log::error('NicePayments: claim payment not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'claim_payment_not_found', 'orderId' => $moid]));
            }

            // 주문 경로의 행 잠금에 대응하는 직렬화 락을 결제번호(CP) 단위로 건다 — 형제 플러그인
            // (kginicis·nhnkcp)의 CP 분기와 같은 규약이다. 같은 콜백이 겹쳐 들어오면 두 요청이 모두
            // 결제 대기를 보고 각자 승인을 부르고, 뒤 요청은 PG 의 중복 거절을 받아 이미 결제완료된
            // 행에 실패 코드를 남긴다. 락 대기 초과는 500 이 아니라 신청서 상세 복귀로 끝난다.
            // 승인 뒤 거절의 보상은 보상 클로저 대신 모듈의 `claim_payment.void_approval` 필터(이
            // 플러그인 승인 리스너의 `voidApproval()`)가 맡는다 — API 승인 경로와 같은 한 구현이다.
            return $this->approveClaimPaymentSerialized('authCallback', $claimPayment, $validated);
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

                // 주문 상태는 바꾸지 않는다. 이 콜백은 비인증 브라우저 POST 이고,
                // 서명(AuthToken+MID+Amt)이 주문번호(Moid)를 묶지 않아 자기 결제창에서 얻은 인증값에
                // 남의 주문번호를 붙여 보낼 수 있다. 승인 실패를 근거로 실패 처리하면 타인의 결제대기
                // 주문이 취소된다. 정당한 결제 실패·창 닫힘은 소유자를 확인하는 close-report 가 기록하고,
                // 남은 결제대기 주문은 같은 주문번호로 재결제하거나 만료 정리 잡이 정리한다.

                // authorize 실패 — NextAppURL 호출이 거부됐어도 PG 측에 잔존 승인이 있을
                // 수 있어 net cancel 호출. NicePay 가 미승인 상태이면 net cancel API 가
                // safe-no-op 으로 동작하므로 모든 실패 경로에서 호출해 안전.
                $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

                return redirect($this->resolveFailUrl([
                    'error' => 'authorize_failed',
                    'orderId' => $moid,
                ]));
            }

            // 승인 응답이 돌려준 주문번호·금액이 이 콜백의 주문과 같아야 반영한다.
            // 서명이 주문번호를 묶지 않으므로, 다른 주문의 인증값으로 받은 승인을 이 주문에 붙이지 못하게
            // PG 가 확인해 준 값과 대조한다. 어긋나거나 승인 금액이 없으면 그 승인을 망취소하고 주문은
            // 건드리지 않는다.
            if (! $this->authorizeResponseMatchesOrder($pgResponse, $moid, $amt)) {
                Log::warning('NicePayments: authorize response does not match callback order; net cancelling', [
                    'moid' => $moid,
                    'pg_moid' => $pgResponse['Moid'] ?? null,
                    'amt' => $amt,
                    'pg_amt' => $pgResponse['Amt'] ?? null,
                    'ip' => $request->ip(),
                ]);

                $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

                return redirect($this->resolveFailUrl(['error' => 'authorize_failed', 'orderId' => $moid]));
            }

            // 이후 반영의 금액 근거는 PG 가 승인해 준 금액이다 — 브라우저가 보낸 콜백 Amt 가 아니다.
            // 위 대조로 승인 금액이 있고 콜백 금액과 같음을 확인했다.
            $approvedAmt = (int) NicePaymentsApiService::parseAmount($pgResponse['Amt'] ?? null);

            // 5단계: 결제 수단별 처리
            $payMethod = $pgResponse['PayMethod'] ?? '';
            $isEscrow = ($pgResponse['EscrowYN'] ?? 'N') === 'Y'
                || $this->apiService->isEscrowEnabled();

            if ($payMethod === 'VBANK') {
                $issueStatus = $this->recordVbankIssueWithLock($moid, $pgResponse, $resultCode, $payMethod, $isEscrow, $txTid, $approvedAmt);
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

                    // 금액·통화 사유는 사유 그대로 안내하고, 그 밖의 사유(결제수단 불일치·결제 불가 상태)는
                    // 결제 불가로 안내한다.
                    $issueError = in_array($issueStatus, ['amount_mismatch', 'invalid_payment_currency'], true)
                        ? $issueStatus
                        : 'order_not_payable';

                    return redirect($this->resolveFailUrl(['error' => $issueError, 'orderId' => $moid]));
                }

                Log::info('NicePayments: vbank account issued', [
                    'moid' => $moid,
                    'vbank_name' => $pgResponse['VbankBankName'] ?? null,
                    'vbank_number_last4' => $this->lastFour((string) ($pgResponse['VbankNum'] ?? '')),
                ]);
            } else {
                $effectiveTid = (string) ($pgResponse['TID'] ?? $txTid);
                $completionStatus = $this->completeAuthorizedPaymentWithLock($moid, $pgResponse, $txTid, $approvedAmt, $isEscrow, $request, $validated);

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

            // 승인은 망취소하고 주문 상태는 바꾸지 않는다 — 금액 불일치도 브라우저가 고른 주문번호로
            // 만들어낼 수 있어 실패 처리 근거가 되지 못한다.
            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (InvalidArgumentException $e) {
            Log::error('NicePayments: invalid payment currency during authorization', [
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            // 승인은 망취소하고 주문 상태는 바꾸지 않는다 (위 금액 불일치와 같은 이유).
            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

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

            // 승인은 망취소하고 주문 상태는 바꾸지 않는다. 승인 예외는 NextAppURL 을 나이스페이 밖으로
            // 바꾸는 것만으로 누구나 일으킬 수 있어, 실패 처리 근거가 되지 못한다.
            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'orderId' => $moid,
            ]));
        }
    }

    /**
     * 승인 응답이 돌려준 주문번호·금액이 콜백의 주문번호·금액과 같은지 확인합니다.
     *
     * 나이스페이 승인 응답은 주문번호(Moid)와 금액(Amt)을 돌려준다. 콜백 서명은 주문번호를 묶지 않으므로
     * 이 대조가 "승인된 결제가 바로 이 주문의 것"임을 보장하는 근거다. 주문번호가 응답에 없으면(구버전 응답 등)
     * 그 항목은 대조하지 않는다. 금액은 결제 반영의 근거이므로 응답에 없거나 숫자가 아니면 불일치로 본다 —
     * 확인된 승인 금액은 이후 completePayment·가상계좌 발급 기록이 주문 청구액과 다시 대조한다.
     *
     * @param  array<string, mixed>  $pgResponse  승인 API 응답
     * @param  string  $moid  콜백 주문번호
     * @param  int  $amt  콜백 금액 (서명에 묶인 값)
     * @return bool 일치하면 true
     */
    private function authorizeResponseMatchesOrder(array $pgResponse, string $moid, int $amt): bool
    {
        $pgMoid = $pgResponse['Moid'] ?? null;
        if ($pgMoid !== null && $pgMoid !== '' && ! hash_equals((string) $pgMoid, $moid)) {
            return false;
        }

        $pgAmt = NicePaymentsApiService::parseAmount($pgResponse['Amt'] ?? null);

        return $pgAmt !== null && $pgAmt === $amt;
    }

    /**
     * 주문·결제 행을 잠근 채 가상계좌 발급을 기록합니다. (결제 전 상태에서만)
     *
     * 주문의 결제수단이 가상계좌이고, PG 가 발급해 준 금액이 주문 청구액과 같을 때만 기록한다. 그렇지 않으면
     * 무시 사유를 돌려주고, 호출 측이 발급 승인을 망취소한다.
     *
     * @param  string  $moid  주문번호
     * @param  array  $pgResponse  PG 승인 응답
     * @param  string  $resultCode  PG 결과 코드
     * @param  string  $payMethod  PG 결제수단
     * @param  bool  $isEscrow  에스크로 결제 여부
     * @param  string  $txTid  PG 임시 거래번호
     * @param  int  $approvedAmt  PG 승인 응답의 발급 금액
     * @return string 처리 결과 코드 (recorded / 무시 사유)
     */
    private function recordVbankIssueWithLock(
        string $moid,
        array $pgResponse,
        string $resultCode,
        string $payMethod,
        bool $isEscrow,
        string $txTid,
        int $approvedAmt,
    ): string {
        return DB::transaction(function () use ($moid, $pgResponse, $resultCode, $payMethod, $isEscrow, $txTid, $approvedAmt): string {
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

            // 가상계좌로 주문하지 않은 결제 건에는 계좌 발급을 붙이지 않는다 — 입금통보도 결제수단이
            // 가상계좌인 건만 받으므로, 붙여 두면 입금을 확정할 경로가 없는 계좌가 남는다.
            if (! $payment->isVirtualAccount()) {
                return 'payment_method_not_vbank';
            }

            // 발급 금액은 PG 승인 응답의 금액으로 주문 청구액과 대조한다. 계좌는 이 금액으로 발급되므로
            // 청구액과 다르면 입금이 끝나도 주문 금액을 받은 것이 아니다.
            $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($lockedOrder, 'vbank_issue', [
                'moid' => $moid,
                'approved_amt' => $approvedAmt,
            ]);
            if ($expectedAmount === null) {
                return 'invalid_payment_currency';
            }

            if ($approvedAmt !== $expectedAmount) {
                Log::warning('NicePayments: vbank issue amount does not match order charge', [
                    'moid' => $moid,
                    'approved_amt' => $approvedAmt,
                    'expected_amt' => $expectedAmount,
                ]);

                return 'amount_mismatch';
            }

            $vbankDueAt = null;
            if (isset($pgResponse['VbankExpDate'])) {
                $dateStr = $pgResponse['VbankExpDate'].($pgResponse['VbankExpTime'] ?? '235959');
                // 입금 기한은 한국 시각이다 — 앱 시간대(UTC)로 그대로 읽으면 9시간 늦게 저장된다.
                // 형식이 다른 기한이 와도 발급 기록은 이어간다(Carbon 형식 예외가 결제 통화 오류 분기로 잡혀 발급이 끊기던 것 방지).
                try {
                    $vbankDueAt = Carbon::createFromFormat('YmdHis', $dateStr, 'Asia/Seoul')->setTimezone(config('app.timezone', 'UTC'));
                } catch (\Exception) {
                    $vbankDueAt = null;
                }
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

            // 계좌를 받은 순간 고객의 주문은 끝났다 — 입금 전이라도 주문한 장바구니 항목을 정리한다(재고는 입금 때 차감).
            $this->orderService->onVbankAccountIssued($lockedOrder);

            return 'recorded';
        });
    }

    /**
     * 주문·결제 행을 잠근 채 승인된 결제를 완료 처리합니다. (결제 전 상태에서만)
     *
     * @param  string  $moid  주문번호
     * @param  array  $pgResponse  PG 승인 응답
     * @param  string  $txTid  PG 임시 거래번호
     * @param  int  $approvedAmt  PG 승인 응답의 승인 금액 (completePayment 가 주문 청구액과 대조하는 값)
     * @param  bool  $isEscrow  에스크로 결제 여부
     * @param  Request  $request  HTTP 요청
     * @param  array  $callbackPayload  검증된 콜백 파라미터 (결제 메타 보관용)
     * @return string 처리 결과 코드 (completed / 무시 사유)
     */
    private function completeAuthorizedPaymentWithLock(
        string $moid,
        array $pgResponse,
        string $txTid,
        int $approvedAmt,
        bool $isEscrow,
        Request $request,
        array $callbackPayload,
    ): string {
        return DB::transaction(function () use ($moid, $pgResponse, $txTid, $approvedAmt, $isEscrow, $request, $callbackPayload): string {
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
            ], $approvedAmt);

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

        // 클레임 추가결제(CP 채번)는 주문이 아니다 — 결제 건 기준으로 같은 강도의 검증을 적용한다.
        if (ClaimPayment::isClaimPaymentNumber($moid)) {
            $claimError = $this->validateClaimSignDataContext($request, $moid, $amt);

            if ($claimError !== null) {
                return $claimError;
            }

            $mid = trim($this->apiService->getMid());

            if ($mid === '') {
                Log::error('NicePayments: claim SignData rejected because MID is not configured', [
                    'payment_number' => $moid,
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
            // 실패 주문을 결제대기로 되살리는 것은 주문 상태 변경이다 — 주문 소유자(회원 본인 로그인 /
            // 비회원 조회 토큰)만 할 수 있다. 위의 주문자 연락처 대조는 제3자도 통과할 수
            // 있어 서명 발급(상태 불변)에만 쓰고, 상태를 바꾸는 복구의 근거로 삼지 않는다.
            if (! $this->requestOwnsOrder($request, $order, $this->guestOrderAuthService)) {
                Log::warning('NicePayments: SignData retry restore rejected — order ownership not verified', [
                    'moid' => $moid,
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 403);
            }

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

    /**
     * SignData 발급 요청의 속도 제한 키를 만듭니다. (IP + 주문번호)
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $moid  주문번호
     * @return string 속도 제한 키
     */
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
     * 공식 spec 에 입금통보 Signature 가 없어 통보 자체의 서명 검증은 할 수 없으며, 대신:
     *   - 발송 IP 화이트리스트 (VbankNotifyIpWhitelist 미들웨어)
     *   - TID/MID/계좌번호/Amt 가 우리 DB 의 발급 정보·주문 청구액과 일치하는지 비교
     *   - 가맹점 키로 서명한 거래 조회로 PG 에 입금(승인) 여부를 직접 확인
     *   - 동일 TID 중복 처리 방지 (행 잠금)
     *   네 단계로 위변조/재처리 방어.
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

            // 입금통보에는 위변조를 확인할 서명이 없다(나이스페이 통보 규격). 결제완료로 바꾸기 전에 이 통보가
            // 가리키는 거래가 실제로 입금됐는지 가맹점 키로 서명한 거래 조회로 PG 에 직접 확인한다. 같은
            // 거래번호로 이미 반영된 재통보와, 발급 정보와 어긋나 어차피 거절될 통보는 PG 를 부르지 않는다.
            // 조회는 잠금 밖에서 한다 — 외부 호출 동안 결제 행을 잡아 두지 않는다. 잠금 안에서 같은 대조를
            // 한 번 더 한다.
            $currentPayment = $order->payment()->first();
            $isReplay = $currentPayment !== null
                && $currentPayment->isPaid()
                && hash_equals((string) $currentPayment->transaction_id, $tid);

            if (! $isReplay) {
                $precheckError = $currentPayment === null
                    ? 'payment_not_found'
                    : $this->validateVbankNotifyContext($order, $currentPayment, $validated, $tid, $amt);

                // 취소된 주문의 입금(P-D1 · Q11) — 주문은 되살리지 않는다. 통보에는 서명이 없으므로 PG 거래 조회로 입금이
                // 확인될 때만 기록·운영자 알림을 남기고 OK 로 재통보를 멈춘다. 확인되지 않으면 종전대로 FAIL.
                $issuedTid = trim((string) ($currentPayment?->transaction_id ?: (($currentPayment?->payment_meta ?? [])['vbank_tid'] ?? '')));
                if ($precheckError === 'order_cancelled'
                    && $issuedTid !== '' && hash_equals($issuedTid, $tid)
                    && $this->confirmVbankDepositWithPg($currentPayment, $tid, $amt) === null) {
                    app(DepositAfterCancelRecorder::class)->record($order, (float) $amt, 'nicepayments', $tid);

                    return response('OK', 200)->header('Content-Type', 'text/plain');
                }

                if ($precheckError !== null) {
                    Log::warning('NicePayments: vbank notify context mismatch', [
                        'reason' => $precheckError,
                        'tid' => $tid,
                        'moid' => $moid,
                        'amt' => $amt,
                    ]);

                    return response('FAIL', 200)->header('Content-Type', 'text/plain');
                }

                $confirmError = $this->confirmVbankDepositWithPg($currentPayment, $tid, $amt);

                if ($confirmError !== null) {
                    Log::warning('NicePayments: vbank notify not confirmed by transaction query', [
                        'reason' => $confirmError,
                        'tid' => $tid,
                        'moid' => $moid,
                        'amt' => $amt,
                    ]);

                    return response('FAIL', 200)->header('Content-Type', 'text/plain');
                }
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

    /**
     * 입금통보가 이 주문에서 발급한 가상계좌 결제 건의 것인지 대조합니다. (TID·계좌·금액)
     *
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  주문 결제 레코드
     * @param  array  $validated  검증된 콜백·통보 파라미터
     * @param  string  $tid  PG 거래번호
     * @param  int  $amount  통보 금액
     * @return string|null 거절 사유 코드 (통과 시 null)
     */
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

        // 취소된 주문의 입금통보는 받지 않는다 (코어 P4 와 같은 가드).
        //
        // 고객이 취소한 뒤에 입금해도 PG 통보는 도착한다. 통과시키면 `CANCELLED` 주문이
        // 결제완료로 되살아나 재고·마일리지가 재차감된다. 아래 결제상태 가드만으로는
        // 부족하다 — 미입금 취소가 결제행을 `waiting_deposit` 그대로 두던 시절의 행이
        // 그 가드를 통과하기 때문이다. 입금된 돈은 환불 절차로 돌려주어야 하는 별개 사건이다.
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            return 'order_cancelled';
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

    /**
     * 입금통보가 가리키는 거래가 PG 에서 실제로 입금(승인)됐는지 거래 조회로 확인합니다.
     *
     * 입금통보는 서명이 없어 통보 내용만으로는 PG 가 보낸 것인지 알 수 없다. 가맹점 키로 서명한 거래
     * 조회는 우리가 PG 에 직접 묻는 것이므로, 그 응답이 "이 거래번호가 승인됐다" 고 답할 때만 입금을
     * 인정한다. 가상계좌는 입금이 끝나야 승인으로 조회되고, 입금 전에는 거래 없음으로 조회된다.
     * 조회는 계좌를 발급한 당시의 MID·모드로 서명한다 (운영자가 이후 설정을 바꿔도 같은 거래를 찾도록).
     *
     * @param  OrderPayment  $payment  주문 결제 레코드 (발급 당시 MID·모드 보관)
     * @param  string  $tid  통보 거래번호
     * @param  int  $amount  통보 금액 (주문 청구액과 대조를 마친 값)
     * @return string|null 거절 사유 코드 (확인되면 null)
     */
    private function confirmVbankDepositWithPg(OrderPayment $payment, string $tid, int $amount): ?string
    {
        $meta = $payment->payment_meta ?? [];
        $storedMid = trim((string) ($meta['mid'] ?? ''));
        if ($storedMid !== '' && array_key_exists('is_test_mode', $meta)) {
            $this->apiService->useStoredCredentials((bool) $meta['is_test_mode'], $storedMid);
        }

        try {
            $result = $this->apiService->queryTransaction($tid);
        } catch (\Throwable $e) {
            // 조회 실패는 입금을 확인하지 못한 것이다 — FAIL 로 응답하면 PG 가 통보를 다시 보낸다.
            Log::warning('NicePayments: vbank deposit transaction query failed', [
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return 'pg_query_failed';
        }

        if ((string) ($result['ResultCode'] ?? '') !== '0000') {
            return 'pg_query_failed';
        }

        $queriedTid = trim((string) ($result['TID'] ?? ''));
        if ($queriedTid !== '' && ! hash_equals($queriedTid, $tid)) {
            return 'pg_tid_mismatch';
        }

        if ((string) ($result['Status'] ?? '') !== '0') {
            return 'pg_not_deposited';
        }

        // 조회 응답이 금액을 실어 오면 그 금액도 통보 금액(= 주문 청구액)과 같아야 한다.
        if (array_key_exists('Amt', $result)) {
            $queriedAmount = NicePaymentsApiService::parseAmount($result['Amt']);
            if ($queriedAmount === null || $queriedAmount !== $amount) {
                return 'pg_amount_mismatch';
            }
        }

        return null;
    }

    /**
     * 나이스 결제창 실패로 취소된 주문을 재결제할 수 있게 결제 대기로 되돌립니다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  결제 레코드
     * @return bool 되돌렸으면 true
     */
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

    /**
     * 나이스 결제창 단계 실패(승인 흔적 없음)로 취소된 주문인지 판정합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderPayment  $payment  결제 레코드
     * @return bool 재결제 가능한 실패이면 true
     */
    private function isRetryableNicepayFailure(Order $order, OrderPayment $payment): bool
    {
        $paymentMeta = $payment->payment_meta ?? [];
        $failureCode = (string) ($paymentMeta['failure_code'] ?? $order->order_meta['payment_failure_code'] ?? '');

        return $order->order_status === OrderStatusEnum::CANCELLED
            && $payment->pg_provider === 'nicepayments'
            // 결제창 닫힘은 결제 취소(CANCELLED)로, 이전 판에서 기록된 닫힘은 결제 실패(FAILED)로 남아 있다.
            && in_array($payment->payment_status, [PaymentStatusEnum::FAILED, PaymentStatusEnum::CANCELLED], true)
            && ($paymentMeta['failure_source'] ?? null) === 'nicepayments'
            && $failureCode !== 'AMOUNT_MISMATCH'
            && blank($payment->transaction_id)
            && blank($payment->card_approval_number)
            && $payment->paid_at === null;
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
        $url = ShopRedirectUrl::resolve($urlTemplate, ['{orderId}' => $orderId]);

        return SiteUrl::absolute($url);
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
        $baseUrl = ShopRedirectUrl::resolve($settings['redirect_fail_url'] ?? ShopRedirectUrl::DEFAULT_FAIL_URL);

        if (! empty($queryParams)) {
            $query = http_build_query(array_filter($queryParams));
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $baseUrl = $baseUrl.$separator.$query;
        }

        return SiteUrl::absolute($baseUrl);
    }

    /**
     * 입금통보 1건의 어드민 표시용 entry 생성.
     *
     * 어드민 패널에서 timeline 형태로 보여줄 핵심 필드만 추려둠. raw 는 전체 보존.
     *
     * @param  string  $resultCode  PG 결과 코드
     * @param  int  $amt  금액
     * @param  string  $type  통보 종류 (입금·입금취소 등)
     * @param  array  $validated  검증된 콜백·통보 파라미터
     * @return array 어드민 표시용 통보 항목
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
     *
     * @param  string  $moid  주문번호
     * @param  string  $tid  PG 거래번호
     * @param  int  $amt  금액
     * @param  string  $resultCode  PG 결과 코드
     * @param  string  $type  통보 종류 (입금·입금취소 등)
     * @param  array  $validated  검증된 콜백·통보 파라미터
     * @return void 반환값 없음
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
     *
     * @param  array  $notifications  누적 입금통보 목록
     * @return array 요약 {count, last_type, last_result_code, deposited_at, cancelled_at}
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

    /**
     * 값의 숫자만 남겨 마지막 네 자리를 돌려줍니다. (계좌번호 마스킹 표시용)
     *
     * @param  string  $value  원본 값 (계좌번호 등)
     * @return string|null 마지막 네 자리 (숫자가 없으면 null)
     */
    private function lastFour(string $value): ?string
    {
        $digits = $this->digitsOnly($value);
        if ($digits === '') {
            return null;
        }

        return substr($digits, -4);
    }
}
