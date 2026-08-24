<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * 클레임 추가결제 승인 리스너
 *
 * 이커머스 모듈의 `claim_payment.approve` 필터를 구독해 토스페이먼츠 Confirm API 로
 * 클레임 결제 건(CP 채번)을 승인합니다. 계약은 이커머스 모듈
 * `docs/api/claim-payments.md` "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
 *
 * 주문 결제와 달리 `Order`/`OrderPayment` 를 전제하지 않는다 — 인자로 받은
 * `ClaimPayment` 와 콜백 파라미터만 사용한다.
 */
class ClaimPaymentApproveListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'tosspayments';

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
     * @param  array  $callback  프론트/콜백이 전달한 PG 파라미터 (paymentKey, orderId, amount)
     * @return array 승인 결과 {handled, success, transaction_id, paid_amount, raw_response, error_code, error_message}
     */
    public function processApprove(array $result, ClaimPayment $payment, array $callback): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $paymentKey = (string) ($callback['paymentKey'] ?? '');
        $orderId = (string) ($callback['orderId'] ?? '');
        $amount = (int) ($callback['amount'] ?? 0);

        if ($paymentKey === '' || $orderId === '' || $amount <= 0) {
            return $this->failure('INVALID_CALLBACK', __('sirsoft-tosspayments::messages.claim.invalid_callback'));
        }

        // 콜백 orderId 는 결제창을 연 CP 결제번호와 같아야 한다 — 다른 건의 paymentKey 를
        // 이 결제 건에 붙이는 교차 승인을 Confirm 호출 전에 차단한다.
        if ($orderId !== $payment->payment_number) {
            return $this->failure('ORDER_ID_MISMATCH', __('sirsoft-tosspayments::messages.claim.order_id_mismatch'));
        }

        try {
            $apiService = $this->getApiService();
            $response = $apiService->confirmPayment($paymentKey, $orderId, $amount);

            // 입금 대기 수단(가상계좌)은 클레임 결제 상태 모델에 대기 상태가 없어 지원하지
            // 않는다 — 발급을 그대로 두면 고객이 입금해도 확정할 경로가 없으므로 즉시 취소한다.
            if (($response['status'] ?? '') === 'WAITING_FOR_DEPOSIT') {
                try {
                    $apiService->cancelPayment($paymentKey, __('sirsoft-tosspayments::messages.claim.deposit_not_supported'));
                } catch (\Exception $cancelException) {
                    Log::error('토스페이먼츠 클레임 결제 가상계좌 발급 취소 실패', [
                        'payment_number' => $payment->payment_number,
                        'payment_key' => $paymentKey,
                        'error' => $cancelException->getMessage(),
                    ]);
                }

                return $this->failure(
                    'CLAIM_DEPOSIT_METHOD_NOT_SUPPORTED',
                    __('sirsoft-tosspayments::messages.claim.deposit_not_supported'),
                    $response,
                );
            }

            Log::info('토스페이먼츠 클레임 결제 승인 성공', [
                'payment_number' => $payment->payment_number,
                'payment_key' => $paymentKey,
                'amount' => $amount,
            ]);

            return [
                'handled' => true,
                'success' => true,
                'transaction_id' => (string) ($response['paymentKey'] ?? $paymentKey),
                // 결제 통화 최소 화폐단위 정수 — 모듈이 스냅샷 환산 청구액과 정수 비교한다.
                'paid_amount' => (int) ($response['totalAmount'] ?? $amount),
                'raw_response' => $response,
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 클레임 결제 승인 실패', [
                'payment_number' => $payment->payment_number,
                'payment_key' => $paymentKey,
                'error' => $e->getMessage(),
            ]);

            return $this->failure('PG_API_ERROR', $e->getMessage());
        }
    }

    /**
     * 실패 반환값을 조립합니다.
     *
     * @param  string  $code  실패 코드
     * @param  string|null  $message  실패 사유 (관리자 진단용)
     * @param  array|null  $rawResponse  PG 원본 응답
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
            'error_message' => $message,
        ];
    }

    /**
     * API 서비스 인스턴스를 가져옵니다.
     *
     * @return TossPaymentsApiService API 서비스
     */
    protected function getApiService(): TossPaymentsApiService
    {
        return app(TossPaymentsApiService::class);
    }
}
