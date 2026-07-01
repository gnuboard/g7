<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayKginicis\Services\CbtReconciliationService;
use Plugins\Sirsoft\PayKginicis\Services\KgInicisApiService;

class AdminCbtReconciliationController extends AdminBaseController
{
    public function __construct(
        private readonly CbtReconciliationService $reconciliationService,
        private readonly KgInicisApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * CBT 조정 레코드를 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 조정 레코드 응답
     */
    public function show(string $orderNumber): JsonResponse
    {
        return ResponseHelper::success('messages.success', $this->reconciliationService->get($orderNumber));
    }

    /**
     * CBT 자동환불 재시도를 수행합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 재시도 결과 응답
     */
    public function retryRefund(string $orderNumber): JsonResponse
    {
        $record = $this->reconciliationService->claimRefundRetry($orderNumber);

        if (! $record) {
            return ResponseHelper::pluginError(
                'sirsoft-pay_kginicis',
                'messages.cbt_reconciliation.not_retryable',
                422,
            );
        }

        $tid = (string) $record['tid'];
        $amount = (int) ($record['amount'] ?? 0);
        $retryCount = (int) ($record['retry_count'] ?? 0);
        $reason = '관리자 CBT 자동환불 재시도';

        try {
            $this->apiService->useStoredCbtCredentials(
                (bool) ($record['is_test_mode'] ?? true),
                (string) ($record['cbt_mid'] ?? ''),
            );

            $refundResult = $this->apiService->refundCbtPayment($tid, null, $reason);

            $updated = $this->reconciliationService->record($orderNumber, [
                'status' => CbtReconciliationService::STATUS_AUTO_REFUNDED,
                'manual_action_required' => false,
                'tid' => $tid,
                'amount' => $amount,
                'reason' => (string) ($record['reason'] ?? ''),
                'refund_error' => null,
                'refund_result' => $this->sanitizeRefundResponse($refundResult),
                'last_retry_at' => now()->toIso8601String(),
                'last_retry_error' => null,
                'retry_count' => $retryCount,
            ]);

            return ResponseHelper::pluginSuccess(
                'sirsoft-pay_kginicis',
                'messages.cbt_reconciliation.retry_success',
                $updated,
            );
        } catch (\Throwable $e) {
            Log::error('KG Inicis CBT admin refund retry failed', [
                'order_number' => $orderNumber,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            $this->reconciliationService->record($orderNumber, [
                'status' => CbtReconciliationService::STATUS_MANUAL_REFUND_REQUIRED,
                'manual_action_required' => true,
                'tid' => $tid,
                'amount' => $amount,
                'refund_error' => $e->getMessage(),
                'last_retry_at' => now()->toIso8601String(),
                'last_retry_error' => $e->getMessage(),
                'retry_count' => $retryCount,
            ]);

            return ResponseHelper::pluginError(
                'sirsoft-pay_kginicis',
                'messages.cbt_reconciliation.retry_failed',
                502,
            );
        }
    }

    private function sanitizeRefundResponse(array $response): array
    {
        return array_intersect_key($response, array_flip([
            'resultCode',
            'resultMsg',
            'tid',
            'cancelDate',
            'cancelTime',
            'prtcCode',
        ]));
    }
}
