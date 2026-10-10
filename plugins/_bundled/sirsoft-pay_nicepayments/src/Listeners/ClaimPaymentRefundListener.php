<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

/**
 * 클레임 추가결제 환급 리스너
 *
 * 이커머스 모듈의 `claim_payment.refund` 필터를 구독해 나이스페이먼츠 취소 API 로
 * 클레임 결제 건을 환급합니다. 계약은 이커머스 모듈 `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md`
 * "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * 나이스페이먼츠 취소 API 에는 멱등키 파라미터가 없어 `$idempotencyKey` 는 로그로만
 * 남긴다 — 중복 방어는 모듈의 잔액 재계산(누적 환급액)과 PG 쪽 중복 취소 거절이 맡는다.
 */
class ClaimPaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nicepayments';

    /**
     * 구독할 훅 매핑을 반환합니다.
     *
     * @return array 훅 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.claim_payment.refund' => [
                'method' => 'processRefund',
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
     * 클레임 결제 건의 PG 환급을 처리합니다.
     *
     * @param  array  $result  환급 결과 (기본값)
     * @param  ClaimPayment  $payment  환급 대상 결제 건
     * @param  float  $amount  환급액 (결제 통화 — 동결 환산액이 없으면 기준통화)
     * @param  string|null  $reason  환급 사유
     * @param  string  $idempotencyKey  모듈이 조립한 멱등키
     * @return array 환급 결과 {handled, success, transaction_id, error_code, error_message}
     */
    public function processRefund(
        array $result,
        ClaimPayment $payment,
        float $amount,
        ?string $reason,
        string $idempotencyKey,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $tid = $payment->transaction_id;

        if (! $tid) {
            return $this->failure('MISSING_TID', __('sirsoft-pay_nicepayments::messages.refund.missing_tid'));
        }

        $cancelAmt = (int) round($amount);

        if ($cancelAmt <= 0) {
            return $this->failure('INVALID_REFUND_AMOUNT', __('sirsoft-pay_nicepayments::messages.claim.invalid_refund_amount'));
        }

        try {
            $apiService = app(NicePaymentsApiService::class);
            $this->restoreCredentials($apiService, $payment);

            $isPartial = (float) $payment->refunded_amount > 0
                || $cancelAmt < (int) round($this->paidLocalTotal($payment));

            $response = $apiService->cancelPayment(
                $tid,
                $payment->payment_number,
                $cancelAmt,
                $reason ?? __('sirsoft-pay_nicepayments::messages.refund.default_reason'),
                $isPartial ? 1 : 0,
            );

            Log::info('NicePayments: claim payment refund success', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
                'is_partial' => $isPartial,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => (string) ($response['TID'] ?? $tid),
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('NicePayments: claim payment refund failed', [
                'payment_number' => $payment->payment_number,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
                'idempotency_key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * 결제 당시의 MID/모드로 API 서명 컨텍스트를 복원합니다.
     *
     * 클레임 승인 리스너가 `raw_response` 에 남긴 메타를 쓴다 — 없으면(구형 건) 현재 설정
     * 자격증명을 그대로 쓴다.
     *
     * @param  NicePaymentsApiService  $apiService  API 서비스
     * @param  ClaimPayment  $payment  결제 건
     */
    private function restoreCredentials(NicePaymentsApiService $apiService, ClaimPayment $payment): void
    {
        $raw = is_array($payment->raw_response) ? $payment->raw_response : [];
        $mid = trim((string) ($raw['mid'] ?? ''));

        if ($mid === '' || ! array_key_exists('is_test_mode', $raw)) {
            return;
        }

        $apiService->useStoredCredentials((bool) $raw['is_test_mode'], $mid);
    }

    /**
     * 결제 통화 기준 청구 총액을 반환합니다. (판정 SSoT 는 모듈)
     *
     * 환산 조건은 모듈이 `$amount` 를 넘길 때 쓰는 것과 같아야 한다 — 사본을 두면 모듈이
     * 조건을 바꿀 때 이쪽만 낡아 부분/전액 취소 판정이 뒤집힌다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return float 결제 통화 기준 총액
     */
    private function paidLocalTotal(ClaimPayment $payment): float
    {
        return app(ClaimPaymentService::class)->resolvePaymentCurrencyTotal($payment);
    }

    /**
     * 실패 반환값을 조립합니다.
     *
     * @param  string  $code  실패 코드
     * @param  string|null  $message  실패 사유 (관리자 진단용)
     * @return array 실패 결과
     */
    private function failure(string $code, ?string $message): array
    {
        return [
            'handled' => true,
            'success' => false,
            'transaction_id' => null,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }
}
