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
     * @return array{order?: Order, payment?: OrderPayment, error_key?: string, status?: int}
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

    private function isRefundableVbankPayment(OrderPayment $payment, string $tid): bool
    {
        return $payment->pg_provider === 'nicepayments'
            && $payment->isVirtualAccount()
            && $payment->payment_status === PaymentStatusEnum::PAID
            && $payment->paid_at !== null
            && $payment->vbank_number !== null
            && hash_equals((string) $payment->transaction_id, $tid);
    }

    private function useStoredCredentials(OrderPayment $payment): void
    {
        $meta = $payment->payment_meta ?? [];
        $mid = trim((string) ($meta['mid'] ?? ''));
        if ($mid === '' || ! array_key_exists('is_test_mode', $meta)) {
            return;
        }

        $this->apiService->useStoredCredentials((bool) $meta['is_test_mode'], $mid);
    }

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

    private function refundError(string $key, int $status): JsonResponse
    {
        return ResponseHelper::error('common.failed', $status, [
            'message' => __("sirsoft-pay_nicepayments::messages.errors.{$key}"),
        ]);
    }
}
