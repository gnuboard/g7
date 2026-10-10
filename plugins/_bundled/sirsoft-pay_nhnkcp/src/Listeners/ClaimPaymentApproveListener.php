<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\Sirsoft\PayNhnkcp\Concerns\ReadsKcpApprovedAmount;
use Plugins\Sirsoft\PayNhnkcp\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayNhnkcp\Services\NhnKcpApiService;

/**
 * 클레임 추가결제 승인 리스너
 *
 * 이커머스 모듈의 `claim_payment.approve` 필터를 구독해 NHN KCP CLI 승인으로
 * 클레임 결제 건(CP 채번)을 승인합니다. 계약은 이커머스 모듈
 * `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * KCP 는 PC/모바일 모두 같은 콜백(enc_data + enc_info)으로 돌아오므로 승인 경로는 하나다.
 * KCP 는 KRW 전용이라 결제 통화가 KRW 가 아니면 승인 없이 거절한다.
 */
class ClaimPaymentApproveListener implements HookListenerInterface
{
    use ReadsKcpApprovedAmount;
    use SanitizesPgResponse;

    private const PG_PROVIDER_ID = 'nhnkcp';

    private const SUCCESS_RES_CD = '0000';

    /**
     * 클레임 승인 응답 보관 화이트리스트 (주문 승인 응답과 같은 축)
     */
    private const CLAIM_AUTH_RESPONSE_KEYS = [
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
     * @param  array  $callback  PG 콜백 파라미터 (res_cd, enc_data, enc_info, good_mny, cust_ip)
     * @return array 승인 결과 {handled, success, transaction_id, paid_amount, raw_response, error_code, error_message}
     */
    public function processApprove(array $result, ClaimPayment $payment, array $callback): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $resCd = (string) ($callback['res_cd'] ?? '');

        if ($resCd !== self::SUCCESS_RES_CD) {
            return $this->failure(
                $resCd !== '' ? $resCd : 'WINDOW_FAILED',
                (string) ($callback['res_msg'] ?? __('sirsoft-pay_nhnkcp::messages.claim.window_failed')),
            );
        }

        $encData = (string) ($callback['enc_data'] ?? '');
        $encInfo = (string) ($callback['enc_info'] ?? '');

        if ($encData === '' || $encInfo === '') {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-pay_nhnkcp::messages.claim.invalid_callback'));
        }

        // KCP 는 KRW 전용 — 승인 호출 전에 결제 통화를 확인한다 (주문 경로의 isKrwOrder 대응).
        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        if ($charge === null || strtoupper($charge['currency']) !== 'KRW') {
            return $this->failure('CURRENCY_NOT_SUPPORTED', __('sirsoft-pay_nhnkcp::messages.claim.currency_not_supported'));
        }

        // 입금 대기 수단(가상계좌)은 클레임 결제 상태 모델에 대기 상태가 없어 지원하지 않는다 —
        // 승인(계좌 발급) 전에 거절하므로 PG 쪽 잔존물이 없다. 판정의 권위는 결제 건에 **저장된**
        // 결제수단이다(주문 경로 KVE-2026-2018 과 같은 기준). 콜백의 use_pay_method 는 브라우저가
        // 보낸 값이라 빼거나 바꿔 보낼 수 있으므로 **거절 쪽으로만** 쓴다 — 통과 근거로는 쓰지 않고,
        // 승인 뒤 KCP 응답으로 한 번 더 판정한다.
        if ($payment->payment_method === PaymentMethodEnum::VBANK->value
            || $this->isVirtualAccountMethod((string) ($callback['use_pay_method'] ?? ''))) {
            return $this->failure('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', __('sirsoft-pay_nhnkcp::messages.claim.deposit_not_supported'));
        }

        // 결제창 금액(good_mny)을 **승인 호출 전에** 청구액과 대조한다. 결제창을 연 사이 청구액이
        // 바뀌면(신청서 수정·관리자 재산정) 낡은 창의 금액이 들어온다 — 그대로 승인하면 모듈이 승인
        // **뒤**에 금액 불일치로 거절하고, 그 거절의 보상(취소)은 서버 콜백 경로에만 있어 클라이언트
        // 승인 API 경로에서는 청구된 채 남는다(#599 validate 07 #8). 이 값은 브라우저가 보낸 것이라
        // **거절 쪽으로만** 쓴다 — 통과 근거는 승인 요청의 ordr_mony 와 승인 뒤 KCP 응답 금액이다.
        $expectedAmount = (int) $charge['minor_unit_amount'];
        $callbackAmount = is_numeric($callback['good_mny'] ?? null) ? (int) $callback['good_mny'] : null;

        if ($callbackAmount !== $expectedAmount) {
            Log::warning('KCP: claim approve amount mismatch before approval', [
                'payment_number' => $payment->payment_number,
                'callback_good_mny' => $callback['good_mny'] ?? null,
                'expected' => $expectedAmount,
            ]);

            return $this->failure('AMOUNT_MISMATCH', __('sirsoft-pay_nhnkcp::messages.claim.amount_mismatch'));
        }

        $apiService = $this->getApiService();

        try {
            // 청구액을 ordr_mony 로 실어 KCP 가 결제창 금액과 다르면 승인 자체를 거절하게 한다.
            $pgResponse = $apiService->approvePayment(
                $encData,
                $encInfo,
                $payment->payment_number,
                (string) ($callback['cust_ip'] ?? '127.0.0.1'),
                $expectedAmount,
            );

            $pgResCd = (string) ($pgResponse['res_cd'] ?? '');

            if ($pgResCd !== self::SUCCESS_RES_CD) {
                return $this->failure(
                    $pgResCd !== '' ? $pgResCd : 'AUTHORIZE_FAILED',
                    (string) ($pgResponse['res_msg'] ?? ''),
                    $this->claimRawResponse($apiService, $pgResponse),
                );
            }

            // 거래번호는 PG 승인 응답의 값만 쓴다 — 브라우저 콜백의 tno 로 대신 채우지 않는다.
            $tno = trim((string) ($pgResponse['tno'] ?? ''));

            if ($tno === '') {
                // 승인 성공 응답에 거래번호가 없으면 취소할 거래를 특정할 수 없다. 확정하지 않고
                // 거절하며, PG 관리자에서 대사하도록 로그를 남긴다.
                Log::error('KCP: claim approve succeeded without tno — MANUAL CANCEL REQUIRED', [
                    'payment_number' => $payment->payment_number,
                    'res_cd' => $pgResCd,
                ]);

                return $this->failure(
                    'MISSING_TNO',
                    __('sirsoft-pay_nhnkcp::messages.errors.payment_failed'),
                    $this->claimRawResponse($apiService, $pgResponse),
                );
            }

            // 승인 뒤 재판정 — 콜백이 결제수단을 숨겨 사전 거절을 지나쳤어도 KCP 응답이 가상계좌를
            // 말하면 계좌만 발급된 상태다(입금 전). 발급분을 취소하고 거절한다.
            if ($this->isVirtualAccountMethod((string) ($pgResponse['use_pay_method'] ?? ($pgResponse['pay_method'] ?? '')))) {
                $this->cancelIssuedQuietly($apiService, $tno, $payment);

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-pay_nhnkcp::messages.claim.deposit_not_supported'),
                    $this->claimRawResponse($apiService, $pgResponse),
                );
            }

            // 대조 금액은 KCP 응답 금액(amount)만 근거로 삼는다. 청구액과 다르거나 응답에 금액이
            // 없으면(비었거나 숫자가 아니거나 0 이하) 승인분을 **여기서** 취소하고 거절한다 — 모듈 쪽
            // 대조의 거절은 보상이 서버 콜백 경로에만 있다. 브라우저 콜백의 good_mny 는 결제창 금액을
            // 바꾼 요청이 청구액으로 맞춰 보낼 수 있으므로 대체값으로 쓰지 않는다.
            $responseMny = $this->kcpApprovedAmount($pgResponse);

            if ($responseMny !== $expectedAmount) {
                Log::error('KCP: claim approve response amount does not match the charge — cancelling', [
                    'payment_number' => $payment->payment_number,
                    'tno' => $tno,
                    'response_amount' => $responseMny,
                    'expected' => $expectedAmount,
                ]);

                $this->cancelApprovedQuietly(
                    $apiService,
                    $tno,
                    $payment,
                    $responseMny ?? $expectedAmount,
                    __('sirsoft-pay_nhnkcp::messages.claim.compensation_cancel_reason'),
                );

                return $this->failure(
                    'AMOUNT_MISMATCH',
                    __('sirsoft-pay_nhnkcp::messages.claim.amount_mismatch'),
                    $this->claimRawResponse($apiService, $pgResponse),
                );
            }

            Log::info('KCP: claim payment approved', [
                'payment_number' => $payment->payment_number,
                'tno' => $tno,
                'amount' => $responseMny,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => $tno,
                // KCP 응답 금액(= 청구액) — 모듈이 스냅샷 환산 청구액과 한 번 더 정수 비교한다.
                'paid_amount' => $responseMny,
                'raw_response' => $this->claimRawResponse($apiService, $pgResponse),
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('KCP: claim payment approve failed', [
                'payment_number' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * KCP 결제수단 값이 가상계좌인지 판정합니다. (문자 코드 VCNT · 12자리 코드 001000000000)
     *
     * @param  string  $method  결제수단 값
     * @return bool 가상계좌이면 true
     */
    private function isVirtualAccountMethod(string $method): bool
    {
        $method = strtoupper(trim($method));

        return $method === 'VCNT' || $method === '001000000000';
    }

    /**
     * 승인으로 발급된 가상계좌를 조용히 취소합니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  NhnKcpApiService  $apiService  API 서비스
     * @param  string  $tno  KCP 거래번호
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     * @return void 반환값 없음
     */
    private function cancelIssuedQuietly(NhnKcpApiService $apiService, string $tno, ClaimPayment $payment): void
    {
        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        $this->cancelApprovedQuietly(
            $apiService,
            $tno,
            $payment,
            (int) ($charge['minor_unit_amount'] ?? round((float) $payment->amount)),
            __('sirsoft-pay_nhnkcp::messages.claim.deposit_not_supported'),
        );
    }

    /**
     * 확정된 승인(또는 발급)을 조용히 취소합니다. (보상 — 실패해도 흐름은 지속, 수동 취소용 로그를 남긴다)
     *
     * @param  NhnKcpApiService  $apiService  API 서비스
     * @param  string  $tno  KCP 거래번호
     * @param  ClaimPayment  $payment  결제 건 (주문번호 자리·로그 문맥)
     * @param  int  $cancelAmount  취소 금액 (승인된 금액)
     * @param  string  $reason  취소 사유
     * @return bool KCP 가 취소를 받아들였으면(res_cd 0000) true
     */
    private function cancelApprovedQuietly(
        NhnKcpApiService $apiService,
        string $tno,
        ClaimPayment $payment,
        int $cancelAmount,
        string $reason,
    ): bool {
        if ($tno === '') {
            Log::error('KCP: claim compensation cancel skipped — no tno (MANUAL CANCEL REQUIRED)', [
                'payment_number' => $payment->payment_number,
            ]);

            return false;
        }

        try {
            $response = $apiService->cancelPayment($tno, (string) $payment->payment_number, $cancelAmount, $reason);
            $resCd = (string) ($response['res_cd'] ?? '');

            if ($resCd !== self::SUCCESS_RES_CD) {
                Log::error('KCP: claim compensation cancel refused — MANUAL CANCEL REQUIRED', [
                    'payment_number' => $payment->payment_number,
                    'tno' => $tno,
                    'res_cd' => $resCd,
                ]);

                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('KCP: claim compensation cancel failed — MANUAL CANCEL REQUIRED', [
                'payment_number' => $payment->payment_number,
                'tno' => $tno,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 모듈이 승인 뒤 거절한 클레임 결제의 KCP 승인을 취소합니다. (`claim_payment.void_approval` 필터)
     *
     * 이 리스너가 승인 전·후로 금액을 먼저 대조하므로 보통은 불리지 않는다 — 모듈 쪽 대조가 따로
     * 거절한 때의 보상이다. 거래번호는 승인 결과의 거래 식별자를, 취소 금액은 승인 결과가 보고한
     * 승인액(없으면 결제 건 청구액)을 쓴다.
     *
     * @param  array  $result  보상 결과 (기본값)
     * @param  ClaimPayment  $payment  거절된 결제 건
     * @param  array  $callback  승인 때 받은 콜백 파라미터
     * @param  array  $approveResult  승인 필터 결과 (transaction_id·paid_amount 등)
     * @return array 보상 결과 {handled, success, error_code, error_message}
     */
    public function voidApproval(array $result, ClaimPayment $payment, array $callback, array $approveResult): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tno = (string) ($approveResult['transaction_id'] ?? '');

        if ($tno === '') {
            $tno = (string) ($callback['tno'] ?? '');
        }

        $amount = is_numeric($approveResult['paid_amount'] ?? null) ? (int) $approveResult['paid_amount'] : 0;

        if ($amount <= 0) {
            $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);
            $amount = (int) ($charge['minor_unit_amount'] ?? round((float) $payment->amount));
        }

        $cancelled = $this->cancelApprovedQuietly(
            $this->getApiService(),
            $tno,
            $payment,
            $amount,
            __('sirsoft-pay_nhnkcp::messages.claim.compensation_cancel_reason'),
        );

        return [
            'handled' => true,
            'success' => $cancelled,
            'error_code' => $cancelled ? null : ($tno === '' ? 'MISSING_TNO' : 'PG_API_ERROR'),
            'error_message' => null,
        ];
    }

    /**
     * 보관용 응답을 조립합니다. (화이트리스트 + 환급 시 자격증명 복원 메타)
     *
     * KCP 환불은 결제 당시의 site_cd/모드로 호출해야 한다 — 주문 결제는 payment_meta 에
     * 이 메타를 남기지만 클레임 결제행에는 raw_response 뿐이므로 여기에 함께 싣는다.
     *
     * @param  NhnKcpApiService  $apiService  API 서비스
     * @param  array  $pgResponse  PG 원본 응답
     * @return array 보관용 응답
     */
    private function claimRawResponse(NhnKcpApiService $apiService, array $pgResponse): array
    {
        return $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS) + [
            'site_cd' => $apiService->getSiteCd(),
            'is_test_mode' => $apiService->isTestMode(),
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
     * @return NhnKcpApiService API 서비스
     */
    protected function getApiService(): NhnKcpApiService
    {
        return app(NhnKcpApiService::class);
    }
}
