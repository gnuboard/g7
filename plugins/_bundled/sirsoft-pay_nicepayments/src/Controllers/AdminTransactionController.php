<?php

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Concerns\ResolvesEasyPayDisplay;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\TransactionQueryRequest;
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
     * @param  TransactionQueryRequest  $request  tid 입력 폼
     * @return JsonResponse 거래 정보 + _local_is_escrow / EscrowYN / _is_test_mode 보강
     */
    public function query(TransactionQueryRequest $request): JsonResponse
    {
        return $this->queryByTid(trim((string) $request->validated('tid')));
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
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $payment = DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->whereNotNull('p.transaction_id')
            ->where('p.transaction_id', '!=', '')
            ->whereIn('p.pg_provider', ['nicepayments', 'nicepay'])
            ->select(['p.transaction_id'])
            ->first();

        if (! $payment) {
            return ResponseHelper::success('common.success', null);
        }

        return $this->queryByTid($payment->transaction_id);
    }

    private function queryByTid(string $tid): JsonResponse
    {
        try {
            $result = $this->apiService->queryTransaction($tid);

            // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
            $localPayment = DB::table((new OrderPayment)->getTable())
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

            return ResponseHelper::success('common.success', $result);
        } catch (\Exception $e) {
            Log::error('NicePayments queryTransaction failed', [
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 502, null);
        }
    }
}
