<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\TempOrderRepositoryInterface;

/**
 * 임시 주문 서비스
 *
 * 주문서 작성 단계에서 장바구니 아이템을 임시 주문으로 변환하고
 * 주문 계산 결과를 저장/관리하는 서비스입니다.
 */
class TempOrderService
{
    /**
     * 임시 주문 만료 시간 (분)
     */
    protected const EXPIRATION_MINUTES = 30;

    public function __construct(
        protected TempOrderRepositoryInterface $tempOrderRepository,
        protected CartRepositoryInterface $cartRepository,
        protected CouponIssueRepositoryInterface $couponIssueRepository,
        protected ProductOptionRepositoryInterface $productOptionRepository,
        protected OrderCalculationService $orderCalculationService
    ) {}

    /**
     * 임시 주문 생성 (주문하기 클릭 시)
     *
     * 장바구니 아이템을 받아 주문 계산 후 임시 주문을 생성합니다.
     * 동일 cart_key 또는 user_id가 존재하면 덮어씁니다.
     * 쿠폰은 초기 생성 시 적용하지 않고, updateTempOrder에서 적용합니다.
     *
     * @param  Collection  $cartItems  장바구니 아이템 컬렉션
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  int  $usePoints  사용할 마일리지
     * @return TempOrder 생성된 임시 주문
     *
     * @throws \Exception
     */
    public function createTempOrder(
        Collection $cartItems,
        ?int $userId,
        ?string $cartKey,
        int $usePoints = 0
    ): TempOrder {
        if ($cartItems->isEmpty()) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.cart_empty'));
        }

        HookManager::doAction('sirsoft-ecommerce.temp_order.before_create', $cartItems, $userId, $cartKey);

        // 마일리지 사용 검증
        $validatedPoints = $this->validatePointsUsage($userId, $usePoints);

        // 장바구니 아이템을 CalculationItem으로 변환
        $calculationItems = $this->convertCartItemsToCalculationItems($cartItems);

        // 주문 계산 실행 (쿠폰 없이)
        $calculationInput = new CalculationInput(
            items: $calculationItems,
            usePoints: $validatedPoints
        );

        $calculationResult = $this->orderCalculationService->calculate($calculationInput);

        // 임시 주문 생성/수정
        $tempOrder = DB::transaction(function () use (
            $cartItems,
            $userId,
            $cartKey,
            $validatedPoints,
            $calculationResult
        ) {
            $data = [
                'cart_key' => $cartKey,
                'user_id' => $userId,
                'items' => $this->serializeCartItems($cartItems),
                'calculation_input' => [
                    'promotions' => [
                        'item_coupons' => [],
                        'order_coupon_issue_id' => null,
                        'shipping_coupon_issue_id' => null,
                    ],
                    'use_points' => $validatedPoints,
                    'shipping_address' => null,
                ],
                'calculation_result' => $calculationResult->toArray(),
                'expires_at' => Carbon::now()->addMinutes(self::EXPIRATION_MINUTES),
            ];

            return $this->tempOrderRepository->upsert($data);
        });

        HookManager::doAction('sirsoft-ecommerce.temp_order.after_create', $tempOrder);

