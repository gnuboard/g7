<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\CartWithCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Exceptions\CartOperationException;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 장바구니 서비스
 */
class CartService
{
    public function __construct(
        protected CartRepositoryInterface $cartRepository,
        protected ProductOptionRepositoryInterface $productOptionRepository,
        protected OrderCalculationService $orderCalculationService,
        protected OrderRepositoryInterface $orderRepository,
        protected ProductRepositoryInterface $productRepository,
        protected AdditionalOptionSelectionService $additionalOptionSelectionService
    ) {}

    /**
     * cart_key 발급 (비회원용)
     *
     * 중복되지 않는 유일한 키를 생성합니다.
     *
     * @return string 발급된 cart_key
     */
    public function issueCartKey(): string
    {
        do {
            $cartKey = 'ck_'.Str::random(32);
        } while ($this->cartRepository->existsByCartKey($cartKey));

        return $cartKey;
    }

    /**
     * 장바구니 조회
     *
     * @param  int|null  $userId  회원 ID (null이면 비회원)
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return Collection 장바구니 아이템 컬렉션
     */
    public function getCart(?int $userId, ?string $cartKey): Collection
    {
        HookManager::doAction('sirsoft-ecommerce.cart.before_list', $userId, $cartKey);

        if ($userId !== null) {
            $items = $this->cartRepository->findByUserId($userId);
        } elseif ($cartKey !== null) {
            $items = $this->cartRepository->findByCartKeyWithoutUser($cartKey);
        } else {
            $items = new Collection;
        }

        $items = HookManager::applyFilters('sirsoft-ecommerce.cart.filter_list_result', $items, $userId, $cartKey);

        HookManager::doAction('sirsoft-ecommerce.cart.after_list', $items, $userId, $cartKey);

        return $items;
    }

    /**
     * 장바구니 조회 + 주문 계산 결과
     *
     * 장바구니 목록과 함께 가격 정보(소계, 할인, 배송비 등)를 계산하여 반환합니다.
     * 쿠폰/마일리지 적용 전 단계에서도 기본 가격 정보를 확인할 수 있습니다.
     *
     * @param  int|null  $userId  회원 ID (null이면 비회원)
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  array  $couponIssueIds  적용할 쿠폰 발급 ID 배열 (선택)
     * @param  int  $usePoints  사용할 마일리지 (선택)
     * @param  array|null  $selectedCartIds  계산에 포함할 장바구니 ID 배열
     *                                       - null: 전체 상품 계산 (기존 동작)
     *                                       - []: 빈 배열 → 계산 생략
     *                                       - [1,2,3]: 해당 ID 상품만 계산
     * @return CartWithCalculationResult 장바구니 목록 + 계산 결과
     */
    public function getCartWithCalculation(
        ?int $userId,
        ?string $cartKey,
        array $couponIssueIds = [],
        int $usePoints = 0,
        ?array $selectedCartIds = null
    ): CartWithCalculationResult {
        // 장바구니 목록 조회
        $items = $this->getCart($userId, $cartKey);

        // selected_ids 파라미터 처리
        $itemsForCalculation = $items;

        if ($selectedCartIds !== null) {
            // 파라미터가 명시적으로 전달된 경우
            if (empty($selectedCartIds)) {
                // 빈 배열이면 계산 생략 (선택된 상품 없음)
                return new CartWithCalculationResult(
                    items: $items,
                    calculation: new OrderCalculationResult
                );
            }

            // 전달된 ID로 필터링
            $itemsForCalculation = $items->filter(function ($cart) use ($selectedCartIds) {
                return in_array($cart->id, $selectedCartIds);
            });

            // 필터 결과가 비어있으면 빈 계산 결과 반환
            if ($itemsForCalculation->isEmpty()) {
                return new CartWithCalculationResult(
                    items: $items,
                    calculation: new OrderCalculationResult
                );
            }
        }
        // selectedCartIds가 null이면 전체 상품 계산 (기존 동작 유지)

        // 비정상 항목(판매중지/품절/출시예정/전시중지) 합계 제외 (U13②/U4 빈 안전망).
        // 응답 items 에는 남기되(2-f 플래그로 구분) 합계 계산에서만 제외한다.
        $itemsForCalculation = $itemsForCalculation->filter(
            fn ($cart) => $cart->product && $cart->product->isPurchasable()
        );

        // 비정상 항목 제외 후 계산 대상이 없으면 빈 계산 결과 반환
        if ($itemsForCalculation->isEmpty()) {
            return new CartWithCalculationResult(
                items: $items,
                calculation: new OrderCalculationResult
            );
        }

        // 장바구니 아이템을 CalculationItem으로 변환
        $calculationItems = $itemsForCalculation->map(function ($cart) {
            return new CalculationItem(
                productId: $cart->product_id,
                productOptionId: $cart->product_option_id,
                quantity: $cart->quantity,
                cartId: $cart->id,
                additionalOptionSelections: $cart->additional_option_selections,
            );
        })->all();

        // 주문 계산 실행
        // 배송국가는 요청별 결정값(ResolveShippingCountry: 헤더 X-Shipping-Country > 저장국가 > GeoIP > 기본국가)을
        // 사용한다. 미전달 시 OrderCalculationService 가 'KR' 로 폴백해 장바구니 배송비가 항상 국내가로 계산되는
        // 회귀를 막는다(selected_shipping_country 표시와 배송비 계산 국가 일치). 우편번호 없는 미리보기이므로
        // 국가만으로 해당 국가 배송비를 산정한다(도서산간/주별 세부 차등은 결제 단계 주소 입력 시 반영).
        $calculationInput = new CalculationInput(
            items: $calculationItems,
            couponIssueIds: $couponIssueIds,
            usePoints: $usePoints,
            shippingAddress: new ShippingAddress(countryCode: ResolveShippingCountry::getCountry()),
        );

        $calculationResult = $this->orderCalculationService->calculate($calculationInput);

        return new CartWithCalculationResult(
            items: $items,
            calculation: $calculationResult
        );
    }

