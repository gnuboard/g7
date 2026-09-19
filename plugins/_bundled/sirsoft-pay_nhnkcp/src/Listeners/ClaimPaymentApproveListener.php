<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
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
        // 승인(계좌 발급) 전에 거절하므로 PG 쪽 잔존물이 없다.
        if (($callback['use_pay_method'] ?? '') === 'VCNT') {
            return $this->failure('CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED', __('sirsoft-pay_nhnkcp::messages.claim.deposit_not_supported'));
        }

        $apiService = $this->getApiService();

        try {
            $pgResponse = $apiService->approvePayment(
                $encData,
                $encInfo,
                $payment->payment_number,
                (string) ($callback['cust_ip'] ?? '127.0.0.1'),
            );

            $pgResCd = (string) ($pgResponse['res_cd'] ?? '');

            if ($pgResCd !== self::SUCCESS_RES_CD) {
                return $this->failure(
                    $pgResCd !== '' ? $pgResCd : 'AUTHORIZE_FAILED',
                    (string) ($pgResponse['res_msg'] ?? ''),
                    $this->claimRawResponse($apiService, $pgResponse),
                );
            }

            $tno = (string) ($pgResponse['tno'] ?? ($callback['tno'] ?? ''));
            $goodMny = (int) ($callback['good_mny'] ?? 0);

            Log::info('KCP: claim payment approved', [
                'payment_number' => $payment->payment_number,
                'tno' => $tno,
                'good_mny' => $goodMny,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => $tno !== '' ? $tno : null,
                // KCP CLI 응답에는 금액이 없는 경우가 많다 — 콜백 good_mny 가 있을 때만 대조값을
                // 싣고, 없으면 null 로 두어 모듈 대조를 건너뛴다 (기대액으로 채우면 대조가 항등식이 된다).
                'paid_amount' => $goodMny > 0 ? $goodMny : null,
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
