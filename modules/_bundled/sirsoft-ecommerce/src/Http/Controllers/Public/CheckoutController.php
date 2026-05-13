<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\CheckoutRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\DeleteCheckoutRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\ExtendCheckoutRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\ShowCheckoutRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\UpdateCheckoutRequest;
use Modules\Sirsoft\Ecommerce\Services\CheckoutDataService;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;

/**
 * 체크아웃 컨트롤러
 *
 * 장바구니에서 주문서 작성 단계로 이동하는 API를 제공합니다.
 * 선택된 장바구니 아이템으로 임시 주문을 생성합니다.
 */
class CheckoutController extends PublicBaseController
{
    public function __construct(
        private TempOrderService $tempOrderService,
        private CheckoutDataService $checkoutDataService
    ) {}

    /**
     * 체크아웃 - 임시 주문 생성
     *
     * 선택한 장바구니 아이템으로 임시 주문을 생성합니다.
     * 주문서 작성 페이지에서 사용할 임시 주문 데이터를 반환합니다.
     *
     * @param CheckoutRequest $request 검증된 요청 데이터
     * @return JsonResponse 임시 주문 정보를 포함한 JSON 응답
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('checkout.store');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');
            $validated = $request->validated();

            $tempOrder = $this->tempOrderService->createTempOrderFromSelectedItems(
                cartIds: $validated['item_ids'],
                userId: $userId,
                cartKey: $cartKey,
                usePoints: $validated['use_points'] ?? 0
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.checkout.created', [
                'temp_order_id' => $tempOrder->id,
                'calculation' => $tempOrder->calculation_result,
                'expires_at' => $tempOrder->expires_at->toIso8601String(),
            ], 201);
        } catch (CartUnavailableException $e) {
            // 구매불가 상품 예외 처리 (재고부족 또는 판매상태)
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.cart_unavailable',
                400,
                [
                    'code' => 'cart_unavailable',
                    'unavailable_items' => $e->getUnavailableItems(),
                    'has_stock_issue' => $e->hasStockIssue(),
                    'has_status_issue' => $e->hasStatusIssue(),
                ]
            );
        } catch (Exception $e) {
            // 장바구니 비어있음 예외 처리
            if (str_contains($e->getMessage(), 'cart_empty')) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.cart_empty',
                    400
                );
            }

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.checkout.create_failed',
                500
            );
        }
    }

    /**
     * 임시 주문 조회 (실시간 재계산 포함)
     *
     * 현재 유효한 임시 주문을 조회하고 최신 가격으로 재계산합니다.
     * 쿼리 파라미터로 배송 주소를 전달하면 해당 주소 기준으로 배송비가 계산됩니다.
     * 도서산간 지역 여부는 우편번호 기반으로 배송정책에서 자동 판별됩니다.
     *
     * @param ShowCheckoutRequest $request 검증된 요청 데이터
     * @return JsonResponse 임시 주문 정보를 포함한 JSON 응답
     *
     * @queryParam zipcode string 우편번호 (배송비 계산용)
     * @queryParam region string 지역/도 (배송비 계산용)
     */
    public function show(ShowCheckoutRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('checkout.show');

            $userId = Auth::id();
            $cartKey = $request->getCartKey();
            $validated = $request->validated();

            // 검증된 파라미터에서 배송 주소 정보 추출
            $shippingAddress = null;
            if (isset($validated['zipcode']) || isset($validated['region'])) {
                $shippingAddress = new ShippingAddress(
                    countryCode: $validated['country_code'] ?? 'KR',
                    zipcode: $validated['zipcode'] ?? null,
                    region: $validated['region'] ?? null,
                    city: $validated['city'] ?? null,
                    address: $validated['address'] ?? null
                );
            }

            // 실시간 재계산 포함 조회
            $result = $this->tempOrderService->getTempOrderWithCalculation($userId, $cartKey, $shippingAddress);

            if (! $result) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.temp_order_not_found',
                    404
                );
            }

            // 응답 데이터 구성 (쿠폰, 마일리지, 상품 정보, 구매불가 상품 포함)
            $responseData = $this->checkoutDataService->buildResponseData(
                $result['temp_order'],
                $result['calculation'],
                $userId,
                $result['unavailable_items'] ?? []
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.checkout.fetched', $responseData);
        } catch (Exception $e) {
            Log::error('Checkout show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.checkout.fetch_failed',
                500
            );
        }
    }

    /**
     * 임시 주문 업데이트 (쿠폰/마일리지/배송주소 변경 시 재계산)
     *
     * 쿠폰, 마일리지, 배송 주소 변경 시 호출하여 주문 금액을 재계산합니다.
     * 배송 주소에 따라 배송비가 달라질 수 있습니다.
     *
     * @param UpdateCheckoutRequest $request 검증된 요청 데이터
     * @return JsonResponse 재계산된 임시 주문 정보를 포함한 JSON 응답
     */
    public function update(UpdateCheckoutRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('checkout.update');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');
            $validated = $request->validated();

            // 배송 주소 DTO 생성 (zipcode만으로 도서산간 배송비 계산)
            $shippingAddress = null;
            if (isset($validated['zipcode']) || isset($validated['country_code'])) {
                $shippingAddress = new ShippingAddress(
                    countryCode: $validated['country_code'] ?? 'KR',
                    zipcode: $validated['zipcode'] ?? null
                );
            }

            // 프로모션 정보 구성 (전송된 필드만 포함, 미전송 필드는 서비스에서 기존 값 유지)
            $promotions = [];
            if ($request->has('item_coupons')) {
                $promotions['item_coupons'] = $validated['item_coupons'] ?? [];
            }
            if ($request->has('order_coupon_issue_id')) {
                $promotions['order_coupon_issue_id'] = $validated['order_coupon_issue_id'];
            }
            if ($request->has('shipping_coupon_issue_id')) {
                $promotions['shipping_coupon_issue_id'] = $validated['shipping_coupon_issue_id'];
            }

            // 마일리지: 전송된 경우만 변경, 미전송 시 null → 서비스에서 기존 값 유지
            $usePoints = $request->has('use_points') ? ($validated['use_points'] ?? 0) : null;

            $tempOrder = $this->tempOrderService->updateTempOrder(
                userId: $userId,
                cartKey: $cartKey,
                promotions: $promotions,
                usePoints: $usePoints,
                shippingAddress: $shippingAddress
            );

            // 응답 데이터 구성 (쿠폰, 마일리지, 상품 정보 포함)
            $responseData = $this->checkoutDataService->buildResponseData(
                $tempOrder,
                $tempOrder->calculation_result,
                $userId
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.checkout.updated', $responseData);
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'temp_order_not_found')) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.temp_order_not_found',
                    404
                );
            }

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.checkout.update_failed',
                500
            );
        }
    }

    /**
     * 임시 주문 삭제 (주문서 페이지 이탈 시)
     *
     * @param DeleteCheckoutRequest $request 검증된 요청 데이터
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function destroy(DeleteCheckoutRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('checkout.destroy');

            $userId = Auth::id();
            $cartKey = $request->getCartKey();

            $deleted = $this->tempOrderService->deleteTempOrder($userId, $cartKey);

            if (! $deleted) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.temp_order_not_found',
                    404
                );
            }

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.checkout.deleted');
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.checkout.delete_failed',
                500
            );
        }
    }

    /**
     * 임시 주문 만료 시간 연장
     *
     * @param ExtendCheckoutRequest $request 검증된 요청 데이터
     * @return JsonResponse 연장된 만료 시간을 포함한 JSON 응답
     */
    public function extend(ExtendCheckoutRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('checkout.extend');

            $userId = Auth::id();
            $cartKey = $request->getCartKey();

            $tempOrder = $this->tempOrderService->extendExpiration($userId, $cartKey);

            if (! $tempOrder) {
                return ResponseHelper::moduleError(
                    'sirsoft-ecommerce',
                    'exceptions.temp_order_not_found',
                    404
                );
            }

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.checkout.extended', [
                'expires_at' => $tempOrder->expires_at->toIso8601String(),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.checkout.extend_failed',
                500
            );
        }
    }

}
