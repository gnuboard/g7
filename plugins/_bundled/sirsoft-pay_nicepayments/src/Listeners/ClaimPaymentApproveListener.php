<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\PayNicepayments\Concerns\SanitizesPgResponse;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

/**
 * 클레임 추가결제 승인 리스너
 *
 * 이커머스 모듈의 `claim_payment.approve` 필터를 구독해 나이스페이먼츠 서버 승인 API 로
 * 클레임 결제 건(CP 채번)을 승인합니다. 계약은 이커머스 모듈
 * `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * 이 필터는 클라이언트가 호출하는 승인 API 경로에서도 발화하므로, 주문 콜백과 같은
 * 서명 검증(AuthToken + MID + Amt)을 여기서 다시 수행한다 — 컨트롤러 검증에 기대지 않는다.
 */
class ClaimPaymentApproveListener implements HookListenerInterface
{
    use SanitizesPgResponse;

    private const PG_PROVIDER_ID = 'nicepayments';

    private const SUCCESS_RESULT_CODES = ['3001', '4000', '4100', 'A000', '7001'];

    /**
     * 클레임 승인 응답 보관 화이트리스트 (주문 승인 응답과 같은 축)
     */
    private const CLAIM_AUTH_RESPONSE_KEYS = [
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
        'CardQuota',
        'CardInterest',
        'EscrowYN',
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
     * @param  array  $callback  PG 콜백 파라미터 (AuthResultCode, AuthToken, TxTid, NextAppURL, Amt, MID, Signature, NetCancelURL)
     * @return array 승인 결과 {handled, success, transaction_id, paid_amount, raw_response, error_code, error_message}
     */
    public function processApprove(array $result, ClaimPayment $payment, array $callback): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $authResultCode = (string) ($callback['AuthResultCode'] ?? '');

        if ($authResultCode !== '0000') {
            return $this->failure(
                $authResultCode !== '' ? $authResultCode : 'WINDOW_FAILED',
                (string) ($callback['AuthResultMsg'] ?? __('sirsoft-pay_nicepayments::messages.claim.window_failed')),
            );
        }

        $nextAppUrl = (string) ($callback['NextAppURL'] ?? '');
        $txTid = (string) ($callback['TxTid'] ?? '');
        $authToken = (string) ($callback['AuthToken'] ?? '');
        $mid = (string) ($callback['MID'] ?? '');
        $amt = (int) ($callback['Amt'] ?? 0);
        $signature = (string) ($callback['Signature'] ?? '');
        $netCancelUrl = (string) ($callback['NetCancelURL'] ?? '');

        if ($nextAppUrl === '' || $txTid === '' || $authToken === '' || $mid === '' || $amt <= 0 || $signature === '') {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-pay_nicepayments::messages.claim.invalid_callback'));
        }

        $apiService = $this->getApiService();

        if ($mid !== $apiService->getMid()) {
            return $this->failure('MID_MISMATCH', __('sirsoft-pay_nicepayments::messages.claim.mid_mismatch'));
        }

        if (! $apiService->verifyCallbackSignature($authToken, $mid, $amt, $signature)) {
            Log::error('NicePayments: claim callback signature verification failed', [
                'payment_number' => $payment->payment_number,
            ]);

            return $this->failure('SIGNATURE_MISMATCH', __('sirsoft-pay_nicepayments::messages.claim.signature_mismatch'));
        }

        try {
            $pgResponse = $apiService->authorizePayment($nextAppUrl, $txTid, $authToken, $amt);
            $resultCode = (string) ($pgResponse['ResultCode'] ?? '');

            if (! in_array($resultCode, self::SUCCESS_RESULT_CODES, true)) {
                // 승인이 거부됐어도 PG 측에 잔존 승인이 있을 수 있다 — 망취소는 미승인 상태에서
                // safe-no-op 이므로 모든 실패 경로에서 호출해 안전하다 (주문 경로와 동일).
                $this->sendNetCancelQuietly($apiService, $netCancelUrl, $txTid, $authToken, $amt, $payment);

                return $this->failure(
                    $resultCode !== '' ? $resultCode : 'AUTHORIZE_FAILED',
                    (string) ($pgResponse['ResultMsg'] ?? ''),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                );
            }

            // 입금 대기 수단(가상계좌)은 클레임 결제 상태 모델에 대기 상태가 없어 지원하지
            // 않는다 — 발급을 그대로 두면 입금해도 확정할 경로가 없으므로 망취소로 되돌린다.
            if (($pgResponse['PayMethod'] ?? '') === 'VBANK') {
                $this->sendNetCancelQuietly($apiService, $netCancelUrl, $txTid, $authToken, $amt, $payment);

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-pay_nicepayments::messages.claim.deposit_not_supported'),
                    $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS),
                );
            }

            $tid = (string) ($pgResponse['TID'] ?? $txTid);

            Log::info('NicePayments: claim payment approved', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'amt' => $amt,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => $tid !== '' ? $tid : null,
                // 서명이 덮는 승인 금액 — 모듈이 스냅샷 환산 청구액과 정수 비교한다.
                'paid_amount' => $amt,
                // 환급은 결제 당시의 MID/모드로 호출해야 한다 — 주문 결제는 payment_meta 에 이
                // 메타를 남기지만 클레임 결제행에는 raw_response 뿐이므로 여기에 함께 싣는다.
                'raw_response' => $this->sanitizePgResponse($pgResponse, self::CLAIM_AUTH_RESPONSE_KEYS) + [
                    'mid' => $apiService->getMid(),
                    'is_test_mode' => $apiService->isTestMode(),
                ],
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            // 승인 상태를 알 수 없다 — 망취소로 잔존 승인을 해제한다 (미승인이면 무해).
            $this->sendNetCancelQuietly($apiService, $netCancelUrl, $txTid, $authToken, $amt, $payment);

            Log::error('NicePayments: claim payment authorize exception', [
                'payment_number' => $payment->payment_number,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * 망취소를 조용히 시도합니다. (보상 — 실패해도 흐름은 지속)
     *
     * @param  NicePaymentsApiService  $apiService  API 서비스
     * @param  string  $netCancelUrl  망취소 URL
     * @param  string  $txTid  임시 거래번호
     * @param  string  $authToken  인증 토큰
     * @param  int  $amt  결제 금액
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     */
    private function sendNetCancelQuietly(
        NicePaymentsApiService $apiService,
        string $netCancelUrl,
        string $txTid,
        string $authToken,
        int $amt,
        ClaimPayment $payment,
    ): void {
        if ($netCancelUrl === '') {
            Log::error('NicePayments: claim net cancel skipped — no NetCancelURL', [
                'payment_number' => $payment->payment_number,
            ]);

            return;
        }

        try {
            $apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);
        } catch (\Exception $e) {
            Log::error('NicePayments: claim net cancel failed', [
                'payment_number' => $payment->payment_number,
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
     * @return NicePaymentsApiService API 서비스
     */
    protected function getApiService(): NicePaymentsApiService
    {
        return app(NicePaymentsApiService::class);
    }
}
