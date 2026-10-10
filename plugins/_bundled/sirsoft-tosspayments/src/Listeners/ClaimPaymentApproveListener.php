<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Plugins\Sirsoft\Tosspayments\Exceptions\TossPaymentsApiException;
use Plugins\Sirsoft\Tosspayments\Services\TossPaymentsApiService;

/**
 * 클레임 추가결제 승인 리스너
 *
 * 이커머스 모듈의 `claim_payment.approve` 필터를 구독해 토스페이먼츠 Confirm API 로
 * 클레임 결제 건(CP 채번)을 승인합니다. 계약은 이커머스 모듈
 * `modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` "결제 플러그인 연동 계약 (필터 훅)" 절이 단일 출처입니다.
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

        // 결제창이 열려 있는 동안 청구액은 바뀔 수 있다 — 신청서 수정(사유가 바뀌면 귀책이
        // 바뀌어 왕복 배송비가 청구에서 빠진다)·관리자 재산정이 모두 `settleChargeDifference()`
        // 로 결제행 `amount` 를 갱신한다. 낡은 창으로 결제하면 PG 는 옛 금액을 승인한다.
        //
        // 그 대조를 승인 **뒤**로 미루면(모듈의 `paidAmountMatches()`) 고객 돈이 실제로 빠져나갔다가
        // 망취소로 돌아오는 왕복이 생기고, 보상이 실패하면 화면은 실패인데 PG 는 청구된 채 남는다.
        // 모듈이 통화 축에 이미 같은 원칙을 적어 두었다 — 「진입도 같은 판정으로 닫는다 — 열어
        // 두면 PG 승인이 확정된 **뒤**에 금액 불일치로 거절되어 망취소 보상이 필요해진다」.
        // 형제 플러그인도 승인 리스너에서 청구액 기준을 먼저 세운다 — nhnkcp·nicepayments 는 콜백
        // 금액을 승인 호출 전에 대조하고, kginicis 는 콜백 금액을 신뢰할 수 없어(서명 발급 단계에서
        // 대조) 승인 응답 금액으로 대조한다.
        //
        // 기준을 세울 수 없으면(주문 삭제 등 통화 스냅샷 부재) 통과시키지 않는다 — 근거 없는
        // "일치" 로 승인을 부르면 그 결과를 되돌릴 방법이 없다.
        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($payment);

        if ($charge === null || $amount !== (int) $charge['minor_unit_amount']) {
            Log::warning('토스페이먼츠 클레임 결제 승인 전 금액 불일치', [
                'payment_number' => $payment->payment_number,
                'callback_amount' => $amount,
                'expected' => $charge['minor_unit_amount'] ?? null,
            ]);

            return $this->failure('AMOUNT_MISMATCH', __('sirsoft-tosspayments::messages.claim.amount_mismatch'));
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

            // 승인 응답이 이 결제 건·이 금액의 것인지 **여기서** 확인하고, 아니면 여기서 취소한다.
            //
            // 모듈도 `paid_amount` 로 한 번 더 대조하고, 그 거절의 보상은 `claim_payment.void_approval`
            // 필터(이 리스너의 `voidApproval()`)가 맡는다. 그래도 여기서 먼저 판정하는 것은 모듈이 보지
            // 않는 축(응답 주문번호)까지 한 번에 닫고, 서버 콜백·클라이언트 승인 API
            // (`claim-payments/{no}/approve` → `respondApprove`) 어느 경로로 들어와도 같은 판정·같은
            // 취소가 일어나게 하기 위해서다 (#599 validate 07 #8).
            //
            // 승인액은 **응답** 값만 쓴다 — 응답에 없을 때 콜백 금액으로 채우면 대조가 항등식이 된다.
            $approvedAmount = isset($response['totalAmount']) && is_numeric($response['totalAmount'])
                ? (int) $response['totalAmount']
                : null;
            $responseOrderId = (string) ($response['orderId'] ?? '');

            if ($approvedAmount !== $amount || ($responseOrderId !== '' && $responseOrderId !== $payment->payment_number)) {
                $orderIdMismatch = $responseOrderId !== '' && $responseOrderId !== $payment->payment_number;

                Log::error('토스페이먼츠 클레임 결제 승인 응답이 결제 건과 어긋나 취소합니다', [
                    'payment_number' => $payment->payment_number,
                    'payment_key' => $paymentKey,
                    'expected_amount' => $amount,
                    'approved_amount' => $approvedAmount,
                    'response_order_id' => $responseOrderId,
                ]);

                $this->cancelConfirmationQuietly($apiService, $paymentKey, $payment);

                return $orderIdMismatch
                    ? $this->failure('ORDER_ID_MISMATCH', __('sirsoft-tosspayments::messages.claim.order_id_mismatch'), $response)
                    : $this->failure('AMOUNT_MISMATCH', __('sirsoft-tosspayments::messages.claim.amount_mismatch'), $response);
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
                // 결제 통화 최소 화폐단위 정수 — 위에서 청구액과 같음을 확인한 응답 금액이다.
                // 모듈이 스냅샷 환산 청구액과 한 번 더 정수 비교한다.
                'paid_amount' => $approvedAmount,
                'raw_response' => $response,
                'error_code' => null,
                'error_message' => null,
            ];
        } catch (\Exception $e) {
            // 토스가 거절 코드를 줬으면 그것을 올린다 — 이 값이 결제행 `pg_error_code` 가 되고
            // 다시 신청서 상세의 「실패 코드 {code}」가 된다. 일반 토큰으로 접으면 한도 초과·
            // 분실 카드·세션 만료가 전부 같은 실패로 보여, 구매자는 무엇을 바꿔 다시 시도할지
            // 알 수 없고 관리자도 건을 구분하지 못한다 (#599 3차 실측 F-5).
            //
            // 응답을 얻지 못한 실패(네트워크 단절 등)에는 코드가 없다 — 그때만 `PG_API_ERROR`
            // 다. 그 자리는 「거절당했다」가 아니라 「PG 와 통신 자체가 실패했다」는 별개의 사실이다.
            $pgCode = $e instanceof TossPaymentsApiException ? $e->getPgCode() : null;
            $pgBody = $e instanceof TossPaymentsApiException ? $e->getPgBody() : null;

            Log::error('토스페이먼츠 클레임 결제 승인 실패', [
                'payment_number' => $payment->payment_number,
                'payment_key' => $paymentKey,
                'pg_code' => $pgCode,
                'error' => $e->getMessage(),
            ]);

            return $this->failure($pgCode ?? 'PG_API_ERROR', $e->getMessage(), $pgBody);
        }
    }

    /**
     * 확정된 승인을 조용히 취소합니다. (보상 — 실패해도 흐름은 지속, 운영자 대사용 로그를 남긴다)
     *
     * @param  TossPaymentsApiService  $apiService  API 서비스
     * @param  string  $paymentKey  토스 결제 키
     * @param  ClaimPayment  $payment  결제 건 (로그 문맥)
     * @return bool 취소 요청이 성공했으면 true
     */
    private function cancelConfirmationQuietly(TossPaymentsApiService $apiService, string $paymentKey, ClaimPayment $payment): bool
    {
        try {
            $apiService->cancelPayment($paymentKey, __('sirsoft-tosspayments::messages.claim.amount_mismatch_cancel_reason'));

            return true;
        } catch (\Exception $e) {
            Log::error('토스페이먼츠 클레임 결제 보상 취소 실패 — 수동 취소 필요', [
                'payment_number' => $payment->payment_number,
                'payment_key' => $paymentKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 모듈이 승인 뒤 거절한 클레임 결제의 토스 승인을 취소합니다. (`claim_payment.void_approval` 필터)
     *
     * 이 리스너가 승인 응답을 먼저 대조하므로 보통은 불리지 않는다 — 모듈 쪽 대조가 따로 거절한
     * 때(예: 스냅샷 환산 규칙이 바뀐 경우)의 보상이다. 취소 키는 승인 결과의 거래 식별자(결제 키)를
     * 먼저 쓰고, 없으면 콜백의 결제 키를 쓴다.
     *
     * @param  array  $result  보상 결과 (기본값)
     * @param  ClaimPayment  $payment  거절된 결제 건
     * @param  array  $callback  승인 때 받은 콜백 파라미터
     * @param  array  $approveResult  승인 필터 결과 (transaction_id 등)
     * @return array 보상 결과 {handled, success, error_code, error_message}
     */
    public function voidApproval(array $result, ClaimPayment $payment, array $callback, array $approveResult): array
    {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        $paymentKey = (string) ($approveResult['transaction_id'] ?? '');

        if ($paymentKey === '') {
            $paymentKey = (string) ($callback['paymentKey'] ?? '');
        }

        if ($paymentKey === '') {
            return ['handled' => true, 'success' => false, 'error_code' => 'MISSING_PAYMENT_KEY', 'error_message' => null];
        }

        $cancelled = $this->cancelConfirmationQuietly($this->getApiService(), $paymentKey, $payment);

        return [
            'handled' => true,
            'success' => $cancelled,
            'error_code' => $cancelled ? null : 'PG_API_ERROR',
            'error_message' => null,
        ];
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
