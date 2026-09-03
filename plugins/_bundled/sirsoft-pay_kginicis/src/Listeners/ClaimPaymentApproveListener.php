<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

/**
 * 클레임 추가결제 승인 리스너
 *
 * 이커머스 모듈의 `claim_payment.approve` 필터를 구독해 KG 이니시스 승인 API 로
 * 클레임 결제 건(CP 채번)을 승인합니다. 계약은 이커머스 모듈
 * `docs/api/claim-payments.md` "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * 콜백 파라미터의 형태로 PC(authToken + authUrl)/모바일(P_TID + P_REQ_URL)을 구분한다.
 * 두 경로 모두 승인 URL 은 idc_name 화이트리스트로 검증한다 — 이 필터는 클라이언트가
 * 호출하는 승인 API 경로에서도 발화하므로, 컨트롤러 검증에 기대지 않고 여기서 다시 막는다
 * (SSRF 방어).
 */
class ClaimPaymentApproveListener implements HookListenerInterface
{
    use SanitizesPgResponse;

    private const PG_PROVIDER_ID = 'kginicis';

    /**
     * 클레임 승인 응답 보관 화이트리스트 (PC + 모바일 승인 응답 공용)
     */
    private const CLAIM_AUTH_RESPONSE_KEYS = [
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
        'goodName',
        'goodsName',
        'currency',
        'currencyCode',
        'P_STATUS',
        'P_RMESG1',
        'P_TID',
        'P_AMT',
        'P_TYPE',
        'P_OID',
        'P_MID',
        'P_AUTH_DT',
        'P_AUTH_NO',
        'P_CARD_NUM',
        'P_CARD_ISSUER_CODE',
        'P_FN_NM',
    ];

    /**
     * CBT(일본 엔화) 승인 응답 보관 화이트리스트
     */
    private const CLAIM_CBT_RESPONSE_KEYS = [
        'resultCode',
        'resultMsg',
        'code',
        'message',
        'tid',
        'transactionId',
        'paymethod',
        'payMethod',
        'currency',
        'currencyCd',
        'currencyCode',
        'approve',
        'applDate',
        'applTime',
        'cardCode',
        'cardName',
        'mid',
        'MID',
        'oid',
        'orderId',
        'orderID',
    ];

    /**
     * 구독할 훅 매핑을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.claim_payment.approve' => [
                'method' => 'processApprove',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 클레임 결제 건의 PG 승인을 처리합니다.
     *
     * @param  array  $result  승인 결과 (기본값)
     * @param  ClaimPayment  $payment  승인 대상 결제 건
     * @param  array  $callback  PG 콜백 파라미터 (PC: authToken/authUrl, 모바일: P_TID/P_REQ_URL)
     * @return array 승인 결과 {handled, success, transaction_id, paid_amount, raw_response, error_code, error_message}
     */
    public function processApprove(array $result, ClaimPayment $payment, array $callback): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        // CBT(일본 엔화)는 승인 재료가 sid 하나다 — 컨트롤러가 그 값을 `cbt_sid` 로 실어 보낸다.
        if (! empty($callback['cbt_sid'])) {
            return $this->approveCbt($payment, $callback);
        }

        if (isset($callback['P_TID'], $callback['P_REQ_URL'])) {
            return $this->approveMobile($payment, $callback);
        }

