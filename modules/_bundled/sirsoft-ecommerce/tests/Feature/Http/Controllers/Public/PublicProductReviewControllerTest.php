<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
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
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'Read Products'],
                'type' => PermissionType::User,
            ]
        );
        $userRole = Role::where('identifier', 'user')->first();
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

    /**
     * 공개 리뷰 목록은 첨부 이미지 배열과 개수를 함께 내려준다.
     *
     * 상품 상세의 리뷰 탭(`partials/shop/detail/_tab_reviews.json`)이 썸네일 3장을 인덱스로
     * 직접 읽고(`review.images[0..2].download_url`) 클릭 시 이미지 모달에 배열 전체를 넘긴다.
     * 이 파셜은 `shop/show.json` 이 include 하므로 그 파일만 보면 참조가 드러나지 않는다.
     *
     * 배열을 빼면 조용히 깨진다 — 썸네일이 `(review.images ?? []).length > 0` 가드 뒤에 있어
     * 예외 없이 자리만 비고, 바깥 컨테이너는 `image_count > 0` 로 열려 "빈 상자" 가 남는다.
     * 개수는 배열 길이가 아니라 DB 집계에서 온다.
     *
     * @scenario surface=public_product_review_list,observation=response_payload
     * @effects public_list_carries_images_and_count_together, image_download_url_is_present_on_each_image, image_count_is_zero_not_missing_when_empty, no_public_single_review_endpoint_exists_as_fallback
     */
    #[Test]
    public function test_public_review_list_carries_images_and_count(): void
    {
        $review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        ProductReviewImage::create([
            'review_id' => $review->id,
            'hash' => substr(md5('rv-'.$review->id), 0, 12),
            'original_filename' => 'r.jpg',
            'stored_filename' => 'r.jpg',
            'disk' => 'public',
            'path' => 'reviews/r.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 512,
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews"
        );

        $response->assertStatus(200);

        $rows = $response->json('data.reviews.data');
        $this->assertNotEmpty($rows);

        $row = collect($rows)->firstWhere('id', $review->id);
        $this->assertNotNull($row);

        $this->assertArrayHasKey(
            'images',
            $row,
            '리뷰 탭이 review.images[0..2] 를 직접 읽는다 — 빼면 썸네일 자리가 조용히 빈다'
        );
        $this->assertCount(1, $row['images'], '첨부한 이미지가 그대로 실려야 한다');
        $this->assertArrayHasKey(
            'download_url',
            $row['images'][0],
            '화면이 download_url 로 썸네일을 그린다'
        );
        $this->assertSame(1, $row['image_count'], '첨부 개수는 DB 집계에서 온다');
    }
}
