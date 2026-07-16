<?php

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class AdminTransactionController extends AdminBaseController
{
    use ResolvesEasyPayDisplay;

    public function __construct(
        private NicePaymentsApiService $apiService
    ) {
        parent::__construct();
    }

    /**
     * TID 단건 거래 조회
     *
     * NicePay 단건 거래 조회 API 를 호출하고 로컬 DB 의 보조 정보(에스크로 여부,
     * 테스트 모드 플래그)를 합쳐 반환한다.
     *
     * @param  Request  $request  tid 입력 폼
     * @return JsonResponse 거래 정보 + _local_is_escrow / EscrowYN / _is_test_mode 보강
     */
    public function query(Request $request): JsonResponse
    {
        $tid = trim((string) $request->input('tid', ''));

        if ($tid === '') {
            return ResponseHelper::error('messages.failed', 422, ['tid' => [__('sirsoft-pay_nicepayments::messages.errors.tid_required')]]);
        }

        return $this->queryByTid($tid);
    }

    /**
     * 주문번호로 거래 조회 (TID 자동 매핑)
     *
     * 어드민 주문 상세에서 거래 조회 버튼 클릭 시 사용. ecommerce_order_payments
     * 의 transaction_id 를 찾아 queryByTid 로 위임.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 거래 정보 또는 매핑 없을 시 null
     */
    public function queryByOrder(string $orderNumber): JsonResponse
    {
        $payment = DB::table('ecommerce_order_payments')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_payments.order_id')
            ->where('ecommerce_orders.order_number', $orderNumber)
            ->whereNotNull('ecommerce_order_payments.transaction_id')
            ->where('ecommerce_order_payments.transaction_id', '!=', '')
            ->whereIn('ecommerce_order_payments.pg_provider', ['nicepayments', 'nicepay'])
            ->select(['ecommerce_order_payments.transaction_id'])
            ->first();

        if (!$payment) {
            return ResponseHelper::success('messages.success', null);
        }

        return $this->queryByTid($payment->transaction_id);
    }

    private function queryByTid(string $tid): JsonResponse
    {
        try {
            $result = $this->apiService->queryTransaction($tid);

            $localPayment = DB::table('ecommerce_order_payments')
                ->where('transaction_id', $tid)
                ->select(['is_escrow', 'payment_meta', 'payment_method', 'embedded_pg_provider'])
                ->first();

            $result['_local_is_escrow'] = (bool) ($localPayment?->is_escrow ?? false);
            $display = $localPayment ? $this->resolvePaymentDisplay($localPayment) : [
                'payment_method_label' => null,
                'payment_method_display_label' => null,
                'embedded_pg_provider' => null,
                'embedded_pg_provider_label' => null,
            ];
            $result['_base_pay_method_label'] = $display['payment_method_label'];
            $result['_embedded_pg_provider'] = $display['embedded_pg_provider'];
            $result['_embedded_pg_provider_label'] = $display['embedded_pg_provider_label'];
            $result['_pay_method_label'] = $display['payment_method_display_label'];
            $result['payment_method_display_label'] = $display['payment_method_display_label'];

            if ($localPayment?->payment_meta) {
                $meta = json_decode($localPayment->payment_meta, true);
                $result['EscrowYN'] = $meta['pg_raw_response']['EscrowYN']
                    ?? ($result['EscrowYN'] ?? 'N');
                $result['_is_test_mode'] = (bool) ($meta['is_test_mode'] ?? false);
            }

            return ResponseHelper::success('messages.success', $result);
        } catch (\Exception $e) {
            Log::error('NicePayments queryTransaction failed', [
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);
            return ResponseHelper::error('messages.failed', 502, null);
        }
    }
}
