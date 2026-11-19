<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청·응답 무변경 — 결제 행 잠금·meta 기록·주문 취소를 이커머스 공개 결제 계약으로 옮긴 내부 리팩토링 (#916)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\PaymentNotFound;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Plugins\Sirsoft\PayNicepayments\Concerns\GuardsOrderScope;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\VbankRefundRequest;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

/**
 * 가상계좌 입금 완료 건 환불 처리
 *
 * 입금이 완료된 가상계좌는 일반 환불 훅으로는 처리할 수 없습니다.
 * 나이스페이먼츠 API가 환불받을 계좌 정보(계좌번호·은행코드·예금주)를 요구하기 때문입니다.
 * 이 컨트롤러는 해당 정보를 직접 수집하여 취소 API를 호출합니다.
 */
class AdminVbankRefundController extends AdminBaseController
{
    use GuardsOrderScope;

    private const PG_PROVIDER_ID = 'nicepayments';

    /**
     * 의존성을 주입받습니다.
     *
     * @param  NicePaymentsApiService  $apiService  나이스페이먼츠 API 서비스
     * @param  PaymentGatewayBridge  $paymentGateway  이커머스 공개 결제 계약 (환불 선점·주문 취소·결과 기록)
     */
    public function __construct(
        private readonly NicePaymentsApiService $apiService,
        private readonly PaymentGatewayBridge $paymentGateway,
    ) {
        parent::__construct();
    }

    /**
     * POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund
     */
    /**
     * 가상계좌 입금 완료 건 환불 처리
     *
     * 일반 환불 훅으로 처리할 수 없는 vbank 입금 완료 건의 환불을 직접 수행.
     * NicePay 가 요구하는 환불 계좌 정보(계좌번호·은행코드·예금주)를 직접 수집.
     * 형식·길이 검증은 VbankRefundRequest 가 PG 호출 전에 차단한다.
     *
     * @param  VbankRefundRequest  $request  TID/MOID/CancelAmt + 환불계좌 정보
     * @return JsonResponse 환불 결과 또는 422 (입력 형식 위반)
     */
    public function refund(VbankRefundRequest $request): JsonResponse
    {
        if ($denied = $this->transactionScopeDenial(trim((string) $request->validated('tid')), 'sirsoft-ecommerce.claims.execute-refund')) {
            return $denied;
        }

        if ($denied = $this->orderScopeDenial(trim((string) $request->validated('moid')), 'sirsoft-ecommerce.claims.execute-refund')) {
            return $denied;
        }

        $tid = trim((string) $request->validated('tid'));
        $moid = trim((string) $request->validated('moid'));
        $cancelAmt = (int) $request->validated('cancel_amt');
        $cancelMsg = trim((string) ($request->validated('cancel_msg') ?? __('sirsoft-pay_nicepayments::messages.defaults.vbank_refund_msg')));
        $refundAcctNo = trim((string) $request->validated('refund_acct_no'));
        $refundBankCd = trim((string) $request->validated('refund_bank_cd'));
        $refundAcctNm = trim((string) $request->validated('refund_acct_nm'));

        $claimed = false;
        $pgCancelled = false;

        try {
            $claim = $this->claimRefund($moid, $tid, $cancelAmt);
            if (isset($claim['error_key'])) {
                return $this->refundError((string) $claim['error_key'], (int) $claim['status']);
            }

            $claimed = true;
            $this->useStoredCredentials($claim['meta']);

            $result = $this->apiService->cancelPayment(
                $tid,
                $moid,
                $cancelAmt,
                $cancelMsg,
                0,
                $refundAcctNo,
                $refundBankCd,
                $refundAcctNm,
            );
            $pgCancelled = true;

            $this->assertCancelResponseMatches($result, $tid, $cancelAmt);

            // 결제사 취소는 위에서 끝났다 — 계약이 주문 취소만 확정한다(결제사 취소를 다시 부르지 않음).
            $cancelled = $this->paymentGateway->cancelOrderForVbankRefund($moid, self::PG_PROVIDER_ID, $cancelMsg, Auth::id());

            $this->markRefundCompleted($moid, $cancelled, $result);

            Log::info('NicePayments: admin vbank refund success', [
                'tid' => $tid,
                'moid' => $moid,
                'cancel_amt' => $cancelAmt,
            ]);

            return ResponseHelper::success('common.success', $result);
        } catch (\Exception $e) {
            Log::error('NicePayments: admin vbank refund failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            if ($claimed) {
                $this->markRefundFailed($moid, $e, $pgCancelled);
            }

            return ResponseHelper::error('common.failed', 502, null);
        }
    }

    /**
     * 환불 대상 주문·결제를 잠그고 환불 가능 여부를 확인해 선점합니다.
     *
     * 판정과 선점 표식(`vbank_refund_status = processing`)이 이커머스 계약의 한 잠금(주문 → 결제 행) 안에서 일어난다.
     *
     * @param  string  $moid  주문번호
     * @param  string  $tid  거래번호
     * @param  int  $cancelAmt  취소 금액
     * @return array{meta?: array<string, mixed>, error_key?: string, status?: int} 선점 뒤 결제 meta 또는 실패 사유
     */
    private function claimRefund(string $moid, string $tid, int $cancelAmt): array
    {
        $rejection = null;

        try {
            $meta = $this->paymentGateway->mutatePaymentMetaWithLock(
                $moid,
                self::PG_PROVIDER_ID,
                function (array $meta, PaymentRecord $payment, OrderRecord $order) use ($tid, $cancelAmt, &$rejection): ?array {
                    $rejection = $this->refundRejection($meta, $payment, $order, $tid, $cancelAmt);
                    if ($rejection !== null) {
                        return null;
                    }

                    return array_merge($meta, [
                        'vbank_refund_status' => 'processing',
                        // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
                        'vbank_refund_started_at' => now()->toIso8601String(),
                    ]);
                },
            );
        } catch (PaymentNotFound) {
            // 주문이 없으면 404, 주문은 있는데 결제 행이 없으면 환불 대상 아님
            return $this->paymentGateway->findOrderByNumber($moid, self::PG_PROVIDER_ID) === null
                ? ['error_key' => 'vbank_refund_order_not_found', 'status' => 404]
                : ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
        } catch (ProviderMismatch) {
            // 다른 결제사가 결제한 행 — 이 플러그인이 환불할 결제가 아니다
            return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
        }

        if ($rejection !== null || $meta === null) {
            return $rejection ?? ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
        }

        return ['meta' => $meta];
    }

    /**
     * 잠긴 주문·결제 행으로 환불 가능 여부를 판정합니다.
     *
     * @param  array<string, mixed>  $meta  잠긴 결제 meta
     * @param  PaymentRecord  $payment  잠긴 결제 행
     * @param  OrderRecord  $order  잠긴 주문
     * @param  string  $tid  거래번호
     * @param  int  $cancelAmt  취소 금액
     * @return array{error_key: string, status: int}|null 거절 사유 (환불 가능하면 null)
     */
    private function refundRejection(array $meta, PaymentRecord $payment, OrderRecord $order, string $tid, int $cancelAmt): ?array
    {
        if ($order->orderStatus !== OrderStatusEnum::PAYMENT_COMPLETE || ! $order->hasActiveOptions) {
            return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
        }

        if (! $this->isRefundableVbankPayment($payment, $tid)) {
            return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
        }

        if (($meta['vbank_refund_status'] ?? null) === 'processing') {
            return ['error_key' => 'vbank_refund_already_processing', 'status' => 409];
        }

        if (in_array(($meta['vbank_refund_status'] ?? null), ['completed', 'pg_cancelled_domain_failed'], true)
            || $payment->isFullyCancelled) {
            return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
        }

        $cancellableAmount = (int) round($payment->cancellableAmount);
        if ($cancelAmt !== $cancellableAmount) {
            return ['error_key' => 'vbank_refund_amount_mismatch', 'status' => 422];
        }

        return null;
    }

    /**
     * 결제 레코드가 이 거래번호의 환불 가능한 나이스페이먼츠 가상계좌 결제인지 판별합니다.
     *
     * @param  PaymentRecord  $payment  결제 레코드
     * @param  string  $tid  거래번호
     * @return bool 환불 가능하면 true
     */
    private function isRefundableVbankPayment(PaymentRecord $payment, string $tid): bool
    {
        return $payment->pgProvider === self::PG_PROVIDER_ID
            && $payment->isVirtualAccount
            && $payment->paymentStatus === PaymentStatusEnum::PAID
            && $payment->paidAt !== null
            && $payment->vbankNumber !== null
            && hash_equals((string) $payment->transactionId, $tid);
    }

    /**
     * 결제 당시의 상점 자격으로 API 서비스를 맞춥니다.
     *
     * @param  array<string, mixed>  $meta  결제 meta
     * @return void 반환값 없음
     */
    private function useStoredCredentials(array $meta): void
    {
        $mid = trim((string) ($meta['mid'] ?? ''));
        if ($mid === '' || ! array_key_exists('is_test_mode', $meta)) {
            return;
        }

        $this->apiService->useStoredCredentials((bool) $meta['is_test_mode'], $mid);
    }

    /**
     * PG 취소 응답의 거래번호·금액이 요청과 같은지 확인합니다.
     *
     * @param  array<string, mixed>  $result  PG 취소 응답
     * @param  string  $tid  거래번호
     * @param  int  $cancelAmt  취소 금액
     * @return void 반환값 없음
     *
     * @throws \RuntimeException 응답이 요청과 다른 경우
     */
    private function assertCancelResponseMatches(array $result, string $tid, int $cancelAmt): void
    {
        $responseTid = trim((string) ($result['TID'] ?? ''));
        if ($responseTid !== '' && ! hash_equals($tid, $responseTid)) {
            throw new \RuntimeException('NicePayments vbank refund TID mismatch');
        }

        if (isset($result['CancelAmt']) && (int) $result['CancelAmt'] !== $cancelAmt) {
            throw new \RuntimeException('NicePayments vbank refund amount mismatch');
        }
    }

    /**
     * 결제 레코드에 환불 완료를 기록합니다.
     *
     * @param  string  $moid  주문번호
     * @param  OrderRecord  $cancelled  주문 취소 뒤 주문
     * @param  array<string, mixed>  $result  PG 취소 응답
     * @return void 반환값 없음
     */
    private function markRefundCompleted(string $moid, OrderRecord $cancelled, array $result): void
    {
        if ($cancelled->payment === null) {
            return;
        }

        $this->paymentGateway->mergePaymentMeta($moid, self::PG_PROVIDER_ID, [
            'vbank_refund_status' => 'completed',
            // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
            'vbank_refunded_at' => now()->toIso8601String(),
            'vbank_refund_result_code' => $result['ResultCode'] ?? null,
            'vbank_refund_tid' => $result['TID'] ?? $cancelled->payment->transactionId,
        ]);
    }

    /**
     * 결제 레코드에 환불 실패를 기록합니다.
     *
     * 계약이 잠금 아래 최신 결제 meta 에 병합한다(다른 요청이 그사이 쓴 값을 덮지 않는다).
     *
     * @param  string  $moid  주문번호
     * @param  \Throwable  $e  실패 원인
     * @param  bool  $pgCancelled  PG 취소까지는 성공했는지
     * @return void 반환값 없음
     */
    private function markRefundFailed(string $moid, \Throwable $e, bool $pgCancelled): void
    {
        $this->paymentGateway->mergePaymentMeta($moid, self::PG_PROVIDER_ID, [
            'vbank_refund_status' => $pgCancelled ? 'pg_cancelled_domain_failed' : 'failed',
            // audit:allow datetime-display-user-timezone reason: ISO8601(오프셋 포함) API 기계값 — 표시는 *_formatted
            'vbank_refund_failed_at' => now()->toIso8601String(),
            'vbank_refund_error' => $e->getMessage(),
        ]);
    }

    /**
     * 환불 실패 사유 키로 오류 응답을 만듭니다.
     *
     * @param  string  $key  실패 사유 메시지 키
     * @param  int  $status  HTTP 상태 코드
     * @return JsonResponse 오류 응답
     */
    private function refundError(string $key, int $status): JsonResponse
    {
        return ResponseHelper::error('common.failed', $status, [
            'message' => __("sirsoft-pay_nicepayments::messages.errors.{$key}"),
        ]);
    }
}
