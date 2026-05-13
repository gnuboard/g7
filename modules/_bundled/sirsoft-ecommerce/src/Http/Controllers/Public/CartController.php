<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\BulkAddToCartRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\ChangeCartOptionRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\DeleteAllCartItemsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\DeleteCartItemRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\DeleteCartItemsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\GetCartRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\MergeGuestCartRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\UpdateCartQuantityRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CartItemResource;
use Modules\Sirsoft\Ecommerce\Services\CartService;

/**
 * 장바구니 컨트롤러
 *
 * 비회원/회원 모두 사용할 수 있는 장바구니 API를 제공합니다.
 */
class CartController extends PublicBaseController
{
    public function __construct(
        private CartService $cartService
    ) {}

    /**
     * cart_key를 발급합니다. (비회원용)
     *
     * @return JsonResponse cart_key를 포함한 JSON 응답
     */
    public function issueCartKey(): JsonResponse
    {
        try {
            $this->logApiUsage('cart.issue_key');

            $cartKey = $this->cartService->issueCartKey();

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.key_issued', [
                'cart_key' => $cartKey,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.key_issue_failed',
                500
            );
        }
    }

    /**
     * 장바구니를 조회합니다.
     *
     * 장바구니 목록과 함께 가격 정보(소계, 할인, 배송비 등)를 계산하여 반환합니다.
     * selected_ids 파라미터가 전달되면 해당 아이템만 계산에 포함됩니다.
     *
     * @param  GetCartRequest  $request  검증된 요청 데이터
     * @return JsonResponse 장바구니 아이템 목록과 계산 결과를 포함한 JSON 응답
     */
    public function index(GetCartRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('cart.index');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');

            // 비회원 cart_key 검증
            $validationError = $this->validateGuestCartKey($userId, $cartKey);
            if ($validationError !== null) {
                return $validationError;
            }

            // 선택된 아이템 ID 목록 (파라미터 전달 여부 확인)
            // - null: 파라미터 미전달 → 전체 상품 계산
            // - []: 빈 배열 전달 → 계산 생략
            // - [1,2,3]: ID 배열 전달 → 해당 상품만 계산
            $selectedIds = $request->has('selected_ids')
                ? $request->validated('selected_ids', [])
                : null;

            // 장바구니 목록 + 가격 계산 결과 조회
            $result = $this->cartService->getCartWithCalculation(
                $userId,
                $cartKey,
                selectedCartIds: $selectedIds
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.fetched', [
                'items' => CartItemResource::collection($result->items),
                'item_ids' => $result->items->pluck('id')->values()->toArray(),
                'item_count' => $result->count(),
                'calculation' => $result->calculation->toArray(),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.fetch_failed',
                500
            );
        }
    }

    /**
     * 장바구니에 상품을 담습니다.
     *
     * 하나의 상품에 대해 단일 또는 여러 옵션 조합을 한 번에 담습니다.
     * items[] 배열 형태로 단일/복수 모두 처리합니다.
     *
     * @param  BulkAddToCartRequest  $request  검증된 요청 데이터
     * @return JsonResponse 추가된 장바구니 아이템 목록과 총 수량을 포함한 JSON 응답
     */
    public function store(BulkAddToCartRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('cart.store');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');

            // 비회원 cart_key 검증
            $validationError = $this->validateGuestCartKey($userId, $cartKey);
            if ($validationError !== null) {
                return $validationError;
            }

            $data = $request->validated();
            $data['user_id'] = $userId;
            $data['cart_key'] = $cartKey;

            $result = $this->cartService->bulkAddToCart($data);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.added', [
                'items' => CartItemResource::collection(
                    collect($result['items'])->map(fn ($item) => $item->load(['product', 'productOption']))
                ),
                'cart_count' => $result['cart_count'],
            ], 201);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.add_failed',
                500
            );
        }
    }

    /**
     * 장바구니 아이템 수량을 변경합니다.
     *
     * 수량 변경 후 전체 장바구니 목록과 계산 결과를 반환합니다.
     * 프론트엔드에서 refetch 없이 바로 상태를 업데이트할 수 있도록
     * index 메서드와 동일한 형태의 응답을 반환합니다.
     *
     * @param  UpdateCartQuantityRequest  $request  검증된 요청 데이터
     * @param  int  $id  장바구니 아이템 ID
     * @return JsonResponse 전체 장바구니 목록과 계산 결과를 포함한 JSON 응답
     */
    public function updateQuantity(UpdateCartQuantityRequest $request, int $id): JsonResponse
    {
        try {
            $this->logApiUsage('cart.update_quantity', ['cart_id' => $id]);

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');

            // 수량 업데이트
            $this->cartService->updateQuantity(
                $id,
                $request->validated('quantity'),
                $userId,
                $cartKey
            );

            // 선택된 아이템 ID 목록 (파라미터 전달 여부 확인)
            $selectedIds = $request->has('selected_ids')
                ? $request->input('selected_ids', [])
                : null;

            // 전체 장바구니 목록 + 계산 결과 반환 (refetch 제거를 위해)
            $result = $this->cartService->getCartWithCalculation(
                $userId,
                $cartKey,
                selectedCartIds: $selectedIds
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.quantity_updated', [
                'items' => CartItemResource::collection($result->items),
                'item_count' => $result->count(),
                'calculation' => $result->calculation->toArray(),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.update_failed',
                500
            );
        }
    }

    /**
     * 장바구니 아이템 옵션을 변경합니다.
     *
     * @param  ChangeCartOptionRequest  $request  검증된 요청 데이터
     * @param  int  $id  장바구니 아이템 ID
     * @return JsonResponse 수정된 장바구니 아이템을 포함한 JSON 응답
     */
    public function changeOption(ChangeCartOptionRequest $request, int $id): JsonResponse
    {
        try {
            $this->logApiUsage('cart.change_option', ['cart_id' => $id]);

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');
            $validated = $request->validated();

            $cart = $this->cartService->changeOption(
                $id,
                $validated['product_option_id'],
                $validated['quantity'],
                $userId,
                $cartKey
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.option_changed', [
                'item' => new CartItemResource($cart->load(['product', 'productOption'])),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.update_failed',
                500
            );
        }
    }

    /**
     * 장바구니 아이템을 삭제합니다.
     *
     * @param  DeleteCartItemRequest  $request  검증된 요청 데이터
     * @param  int  $id  장바구니 아이템 ID
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function destroy(DeleteCartItemRequest $request, int $id): JsonResponse
    {
        try {
            $this->logApiUsage('cart.destroy', ['cart_id' => $id]);

            $userId = Auth::id();
            $cartKey = $request->getCartKey();

            $this->cartService->deleteItem($id, $userId, $cartKey);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.deleted');
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.delete_failed',
                500
            );
        }
    }

    /**
     * 장바구니 아이템을 선택 삭제합니다.
     *
     * @param  DeleteCartItemsRequest  $request  검증된 요청 데이터
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function destroyMultiple(DeleteCartItemsRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('cart.destroy_multiple');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');

            $deletedCount = $this->cartService->deleteItems(
                $request->validated('ids'),
                $userId,
                $cartKey
            );

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.deleted_multiple', [
                'deleted_count' => $deletedCount,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.delete_failed',
                500
            );
        }
    }

    /**
     * 장바구니 전체를 삭제합니다.
     *
     * @param  DeleteAllCartItemsRequest  $request  검증된 요청 데이터
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function destroyAll(DeleteAllCartItemsRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('cart.destroy_all');

            $userId = Auth::id();
            $cartKey = $request->getCartKey();

            $deletedCount = $this->cartService->deleteAll($userId, $cartKey);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.deleted_all', [
                'deleted_count' => $deletedCount,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.delete_failed',
                500
            );
        }
    }

    /**
     * 비회원 장바구니를 회원 계정으로 병합합니다.
     *
     * @param  MergeGuestCartRequest  $request  검증된 요청 데이터
     * @return JsonResponse 병합 결과를 포함한 JSON 응답
     */
    public function merge(MergeGuestCartRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('cart.merge');

            $userId = Auth::id();
            $cartKey = $request->getCartKey();

            $mergedCount = $this->cartService->mergeGuestCartToUser($cartKey, $userId);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.merged', [
                'merged_count' => $mergedCount,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.merge_failed',
                500
            );
        }
    }

    /**
     * 장바구니 아이템 수를 조회합니다.
     *
     * @param  GetCartRequest  $request  검증된 요청 데이터
     * @return JsonResponse 아이템 수를 포함한 JSON 응답
     */
    public function count(GetCartRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('cart.count');

            $userId = Auth::id();
            $cartKey = $request->header('X-Cart-Key');

            $count = $this->cartService->getItemCount($userId, $cartKey);

            return ResponseHelper::moduleSuccess('sirsoft-ecommerce', 'messages.cart.count_fetched', [
                'count' => $count,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.fetch_failed',
                500
            );
        }
    }

    /**
     * 비회원 cart_key 검증
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  cart_key
     * @return JsonResponse|null 검증 실패 시 에러 응답, 성공 시 null
     */
    protected function validateGuestCartKey(?int $userId, ?string $cartKey): ?JsonResponse
    {
        // 회원인 경우 cart_key 검증 불필요
        if ($userId !== null) {
            return null;
        }

        // 비회원인 경우 cart_key 필수
        if (empty($cartKey)) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.cart_key_required',
                400
            );
        }

        // cart_key 형식 검증: ck_ + 32자 영숫자
        if (! preg_match('/^ck_[a-zA-Z0-9]{32}$/', $cartKey)) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.cart.invalid_cart_key',
                400
            );
        }

        return null;
    }
}
