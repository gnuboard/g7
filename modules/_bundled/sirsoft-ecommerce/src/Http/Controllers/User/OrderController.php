<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CreateOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\CancelOrderRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\CancelPaymentRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\ConfirmOrderOptionRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\EstimateRefundRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateOrderShippingAddressRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserOrderListRequest;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserOrderCollection;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;
use Modules\Sirsoft\Ecommerce\Services\UserAddressService;

/**
 * 사용자 주문 컨트롤러
 *
 * 마이페이지 주문 관련 API를 제공합니다.
 */
class OrderController extends AuthBaseController
{
    public function __construct(
        private OrderProcessingService $orderProcessingService,
        private OrderCancellationService $cancellationService,
        private OrderService $orderService,
        private TempOrderService $tempOrderService,
        private StockService $stockService,
        private UserAddressService $userAddressService
    ) {}

    /**
     * 주문 목록 조회
     *
     * 마이페이지 주문내역에서 사용됩니다.
     * 본인의 주문만 조회하며, 상태별 통계를 포함합니다.
     *
     * @param UserOrderListRequest $request 검증된 요청 데이터
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
     * @param int $id 주문 ID
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
     * 주문번호로 주문 상세 조회
     *
     * 주문 완료 페이지에서 사용됩니다.
     * 본인의 주문만 조회 가능합니다.
     *
     * @param string $orderNumber 주문번호
     * @return JsonResponse 주문 상세 정보
     */
    public function showByOrderNumber(string $orderNumber): JsonResponse
    {
        $this->logApiUsage('user.orders.show-by-number');

        $order = $this->orderService->getByOrderNumber($orderNumber);

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
     * 주문 생성 (결제하기)
     *
     * 임시 주문을 실제 주문으로 변환합니다.
     *
     * @param CreateOrderRequest $request 검증된 요청 데이터
     * @return JsonResponse 생성된 주문 정보를 포함한 JSON 응답
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.orders.store');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');

            // 임시 주문 조회
            $tempOrder = $this->tempOrderService->getTempOrder($userId, $cartKey);

