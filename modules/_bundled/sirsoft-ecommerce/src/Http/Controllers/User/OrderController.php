<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\CancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ConfirmOrderOptionRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\EstimateRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateOrderShippingAddressRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserOrderListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserOrderCollection;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;

/**
 * 회원 마이페이지 주문 컨트롤러
 *
 * 마이페이지(인증 필수) 주문 관련 API를 제공합니다. 회원/비회원 공유 액션
 * (주문 생성, 주문번호로 상세 조회, PG 결제창 닫기 기록)과 비회원 토큰 후속 액션은
 * Public\OrderController 가 담당합니다.
 *
 * 본 컨트롤러는 AuthBaseController 상속으로 모든 메서드에 auth:sanctum 이 강제 적용됩니다.
 */
class OrderController extends AuthBaseController
{
    public function __construct(
        private OrderCancellationService $cancellationService,
        private OrderService $orderService,
        private CartService $cartService
    ) {}

    /**
     * 주문 목록 조회
     *
     * 마이페이지 주문내역에서 사용됩니다.
     * 본인의 주문만 조회하며, 상태별 통계를 포함합니다.
     *
     * @param  UserOrderListRequest  $request  검증된 요청 데이터
     * @return JsonResponse 주문 목록 및 통계
     */
    public function index(UserOrderListRequest $request): JsonResponse
    {
        $this->logApiUsage('user.orders.index');

        $filters = $request->validated();
        $userId = Auth::id();
        $filters['user_id'] = $userId;

        if (! empty($filters['status'])) {
            $filters['order_status'] = $filters['status'];
        }

        $orders = $this->orderService->getList($filters);
        $statistics = $this->orderService->getUserStatistics($userId);
        $collection = new UserOrderCollection($orders);

        return ResponseHelper::success('sirsoft-ecommerce::messages.orders.retrieved',
            $collection->withStatistics($statistics)
        );
    }

