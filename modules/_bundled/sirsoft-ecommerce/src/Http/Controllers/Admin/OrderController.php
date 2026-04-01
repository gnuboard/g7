<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkChangeOrderOptionStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOrdersRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\EstimateRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\OrderListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\SendOrderEmailRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderOptionService;
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
    ) {}

    /**
     * 필터링된 주문 목록을 조회합니다.
     *
     * @param OrderListRequest $request 주문 목록 요청 데이터
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
     * @param Order $order 조회할 주문 모델
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
     * @param UpdateOrderRequest $request 주문 수정 요청 데이터
     * @param Order $order 수정할 주문 모델
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
     * @param BulkUpdateOrdersRequest $request 일괄 변경 요청 데이터
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
     * @param Order $order 삭제할 주문 모델
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
     * @param SendOrderEmailRequest $request 이메일 발송 요청 데이터
     * @param Order $order 대상 주문 모델
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
     * 주문의 활동 로그를 조회합니다.
     *
     * @param Request $request 요청 객체
     * @param Order $order 주문 모델
     * @return JsonResponse 활동 로그 목록 JSON 응답
     */
    public function logs(Request $request, Order $order): JsonResponse
    {
        try {
            $perPage = (int) ($request->query('per_page', 10));
            $sortOrder = $request->query('sort_order', 'desc');

            // 주문 + 주문옵션 + 배송지 로그를 합쳐서 조회
            $optionIds = $order->options()->pluck('id')->toArray();
            $addressIds = $order->addresses()->pluck('id')->toArray();

            $query = ActivityLog::where(function ($q) use ($order, $optionIds, $addressIds) {
                // 주문 자체 로그
                $q->where(function ($sub) use ($order) {
                    $sub->where('loggable_type', $order->getMorphClass())
                        ->where('loggable_id', $order->getKey());
                });

                // 해당 주문의 옵션 로그
                if (! empty($optionIds)) {
                    $q->orWhere(function ($sub) use ($optionIds) {
                        $sub->where('loggable_type', (new OrderOption)->getMorphClass())
                            ->whereIn('loggable_id', $optionIds);
                    });
                }

                // 해당 주문의 배송지 로그
                if (! empty($addressIds)) {
                    $q->orWhere(function ($sub) use ($addressIds) {
                        $sub->where('loggable_type', (new OrderAddress)->getMorphClass())
                            ->whereIn('loggable_id', $addressIds);
                    });
                }
            })->orderBy('created_at', $sortOrder);

            $logs = $query->paginate($perPage);

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