            // 임시 주문 없거나 만료된 경우 (findValidByUserOrCartKey에서 만료된 주문은 null 반환)
            if (! $tempOrder) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.temp_order_not_found',
                    404
                );
            }

            // 재고 검증
            $items = $this->buildStockValidationItems($tempOrder);
            $this->stockService->validateStock($items);

            // 주문 생성
            $order = $this->orderProcessingService->createFromTempOrder(
                tempOrder: $tempOrder,
                ordererInfo: $request->getOrdererInfo(),
                shippingInfo: $request->getShippingInfo(),
                paymentMethod: $request->input('payment_method'),
                expectedTotalAmount: (float) $request->input('expected_total_amount'),
                shippingMemo: $request->input('shipping_memo'),
                depositorName: $request->input('depositor_name'),
                dbankInfo: $request->getDbankInfo()
            );

            $order->load(['options', 'payment', 'shippingAddress']);

            // PG 결제 필요 여부 판단
            $paymentMethod = PaymentMethodEnum::tryFrom($order->payment->payment_method->value ?? $order->payment->payment_method);
            $pgProvider = $this->orderProcessingService->determinePgProvider($paymentMethod->value);
            $requiresPg = $paymentMethod->needsPgProvider()
                && ! in_array($pgProvider, ['manual', 'internal', 'none']);

            // 임시 주문 삭제: non-PG만 즉시 삭제, PG 결제는 completePayment() 시점에 삭제
            if (! $requiresPg) {
                $this->tempOrderService->deleteTempOrder($userId, $cartKey);
            }

            // 배송지 자동 저장 처리
            if ($userId && $request->boolean('save_shipping_address')) {
                if (! $requiresPg) {
                    // 비PG 결제: 즉시 배송지 저장
                    try {
                        $name = $this->userAddressService->generateUniqueName(
                            $userId,
                            __('sirsoft-ecommerce::messages.address.auto_saved_label')
                        );
                        $shippingInfo = $request->getShippingInfo();
                        $this->userAddressService->createAddress([
                            'user_id' => $userId,
                            'name' => $name,
                            'recipient_name' => $shippingInfo['recipient_name'] ?? '',
                            'recipient_phone' => $shippingInfo['recipient_phone'] ?? '',
                            'country_code' => $shippingInfo['country_code'] ?? 'KR',
                            'zipcode' => $shippingInfo['zipcode'] ?? $shippingInfo['intl_postal_code'] ?? '',
                            'address' => $shippingInfo['address'] ?? $shippingInfo['address_line_1'] ?? '',
                            'address_detail' => $shippingInfo['address_detail'] ?? $shippingInfo['address_line_2'] ?? '',
                            'region' => $shippingInfo['region'] ?? $shippingInfo['intl_state'] ?? '',
                            'city' => $shippingInfo['city'] ?? $shippingInfo['intl_city'] ?? '',
                        ]);
                    } catch (Exception $e) {
                        Log::warning('Auto save shipping address failed on order creation', [
                            'user_id' => $userId,
                            'order_id' => $order->id,
                            'message' => $e->getMessage(),
                        ]);
                    }
                } else {
                    // PG 결제: order_meta에 플래그 저장 (completePayment 시점에 처리)
                    $order->update([
                        'order_meta' => array_merge($order->order_meta ?? [], [
                            'save_shipping_address' => true,
                            'shipping_info_for_save' => $request->getShippingInfo(),
                        ]),
                    ]);
                }
            }

            $responseData = [
                'order' => new OrderResource($order),
                'redirect_url' => "/shop/orders/{$order->order_number}/complete",
                'requires_pg_payment' => $requiresPg,
            ];

            if ($requiresPg) {
                $responseData['pg_provider'] = "sirsoft-{$pgProvider}";
                $responseData['pg_payment_data'] = $this->buildPgPaymentData($order);
            }

            return ResponseHelper::success('sirsoft-ecommerce::messages.order.created', $responseData, 201);

        } catch (PaymentAmountMismatchException $e) {
            Log::warning('Order creation failed: amount mismatch', [
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::error(
                __('sirsoft-ecommerce::exceptions.payment_amount_mismatch', [
                    'expected' => number_format($e->getExpectedAmount()),
                    'actual' => number_format($e->getActualAmount()),
                ]),
                422
            );

        } catch (InsufficientStockException $e) {
            Log::warning('Order creation failed: insufficient stock', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::error($e->getMessage(), 422);

        } catch (Exception $e) {
            Log::error('Order creation failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.order.create_failed',
                500
            );
        }
    }

    /**
     * 결제 취소 기록 (결제창 닫기)
     *
     * 유저가 PG 결제창을 닫았을 때 호출됩니다.
     * 주문 상태는 변경하지 않고, order_payments에 취소 이력만 기록합니다.
     *
     * @param CancelPaymentRequest $request 검증된 요청 데이터
     * @return JsonResponse
     */
    public function cancelPayment(CancelPaymentRequest $request): JsonResponse
    {
        $this->logApiUsage('user.orders.cancel-payment');

        $this->orderProcessingService->recordPaymentCancellation(
            $request->getOrder(),
            $request->validated('cancel_code'),
            $request->validated('cancel_message')
        );

        return ResponseHelper::success('sirsoft-ecommerce::messages.order.payment_cancelled');
    }

    /**
     * 주문 취소 (마이페이지)
     *
     * 사용자가 마이페이지에서 주문을 취소합니다.
     * 취소 가능 상태인 주문만 취소할 수 있습니다.
     * items 파라미터가 있으면 부분취소, 없으면 전체취소로 처리합니다.
     *
     * @param  CancelOrderRequest  $request  검증된 요청 데이터
     * @return JsonResponse
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

            return ResponseHelper::error($e->getMessage(), 422);
        }
    }

    /**
     * 환불 예상금액을 조회합니다. (마이페이지)
     *
     * @param  EstimateRefundRequest  $request  환불 예상 요청 데이터
     * @return JsonResponse
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

            return ResponseHelper::error($e->getMessage(), 500);
        }
    }

    /**
     * 주문 배송지 변경
     *
     * @param UpdateOrderShippingAddressRequest $request 검증된 배송지 데이터
     * @param int $id 주문 ID
     * @return JsonResponse
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
        } catch (\Exception $e) {
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
     * @param ConfirmOrderOptionRequest $request 구매확정 요청
     * @return JsonResponse
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
        } catch (\Exception $e) {
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
     * 재고 검증용 아이템 목록 생성
     *
     * @param \Modules\Sirsoft\Ecommerce\Models\TempOrder $tempOrder 임시 주문
     * @return array 재고 검증용 아이템 배열
     */
    protected function buildStockValidationItems($tempOrder): array
    {
        $items = [];
        $calculationResult = $tempOrder->calculation_result ?? [];

        foreach ($calculationResult['items'] ?? [] as $item) {
            $items[] = [
                'product_option_id' => $item['productOptionId'] ?? $item['product_option_id'],
                'quantity' => $item['quantity'],
            ];
        }

        return $items;
    }

    /**
     * PG 결제용 데이터 생성
     *
     * 주문 생성 API 응답에 포함될 PG 결제 요청 데이터를 빌드합니다.
     *
     * @param \Modules\Sirsoft\Ecommerce\Models\Order $order 주문
     * @return array PG 결제 요청 데이터
     */
    protected function buildPgPaymentData($order): array
    {
        // 주문명 생성 (로컬라이즈된 첫 번째 상품명 + 외 N건)
        $options = $order->options;
        $locale = app()->getLocale();
        $firstName = $options->first()?->product_name;
        $orderName = is_array($firstName)
            ? ($firstName[$locale] ?? $firstName['ko'] ?? reset($firstName) ?: '')
            : ($firstName ?? '');
        if ($options->count() > 1) {
            $orderName .= ' 외 ' . ($options->count() - 1) . '건';
        }

        // 주문자 정보 (배송지 주소에서 가져옴)
        $shippingAddress = $order->shippingAddress;

        return [
            'order_number' => $order->order_number,
            'order_name' => $orderName,
            'amount' => (int) $order->total_due_amount,
            'currency' => $order->currency_snapshot['order_currency'] ?? 'KRW',
            'customer_name' => $shippingAddress?->orderer_name,
            'customer_email' => $shippingAddress?->orderer_email,
            'customer_phone' => preg_replace('/[^0-9]/', '', $shippingAddress?->orderer_phone ?? ''),
            'customer_key' => $order->user_id ? "user_{$order->user_id}" : null,
        ];
    }
}