    /**
     * 장바구니 담기 (동일 옵션 존재 시 수량 증가)
     *
     * @param  array  $data  장바구니 데이터 {product_id, product_option_id, quantity, user_id?, cart_key?}
     * @return Cart 생성/수정된 장바구니 아이템
     */
    private function addToCart(array $data): Cart
    {
        HookManager::doAction('sirsoft-ecommerce.cart.before_add', $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.cart.filter_add_data', $data);

        // 추가옵션 선택 검증·정규화 (서버 SSoT — D9/D12)
        $additionalSelections = $this->additionalOptionSelectionService->validateAndNormalize(
            $data['product_id'],
            $data['additional_option_selections'] ?? null
        );
        $selectionHash = Cart::normalizeAdditionalOptionSelectionHash($additionalSelections);

        // 동일 옵션 + 동일 추가옵션 조합 존재 여부 확인 (합산 판정에 추가옵션 해시 포함 — D3)
        $existingItem = null;
        $candidates = collect();
        if (! empty($data['user_id'])) {
            $candidates = $this->cartRepository->findAllByUserAndOption(
                $data['user_id'],
                $data['product_option_id']
            );
        } elseif (! empty($data['cart_key'])) {
            $candidates = $this->cartRepository->findAllByCartKeyAndOption(
                $data['cart_key'],
                $data['product_option_id']
            );
        }
        $existingItem = $candidates->first(
            fn ($cart) => $cart->getAdditionalOptionSelectionHash() === $selectionHash
        );

        // 판매상태 검증 (판매중지/품절/출시예정/전시중지 차단 — 재고보다 우선)
        $this->validatePurchasable($data['product_option_id']);

        // 재고 검증 (기존 수량 + 추가 수량)
        $currentQuantity = $existingItem ? $existingItem->quantity : 0;
        $this->validateStock($data['product_option_id'], $data['quantity'] ?? 1, $currentQuantity);

        $cart = DB::transaction(function () use ($data, $existingItem, $additionalSelections) {
            if ($existingItem) {
                // 동일 옵션 + 동일 추가옵션 조합 존재: 수량 증가
                return $this->cartRepository->update($existingItem, [
                    'quantity' => $existingItem->quantity + ($data['quantity'] ?? 1),
                ]);
            }

            // 새 아이템 생성
            return $this->cartRepository->create([
                'cart_key' => $data['cart_key'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'product_id' => $data['product_id'],
                'product_option_id' => $data['product_option_id'],
                'additional_option_selections' => ! empty($additionalSelections) ? $additionalSelections : null,
                'quantity' => $data['quantity'] ?? 1,
            ]);
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_add', $cart, $data);

        return $cart;
    }

    /**
     * 장바구니 일괄 담기
     *
     * 하나의 상품에 대해 여러 옵션 조합을 한 번에 장바구니에 담습니다.
     * option_values가 없는 경우(옵션 없는 상품)는 기본 옵션을 자동 조회합니다.
     *
     * @param  array  $data  {product_id, items: [{option_values?, quantity}], user_id?, cart_key?}
     * @return array{items: array<Cart>, cart_count: int} 추가된 아이템 목록과 장바구니 총 수량
     */
    public function bulkAddToCart(array $data): array
    {
        $productId = $data['product_id'];
        $userId = $data['user_id'] ?? null;
        $cartKey = $data['cart_key'] ?? null;

        // 상품의 전체 옵션 목록 조회
        $productOptions = $this->productOptionRepository->getByProductId($productId);

        // 상품당 총수량(기존 장바구니 + 이번 요청) 기준 최소/최대 구매수량 검증 (A25, 1차 방어선)
        $requestedTotal = array_sum(array_map(
            fn ($item) => max(1, (int) ($item['quantity'] ?? 1)),
            $data['items']
        ));
        $existingTotal = $this->cartRepository->sumQuantityByProduct($productId, $userId, $cartKey);
        $this->validatePurchaseQuantityLimit($productId, $existingTotal + $requestedTotal);

        $addedItems = [];

        foreach ($data['items'] as $item) {
            $optionValues = $item['option_values'] ?? null;
            $quantity = $item['quantity'] ?? 1;

            // option_values로 product_option_id 조회 (로케일 기준 값으로 매칭)
            if (! empty($optionValues)) {
                $matchedOption = $productOptions->first(function ($option) use ($optionValues) {
                    return $option->getLocalizedOptionValues() == $optionValues;
                });

                if (! $matchedOption) {
                    throw new \Exception(__('sirsoft-ecommerce::validation.cart.option_values_not_found'));
                }

                $productOptionId = $matchedOption->id;
            } else {
                // 옵션 없는 상품: 첫 번째(기본) 옵션 사용
                $defaultOption = $productOptions->first();
                if (! $defaultOption) {
                    throw new \Exception(__('sirsoft-ecommerce::validation.cart.option_not_found'));
                }
                $productOptionId = $defaultOption->id;
            }

            $cart = $this->addToCart([
                'product_id' => $productId,
                'product_option_id' => $productOptionId,
                'quantity' => $quantity,
                'user_id' => $userId,
                'cart_key' => $cartKey,
                'additional_option_selections' => $item['additional_option_selections'] ?? null,
            ]);

            $addedItems[] = $cart;
        }

        $cartCount = $this->getItemCount($userId, $cartKey);

        return [
            'items' => $addedItems,
            'cart_count' => $cartCount,
        ];
    }

    /**
     * 수량 변경
     *
     * @param  int  $cartId  장바구니 ID
     * @param  int  $quantity  변경할 수량
     * @param  int|null  $userId  회원 ID (권한 확인용)
     * @param  string|null  $cartKey  비회원 장바구니 키 (권한 확인용)
     * @return Cart 수정된 장바구니 아이템
     *
     * @throws \Exception 권한 없음 또는 아이템 없음
     */
    public function updateQuantity(int $cartId, int $quantity, ?int $userId, ?string $cartKey): Cart
    {
        $cart = $this->cartRepository->find($cartId);

        if (! $cart) {
            throw new CartOperationException('item_not_found');
        }

        // 권한 확인
        if (! $this->hasAccessToCart($cart, $userId, $cartKey)) {
            throw new CartOperationException('access_denied');
        }

        // 판매상태 검증 (판매중지/품절/출시예정/전시중지 차단)
        $this->validatePurchasable($cart->product_option_id);

        // 재고 검증 (변경 요청 수량)
        $this->validateStock($cart->product_option_id, $quantity, 0);

        // 상품당 총수량(동일 product 다른 라인 + 변경 후 이 라인) 기준 구매수량 한도 검증 (A25, 우회 차단)
        $otherLinesTotal = $this->cartRepository->sumQuantityByProduct(
            $cart->product_id,
            $userId,
            $cartKey,
            $cart->id
        );
        $this->validatePurchaseQuantityLimit($cart->product_id, $otherLinesTotal + $quantity);

        HookManager::doAction('sirsoft-ecommerce.cart.before_update_quantity', $cart, $quantity);

        $cart = DB::transaction(function () use ($cart, $quantity) {
            return $this->cartRepository->update($cart, ['quantity' => $quantity]);
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_update_quantity', $cart, $quantity);

        return $cart;
    }

    /**
     * 옵션 변경 (수량 포함)
     *
     * @param  int  $cartId  장바구니 ID
     * @param  int  $newProductOptionId  새 상품 옵션 ID
     * @param  int  $quantity  수량
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  array|null  $additionalOptionSelections  새 추가옵션 선택 (null이면 기존 선택 유지)
     * @return Cart 수정된 장바구니 아이템
     *
     * @throws \Exception
     */
    public function changeOption(int $cartId, int $newProductOptionId, int $quantity, ?int $userId, ?string $cartKey, ?array $additionalOptionSelections = null): Cart
    {
        $cart = $this->cartRepository->find($cartId);

        if (! $cart) {
            throw new CartOperationException('item_not_found');
        }

        if (! $this->hasAccessToCart($cart, $userId, $cartKey)) {
            throw new CartOperationException('access_denied');
        }

        // 동일 상품 옵션 검증 (다른 상품의 옵션으로 변경 불가)
        $this->validateSameProduct($cart, $newProductOptionId);

        // 판매상태 검증 (판매중지/품절/출시예정/전시중지 차단)
        $this->validatePurchasable($newProductOptionId);

        // 추가옵션: 미전달 시 기존 선택 유지, 전달 시 검증·정규화 (서버 SSoT — D3/D9/D12)
        $newSelections = $additionalOptionSelections === null
            ? ($cart->additional_option_selections ?? [])
            : $this->additionalOptionSelectionService->validateAndNormalize($cart->product_id, $additionalOptionSelections);
        $newSelectionHash = Cart::normalizeAdditionalOptionSelectionHash($newSelections);

        // 변경하려는 (옵션 + 추가옵션 조합)이 이미 장바구니에 있는지 확인 (재고 검증 전에 확인 — D3)
        $candidates = collect();
        if ($userId !== null) {
            $candidates = $this->cartRepository->findAllByUserAndOption($userId, $newProductOptionId);
        } elseif ($cartKey !== null) {
            $candidates = $this->cartRepository->findAllByCartKeyAndOption($cartKey, $newProductOptionId);
        }
        $existingItem = $candidates->first(
            fn ($candidate) => $candidate->id !== $cart->id
                && $candidate->getAdditionalOptionSelectionHash() === $newSelectionHash
        );

        // 재고 검증 (합산된 수량 기준)
        if ($existingItem) {
            $this->validateStock($newProductOptionId, $quantity, $existingItem->quantity);
        } else {
            $this->validateStock($newProductOptionId, $quantity, 0);
        }

        HookManager::doAction('sirsoft-ecommerce.cart.before_change_option', $cart, $newProductOptionId, $quantity);

        $cart = DB::transaction(function () use ($cart, $newProductOptionId, $quantity, $existingItem, $newSelections) {
            if ($existingItem) {
                // 이미 존재(동일 옵션+추가옵션 조합)하면 수량 합산 후 기존 아이템 삭제
                $this->cartRepository->update($existingItem, [
                    'quantity' => $existingItem->quantity + $quantity,
                ]);
                $this->cartRepository->delete($cart);

                return $existingItem->fresh();
            }

            // 옵션·수량·추가옵션 변경
            return $this->cartRepository->update($cart, [
                'product_option_id' => $newProductOptionId,
                'quantity' => $quantity,
                'additional_option_selections' => ! empty($newSelections) ? $newSelections : null,
            ]);
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_change_option', $cart, $newProductOptionId, $quantity);

        return $cart;
    }

    /**
     * 단일 삭제
     *
     * @param  int  $cartId  장바구니 ID
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return bool 삭제 성공 여부
     *
     * @throws \Exception
     */
    public function deleteItem(int $cartId, ?int $userId, ?string $cartKey): bool
    {
        $cart = $this->cartRepository->find($cartId);

        if (! $cart) {
            throw new CartOperationException('item_not_found');
        }

        if (! $this->hasAccessToCart($cart, $userId, $cartKey)) {
            throw new CartOperationException('access_denied');
        }

        HookManager::doAction('sirsoft-ecommerce.cart.before_delete', $cart);

        $result = DB::transaction(function () use ($cart) {
            return $this->cartRepository->delete($cart);
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_delete', $cartId);

        return $result;
    }

    /**
     * 선택 삭제
     *
     * @param  array  $ids  장바구니 ID 배열
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return int 삭제된 개수
     */
    public function deleteItems(array $ids, ?int $userId, ?string $cartKey): int
    {
        HookManager::doAction('sirsoft-ecommerce.cart.before_delete_multiple', $ids);

        $deletedCount = DB::transaction(function () use ($ids, $userId, $cartKey) {
            $items = $this->cartRepository->findByIds($ids);

            // 권한 있는 아이템만 필터링
            $authorizedIds = $items->filter(function ($item) use ($userId, $cartKey) {
                return $this->hasAccessToCart($item, $userId, $cartKey);
            })->pluck('id')->toArray();

            if (empty($authorizedIds)) {
                return 0;
            }

            return $this->cartRepository->deleteByIds($authorizedIds);
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_delete_multiple', $ids, $deletedCount);

        return $deletedCount;
    }

    /**
     * 비회원 장바구니를 회원 계정으로 병합
     *
     * @param  string  $cartKey  비회원 cart_key
     * @param  int  $userId  로그인한 회원 ID
     * @return int 병합된 아이템 수
     */
    public function mergeGuestCartToUser(string $cartKey, int $userId): int
    {
        HookManager::doAction('sirsoft-ecommerce.cart.before_merge', $cartKey, $userId);

        $mergedCount = DB::transaction(function () use ($cartKey, $userId) {
            $guestItems = $this->cartRepository->findByCartKeyWithoutUser($cartKey);
            $count = 0;

            foreach ($guestItems as $guestItem) {
                // 재고 확인
                $productOption = $this->productOptionRepository->findById($guestItem->product_option_id);
                $availableStock = $productOption?->stock_quantity ?? 0;

                // 회원 장바구니에 동일 옵션 + 동일 추가옵션 조합이 있는지 확인 (D3)
                $guestHash = $guestItem->getAdditionalOptionSelectionHash();
                $existingItem = $this->cartRepository
                    ->findAllByUserAndOption($userId, $guestItem->product_option_id)
                    ->first(fn ($candidate) => $candidate->getAdditionalOptionSelectionHash() === $guestHash);

                if ($existingItem) {
                    // 동일 옵션 존재: 수량 합산 (재고 초과 시 재고 최대치로 조정)
                    $totalQuantity = $existingItem->quantity + $guestItem->quantity;
                    $finalQuantity = $availableStock > 0 ? min($totalQuantity, $availableStock) : $totalQuantity;

                    $this->cartRepository->update($existingItem, [
                        'quantity' => $finalQuantity,
                    ]);
                    $this->cartRepository->delete($guestItem);
                } else {
                    // 동일 옵션 없음: 재고 초과 시 조정 후 user_id 업데이트 (cart_key 유지)
                    $finalQuantity = $availableStock > 0 ? min($guestItem->quantity, $availableStock) : $guestItem->quantity;

                    $this->cartRepository->update($guestItem, [
                        'user_id' => $userId,
                        'quantity' => $finalQuantity,
                    ]);
                }

                $count++;
            }

            return $count;
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_merge', $cartKey, $userId, $mergedCount);

        return $mergedCount;
    }

    /**
     * 과거 주문에서 재주문 — 주문 옵션을 장바구니에 추가.
     *
     * 사용자의 과거 주문 (취소된 주문 포함) 에서 상품 옵션을 추출하여
     * 현재 장바구니에 일괄 추가한다. 품절/단종/옵션 변경 등으로 추가 불가한
     * 항목은 skip 하고 사유를 누적해 반환한다.
     *
     * @param  int  $orderId  대상 주문 ID
     * @param  int  $userId  요청 사용자 ID (소유권 검증)
     * @return array{added_count:int, skipped:list<array{product_name:string,reason:string}>, cart_count:int}
     *
     * @throws \Exception 주문 미존재 또는 사용자 권한 불일치 시
     */
    public function reorderFromOrder(int $orderId, int $userId): array
    {
        $order = $this->orderRepository->findWithRelations($orderId);

        if (! $order) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.order_not_found'));
        }

        if ((int) $order->user_id !== $userId) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.unauthorized'));
        }

        HookManager::doAction('sirsoft-ecommerce.cart.before_reorder', $order, $userId);

        $addedCount = 0;
        $skipped = [];

        $topLevelOptions = $order->options->filter(fn ($opt) => $opt->parent_option_id === null);

        $locale = app()->getLocale();
        foreach ($topLevelOptions as $orderOption) {
            $rawName = $orderOption->product_name;
            if (is_array($rawName)) {
                $productName = (string) ($rawName[$locale] ?? $rawName['ko'] ?? $rawName['en'] ?? __('sirsoft-ecommerce::messages.cart.unknown_product'));
            } else {
                $productName = (string) ($rawName ?? __('sirsoft-ecommerce::messages.cart.unknown_product'));
            }
            $quantity = max(1, (int) $orderOption->quantity);

            $productOption = $this->productOptionRepository->findById((int) $orderOption->product_option_id);
            if (! $productOption) {
                $skipped[] = [
                    'product_name' => $productName,
                    'reason' => __('sirsoft-ecommerce::messages.cart.reorder_option_not_found'),
                ];

                continue;
            }

            try {
                $this->addToCart([
                    'product_id' => (int) $orderOption->product_id,
                    'product_option_id' => (int) $orderOption->product_option_id,
                    'quantity' => $quantity,
                    'user_id' => $userId,
                    'cart_key' => null,
                ]);
                $addedCount++;
            } catch (\Exception $e) {
                $skipped[] = [
                    'product_name' => $productName,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $cartCount = $this->getItemCount($userId, null);

        HookManager::doAction('sirsoft-ecommerce.cart.after_reorder', $order, $userId, $addedCount, $skipped);

        return [
            'added_count' => $addedCount,
            'skipped' => $skipped,
            'cart_count' => $cartCount,
        ];
    }

    /**
     * 장바구니 아이템 수 조회
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return int 아이템 수
     */
    public function getItemCount(?int $userId, ?string $cartKey): int
    {
        return $this->cartRepository->countItems($userId, $cartKey);
    }

    /**
     * 장바구니 전체 삭제
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return int 삭제된 개수
     */
    public function deleteAll(?int $userId, ?string $cartKey): int
    {
        HookManager::doAction('sirsoft-ecommerce.cart.before_delete_all', $userId, $cartKey);

        $deletedCount = DB::transaction(function () use ($userId, $cartKey) {
            if ($userId !== null) {
                return $this->cartRepository->deleteByUserId($userId);
            } elseif ($cartKey !== null) {
                return $this->cartRepository->deleteByCartKey($cartKey);
            }

            return 0;
        });

        HookManager::doAction('sirsoft-ecommerce.cart.after_delete_all', $userId, $cartKey, $deletedCount);

        return $deletedCount;
    }

    /**
     * 장바구니 접근 권한 확인
     *
     * @param  Cart  $cart  장바구니 아이템
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return bool 접근 권한 여부
     */
    protected function hasAccessToCart(Cart $cart, ?int $userId, ?string $cartKey): bool
    {
        // 회원 장바구니
        if ($cart->user_id !== null) {
            return $cart->user_id === $userId;
        }

        // 비회원 장바구니
        return $cart->cart_key === $cartKey;
    }

    /**
     * 재고 검증
     *
     * @param  int  $productOptionId  상품 옵션 ID
     * @param  int  $requestedQuantity  요청 수량
     * @param  int  $currentQuantity  현재 장바구니에 담긴 수량 (합산용, 기본값 0)
     *
     * @throws \Exception 재고 초과 시 예외
     */
    protected function validateStock(int $productOptionId, int $requestedQuantity, int $currentQuantity = 0): void
    {
        $productOption = $this->productOptionRepository->findById($productOptionId);

        if (! $productOption) {
            throw new CartOperationException('option_not_found');
        }

        $totalQuantity = $currentQuantity + $requestedQuantity;
        $availableStock = $productOption->stock_quantity ?? 0;

        // 재고 0 이하(품절) 또는 요청 수량 초과 — 둘 다 422 (CartUnavailableException reason=stock).
        // generic \Exception 으로 던지면 컨트롤러 catch-all 에 걸려 500 이 되므로,
        // 도메인 예외로 던져 cartUnavailableResponse() 의 422 + getUserMessage() 경로를 타게 한다.
        // (MP07 §1-b — U9/U8 5b 담기 500 결함)
        if ($availableStock <= 0 || $totalQuantity > $availableStock) {
            $product = $productOption->product
                ?? $this->productOptionRepository->findByIdWithProduct($productOptionId)?->product;

            throw CartUnavailableException::fromItems([[
                'product_id' => $product?->id,
                'product_option_id' => $productOptionId,
                'name' => $product?->getLocalizedName() ?? '',
                // getUserMessage() 의 stock 분기가 quantity→:requested, stock→:available 로 치환
                'quantity' => $totalQuantity,
                'stock' => max(0, (int) $availableStock),
                'reason' => 'stock',
            ]]);
        }
    }

    /**
     * 상품 판매 가능 여부를 검증합니다. (담기/수량변경/옵션변경 시)
     *
     * 판매중지/품절/출시예정/전시중지(hidden) 상품은 담을 수 없습니다.
     * 재고 검증보다 먼저 호출하여 판매상태 메시지를 우선 노출합니다.
     *
     * @param  int  $productOptionId  상품 옵션 ID
     *
     * @throws CartUnavailableException 판매중지/품절/출시예정/전시중지 상품인 경우
     * @throws \Exception 옵션/상품을 찾을 수 없는 경우
     */
    protected function validatePurchasable(int $productOptionId): void
    {
        $option = $this->productOptionRepository->findByIdWithProduct($productOptionId);

        if (! $option || ! $option->product) {
            throw new CartOperationException('option_not_found');
        }

        if (! $option->product->isPurchasable()) {
            throw CartUnavailableException::fromItems([[
                'product_id' => $option->product->id,
                'product_option_id' => $productOptionId,
                'name' => $option->product->getLocalizedName(),
                'reason' => 'status',
            ]]);
        }
    }

    /**
     * 상품당 총수량 기준 최소/최대 구매수량 한도를 검증합니다. (담기/수량변경 시 1차 방어선)
     *
     * 동일 상품의 모든 옵션 라인 합산 수량으로 판정합니다 (옵션 분할 우회 차단).
     * max_purchase_qty = 0 은 무제한(상한 skip), min_purchase_qty 기본 1.
     *
     * @param  int  $productId  상품 ID
     * @param  int  $totalQuantity  검증 후 상품 총수량 (기존 장바구니 + 이번 요청)
     *
     * @throws CartUnavailableException 최소 미달/최대 초과 시
     */
    protected function validatePurchaseQuantityLimit(int $productId, int $totalQuantity): void
    {
        $product = $this->productRepository->find($productId);

        if (! $product) {
            return;
        }

        $min = (int) ($product->min_purchase_qty ?? 1);
        $max = (int) ($product->max_purchase_qty ?? 0);

        if ($min > 0 && $totalQuantity < $min) {
            throw CartUnavailableException::fromItems([[
                'product_id' => $productId,
                'name' => $product->getLocalizedName(),
                'requested' => $totalQuantity,
                'limit' => $min,
                'reason' => 'min_qty',
            ]]);
        }

        if ($max > 0 && $totalQuantity > $max) {
            throw CartUnavailableException::fromItems([[
                'product_id' => $productId,
                'name' => $product->getLocalizedName(),
                'requested' => $totalQuantity,
                'limit' => $max,
                'reason' => 'max_qty',
            ]]);
        }
    }

    /**
     * 동일 상품 옵션 검증 (옵션 변경 시)
     *
     * @param  Cart  $cart  현재 장바구니 아이템
     * @param  int  $newProductOptionId  변경하려는 옵션 ID
     *
     * @throws \Exception 다른 상품의 옵션으로 변경 시도 시
     */
    protected function validateSameProduct(Cart $cart, int $newProductOptionId): void
    {
        $newOption = $this->productOptionRepository->findById($newProductOptionId);

        if (! $newOption) {
            throw new CartOperationException('option_not_found');
        }

        if ($cart->product_id !== $newOption->product_id) {
            throw new CartOperationException('invalid_option');
        }
    }
}
