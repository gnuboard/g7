<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class AdminEscrowController extends AdminBaseController
{
    public function __construct(
        private readonly NicePaymentsApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * 주문의 에스크로 결제 목록 조회
     *
     * GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments
     */
    /**
     * 주문의 에스크로 결제 목록 조회
     *
     * 에스크로(EscrowYN=Y) 로 결제된 가상계좌/카드 결제 건들의 TID/금액/상태를 반환.
     * 어드민 에스크로 배송 등록 화면에서 결제 선택 UI 데이터 소스로 사용.
     *
     * @param  string  $orderNumber  주문번호
     * @return JsonResponse 에스크로 결제 목록
     */
    public function getEscrowPayments(string $orderNumber): JsonResponse
    {
        $payments = DB::table('ecommerce_order_payments')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_payments.order_id')
            ->where('ecommerce_orders.order_number', $orderNumber)
            ->where('ecommerce_order_payments.pg_provider', 'nicepayments')
            ->where('ecommerce_order_payments.is_escrow', 1)
            ->get(['ecommerce_order_payments.id', 'ecommerce_order_payments.transaction_id', 'ecommerce_order_payments.payment_method', 'ecommerce_order_payments.payment_status']);

        return ResponseHelper::success('messages.success', [
            'escrow_payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'transaction_id' => $p->transaction_id,
                'payment_method' => $p->payment_method,
                'payment_status' => $p->payment_status,
            ])->values()->all(),
        ]);
    }

    /**
     * 에스크로 배송 등록
     *
     * POST /api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery
     */
    /**
     * 에스크로 배송 등록
     *
     * NicePay escrow_process.jsp 호출하여 배송 정보(택배사/송장번호/수령인 등)를
     * NicePay 측에 등록. 등록 완료 시 구매자에게 자동으로 구매확정 안내가 발송됨.
     *
     * @param  Request  $request  배송 정보 폼
     * @return JsonResponse 등록 결과 + ResultCode
     */
    public function registerDelivery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tid' => 'required|string',
            'delivery_name' => 'required|string|max:100',
            'tracking_number' => 'required|string|max:100',
            'buyer_address' => 'required|string|max:200',
            'register_name' => 'required|string|max:50',
        ]);

        try {
            $payment = $this->findRegisterableEscrowPayment($validated['tid']);
            if (! $payment) {
                return ResponseHelper::error('messages.failed', 422, [
                    'message' => ['Escrow payment not found for the requested NicePayments TID.'],
                ]);
            }

            $this->useStoredCredentials($payment);

            $result = $this->apiService->registerEscrowDelivery(
                tid: $validated['tid'],
                deliveryName: $validated['delivery_name'],
                trackingNumber: $validated['tracking_number'],
                buyerAddress: $validated['buyer_address'],
                registerName: $validated['register_name'],
            );

            Log::info('NicePayments: escrow delivery registered', [
                'tid' => $validated['tid'],
                'delivery_name' => $validated['delivery_name'],
                'tracking_number' => $validated['tracking_number'],
            ]);

            return ResponseHelper::success('messages.success', $result);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 502, null);
        }
    }

    private function findRegisterableEscrowPayment(string $tid): ?OrderPayment
    {
        return OrderPayment::query()
            ->where('pg_provider', 'nicepayments')
            ->where('is_escrow', true)
            ->where('payment_status', PaymentStatusEnum::PAID)
            ->where('transaction_id', $tid)
            ->first();
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
}
