<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\Sirsoft\PayKginicis\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimApproveToken;
use Plugins\Sirsoft\PayKginicis\Support\CbtClaimAuthAmount;
use Plugins\Sirsoft\PayKginicis\Support\CbtCvsResponse;

/**
 * 클레임 추가결제 승인 리스너
 *
 * 이커머스 모듈의 `claim_payment.approve` 필터를 구독해 KG 이니시스 승인 API 로
 * 클레임 결제 건(CP 채번)을 승인합니다. 계약은 이커머스 모듈
 * `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * 콜백 파라미터의 형태로 PC(authToken + authUrl)/모바일(P_TID + P_REQ_URL)을 구분한다.
 * 두 경로 모두 승인 URL 은 idc_name 화이트리스트로 검증한다 — 이 필터는 클라이언트가
 * 호출하는 승인 API 경로에서도 발화하므로, 컨트롤러 검증에 기대지 않고 여기서 다시 막는다
 * (SSRF 방어).
 *
 * 승인 응답이 이 결제 건·이 청구액의 것인지도 여기서 판정하고, 아니면 여기서 되돌린다(망취소·
 * 취소·환불). 모듈도 `paid_amount` 로 한 번 더 대조하며, 그 거절의 보상은 `claim_payment.void_approval`
 * 필터(이 리스너의 `voidApproval()`)가 맡는다 — 서버 콜백 경로와 클라이언트 승인 API 경로
 * (`claim-payments/{no}/approve`)의 판정·보상이 이 한 클래스에 있다(#599 validate 07 #8).
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
            // 모듈이 승인 **뒤**에 거절한 건(승인 금액 대조 실패)을 되돌리는 보상 — 서버 콜백·API
            // 승인 두 경로의 보상이 이 한 곳이다(콜백 컨트롤러는 따로 보상 클로저를 넘기지 않는다).
            'sirsoft-ecommerce.claim_payment.void_approval' => [
                'method' => 'voidApproval',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void 반환값 없음
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
     * 승인 재료(sid)는 **서버 콜백 컨트롤러가 서명해 넘긴 것만** 받는다({@see CbtClaimApproveToken}).
     * 승인 응답에 금액·주문번호가 없어(매뉴얼상 미사용) 응답만으로는 sid 가 이 결제 건의 것인지 알 수
     * 없으므로, 클라이언트 승인 API 가 다른 결제 건의 sid 를 실어 보내는 경로를 먼저 막는다.
     *
     * 응답에 주문번호·MID·통화가 실려 오면 주문 경로(`assertCbtApproveResponseMatchesOrder`)와 같게
     * 대조하고, 어긋나면 승인분을 환불로 되돌린다. 금액이 실려 오면 여기서 청구액과 대조해 다르면
     * 환불로 되돌리고, 같으면 `paid_amount` 로 넘겨 모듈이 한 번 더 대조한다. 금액이 없으면 주문 경로와
     * 같게 대조를 생략한다 — 금액의 권위는 인증요청 때
     * 보낸 값이고, 그 값은 해시 발급 단계가 결제 건의 청구액과 이미 대조했다(2026-06-29 결정).
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  array  $callback  CBT 콜백 파라미터 (cbt_sid·cbt_token 필수)
     * @return array 승인 결과
     */
    private function approveCbt(ClaimPayment $payment, array $callback): array
    {
        $sid = (string) ($callback['cbt_sid'] ?? '');

        if ($sid === '') {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-pay_kginicis::messages.claim.invalid_callback'));
        }

        $token = isset($callback['cbt_token']) ? (string) $callback['cbt_token'] : null;

        if (! CbtClaimApproveToken::verify((string) $payment->payment_number, $sid, $token)) {
            Log::warning('KG Inicis: claim cbt approve refused — sid not signed by the server callback', [
                'payment_number' => $payment->payment_number,
            ]);

            return $this->failure('CBT_APPROVE_UNVERIFIED', __('sirsoft-pay_kginicis::messages.claim.invalid_callback'));
        }

        // CBT 는 엔화 결제다 — 청구액 기준(결제 통화 JPY)을 세울 수 없으면 승인을 부르지 않는다.
        $expectedAmount = $this->expectedChargeAmount($payment, 'JPY');

        if ($expectedAmount === null) {
            return $this->failure('AMOUNT_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.amount_mismatch'));
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

            $mismatch = $this->cbtResponseMismatch($pgResponse, $payment);

            if ($mismatch !== null) {
                Log::error('KG Inicis: claim cbt approve response does not match the payment', [
                    'payment_number' => $payment->payment_number,
                    'tid' => $tid,
                    'mismatch' => $mismatch,
                ]);
                $this->refundCbtQuietly($apiService, $tid, $payment);

                return $this->failure(
                    'CBT_APPROVE_MISMATCH',
                    __('sirsoft-pay_kginicis::messages.claim.invalid_callback'),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_CBT_RESPONSE_KEYS),
                );
            }

            // 편의점(CVS)은 입금 대기 수단이다 — 클레임 결제 상태 모델에 대기 상태가 없어
            // 발급을 그대로 두면 입금해도 확정할 경로가 없다. 국내 가상계좌와 같은 판정으로
            // 거절하고, 발급된 건은 환불 API 로 되돌린다.
            //
            // 판정의 권위는 **승인 응답**이다 — 응답의 결제수단이 CVS 이거나, 결제수단이 비어 와도
            // CVS 발급에만 실리는 필드(결제번호 confNo·receiptNo, 편의점 convenience, 납부기한
            // paymentTerm — 매뉴얼상 CVS 승인 응답 전용)가 있으면 CVS 로 본다. 콜백의 paymethod 는
            // 브라우저를 거친 값이라 **거절 쪽으로만** 쓴다 — CVS 라고 말하면 거절하지만, 말하지
            // 않았다고 통과시키지 않는다(nhnkcp 의 use_pay_method 와 같은 규칙).
            $callbackMethod = strtoupper(trim((string) ($callback['paymethod'] ?? '')));

            if (CbtCvsResponse::matches($pgResponse) || $callbackMethod === 'CVS') {
                $this->refundCbtQuietly($apiService, $tid, $payment);

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-pay_kginicis::messages.claim.deposit_not_supported'),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_CBT_RESPONSE_KEYS),
                );
            }

            // 승인액: 응답에 금액이 실려 오면 그 값, 없으면(매뉴얼상 미사용) 해시 발급 때 기록한
            // 인증요청 금액(`CbtClaimAuthAmount`), 그것도 없으면 주문 경로와 같게 결제 예정액이다
            // (금액의 권위는 인증요청 때 보낸 값 — 2026-06-29 결정). 모듈은 `paid_amount` 가 없으면
            // 불일치로 거절하므로 이 값은 반드시 싣는다. 청구액과 다르면(결제창을 연 뒤 청구액이
            // 바뀐 경우 등) 여기서 환불로 되돌린다.
            $responseAmount = $this->cbtResponseAmount($pgResponse)
                ?? app(CbtClaimAuthAmount::class)->recall((string) $payment->payment_number)
                ?? $expectedAmount;

            if ($responseAmount !== $expectedAmount) {
                Log::error('KG Inicis: claim cbt approve amount does not match the charge — refunding', [
                    'payment_number' => $payment->payment_number,
                    'tid' => $tid,
                    'response_amount' => $responseAmount,
                    'expected' => $expectedAmount,
                ]);
                $this->refundCbtQuietly($apiService, $tid, $payment);

                return $this->failure(
                    'AMOUNT_MISMATCH',
                    __('sirsoft-pay_kginicis::messages.claim.amount_mismatch'),
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
                // 위에서 청구액과 같음을 확인한 승인액 — 모듈이 한 번 더 대조한다.
                'paid_amount' => $responseAmount,
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
     * CBT 승인 응답이 이 결제 건의 것이 아닌 신호를 찾습니다. (주문 경로 대조와 같은 축)
     *
     * 응답에 없는 필드는 판정하지 않는다 — CBT 응답은 결제수단마다 싣는 필드가 다르다.
     *
     * @param  array  $pgResponse  승인 응답
     * @param  ClaimPayment  $payment  결제 건
     * @return string|null 어긋난 축 이름 (일치하면 null)
     */
    private function cbtResponseMismatch(array $pgResponse, ClaimPayment $payment): ?string
    {
        $orderId = $this->firstFilled($pgResponse, ['orderId', 'orderID', 'oid']);
        if ($orderId !== null && $orderId !== (string) $payment->payment_number) {
            return 'order_id';
        }

        $mid = $this->firstFilled($pgResponse, ['mid', 'MID']);
        if ($mid !== null && $mid !== $this->getApiService()->getJapanMid()) {
            return 'mid';
        }

        $currency = $this->firstFilled($pgResponse, ['currencyCd', 'currencyCode', 'currency']);
        if ($currency !== null && strtoupper($currency) !== 'JPY') {
            return 'currency';
        }

        return null;
    }

    /**
     * CBT 승인 응답에 실린 결제 금액(엔화 정수)을 반환합니다. 없으면 null.
     *
     * @param  array  $pgResponse  승인 응답
     * @return int|null 금액
     */
    private function cbtResponseAmount(array $pgResponse): ?int
    {
        $amount = $this->firstFilled($pgResponse, ['amount', 'price', 'bokuApplPrice', 'bokuLocalApplPrice']);

        return $amount !== null && is_numeric($amount) ? (int) $amount : null;
    }

    /**
     * 결제 건의 기대 청구액(결제 통화 최소 화폐단위)을 반환합니다.
     *
     * 기준을 세울 수 없거나(통화 스냅샷 부재) 결제 통화가 이 경로의 통화가 아니면 null — 호출부는
     * 승인을 부르지 않고 거절한다. 근거 없는 "일치" 로 승인하면 그 결과를 되돌릴 방법이 없다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  string  $currency  이 경로의 결제 통화 (국내 표준결제 KRW / CBT JPY)
     * @return int|null 기대 청구액
     */
    private function expectedChargeAmount(ClaimPayment $payment, string $currency): ?int
    {
        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        if ($charge === null || strtoupper((string) $charge['currency']) !== $currency) {
            Log::warning('KG Inicis: claim approve refused — charge basis unavailable for this path', [
                'payment_number' => $payment->payment_number,
                'path_currency' => $currency,
                'charge_currency' => $charge['currency'] ?? null,
            ]);

            return null;
        }

        return (int) $charge['minor_unit_amount'];
    }

    /**
     * 승인 응답에서 후보 키의 정수 금액을 꺼냅니다. (콜백 값으로 채우지 않는다)
     *
     * @param  array  $pgResponse  승인 응답
     * @param  array<int, string>  $keys  후보 키
     * @return int|null 금액 (없거나 숫자가 아니면 null)
     */
    private function responseAmount(array $pgResponse, array $keys): ?int
    {
        $value = $this->firstFilled($pgResponse, $keys);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * 후보 키 중 처음으로 값이 채워진 것을 문자열로 반환합니다.
     *
     * @param  array  $source  원본
     * @param  array<int, string>  $keys  후보 키
     * @return string|null 값
     */
    private function firstFilled(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($source[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * CBT 승인 건을 환불 API 로 조용히 되돌립니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  KgInicisApiService  $apiService  API 서비스
     * @param  string  $tid  거래번호
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     * @return bool 환불 요청이 성공했으면 true
     */
    private function refundCbtQuietly(KgInicisApiService $apiService, string $tid, ClaimPayment $payment): bool
    {
        try {
            $apiService->refundCbtPayment($tid, null, __('sirsoft-pay_kginicis::messages.claim.compensation_cancel_reason'));

            return true;
        } catch (\Exception $e) {
            Log::error('KG Inicis: claim cbt compensation refund failed — MANUAL CANCEL REQUIRED', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return false;
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

        // 청구액 기준(KRW)을 세울 수 없으면 승인을 부르지 않는다 — 콜백 금액(TotPrice)은 브라우저를
        // 거친 값이라 기준이 될 수 없다(금액은 서명 발급 단계에서 청구액과 대조됐다).
        $expectedAmount = $this->expectedChargeAmount($payment, 'KRW');

        if ($expectedAmount === null) {
            return $this->failure('AMOUNT_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.amount_mismatch'));
        }

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

            // 승인액은 **응답**의 TotPrice 만 쓴다 — 응답에 없을 때 콜백 TotPrice 로 채우면 브라우저가
            // 보낸 값이 청구액과 대조되어 대조가 항등식이 된다. 응답 금액이 없거나 청구액과 다르거나,
            // 응답 주문번호가 이 결제 건이 아니면 여기서 망취소하고 거절한다.
            $totPrice = $this->responseAmount($pgResponse, ['TotPrice', 'totPrice']);
            $responseMoid = $this->firstFilled($pgResponse, ['MOID', 'moid']);
            $orderIdMismatch = $responseMoid !== null && $responseMoid !== (string) $payment->payment_number;

            if ($orderIdMismatch || $totPrice !== $expectedAmount) {
                Log::error('KG Inicis: claim pc approve response does not match the payment — net cancel', [
                    'payment_number' => $payment->payment_number,
                    'tid' => $pgResponse['tid'] ?? null,
                    'response_moid' => $responseMoid,
                    'response_tot_price' => $totPrice,
                    'expected' => $expectedAmount,
                ]);
                $this->sendNetCancelQuietly($apiService, $netCancelUrl, $authToken, $payment);

                return $orderIdMismatch
                    ? $this->failure('ORDER_ID_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.order_id_mismatch'), $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS))
                    : $this->failure('AMOUNT_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.amount_mismatch'), $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS));
            }

            Log::info('KG Inicis: claim payment approved (pc)', [
                'payment_number' => $payment->payment_number,
                'tid' => $pgResponse['tid'] ?? null,
                'tot_price' => $totPrice,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => (string) ($pgResponse['tid'] ?? '') ?: null,
                // 결제 통화 최소 화폐단위 정수 — 위에서 청구액과 같음을 확인한 응답 금액이다.
                // 모듈이 스냅샷 환산 청구액과 한 번 더 정수 비교한다.
                'paid_amount' => $totPrice,
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

        $expectedAmount = $this->expectedChargeAmount($payment, 'KRW');

        if ($expectedAmount === null) {
            return $this->failure('AMOUNT_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.amount_mismatch'));
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

            // 승인액은 응답의 P_AMT 만 쓴다. 없거나 청구액과 다르거나, 응답 주문번호(P_OID)가 이 결제
            // 건이 아니면 여기서 취소 API 로 되돌리고 거절한다(모바일 경로에는 망취소 URL 이 없다).
            $amount = $this->responseAmount($pgResponse, ['P_AMT']);
            $responseOid = $this->firstFilled($pgResponse, ['P_OID']);
            $orderIdMismatch = $responseOid !== null && $responseOid !== (string) $payment->payment_number;

            if ($orderIdMismatch || $amount !== $expectedAmount) {
                Log::error('KG Inicis: claim mobile approve response does not match the payment — cancelling', [
                    'payment_number' => $payment->payment_number,
                    'tid' => $tid,
                    'response_oid' => $responseOid,
                    'response_amount' => $amount,
                    'expected' => $expectedAmount,
                ]);
                $this->cancelMobileApprovalQuietly($apiService, $tid, $payment);

                return $orderIdMismatch
                    ? $this->failure('ORDER_ID_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.order_id_mismatch'), $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS))
                    : $this->failure('AMOUNT_MISMATCH', __('sirsoft-pay_kginicis::messages.claim.amount_mismatch'), $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS));
            }

            Log::info('KG Inicis: claim payment approved (mobile)', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'amount' => $amount,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => $tid !== '' ? $tid : null,
                'paid_amount' => $amount,
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
     * @return void 반환값 없음
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
     * @return bool 취소 요청이 성공했으면 true
     */
    private function cancelMobileApprovalQuietly(KgInicisApiService $apiService, string $tid, ClaimPayment $payment): bool
    {
        if ($tid === '') {
            return false;
        }

        try {
            $apiService->cancelPayment($tid, 'Card', null, __('sirsoft-pay_kginicis::messages.claim.compensation_cancel_reason'));

            return true;
        } catch (\Exception $e) {
            Log::error('KG Inicis: claim compensation cancel failed — MANUAL CANCEL REQUIRED', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 모듈이 승인 뒤 거절한 클레임 결제의 KG 승인을 되돌립니다. (`claim_payment.void_approval` 필터)
     *
     * 이 리스너가 승인 응답을 먼저 대조하므로 보통은 불리지 않는다 — 모듈 쪽 대조가 따로 거절한
     * 때의 보상이다. 승인이 이미 확정됐으므로 망취소가 아니라 거래번호 기준으로 되돌린다 —
     * CBT(엔화)는 CBT 환불 API, 국내 표준결제(PC·모바일)는 취소 API(INIAPI).
     *
     * @param  array  $result  보상 결과 (기본값)
     * @param  ClaimPayment  $payment  거절된 결제 건
     * @param  array  $callback  승인 때 받은 콜백 파라미터 (경로 판별용)
     * @param  array  $approveResult  승인 필터 결과 (transaction_id 등)
     * @return array 보상 결과 {handled, success, error_code, error_message}
     */
    public function voidApproval(array $result, ClaimPayment $payment, array $callback, array $approveResult): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tid = (string) ($approveResult['transaction_id'] ?? '');

        if ($tid === '') {
            Log::error('KG Inicis: claim approval void skipped — no tid (MANUAL CANCEL REQUIRED)', [
                'payment_number' => $payment->payment_number,
            ]);

            return ['handled' => true, 'success' => false, 'error_code' => 'MISSING_TID', 'error_message' => null];
        }

        $apiService = $this->getApiService();
        $reverted = ! empty($callback['cbt_sid'])
            ? $this->refundCbtQuietly($apiService, $tid, $payment)
            : $this->cancelMobileApprovalQuietly($apiService, $tid, $payment);

        return [
            'handled' => true,
            'success' => $reverted,
            'error_code' => $reverted ? null : 'PG_API_ERROR',
            'error_message' => null,
        ];
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
