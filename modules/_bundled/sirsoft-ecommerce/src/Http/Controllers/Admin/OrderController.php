<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkChangeOrderOptionStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOrdersRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ConfirmDepositRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\EstimateRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderLogsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ResetGuestLookupPasswordRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\SendOrderEmailRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;

/**
 * 주문 관리 컨트롤러
 *
 * 관리자가 주문을 관리할 수 있는 기능을 제공합니다.
 */
class OrderController extends AdminBaseController
{
    public function __construct(
        private OrderService $orderService,
        private OrderOptionService $orderOptionService,
        private OrderCancellationService $cancellationService,
        private ActivityLogService $activityLogService,
        private OrderProcessingService $orderProcessingService,
    ) {}

    /**
     * 필터링된 주문 목록을 조회합니다.
     *
     * @param  OrderListRequest  $request  주문 목록 요청 데이터
     * @return JsonResponse 주문 목록과 통계 정보를 포함한 JSON 응답
     */
    public function index(OrderListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $orders = $this->orderService->getList($filters);
            $statistics = $this->orderService->getStatistics();

            $collection = new OrderCollection($orders);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.fetch_success',
                $collection->withStatistics($statistics)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.fetch_failed',
                500
            );
        }
    }

    /**
     * 특정 주문의 상세 정보를 조회합니다.
     *
     * @param  Order  $order  조회할 주문 모델
     * @return JsonResponse 주문 상세 정보를 포함한 JSON 응답
     */
    public function show(Order $order): JsonResponse
    {
        try {
            $order = $this->orderService->getDetail($order->id);

            if (! $order) {
                return ResponseHelper::notFound(
                    'messages.orders.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.fetch_success',
                new OrderResource($order)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.fetch_failed',
                500
            );
        }
    }

    /**
     * 주문 정보를 수정합니다.
     *
     * @param  UpdateOrderRequest  $request  주문 수정 요청 데이터
     * @param  Order  $order  수정할 주문 모델
     * @return JsonResponse 수정된 주문 정보를 포함한 JSON 응답
     */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $updatedOrder = $this->orderService->update($order, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.updated',
                new OrderResource($updatedOrder)
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.update_failed',
                500
            );
        }
    }

    /**
     * 여러 주문을 일괄 변경합니다.
     *
     * @param  BulkUpdateOrdersRequest  $request  일괄 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdate(BulkUpdateOrdersRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->orderService->bulkUpdate($validated);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.bulk_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.bulk_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.bulk_update_failed',
                500
            );
        }
    }

    /**
     * 주문을 삭제합니다 (Soft Delete).
     *
     * @param  Order  $order  삭제할 주문 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(Order $order): JsonResponse
    {
        try {
            $orderId = $order->id;
            $this->orderService->delete($order);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.deleted',
                ['deleted' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.delete_failed',
                500
            );
        }
    }

    /**
     * 주문 옵션 일괄 상태 변경 (수량 분할 지원)
     *
     * @param  BulkChangeOrderOptionStatusRequest  $request  일괄 변경 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkChangeOptionStatus(BulkChangeOrderOptionStatusRequest $request, Order $order): JsonResponse
    {
        try {
            $validated = $request->validated();
            $newStatus = OrderStatusEnum::from($validated['status']);

            $metadata = [];
            if (! empty($validated['carrier_id'])) {
                $metadata['carrier_id'] = $validated['carrier_id'];
            }
            if (! empty($validated['tracking_number'])) {
                $metadata['tracking_number'] = $validated['tracking_number'];
            }

            $result = $this->orderOptionService->bulkChangeStatusWithQuantity(
                $validated['items'],
                $newStatus,
                $metadata
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.option_status_changed',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.option_status_change_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.option_status_change_failed',
                500
            );
        }
    }

    /**
     * 주문 관련 이메일을 발송합니다.
     *
     * @param  SendOrderEmailRequest  $request  이메일 발송 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 발송 결과 JSON 응답
     */
    public function sendEmail(SendOrderEmailRequest $request, Order $order): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->orderService->sendEmail($order, $validated['email'], $validated['message']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.email_sent'
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.email_send_failed',
                500,
                ['detail' => $e->getMessage()]
            );
        }
    }

    /**
     * 환불 예상금액을 조회합니다.
     *
     * @param  EstimateRefundRequest  $request  환불 예상 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 환불 예상금액 JSON 응답
     */
    public function estimateRefund(EstimateRefundRequest $request, Order $order): JsonResponse
    {
        try {
            $result = $this->cancellationService->previewRefund(
                $order,
                $request->getCancelItems(),
                $request->getRefundPriority()
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.estimate_refund_success',
                $result->toPreviewArray()
            );
        } catch (Exception $e) {
            Log::error('환불 예상금액 계산 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.estimate_refund_failed',
                500
            );
        }
    }

    /**
     * 주문을 취소합니다. (전체취소/부분취소)
     *
     * @param  CancelOrderRequest  $request  주문 취소 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 취소 결과 JSON 응답
     */
    public function cancelOrder(CancelOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $cancelledBy = Auth::id();

            if ($request->isFullCancel()) {
                $result = $this->cancellationService->cancelOrder(
                    order: $order,
                    reason: $request->getReason(),
                    reasonDetail: $request->getReasonDetail(),
                    cancelledBy: $cancelledBy,
                    cancelPg: $request->shouldCancelPg(),
                    refundPriority: $request->getRefundPriority(),
                );
            } else {
                $result = $this->cancellationService->cancelOrderOptions(
                    order: $order,
                    cancelItems: $request->getCancelItems(),
                    reason: $request->getReason(),
                    reasonDetail: $request->getReasonDetail(),
                    cancelledBy: $cancelledBy,
                    cancelPg: $request->shouldCancelPg(),
                    refundPriority: $request->getRefundPriority(),
                );
            }

            // 주문 상세 정보를 새로 로드하여 반환
            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.cancelled',
                new OrderResource($updatedOrder)
            );
        } catch (Exception $e) {
            Log::error('주문 취소 실패 (관리자)', [
                'order_id' => $order->id,
                'type' => $request->validated('type'),
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.cancel_failed',
                422,
                ['detail' => $e->getMessage()]
            );
        }
    }

    /**
     * 무통장 주문의 입금을 확인하여 결제완료로 전이합니다.
     *
     * 무통장(dbank) 미결제 주문에 한해 입금자명·입금액을 기록하고 결제완료 처리합니다.
     * 입금액이 결제예정금액과 정확히 일치하지 않으면 422 를 반환합니다.
     *
     * @param  ConfirmDepositRequest  $request  입금확인 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 입금확인 결과 JSON 응답
     */
    public function confirmDeposit(ConfirmDepositRequest $request, Order $order): JsonResponse
    {
        try {
            $this->orderProcessingService->confirmManualDeposit(
                $order,
                $request->getAmount(),
                $request->getDepositorName(),
                $request->shouldMarkOrderComplete(),
            );

            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.deposit_confirmed',
                new OrderResource($updatedOrder)
            );
        } catch (PaymentAmountMismatchException $e) {
            // 입금액 불일치 — 사용자에게 금액 검증 실패를 알림
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.deposit_amount_mismatch',
                422,
                ['detail' => $e->getMessage()]
            );
        } catch (Exception $e) {
            Log::error('무통장 입금확인 실패 (관리자)', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.deposit_confirm_failed',
                422,
                ['detail' => $e->getMessage()]
            );
        }
    }

    /**
     * 비회원 주문의 조회 비밀번호를 재설정합니다.
     *
     * 비회원 주문(user_id IS NULL)만 허용하며, 회원 주문은 거부합니다.
     * 평문 비밀번호는 응답/로그에 노출하지 않고 해시만 저장합니다.
     *
     * @param  ResetGuestLookupPasswordRequest  $request  재설정 요청 데이터
     * @param  Order  $order  대상 주문 모델
     * @return JsonResponse 재설정 결과 JSON 응답
     */
    public function resetGuestLookupPassword(ResetGuestLookupPasswordRequest $request, Order $order): JsonResponse
    {
        // 회원 주문은 조회 비밀번호 재설정 대상이 아님
        if ($order->user_id !== null) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.guest_password_reset_not_guest',
                422
            );
        }

        try {
            $this->orderService->resetGuestLookupPassword(
                $order,
                $request->validated('guest_lookup_password')
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.guest_password_reset_success'
            );
        } catch (Exception $e) {
            Log::error('비회원 조회 비밀번호 재설정 실패', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.guest_password_reset_failed',
                500
            );
        }
    }

    /**
     * 주문의 활동 로그를 조회합니다.
     *
     * @param  OrderLogsRequest  $request  활동 로그 조회 요청 데이터
     * @param  Order  $order  주문 모델
     * @return JsonResponse 활동 로그 목록 JSON 응답
     */
    public function logs(OrderLogsRequest $request, Order $order): JsonResponse
    {
        try {
            // 주문 + 주문옵션 + 배송지 로그 합산 조회는 Repository 위임 (Service 경유)
            $logs = $this->orderService->getActivityLogs($order, $request->getFilters());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.orders.logs_fetch_success',
                ActivityLogResource::collection($logs)->response()->getData(true)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.logs_fetch_failed',
                500
            );
        }
    }
}
