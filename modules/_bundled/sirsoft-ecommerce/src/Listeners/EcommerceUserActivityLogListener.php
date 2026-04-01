<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 이커머스 사용자 활동 로그 리스너
 *
 * 사용자 측 이커머스 서비스 훅을 구독하여
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 *
 * Monolog 기반 아키텍처:
 * Service -> doAction -> EcommerceUserActivityLogListener -> Log::channel('activity') -> ActivityLogHandler -> DB
 */
class EcommerceUserActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── Cart ───
            'sirsoft-ecommerce.cart.after_add' => ['method' => 'handleCartAfterAdd', 'priority' => 20],
            'sirsoft-ecommerce.cart.after_update_quantity' => ['method' => 'handleCartAfterUpdateQuantity', 'priority' => 20],
            'sirsoft-ecommerce.cart.after_change_option' => ['method' => 'handleCartAfterChangeOption', 'priority' => 20],
            'sirsoft-ecommerce.cart.after_delete' => ['method' => 'handleCartAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.cart.after_delete_all' => ['method' => 'handleCartAfterDeleteAll', 'priority' => 20],

            // ─── Wishlist ───
            'sirsoft-ecommerce.wishlist.after_toggle' => ['method' => 'handleWishlistAfterToggle', 'priority' => 20],

            // ─── User Coupon ───
            'sirsoft-ecommerce.user_coupon.after_download' => ['method' => 'handleUserCouponAfterDownload', 'priority' => 20],

            // ─── Order ───
            'sirsoft-ecommerce.order.after_create' => ['method' => 'handleOrderAfterCreate', 'priority' => 20],

            // ─── Order Option ───
            'sirsoft-ecommerce.order-option.after_confirm' => ['method' => 'handleOrderOptionAfterConfirm', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // Cart 핸들러
    // ═══════════════════════════════════════════

    /**
     * 장바구니 상품 추가 후 로그 기록
     *
     * @param Cart $cart 장바구니 모델
     * @param array $data 추가 데이터
     */
    public function handleCartAfterAdd(Cart $cart, array $data): void
    {
        $productName = $cart->product?->name ?? $cart->product_name ?? '';

        $this->logActivity('cart.add', [

            'loggable' => $cart,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.cart_add',
            'description_params' => ['product_name' => $productName],
            'properties' => [
                'product_name' => $productName,
                'quantity' => $data['quantity'] ?? null,
            ],
        ]);
    }

    /**
     * 장바구니 수량 변경 후 로그 기록
     *
     * @param Cart $cart 장바구니 모델
     * @param int $quantity 변경된 수량
     */
    public function handleCartAfterUpdateQuantity(Cart $cart, int $quantity): void
    {
        $productName = $cart->product?->name ?? $cart->product_name ?? '';

        $this->logActivity('cart.update_quantity', [

            'loggable' => $cart,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.cart_update_quantity',
            'description_params' => ['product_name' => $productName],
            'properties' => [
                'product_name' => $productName,
                'quantity' => $quantity,
            ],
        ]);
    }

    /**
     * 장바구니 옵션 변경 후 로그 기록
     *
     * @param Cart $cart 장바구니 모델
     * @param int $newProductOptionId 변경된 상품 옵션 ID
     * @param int $quantity 수량
     */
    public function handleCartAfterChangeOption(Cart $cart, int $newProductOptionId, int $quantity): void
    {
        $productName = $cart->product?->name ?? $cart->product_name ?? '';

        $this->logActivity('cart.change_option', [

            'loggable' => $cart,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.cart_change_option',
            'description_params' => ['product_name' => $productName],
            'properties' => [
                'product_name' => $productName,
                'new_option_id' => $newProductOptionId,
                'quantity' => $quantity,
            ],
        ]);
    }

    /**
     * 장바구니 항목 삭제 후 로그 기록
     *
     * @param int $cartId 삭제된 장바구니 ID
     */
    public function handleCartAfterDelete(int $cartId): void
    {
        $this->logActivity('cart.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.cart_delete',
            'properties' => ['cart_id' => $cartId],
        ]);
    }

    /**
     * 장바구니 전체 삭제 후 로그 기록
     *
     * @param int|null $userId 사용자 ID
     * @param string|null $cartKey 장바구니 키
     * @param int $deletedCount 삭제된 항목 수
     */
    public function handleCartAfterDeleteAll(?int $userId, ?string $cartKey, int $deletedCount): void
    {
        $this->logActivity('cart.delete_all', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.cart_delete_all',
            'properties' => [
                'user_id' => $userId,
                'cart_key' => $cartKey,
                'deleted_count' => $deletedCount,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Wishlist 핸들러
    // ═══════════════════════════════════════════

    /**
     * 위시리스트 토글 후 로그 기록
     *
     * @param int $userId 사용자 ID
     * @param int $productId 상품 ID
     * @param bool $added 추가 여부 (true: 추가, false: 제거)
     */
    public function handleWishlistAfterToggle(int $userId, int $productId, bool $added): void
    {
        $product = Product::find($productId);
        $action = $added ? 'wishlist.add' : 'wishlist.remove';
        $descriptionKey = $added
            ? 'sirsoft-ecommerce::activity_log.description.wishlist_add'
            : 'sirsoft-ecommerce::activity_log.description.wishlist_remove';

        $this->logActivity($action, [

            'loggable' => $product,
            'description_key' => $descriptionKey,
            'description_params' => ['product_name' => $product?->name ?? ''],
            'properties' => [
                'product_id' => $productId,
                'added' => $added,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // User Coupon 핸들러
    // ═══════════════════════════════════════════

    /**
     * 쿠폰 다운로드 후 로그 기록
     *
     * @param CouponIssue $couponIssue 발급된 쿠폰
     * @param int $userId 사용자 ID
     * @param int $couponId 쿠폰 ID
     */
    public function handleUserCouponAfterDownload(CouponIssue $couponIssue, int $userId, int $couponId): void
    {
        $coupon = Coupon::find($couponId);

        $this->logActivity('user_coupon.download', [

            'loggable' => $couponIssue,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.user_coupon_download',
            'description_params' => ['coupon_name' => $coupon?->name ?? ''],
            'properties' => [
                'coupon_id' => $couponId,
                'coupon_name' => $coupon?->name ?? '',
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Order 핸들러
    // ═══════════════════════════════════════════

    /**
     * 주문 생성 후 사용자 로그 기록
     *
     * @param Order $order 생성된 주문
     * @return void
     */
    public function handleOrderAfterCreate(Order $order): void
    {
        $this->logActivity('order.create', [

            'loggable' => $order,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.user_order_create',
            'description_params' => ['order_id' => $order->id],
            'properties' => [
                'order_id' => $order->id,
                'order_code' => $order->order_code ?? null,
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Order Option 핸들러
    // ═══════════════════════════════════════════

    /**
     * 구매확인 후 사용자 로그 기록
     *
     * @param Order $order 주문
     * @param OrderOption $option 확인된 주문옵션
     * @return void
     */
    public function handleOrderOptionAfterConfirm(Order $order, OrderOption $option): void
    {
        $this->logActivity('order_option.confirm', [

            'loggable' => $option,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.user_order_option_confirm',
            'description_params' => ['option_id' => $option->id],
            'properties' => [
                'order_id' => $order->id,
                'option_id' => $option->id,
            ],
        ]);
    }

}
