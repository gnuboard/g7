<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 상품 리뷰 API Feature 테스트
 *
 * 비로그인 사용자가 접근하는 상품 리뷰 목록 및 별점 통계 API 테스트
 */
class PublicProductReviewControllerTest extends ModuleTestCase
{
    private Product $product;

    private User $user;

    private OrderOption $orderOption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();

        // 공개 상품/리뷰 API에 필요한 user 권한 부여
        $permission = \App\Models\Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'Read Products'],
                'type' => \App\Enums\PermissionType::User,
            ]
        );
        $userRole = \App\Models\Role::where('identifier', 'user')->first();
        $userRole->permissions()->syncWithoutDetaching([$permission->id]);

        $this->product = Product::factory()->onSale()->create();
        $this->orderOption = OrderOption::factory()->create([
            'product_id' => $this->product->id,
        ]);
    }

    // ========================================
    // index() 기본 동작 테스트
    // ========================================

    /**
     * 비로그인 사용자가 리뷰 목록을 조회할 수 있다
     */
    #[Test]
    public function test_guest_can_fetch_product_reviews(): void
    {
        // Given: visible 리뷰 2개 생성
        ProductReview::factory()->count(2)->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        // When: 비로그인 상태로 요청
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews"
        );

        // Then
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'reviews' => [
                        'data',
                        'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                    ],
                    'rating_stats',
                    'total_count',
                ],
            ])
            ->assertJsonPath('data.reviews.meta.total', 2)
            ->assertJsonPath('data.total_count', 2);
    }

    /**
     * 필터 적용 시 total_count는 전체 리뷰 수를 유지한다
     */
    #[Test]
    public function test_total_count_is_unaffected_by_filters(): void
    {
        // Given: visible 리뷰 3개 (별점 5: 2개, 별점 3: 1개)
        ProductReview::factory()->count(2)->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
            'rating' => 5,
        ]);
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
            'rating' => 3,
        ]);

        // When: 별점 5 필터 적용
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?rating=5"
        );

        // Then: 필터된 결과는 2개, total_count는 전체 3개 유지
        $response->assertStatus(200)
            ->assertJsonPath('data.reviews.meta.total', 2)
            ->assertJsonPath('data.total_count', 3);
    }

    /**
     * photo_only=false (문자열)일 때 모든 리뷰가 반환된다 — 버그 회귀 테스트
     *
     * 버그: empty('false') === false 이므로 whereHas('images') 조건이 잘못 적용되어
     * 이미지 없는 리뷰가 모두 제외됨. 수정 후에는 'false' 문자열은 필터 미적용.
     */
    #[Test]
    public function test_photo_only_false_string_returns_all_reviews(): void
    {
        // Given: 이미지 없는 리뷰 3개
        ProductReview::factory()->count(3)->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        // When: URL에서 photo_only=false 문자열로 전달
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?photo_only=false"
        );

        // Then: 이미지 없는 리뷰 3개 모두 반환
        $response->assertStatus(200)
            ->assertJsonPath('data.reviews.meta.total', 3);
    }

    /**
     * hidden 상태 리뷰는 공개 API에서 반환되지 않는다
     */
    #[Test]
    public function test_hidden_reviews_are_excluded(): void
    {
        // Given: visible 1개, hidden 1개
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::HIDDEN->value,
        ]);

        // When
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews"
        );

        // Then: visible 1개만 반환
        $response->assertStatus(200)
            ->assertJsonPath('data.reviews.meta.total', 1);
    }

    // ========================================
    // sort 파라미터 validation 테스트
    // ========================================

    /**
     * created_at_desc 정렬 파라미터가 정상 동작한다 — 버그 회귀 테스트
     *
     * 버그: PublicReviewListRequest의 sort 허용값이 'latest,oldest'로 되어 있어
     * 프론트엔드에서 전송하는 'created_at_desc'가 validation 실패함.
     */
    #[Test]
    public function test_sort_created_at_desc_is_accepted(): void
    {
        // Given
        ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        // When
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?sort=created_at_desc"
        );

        // Then
        $response->assertStatus(200);
    }

    /**
     * 허용되지 않은 정렬 값은 422를 반환한다
     */
    #[Test]
    public function test_invalid_sort_value_returns_422(): void
    {
        // When
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?sort=invalid_sort"
        );

        // Then
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    /**
     * 리뷰가 없을 때 빈 목록과 0 통계를 반환한다
     */
    #[Test]
    public function test_returns_empty_list_when_no_reviews(): void
    {
        // When
        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews"
        );

        // Then
        $response->assertStatus(200)
            ->assertJsonPath('data.reviews.meta.total', 0)
            ->assertJsonPath('data.reviews.data', [])
            ->assertJsonPath('data.total_count', 0);
    }
}
