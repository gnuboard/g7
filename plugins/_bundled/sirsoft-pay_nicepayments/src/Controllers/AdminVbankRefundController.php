<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
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

    /**
     * 의존성을 주입받습니다.
     *
     * @param  NicePaymentsApiService  $apiService  나이스페이먼츠 API 서비스
     * @param  OrderCancellationService  $cancellationService  주문 취소 서비스
     */
    public function __construct(
        private readonly NicePaymentsApiService $apiService,
        private readonly OrderCancellationService $cancellationService,
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

        $claimedPayment = null;
        $pgCancelled = false;

        try {
            $claim = $this->claimRefund($moid, $tid, $cancelAmt);
            if (isset($claim['error_key'])) {
                return $this->refundError((string) $claim['error_key'], (int) $claim['status']);
            }

            /** @var Order $order */
            $order = $claim['order'];
            /** @var OrderPayment $payment */
            $payment = $claim['payment'];
            $claimedPayment = $payment;

            $this->useStoredCredentials($payment);

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

            $cancellation = $this->cancellationService->cancelOrder(
                $order->fresh(['options', 'payment', 'shippings']),
                $cancelMsg,
                null,
                Auth::id(),
                false,
            );

            $this->markRefundCompleted($cancellation->order->payment, $result);

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

            $this->markRefundFailed($claimedPayment, $e, $pgCancelled);

            return ResponseHelper::error('common.failed', 502, null);
        }
    }

    /**
     * 환불 대상 주문·결제를 잠그고 환불 가능 여부를 확인해 선점합니다.
     *
     * @param  string  $moid  주문번호
     * @param  string  $tid  거래번호
     * @param  int  $cancelAmt  취소 금액
     * @return array{order?: Order, payment?: OrderPayment, error_key?: string, status?: int} 선점 결과 또는 실패 사유
     */
    private function claimRefund(string $moid, string $tid, int $cancelAmt): array
    {
        return DB::transaction(function () use ($moid, $tid, $cancelAmt): array {
            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $order = Order::query()
                ->where('order_number', $moid)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return ['error_key' => 'vbank_refund_order_not_found', 'status' => 404];
            }

            if ($order->order_status !== OrderStatusEnum::PAYMENT_COMPLETE
                || ! $order->options()->where('option_status', '!=', OrderStatusEnum::CANCELLED->value)->exists()) {
                return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
            }

            $payment = $order->payment()->lockForUpdate()->first();
            if (! $payment || ! $this->isRefundableVbankPayment($payment, $tid)) {
                return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
            }

            $meta = $payment->payment_meta ?? [];
            if (($meta['vbank_refund_status'] ?? null) === 'processing') {
                return ['error_key' => 'vbank_refund_already_processing', 'status' => 409];
            }

            if (in_array(($meta['vbank_refund_status'] ?? null), ['completed', 'pg_cancelled_domain_failed'], true)
                || $payment->isFullyCancelled()) {
                return ['error_key' => 'vbank_refund_invalid_payment', 'status' => 422];
            }

            $cancellableAmount = (int) round((float) $payment->getCancellableAmount());
            if ($cancelAmt !== $cancellableAmount) {
                return ['error_key' => 'vbank_refund_amount_mismatch', 'status' => 422];
            }

            $payment->update([
                'payment_meta' => array_merge($meta, [
                    'vbank_refund_status' => 'processing',
                    'vbank_refund_started_at' => now()->toIso8601String(),
                ]),
            ]);

            return [
                'order' => $order->fresh(['options', 'payment', 'shippings']),
                'payment' => $payment->fresh(),
            ];
        });
    }

    /**
     * 결제 레코드가 이 거래번호의 환불 가능한 나이스페이먼츠 가상계좌 결제인지 판별합니다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @param  string  $tid  거래번호
     * @return bool 환불 가능하면 true
     */
    private function isRefundableVbankPayment(OrderPayment $payment, string $tid): bool
    {
        return $payment->pg_provider === 'nicepayments'
            && $payment->isVirtualAccount()
            && $payment->payment_status === PaymentStatusEnum::PAID
            && $payment->paid_at !== null
            && $payment->vbank_number !== null
            && hash_equals((string) $payment->transaction_id, $tid);
    }

    /**
     * 결제 당시의 상점 자격으로 API 서비스를 맞춥니다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @return void 반환값 없음
     */
    private function useStoredCredentials(OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];
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
     * @param  OrderPayment|null  $payment  결제 레코드
     * @param  array<string, mixed>  $result  PG 취소 응답
     * @return void 반환값 없음
     */
    private function markRefundCompleted(?OrderPayment $payment, array $result): void
    {
        if (! $payment) {
            return;
        }

        $payment->update([
            'payment_meta' => array_merge($payment->payment_meta ?? [], [
                'vbank_refund_status' => 'completed',
                'vbank_refunded_at' => now()->toIso8601String(),
                'vbank_refund_result_code' => $result['ResultCode'] ?? null,
                'vbank_refund_tid' => $result['TID'] ?? $payment->transaction_id,
            ]),
        ]);
    }

    /**
     * 결제 레코드에 환불 실패를 기록합니다.
     *
     * @param  OrderPayment|null  $payment  결제 레코드
     * @param  \Throwable  $e  실패 원인
     * @param  bool  $pgCancelled  PG 취소까지는 성공했는지
     * @return void 반환값 없음
     */
    private function markRefundFailed(?OrderPayment $payment, \Throwable $e, bool $pgCancelled): void
    {
        if (! $payment) {
            return;
        }

        $payment->refresh();
        $payment->update([
            'payment_meta' => array_merge($payment->payment_meta ?? [], [
                'vbank_refund_status' => $pgCancelled ? 'pg_cancelled_domain_failed' : 'failed',
                'vbank_refund_failed_at' => now()->toIso8601String(),
                'vbank_refund_error' => $e->getMessage(),
            ]),
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