        return $tempOrder;
    }

    /**
     * 선택된 장바구니 아이템으로 임시 주문 생성
     *
     * 장바구니 ID 배열을 받아 해당 아이템들만 임시 주문에 포함합니다.
     * 쿠폰은 초기 생성 시 적용하지 않고, updateTempOrder에서 적용합니다.
     *
     * @param  array  $cartIds  선택된 장바구니 ID 배열
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  int  $usePoints  사용할 마일리지
     * @return TempOrder 생성된 임시 주문
     *
     * @throws \Exception
     */
    public function createTempOrderFromSelectedItems(
        array $cartIds,
        ?int $userId,
        ?string $cartKey,
        int $usePoints = 0
    ): TempOrder {
        // 선택된 장바구니 아이템 조회
        $cartItems = $this->cartRepository->findByIds($cartIds);

        // 권한 확인: 본인 장바구니만 허용
        $authorizedItems = $cartItems->filter(function ($item) use ($userId, $cartKey) {
            if ($userId !== null) {
                return $item->user_id === $userId;
            }

            return $item->cart_key === $cartKey && $item->user_id === null;
        });

        // 재고/판매상태 검증 (실패 시 CartUnavailableException 발생)
        $this->validateCartItemsAvailability($authorizedItems);

        return $this->createTempOrder($authorizedItems, $userId, $cartKey, $usePoints);
    }

    /**
     * 유효한 임시 주문 조회
     *
     * 만료되지 않은 임시 주문을 조회합니다.
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     */
    public function getTempOrder(?int $userId, ?string $cartKey): ?TempOrder
    {
        return $this->tempOrderRepository->findValidByUserOrCartKey($userId, $cartKey);
    }

    /**
     * 임시 주문 업데이트 (쿠폰/마일리지/배송주소 변경 시 재계산)
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  array  $promotions  프로모션 정보 {item_coupons, order_coupon_issue_id, shipping_coupon_issue_id}
     * @param  int  $usePoints  사용할 마일리지
     * @param  ShippingAddress|null  $shippingAddress  배송 주소 정보
     * @return TempOrder|null 수정된 임시 주문
     *
     * @throws \Exception
     */
    public function updateTempOrder(
        ?int $userId,
        ?string $cartKey,
        array $promotions = [],
        ?int $usePoints = 0,
        ?ShippingAddress $shippingAddress = null
    ): ?TempOrder {
        $tempOrder = $this->getTempOrder($userId, $cartKey);

        if (! $tempOrder) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.temp_order_not_found'));
        }

        // 미전송 필드는 기존 값 유지 (배송 주소만 변경 시 쿠폰/마일리지 초기화 방지)
        $existingPromotions = $tempOrder->getPromotions() ?? [];

        // 마일리지: null이면 기존 값 유지
        if ($usePoints === null) {
            $usePoints = $tempOrder->getUsedPoints() ?? 0;
        }

        HookManager::doAction('sirsoft-ecommerce.temp_order.before_update', $tempOrder, $promotions, $usePoints, $shippingAddress);

        // 프로모션에서 쿠폰 정보 추출 (미전송 키는 기존 값으로 폴백)
        $itemCoupons = array_key_exists('item_coupons', $promotions)
            ? ($promotions['item_coupons'] ?? [])
            : ($existingPromotions['item_coupons'] ?? []);
        $orderCouponIssueId = array_key_exists('order_coupon_issue_id', $promotions)
            ? $promotions['order_coupon_issue_id']
            : ($existingPromotions['order_coupon_issue_id'] ?? null);
        $shippingCouponIssueId = array_key_exists('shipping_coupon_issue_id', $promotions)
            ? $promotions['shipping_coupon_issue_id']
            : ($existingPromotions['shipping_coupon_issue_id'] ?? null);

        // 상품별 쿠폰 소유권 검증 및 필터링
        $validatedItemCoupons = $this->validateAndFilterItemCoupons($userId, $itemCoupons);

        // 주문/배송비 쿠폰 소유권 검증
        $validatedOrderCouponId = $this->validateSingleCoupon($userId, $orderCouponIssueId);
        $validatedShippingCouponId = $this->validateSingleCoupon($userId, $shippingCouponIssueId);

        // 마일리지 사용 검증
        $validatedPoints = $this->validatePointsUsage($userId, $usePoints);

        // 기존 아이템 데이터로 재계산
        $items = $tempOrder->items ?? [];
        $calculationItems = array_map(
            fn (array $item) => CalculationItem::fromArray($item),
            $items
        );

        // 배송 주소가 없으면 기존 저장된 주소 사용
        if ($shippingAddress === null && $tempOrder->getShippingAddress() !== null) {
            $shippingAddress = ShippingAddress::fromArray($tempOrder->getShippingAddress());
        }

        // CalculationInput에 전달할 쿠폰 ID 배열 구성
        $couponIssueIds = array_filter([$validatedOrderCouponId, $validatedShippingCouponId]);

        $calculationInput = new CalculationInput(
            items: $calculationItems,
            couponIssueIds: $couponIssueIds,
            itemCoupons: $validatedItemCoupons,
            usePoints: $validatedPoints,
            shippingAddress: $shippingAddress
        );

        $calculationResult = $this->orderCalculationService->calculate($calculationInput);

        // 검증된 프로모션 정보 구성 (프론트 필드명 그대로 저장)
        $validatedPromotions = [
            'item_coupons' => $validatedItemCoupons,
            'order_coupon_issue_id' => $validatedOrderCouponId,
            'shipping_coupon_issue_id' => $validatedShippingCouponId,
        ];

        $tempOrder = DB::transaction(function () use ($tempOrder, $validatedPromotions, $validatedPoints, $shippingAddress, $calculationResult) {
            $updateData = [
                'calculation_input' => [
                    'promotions' => $validatedPromotions,
                    'use_points' => $validatedPoints,
                    'shipping_address' => $shippingAddress?->toArray(),
                ],
                'calculation_result' => $calculationResult->toArray(),
                'expires_at' => Carbon::now()->addMinutes(self::EXPIRATION_MINUTES),
            ];

            return $this->tempOrderRepository->update($tempOrder, $updateData);
        });

        HookManager::doAction('sirsoft-ecommerce.temp_order.after_update', $tempOrder);

        return $tempOrder;
    }

    /**
     * 임시 주문 조회 및 실시간 재계산
     *
     * 저장된 임시 주문의 아이템/쿠폰/마일리지/주소 정보로 실시간 재계산하여 반환합니다.
     * 상품 가격 변경, 쿠폰 만료 등이 반영됩니다.
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @param  ShippingAddress|null  $shippingAddress  배송 주소 (null이면 저장된 주소 사용)
     * @return array{temp_order: TempOrder, calculation: OrderCalculationResult}|null
     */
    public function getTempOrderWithCalculation(
        ?int $userId,
        ?string $cartKey,
        ?ShippingAddress $shippingAddress = null
    ): ?array {
        $tempOrder = $this->getTempOrder($userId, $cartKey);

        if (! $tempOrder) {
            return null;
        }

        // 조회 시 만료 시간 자동 연장 (사용자가 아직 결제 중임을 의미)
        $tempOrder = $this->tempOrderRepository->update($tempOrder, [
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRATION_MINUTES),
        ]);

        // 저장된 아이템으로 재계산
        $items = $tempOrder->items ?? [];
        $calculationItems = array_map(
            fn (array $item) => CalculationItem::fromArray($item),
            $items
        );

        // 배송 주소 결정: 파라미터 > 저장된 주소
        $effectiveAddress = $shippingAddress;
        if ($effectiveAddress === null && $tempOrder->getShippingAddress() !== null) {
            $effectiveAddress = ShippingAddress::fromArray($tempOrder->getShippingAddress());
        }

        // 저장된 프로모션 정보에서 쿠폰 ID 배열 구성
        $promotions = $tempOrder->getPromotions();
        $couponIssueIds = array_filter([
            $promotions['order_coupon_issue_id'] ?? null,
            $promotions['shipping_coupon_issue_id'] ?? null,
        ]);

        $calculationInput = new CalculationInput(
            items: $calculationItems,
            couponIssueIds: $couponIssueIds,
            itemCoupons: $promotions['item_coupons'] ?? [],
            usePoints: $tempOrder->getUsedPoints(),
            shippingAddress: $effectiveAddress
        );

        $calculationResult = $this->orderCalculationService->calculate($calculationInput);

        // 재고/판매상태 검증 (예외 없이 목록 반환)
        $unavailableItems = $this->checkTempOrderItemsAvailability($tempOrder);

        return [
            'temp_order' => $tempOrder,
            'calculation' => $calculationResult,
            'unavailable_items' => $unavailableItems,
        ];
    }

    /**
     * 임시 주문 만료 시간 연장
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return TempOrder|null 수정된 임시 주문
     */
    public function extendExpiration(?int $userId, ?string $cartKey): ?TempOrder
    {
        $tempOrder = $this->getTempOrder($userId, $cartKey);

        if (! $tempOrder) {
            return null;
        }

        return $this->tempOrderRepository->update($tempOrder, [
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRATION_MINUTES),
        ]);
    }

    /**
     * 임시 주문 삭제
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     * @return bool 삭제 성공 여부
     */
    public function deleteTempOrder(?int $userId, ?string $cartKey): bool
    {
        $tempOrder = $this->tempOrderRepository->findByUserOrCartKey($userId, $cartKey);

        if (! $tempOrder) {
            return false;
        }

        HookManager::doAction('sirsoft-ecommerce.temp_order.before_delete', $tempOrder);

        $result = $this->tempOrderRepository->delete($tempOrder);

        HookManager::doAction('sirsoft-ecommerce.temp_order.after_delete', $userId, $cartKey);

        return $result;
    }

    /**
     * 만료된 임시 주문 정리
     *
     * @return int 삭제된 개수
     */
    public function cleanupExpiredTempOrders(): int
    {
        HookManager::doAction('sirsoft-ecommerce.temp_order.before_cleanup');

        $deletedCount = $this->tempOrderRepository->deleteExpired();

        HookManager::doAction('sirsoft-ecommerce.temp_order.after_cleanup', $deletedCount);

        return $deletedCount;
    }

    /**
     * 계산 결과만 조회 (API 응답용)
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     */
    public function getCalculationResult(?int $userId, ?string $cartKey): ?OrderCalculationResult
    {
        $tempOrder = $this->getTempOrder($userId, $cartKey);

        if (! $tempOrder) {
            return null;
        }

        return OrderCalculationResult::fromArray($tempOrder->calculation_result ?? []);
    }

    /**
     * 임시 주문 존재 여부 확인
     *
     * @param  int|null  $userId  회원 ID
     * @param  string|null  $cartKey  비회원 장바구니 키
     */
    public function hasTempOrder(?int $userId, ?string $cartKey): bool
    {
        return $this->getTempOrder($userId, $cartKey) !== null;
    }

    /**
     * 장바구니 아이템을 CalculationItem 배열로 변환
     *
     * @param  Collection  $cartItems  장바구니 아이템 컬렉션
     * @return CalculationItem[]
     */
    protected function convertCartItemsToCalculationItems(Collection $cartItems): array
    {
        return $cartItems->map(function ($cart) {
            return new CalculationItem(
                productId: $cart->product_id,
                productOptionId: $cart->product_option_id,
                quantity: $cart->quantity,
                cartId: $cart->id
            );
        })->all();
    }

    /**
     * 장바구니 아이템을 저장용 배열로 직렬화
     *
     * @param  Collection  $cartItems  장바구니 아이템 컬렉션
     */
    protected function serializeCartItems(Collection $cartItems): array
    {
        return $cartItems->map(function ($cart) {
            return [
                'cart_id' => $cart->id,
                'product_id' => $cart->product_id,
                'product_option_id' => $cart->product_option_id,
                'quantity' => $cart->quantity,
            ];
        })->all();
    }

    /**
     * 쿠폰 소유권을 검증하고 유효한 쿠폰만 필터링
     *
     * 비회원은 쿠폰 사용 불가, 회원은 본인 소유 쿠폰만 사용 가능
     *
     * @param  int|null  $userId  사용자 ID
     * @param  array  $couponIssueIds  쿠폰 발급 ID 배열
     * @return array 유효한 쿠폰 ID 배열
     */
    protected function validateAndFilterCoupons(?int $userId, array $couponIssueIds): array
    {
        // 비회원은 쿠폰 사용 불가
        if ($userId === null) {
            return [];
        }

        // 빈 배열이면 그대로 반환
        if (empty($couponIssueIds)) {
            return [];
        }

        // 소유권 검증 (본인 소유 쿠폰만 필터링)
        $ownedCoupons = $this->couponIssueRepository->findByIdsForUser($couponIssueIds, $userId);
        $ownedCouponIds = $ownedCoupons->pluck('id')->all();

        // 요청한 쿠폰 중 본인 소유인 것만 반환
        return array_values(array_intersect($couponIssueIds, $ownedCouponIds));
    }

    /**
     * 상품별 쿠폰 소유권 검증 및 필터링
     *
     * 비회원은 쿠폰 사용 불가
     * 본인 소유가 아닌 쿠폰은 필터링
     *
     * @param  int|null  $userId  사용자 ID
     * @param  array<int, int[]>  $itemCoupons  상품옵션별 쿠폰 [상품옵션ID => [쿠폰발급ID, ...]]
     * @return array<int, int[]> 검증된 상품옵션별 쿠폰
     */
    protected function validateAndFilterItemCoupons(?int $userId, array $itemCoupons): array
    {
        // 비회원은 쿠폰 사용 불가
        if ($userId === null) {
            return [];
        }

        // 빈 배열이면 그대로 반환
        if (empty($itemCoupons)) {
            return [];
        }

        // 모든 쿠폰 ID를 추출
        $allCouponIds = [];
        foreach ($itemCoupons as $couponIds) {
            $allCouponIds = array_merge($allCouponIds, $couponIds);
        }
        $allCouponIds = array_unique($allCouponIds);

        // 소유권 검증 (본인 소유 쿠폰만 필터링)
        $ownedCoupons = $this->couponIssueRepository->findByIdsForUser($allCouponIds, $userId);
        $ownedCouponIds = $ownedCoupons->pluck('id')->all();

        // 상품옵션별로 본인 소유 쿠폰만 필터링하여 반환
        $validatedItemCoupons = [];
        foreach ($itemCoupons as $optionId => $couponIds) {
            $validCouponIds = array_values(array_intersect($couponIds, $ownedCouponIds));
            if (! empty($validCouponIds)) {
                $validatedItemCoupons[$optionId] = $validCouponIds;
            }
        }

        return $validatedItemCoupons;
    }

    /**
     * 마일리지 사용 검증
     *
     * 비회원은 마일리지 사용 불가
     *
     * @param  int|null  $userId  사용자 ID
     * @param  int  $usePoints  사용할 마일리지
     * @return int 검증된 사용 마일리지
     */
    protected function validatePointsUsage(?int $userId, int $usePoints): int
    {
        // 비회원은 마일리지 사용 불가
        if ($userId === null) {
            return 0;
        }

        // TODO: 마일리지 시스템 구현 시 실제 잔액 검증 추가
        // 현재는 음수 방지만 처리
        return max(0, $usePoints);
    }

    /**
     * 단일 쿠폰 소유권 검증
     *
     * @param  int|null  $userId  사용자 ID
     * @param  int|null  $couponIssueId  쿠폰 발급 ID
     * @return int|null 검증된 쿠폰 ID (소유하지 않으면 null)
     */
    protected function validateSingleCoupon(?int $userId, ?int $couponIssueId): ?int
    {
        // 비회원은 쿠폰 사용 불가
        if ($userId === null || $couponIssueId === null) {
            return null;
        }

        // 소유권 검증
        $ownedCoupons = $this->couponIssueRepository->findByIdsForUser([$couponIssueId], $userId);

        return $ownedCoupons->isNotEmpty() ? $couponIssueId : null;
    }

    /**
     * 장바구니 아이템의 재고/판매상태 검증
     *
     * 구매 불가능한 상품(재고 부족, 판매중지)이 있으면 예외를 발생시킵니다.
     *
     * @param  Collection  $cartItems  장바구니 아이템 컬렉션 (product, productOption 관계 로드 필요)
     *
     * @throws CartUnavailableException 구매 불가능한 상품이 있는 경우
     */
    protected function validateCartItemsAvailability(Collection $cartItems): void
    {
        $unavailableItems = [];

        foreach ($cartItems as $item) {
            $product = $item->product;
            $option = $item->productOption;

            if (! $product || ! $option) {
                continue;
            }

            // 썸네일 URL 추출
            $thumbnailImage = $product->relationLoaded('images')
                ? ($product->images->firstWhere('is_thumbnail', true) ?? $product->images->first())
                : null;

            // 옵션 값 포맷 (예: "색상: 빨강, 사이즈: L")
            $formattedOption = $this->formatOptionValues($option->getLocalizedOptionValues());

            // 1. 판매상태 체크 (sales_status !== 'on_sale')
            if ($product->sales_status !== ProductSalesStatus::ON_SALE) {
                $unavailableItems[] = [
                    'cart_id' => $item->id,
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'name' => $product->getLocalizedName(),
                    'option' => $formattedOption,
                    'thumbnail' => $thumbnailImage?->download_url,
                    'quantity' => $item->quantity,
                    'stock' => $option->stock_quantity ?? 0,
                    'reason' => 'status',
                ];

                continue;
            }

            // 2. 재고 체크 (quantity > stock_quantity)
            $stockQuantity = $option->stock_quantity ?? 0;
            if ($item->quantity > $stockQuantity) {
                $unavailableItems[] = [
                    'cart_id' => $item->id,
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'name' => $product->getLocalizedName(),
                    'option' => $formattedOption,
                    'thumbnail' => $thumbnailImage?->download_url,
                    'quantity' => $item->quantity,
                    'stock' => $stockQuantity,
                    'reason' => 'stock',
                ];
            }
        }

        if (! empty($unavailableItems)) {
            throw CartUnavailableException::fromItems($unavailableItems);
        }
    }

    /**
     * 옵션 값 배열을 포맷된 문자열로 변환
     *
     * @param array $optionValues 옵션 값 배열 (예: ['색상' => '빨강', '사이즈' => 'L'])
     * @return string|null 포맷된 문자열 (예: "색상: 빨강, 사이즈: L") 또는 빈 경우 null
     */
    protected function formatOptionValues(array $optionValues): ?string
    {
        if (empty($optionValues)) {
            return null;
        }

        $parts = [];
        foreach ($optionValues as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return implode(', ', $parts);
    }

    /**
     * 임시 주문 아이템의 재고/판매상태 검증 (예외를 발생시키지 않음)
     *
     * 체크아웃 페이지 새로고침 시 현재 재고 상황을 확인하고
     * 구매 불가능한 상품 목록을 반환합니다.
     *
     * @param TempOrder $tempOrder 임시 주문
     * @return array 구매불가 상품 목록 (없으면 빈 배열)
     */
    public function checkTempOrderItemsAvailability(TempOrder $tempOrder): array
    {
        $items = $tempOrder->items ?? [];
        if (empty($items)) {
            return [];
        }

        // 상품 옵션 ID 추출
        $optionIds = array_filter(array_column($items, 'product_option_id'));
        if (empty($optionIds)) {
            return [];
        }

        // Repository를 통해 옵션 정보 조회 (상품, 이미지 관계 포함)
        $options = $this->productOptionRepository->findByIdsWithProduct($optionIds);
        $optionsById = $options->keyBy('id');

        $unavailableItems = [];

        foreach ($items as $item) {
            $optionId = $item['product_option_id'] ?? null;
            if (! $optionId || ! isset($optionsById[$optionId])) {
                continue;
            }

            /** @var \Modules\Sirsoft\Ecommerce\Models\ProductOption $option */
            $option = $optionsById[$optionId];
            $product = $option->product;

            if (! $product) {
                continue;
            }

            $quantity = $item['quantity'] ?? 1;
            $stockQuantity = $option->stock_quantity ?? 0;

            // 썸네일 URL 추출
            $thumbnailImage = $product->relationLoaded('images')
                ? ($product->images->firstWhere('is_thumbnail', true) ?? $product->images->first())
                : null;

            // 옵션 값 포맷 (예: "색상: 빨강, 사이즈: L")
            $formattedOption = $this->formatOptionValues($option->getLocalizedOptionValues());

            // 1. 판매상태 체크
            if ($product->sales_status !== ProductSalesStatus::ON_SALE) {
                $unavailableItems[] = [
                    'cart_id' => $item['cart_id'] ?? null,
                    'product_id' => $product->id,
                    'product_option_id' => $optionId,
                    'name' => $product->getLocalizedName(),
                    'option' => $formattedOption,
                    'thumbnail' => $thumbnailImage?->download_url,
                    'quantity' => $quantity,
                    'stock' => $stockQuantity,
                    'reason' => 'status',
                ];

                continue;
            }

            // 2. 재고 체크
            if ($quantity > $stockQuantity) {
                $unavailableItems[] = [
                    'cart_id' => $item['cart_id'] ?? null,
                    'product_id' => $product->id,
                    'product_option_id' => $optionId,
                    'name' => $product->getLocalizedName(),
                    'option' => $formattedOption,
                    'thumbnail' => $thumbnailImage?->download_url,
                    'quantity' => $quantity,
                    'stock' => $stockQuantity,
                    'reason' => 'stock',
                ];
            }
        }

        return $unavailableItems;
    }
}