        return $this->approvePc($payment, $callback);
    }

    /**
     * CBT(일본 엔화) 승인을 처리합니다. (/cbtapprove — mid + sid)
     *
     * 승인 응답에는 신뢰할 금액 필드가 없다(매뉴얼상 미사용). 그래서 `paid_amount` 를 싣지
     * 않고 모듈의 금액 대조를 건너뛴다 — 금액의 권위는 인증요청 시 보낸 값이고, 그 값은
     * 컨트롤러가 결제 건의 청구액과 이미 대조했다(주문 CBT 경로와 같은 판정).
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  CBT 콜백 파라미터 (cbt_sid 필수)
     * @return array 승인 결과
     */
    private function approveCbt(ClaimPayment $payment, array $callback): array
    {
        $sid = (string) ($callback['cbt_sid'] ?? '');

        if ($sid === '') {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-pay_kginicis::messages.claim.invalid_callback'));
        }

        $apiService = $this->getApiService();

        try {
            $pgResponse = $apiService->approveCbtPayment($sid);
            $resultCode = (string) ($pgResponse['resultCode'] ?? ($pgResponse['code'] ?? ''));

            if (! in_array($resultCode, ['OK', '00', '0000'], true)) {
                return $this->failure(
                    $resultCode !== '' ? $resultCode : 'AUTHORIZE_FAILED',
                    (string) ($pgResponse['resultMsg'] ?? ($pgResponse['message'] ?? '')),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_CBT_RESPONSE_KEYS),
                );
            }

            $tid = (string) ($pgResponse['tid'] ?? ($pgResponse['transactionId'] ?? ''));

            if ($tid === '') {
                return $this->failure(
                    'AUTHORIZE_FAILED',
                    'KG Inicis CBT approve response missing tid.',
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_CBT_RESPONSE_KEYS),
                );
            }

            $payMethod = strtoupper(trim((string) ($pgResponse['paymethod'] ?? $callback['paymethod'] ?? '')));

            // 편의점(CVS)은 입금 대기 수단이다 — 클레임 결제 상태 모델에 대기 상태가 없어
            // 발급을 그대로 두면 입금해도 확정할 경로가 없다. 국내 가상계좌와 같은 판정으로
            // 거절하고, 발급된 건은 환불 API 로 되돌린다.
            if ($payMethod === 'CVS') {
                $this->refundCbtQuietly($apiService, $tid, $payment);

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-pay_kginicis::messages.claim.deposit_not_supported'),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_CBT_RESPONSE_KEYS),
                );
            }

            Log::info('KG Inicis: claim payment approved (cbt)', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => $tid,
                // CBT 승인 응답에 금액이 없다 — null 이면 모듈이 금액 대조를 건너뛴다.
                'paid_amount' => null,
                'raw_response' => $this->sanitizePgResponse($pgResponse, self::CLAIM_CBT_RESPONSE_KEYS),
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('KG Inicis: claim payment authorize exception (cbt)', [
                'payment_number' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * CBT 승인 건을 환불 API 로 조용히 되돌립니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  KgInicisApiService  $apiService  API 서비스
     * @param  string  $tid  거래번호
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     */
    private function refundCbtQuietly(KgInicisApiService $apiService, string $tid, ClaimPayment $payment): void
    {
        try {
            $apiService->refundCbtPayment($tid, null, __('sirsoft-pay_kginicis::messages.claim.compensation_cancel_reason'));
        } catch (\Exception $e) {
            Log::error('KG Inicis: claim cbt compensation refund failed', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PC 표준결제 승인을 처리합니다. (authUrl POST)
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  PC 콜백 파라미터
     * @return array 승인 결과
     */
    private function approvePc(ClaimPayment $payment, array $callback): array
    {
        $resultCode = (string) ($callback['resultCode'] ?? '');

        if ($resultCode !== '0000') {
            return $this->failure(
                $resultCode !== '' ? $resultCode : 'WINDOW_FAILED',
                (string) ($callback['resultMsg'] ?? __('sirsoft-pay_kginicis::messages.claim.window_failed')),
            );
        }

        $authToken = (string) ($callback['authToken'] ?? '');
        $idcName = (string) ($callback['idc_name'] ?? '');
        $authUrl = (string) ($callback['authUrl'] ?? $callback['checkAckUrl'] ?? '');

        if ($authToken === '' || $idcName === '' || $authUrl === '') {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-pay_kginicis::messages.claim.invalid_callback'));
        }

        $apiService = $this->getApiService();

        if (! $apiService->isValidIdcAuthUrl($idcName, $authUrl)) {
            Log::error('KG Inicis: claim authUrl not in whitelist (possible SSRF attempt)', [
                'payment_number' => $payment->payment_number,
                'idc_name' => $idcName,
                'received' => $authUrl,
            ]);

            return $this->failure('AUTH_URL_INVALID', __('sirsoft-pay_kginicis::messages.claim.auth_url_invalid'));
        }

        $netCancelUrl = $apiService->resolveIdcNetCancelUrl($idcName);

        try {
            $pgResponse = $apiService->authorizePayment($authUrl, $authToken);
            $pgResultCode = (string) ($pgResponse['resultCode'] ?? '');

            if ($pgResultCode !== '0000') {
                return $this->failure(
                    $pgResultCode !== '' ? $pgResultCode : 'AUTHORIZE_FAILED',
                    (string) ($pgResponse['resultMsg'] ?? ''),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                );
            }

            // 입금 대기 수단(가상계좌)은 클레임 결제 상태 모델에 대기 상태가 없어 지원하지
            // 않는다 — 발급을 그대로 두면 입금해도 확정할 경로가 없으므로 망취소로 되돌린다.
            if (($pgResponse['payMethod'] ?? '') === 'VBank') {
                $this->sendNetCancelQuietly($apiService, $netCancelUrl, $authToken, $payment);

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-pay_kginicis::messages.claim.deposit_not_supported'),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                );
            }

            $totPrice = (int) ($pgResponse['TotPrice'] ?? $pgResponse['totPrice'] ?? $callback['TotPrice'] ?? 0);

            Log::info('KG Inicis: claim payment approved (pc)', [
                'payment_number' => $payment->payment_number,
                'tid' => $pgResponse['tid'] ?? null,
                'tot_price' => $totPrice,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => (string) ($pgResponse['tid'] ?? '') ?: null,
                // 결제 통화 최소 화폐단위 정수 — 모듈이 스냅샷 환산 청구액과 정수 비교한다.
                'paid_amount' => $totPrice > 0 ? $totPrice : null,
                'raw_response' => $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            // 승인 상태를 알 수 없다 — 망취소로 잔존 승인을 해제한다 (미승인이면 무해).
            $this->sendNetCancelQuietly($apiService, $netCancelUrl, $authToken, $payment);

            Log::error('KG Inicis: claim payment authorize exception (pc)', [
                'payment_number' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * 모바일 표준결제 승인을 처리합니다. (P_REQ_URL POST)
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  모바일 콜백 파라미터
     * @return array 승인 결과
     */
    private function approveMobile(ClaimPayment $payment, array $callback): array
    {
        $pStatus = (string) ($callback['P_STATUS'] ?? '00');

        if ($pStatus !== '00') {
            return $this->failure(
                $pStatus !== '' ? $pStatus : 'WINDOW_FAILED',
                (string) ($callback['P_RMESG1'] ?? __('sirsoft-pay_kginicis::messages.claim.window_failed')),
            );
        }

        $pTid = (string) ($callback['P_TID'] ?? '');
        $idcName = (string) ($callback['idc_name'] ?? '');
        $reqUrl = (string) ($callback['P_REQ_URL'] ?? '');

        if ($pTid === '' || $idcName === '' || $reqUrl === '') {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-pay_kginicis::messages.claim.invalid_callback'));
        }

        $apiService = $this->getApiService();

        if (! $apiService->isValidIdcAuthUrl($idcName, $reqUrl)) {
            Log::error('KG Inicis: claim P_REQ_URL not in whitelist (possible SSRF attempt)', [
                'payment_number' => $payment->payment_number,
                'idc_name' => $idcName,
                'received' => $reqUrl,
            ]);

            return $this->failure('AUTH_URL_INVALID', __('sirsoft-pay_kginicis::messages.claim.auth_url_invalid'));
        }

        try {
            $pgResponse = $apiService->authorizeMobilePayment($reqUrl, $pTid);
            $resultStatus = (string) ($pgResponse['P_STATUS'] ?? '');

            if ($resultStatus !== '00') {
                return $this->failure(
                    $resultStatus !== '' ? $resultStatus : 'AUTHORIZE_FAILED',
                    (string) ($pgResponse['P_RMESG1'] ?? ''),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                );
            }

            $tid = (string) ($pgResponse['P_TID'] ?? $pTid);

            if (strcasecmp((string) ($pgResponse['P_TYPE'] ?? ''), 'VBank') === 0) {
                $this->cancelMobileApprovalQuietly($apiService, $tid, $payment);

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-pay_kginicis::messages.claim.deposit_not_supported'),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                );
            }

            $amount = (int) ($pgResponse['P_AMT'] ?? 0);

            Log::info('KG Inicis: claim payment approved (mobile)', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'amount' => $amount,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => $tid !== '' ? $tid : null,
                'paid_amount' => $amount > 0 ? $amount : null,
                'raw_response' => $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            // 모바일 경로에는 망취소 URL 이 없다 — 승인됐을 수 있는 거래를 취소 API 로 해제한다.
            $this->cancelMobileApprovalQuietly($apiService, $pTid, $payment);

            Log::error('KG Inicis: claim payment authorize exception (mobile)', [
                'payment_number' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * 망취소를 조용히 시도합니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  KgInicisApiService  $apiService  API 서비스
     * @param  string|null  $netCancelUrl  망취소 URL
     * @param  string  $authToken  인증 토큰
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     */
    private function sendNetCancelQuietly(KgInicisApiService $apiService, ?string $netCancelUrl, string $authToken, ClaimPayment $payment): void
    {
        if (! $netCancelUrl) {
            Log::error('KG Inicis: claim net cancel skipped — no netCancelUrl', [
                'payment_number' => $payment->payment_number,
            ]);

            return;
        }

        $apiService->sendNetCancel($netCancelUrl, $authToken);
    }

    /**
     * 모바일 승인 건을 취소 API 로 조용히 해제합니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  KgInicisApiService  $apiService  API 서비스
     * @param  string  $tid  거래번호
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     */
    private function cancelMobileApprovalQuietly(KgInicisApiService $apiService, string $tid, ClaimPayment $payment): void
    {
        if ($tid === '') {
            return;
        }

        try {
            $apiService->cancelPayment($tid, 'Card', null, __('sirsoft-pay_kginicis::messages.claim.compensation_cancel_reason'));
        } catch (\Exception $e) {
            Log::error('KG Inicis: claim mobile compensation cancel failed', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 실패 반환값을 조립합니다.
     *
     * @param  string  $code  실패 코드
     * @param  string|null  $message  실패 사유 (관리자 진단용)
     * @param  array|null  $rawResponse  PG 응답 (화이트리스트 통과분)
     * @return array 실패 결과
     */
    private function failure(string $code, ?string $message, ?array $rawResponse = null): array
    {
        return [
            'handled' => true,
            'success' => false,
            'transaction_id' => null,
            'paid_amount' => null,
            'raw_response' => $rawResponse,
            'error_code' => $code,
            'error_message' => $message !== '' ? $message : null,
        ];
    }

    /**
     * API 서비스 인스턴스를 가져옵니다.
     *
     * @return KgInicisApiService API 서비스
     */
    protected function getApiService(): KgInicisApiService
    {
        return app(KgInicisApiService::class);
    }
}
