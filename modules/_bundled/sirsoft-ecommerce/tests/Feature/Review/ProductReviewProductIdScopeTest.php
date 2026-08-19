<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Review;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 리뷰 product_id 스코프 회귀 테스트 (③)
 *
 * 리뷰 저장 시 product_id 는 클라이언트 payload 가 아니라 확정된 주문 옵션에서
 * 도출되어야 한다. payload 의 product_id 를 신뢰하면 소유하지 않은 상품에 리뷰를
 * 붙일 수 있다.
 */
class ProductReviewProductIdScopeTest extends ModuleTestCase
{
    /**
     * 상품 A 의 확정 옵션으로 product_id=B 를 지정해도 리뷰는 옵션의 상품(A)로 저장된다.
     *
     * @scenario case=review_product_scope
     *
     * @effects review_product_id_derived_from_order_option
     */
    public function test_review_product_id_is_derived_from_order_option_not_payload(): void
    {
        $user = $this->createUser();

        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        $order = Order::factory()->create(['user_id' => $user->id]);
        $option = OrderOption::factory()->forOrder($order)->create([
            'product_id' => $productA->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now(),
            'quantity' => 1,
        ]);

        /** @var ProductReviewService $service */
        $service = app(ProductReviewService::class);

        $review = $service->createReview($user->id, [
            'order_option_id' => $option->id,
            // 클라이언트가 다른 상품(B)의 id 를 주입 — 무시되어야 한다
            'product_id' => $productB->id,
            'rating' => 5,
            'content' => str_repeat('좋은 상품입니다 ', 3),
            'content_mode' => 'text',
        ]);

        // 저장된 product_id 는 옵션의 상품(A)여야 한다 (payload 의 B 가 아니라)
        $this->assertSame($productA->id, $review->product_id);
        $this->assertNotSame($productB->id, $review->product_id);

        $this->assertDatabaseHas('ecommerce_product_reviews', [
            'id' => $review->id,
            'order_option_id' => $option->id,
            'product_id' => $productA->id,
        ]);
    }
}