    /**
     * ID로 주문 상세 조회
     *
     * 마이페이지 주문 상세에서 사용됩니다.
     * 본인의 주문만 조회 가능합니다.
     *
     * @param  int  $id  주문 ID
     * @return JsonResponse 주문 상세 정보
     */
    public function show(int $id): JsonResponse
    {
        $this->logApiUsage('user.orders.show');

        $order = $this->orderService->getDetail($id);

        if (! $order || $order->user_id !== Auth::id()) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.order_not_found',
                404
            );
        }

        return ResponseHelper::success('sirsoft-ecommerce::messages.orders.retrieved', new OrderResource($order));
    }

    /**
     * 주문 취소 (마이페이지)
     *
     * 사용자가 마이페이지에서 주문을 취소합니다.
     * 취소 가능 상태인 주문만 취소할 수 있습니다.
     * items 파라미터가 있으면 부분취소, 없으면 전체취소로 처리합니다.
     *
     * @param  CancelOrderRequest  $request  검증된 요청 데이터
     * @return JsonResponse 취소 후 주문 상세
     */
    public function cancel(CancelOrderRequest $request): JsonResponse
    {
        $this->logApiUsage('user.orders.cancel');

        try {
            $order = $request->getOrder();
            $cancelledBy = Auth::id();

            if ($request->isPartialCancel()) {
                $result = $this->cancellationService->cancelOrderOptions(
                    order: $order,
                    cancelItems: $request->getCancelItems(),
                    reason: $request->getReason(),
                    reasonDetail: $request->getReasonDetail(),
                    cancelledBy: $cancelledBy,
                    refundPriority: $request->getRefundPriority(),
                );
            } else {
                $result = $this->cancellationService->cancelOrder(
                    order: $order,
                    reason: $request->getReason(),
                    reasonDetail: $request->getReasonDetail(),
                    cancelledBy: $cancelledBy,
                    refundPriority: $request->getRefundPriority(),
                );
            }

            // 주문 상세 정보를 새로 로드하여 반환
            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.order.cancelled',
                new OrderResource($updatedOrder)
            );
        } catch (Exception $e) {
            Log::error('주문 취소 실패', [
                'order_id' => $request->getOrder()->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.order_cancel_failed',
                422
            );
        }
    }

    /**
     * 환불 예상금액을 조회합니다. (마이페이지)
     *
     * @param  EstimateRefundRequest  $request  환불 예상 요청 데이터
     * @return JsonResponse 환불 예상 결과
     */
    public function estimateRefund(EstimateRefundRequest $request): JsonResponse
    {
        $this->logApiUsage('user.orders.estimate-refund');

        try {
            $result = $this->cancellationService->previewRefund(
                $request->getOrder(),
                $request->getCancelItems(),
                $request->getRefundPriority()
            );

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.order.estimate_refund_success',
                $result->toPreviewArray()
            );
        } catch (Exception $e) {
            Log::error('환불 예상금액 계산 실패', [
                'order_id' => $request->getOrder()->id,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error(
                'sirsoft-ecommerce::exceptions.order_estimate_refund_failed',
                500
            );
        }
    }

    /**
     * 주문 배송지 변경
     *
     * @param  UpdateOrderShippingAddressRequest  $request  검증된 배송지 데이터
     * @param  int  $id  주문 ID
     * @return JsonResponse 배송지 변경 후 주문 상세
     */
    public function updateShippingAddress(UpdateOrderShippingAddressRequest $request, int $id): JsonResponse
    {
        $this->logApiUsage('user.orders.update-shipping-address');

        try {
            $order = $this->orderService->getDetail($id);

            // 본인 주문인지 확인
            if ($order->user_id !== Auth::id()) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'messages.orders.not_found',
                    404
                );
            }

            $order = $this->orderService->updateShippingAddress($order, $request->validated());

            return ResponseHelper::success('sirsoft-ecommerce::messages.orders.shipping_address_updated', [
                'order' => new OrderResource($order),
            ]);
        } catch (Exception $e) {
            Log::error('Order shipping address update failed', [
                'message' => $e->getMessage(),
                'order_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.orders.cannot_modify_address',
                422
            );
        }
    }

    /**
     * 주문 옵션 구매확정
     *
     * 마이페이지에서 개별 주문 옵션을 구매확정합니다.
     *
     * @param  ConfirmOrderOptionRequest  $request  구매확정 요청
     * @return JsonResponse 구매확정 후 주문 상세
     */
    public function confirmOption(ConfirmOrderOptionRequest $request): JsonResponse
    {
        $this->logApiUsage('user.orders.confirm-option');

        try {
            $order = $request->getOrder();
            $option = $request->getOption();

            $this->orderService->confirmOption($order, $option);
            $updatedOrder = $this->orderService->getDetail($order->id);

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.order.confirmed',
                ['order' => new OrderResource($updatedOrder)]
            );
        } catch (Exception $e) {
            Log::error('Order option confirm failed', [
                'message' => $e->getMessage(),
                'order_id' => $request->route('id'),
                'option_id' => $request->route('optionId'),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.order_option_cannot_confirm',
                422
            );
        }
    }

    /**
     * 과거 주문 → 장바구니 재추가 (재주문)
     *
     * 사용자의 과거 주문 (취소된 주문 포함) 의 옵션을 현재 장바구니에 추가합니다.
     * 품절/단종 등으로 추가 불가한 항목은 skip 하고 skipped 배열로 반환합니다.
     *
     * @param  int  $id  주문 ID
     * @return JsonResponse {added_count, skipped[], cart_count}
     */
    public function reorder(int $id): JsonResponse
    {
        $this->logApiUsage('user.orders.reorder', ['order_id' => $id]);

        try {
            $userId = Auth::id();
            $result = $this->cartService->reorderFromOrder($id, (int) $userId);

            return ResponseHelper::success(
                'sirsoft-ecommerce::messages.cart.reorder_added',
                $result
            );
        } catch (Exception $e) {
            Log::error('Order reorder failed', [
                'message' => $e->getMessage(),
                'order_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.reorder_failed',
                422
            );
        }
    }
}
