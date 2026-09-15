<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

// audit:allow api-doc-coverage 요청 파라미터·응답 구조 무변경 — 테이블명 리터럴을 모델 파생으로 정리한 내부 리팩토링 (#571)

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\EscrowDeliveryRegisterRequest;
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
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
        $payments = DB::table((new OrderPayment)->getTable().' as p')
            ->join((new Order)->getTable().' as o', 'o.id', '=', 'p.order_id')
            ->where('o.order_number', $orderNumber)
            ->where('p.pg_provider', 'nicepayments')
            ->where('p.is_escrow', 1)
            ->get(['p.id', 'p.transaction_id', 'p.payment_method', 'p.payment_status']);

        return ResponseHelper::success('common.success', [
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
     * @param  EscrowDeliveryRegisterRequest  $request  배송 정보 폼
     * @return JsonResponse 등록 결과 + ResultCode
     */
    public function registerDelivery(EscrowDeliveryRegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $payment = $this->findRegisterableEscrowPayment($validated['tid']);
            if (! $payment) {
                return ResponseHelper::error('common.failed', 422, [
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

            return ResponseHelper::success('common.success', $result);
        } catch (\Exception $e) {
            // 메시지 키 자리에는 원문 금지 — 키 해석에 실패해 원문이 그대로 나간다.
            // 단 errors 페이로드는 관리자 전용 면의 진단 통로다(형제 KCP/이니시스
            // 에스크로와 동형) — 비우면 운영자가 서버 로그 없이는 실패 원인을 모른다.
            Log::error('NicePayments: escrow delivery registration failed', [
                'tid' => $validated['tid'],
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('common.failed', 502, [
                'message' => [$e->getMessage()],
            ]);
        }
    }

    private function findRegisterableEscrowPayment(string $tid): ?OrderPayment
    {
        // audit:allow controller-direct-data-access reason: PG 플러그인의 결제 레코드 직접 조회/기록 — ecommerce Repository 의존 시 모듈 버전 제약 연쇄(PaymentLimits 선례). Service/Repository 이관은 후속 백로그
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
