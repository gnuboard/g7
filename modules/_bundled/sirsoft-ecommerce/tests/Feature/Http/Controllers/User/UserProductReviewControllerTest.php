<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 사용자 상품 리뷰 API Feature 테스트
 *
 * User ProductReviewController의 canWrite / store / destroy 기능을 검증합니다.
 */
class UserProductReviewControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/user/reviews';

    private User $user;

    private Product $product;

    private Order $order;

    private OrderOption $orderOption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();

        // user/reviews 라우트에 필요한 user-products.read 권한 부여
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'View Products'],
                'type' => PermissionType::User,
            ]
        );
        $userRole = Role::where('identifier', 'user')->first();
        $userRole->permissions()->syncWithoutDetaching([$permission->id]);

        $this->product = Product::factory()->onSale()->create();

        // 구매확정 상태의 주문 + 주문 옵션 생성
        $this->order = Order::factory()->confirmed()->forUser($this->user)->create();
        $this->orderOption = OrderOption::factory()->create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);
    }

    // ========================================
    // canWrite() — 리뷰 작성 가능 여부
    // ========================================

    #[Test]
    public function test_can_write_returns_true_for_confirmed_order_option(): void
    {
        // When
        $response = $this->actingAs($this->user)
            ->getJson("{$this->apiBase}/can-write/{$this->orderOption->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.can_write', true)
            ->assertJsonPath('data.reason', null);
    }

    #[Test]
    public function test_can_write_returns_false_for_not_confirmed_order_option(): void
    {
        // Given: 배송 완료 상태 (구매확정 아님)
        $deliveredOption = OrderOption::factory()->create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::DELIVERED,
        ]);

        // When
        $response = $this->actingAs($this->user)
            ->getJson("{$this->apiBase}/can-write/{$deliveredOption->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.can_write', false)
            ->assertJsonPath('data.reason', 'not_confirmed');
    }

    #[Test]
    public function test_can_write_returns_false_for_other_users_order_option(): void
    {
        // Given: 다른 사용자의 주문 옵션
        $anotherUser = $this->createUser();
        $anotherOrder = Order::factory()->confirmed()->forUser($anotherUser)->create();
        $anotherOption = OrderOption::factory()->create([
            'order_id' => $anotherOrder->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);

        // When: 현재 사용자로 요청
        $response = $this->actingAs($this->user)
            ->getJson("{$this->apiBase}/can-write/{$anotherOption->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.can_write', false)
            ->assertJsonPath('data.reason', 'not_own_order');
    }

    #[Test]
    public function test_can_write_returns_false_when_already_written(): void
    {
        // Given: 이미 리뷰 작성됨
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        // When
        $response = $this->actingAs($this->user)
            ->getJson("{$this->apiBase}/can-write/{$this->orderOption->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.can_write', false)
            ->assertJsonPath('data.reason', 'already_written');
    }

    #[Test]
    public function test_can_write_returns_false_for_nonexistent_order_option(): void
    {
        // When: 존재하지 않는 주문 옵션
        $response = $this->actingAs($this->user)
            ->getJson("{$this->apiBase}/can-write/99999");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.can_write', false)
            ->assertJsonPath('data.reason', 'order_option_not_found');
    }

    #[Test]
    public function test_can_write_requires_authentication(): void
    {
        // When: 비로그인
        $response = $this->getJson("{$this->apiBase}/can-write/{$this->orderOption->id}");

        // Then
        $response->assertUnauthorized();
    }

    // ========================================
    // store() — 리뷰 작성
    // ========================================

    #[Test]
    public function test_user_can_store_review(): void
    {
        // When
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'product_id' => $this->product->id,
                'order_option_id' => $this->orderOption->id,
                'rating' => 5,
                'content' => '정말 만족스러운 상품입니다. 배송도 빠르고 품질도 좋네요.',
                'content_mode' => 'text',
            ]);

        // Then
        $response->assertStatus(201)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.status', ReviewStatus::VISIBLE->value);

        $this->assertDatabaseHas('ecommerce_product_reviews', [
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'rating' => 5,
        ]);
    }

    #[Test]
    public function test_store_requires_product_id(): void
    {
        // When: product_id 누락
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'order_option_id' => $this->orderOption->id,
                'rating' => 5,
                'content' => '좋은 상품입니다. 강력 추천합니다.',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    }

    #[Test]
    public function test_store_requires_order_option_id(): void
    {
        // When: order_option_id 누락
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'product_id' => $this->product->id,
                'rating' => 5,
                'content' => '좋은 상품입니다. 강력 추천합니다.',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['order_option_id']);
    }

    #[Test]
    public function test_store_validates_rating_range(): void
    {
        // When: 범위 초과 별점
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'product_id' => $this->product->id,
                'order_option_id' => $this->orderOption->id,
                'rating' => 6,
                'content' => '좋은 상품입니다. 강력 추천합니다.',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['rating']);
    }

    #[Test]
    public function test_store_validates_content_min_length(): void
    {
        // When: 10자 미만 내용
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'product_id' => $this->product->id,
                'order_option_id' => $this->orderOption->id,
                'rating' => 5,
                'content' => '짧은글',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    #[Test]
    public function test_store_validates_content_max_length(): void
    {
        // When: 2000자 초과
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'product_id' => $this->product->id,
                'order_option_id' => $this->orderOption->id,
                'rating' => 5,
                'content' => str_repeat('가', 2001),
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    #[Test]
    public function test_store_validates_nonexistent_product(): void
    {
        // When: 존재하지 않는 상품
        $response = $this->actingAs($this->user)
            ->postJson($this->apiBase, [
                'product_id' => 99999,
                'order_option_id' => $this->orderOption->id,
                'rating' => 5,
                'content' => '좋은 상품입니다. 강력 추천합니다.',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    }

    #[Test]
    public function test_store_requires_authentication(): void
    {
        // When: 비로그인
        $response = $this->postJson($this->apiBase, [
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'rating' => 5,
            'content' => '좋은 상품입니다. 강력 추천합니다.',
        ]);

        // Then
        $response->assertUnauthorized();
    }

    // ========================================
    // destroy() — 내 리뷰 삭제
    // ========================================

    #[Test]
    public function test_user_can_delete_own_review(): void
    {
        // Given: 내 리뷰
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
        ]);
        $reviewId = $review->id;

        // When
        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->apiBase}/{$reviewId}");

        // Then
        $response->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('ecommerce_product_reviews', ['id' => $reviewId]);
    }

    #[Test]
    public function test_user_cannot_delete_others_review(): void
    {
        // Given: 다른 사용자의 리뷰
        $anotherUser = $this->createUser();
        $anotherOrder = Order::factory()->confirmed()->forUser($anotherUser)->create();
        $anotherOption = OrderOption::factory()->create([
            'order_id' => $anotherOrder->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $anotherOption->id,
            'user_id' => $anotherUser->id,
        ]);

        // When: 현재 사용자가 다른 사용자 리뷰 삭제 시도
        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->apiBase}/{$review->id}");

        // Then: 403 Forbidden
        $response->assertForbidden();

        // DB에 여전히 존재
        $this->assertDatabaseHas('ecommerce_product_reviews', ['id' => $review->id, 'deleted_at' => null]);
    }

    #[Test]
    public function test_destroy_returns_404_for_nonexistent_review(): void
    {
        // When
        $response = $this->actingAs($this->user)
            ->deleteJson("{$this->apiBase}/99999");

        // Then
        $response->assertNotFound();
    }

    #[Test]
    public function test_destroy_requires_authentication(): void
    {
        // Given
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
        ]);

        // When: 비로그인
        $response = $this->deleteJson("{$this->apiBase}/{$review->id}");

        // Then
        $response->assertUnauthorized();
    }
}
