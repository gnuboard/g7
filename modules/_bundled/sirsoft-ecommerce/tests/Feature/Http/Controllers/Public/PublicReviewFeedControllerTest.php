<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 전체 후기 공개 API(리뷰 모아보기) 테스트
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/public-review-author-masking-and-feed.yaml
 */
class PublicReviewFeedControllerTest extends ModuleTestCase
{
    private const URL = '/api/modules/sirsoft-ecommerce/reviews';

    private Product $product;

    /**
     * 공개 상품 조회 권한과 상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'Read Products'], 'type' => PermissionType::User]
        );
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);
        Role::where('identifier', 'guest')->first()?->permissions()->syncWithoutDetaching([$permission->id]);

        $this->product = Product::factory()->onSale()->create();
    }

    /**
     * 비회원이 여러 상품의 후기를 상품 요약·가린 이름과 함께 받는다.
     *
     * @scenario surface=review_feed, masking=middle, author=member
     *
     * @effects feed_lists_reviews_across_products, feed_row_carries_product_summary, public_review_name_masked, public_review_omits_member_uuid
     *
     * @return void 반환값 없음
     */
    public function test_feed_lists_reviews_across_products_with_product_summary(): void
    {
        $other = Product::factory()->onSale()->create();
        $author = User::factory()->create(['name' => '홍길동']);
        $this->createReview(['user_id' => $author->id]);
        $this->createReview(['user_id' => $author->id, 'product_id' => $other->id]);

        $response = $this->getJson(self::URL)->assertOk();

        $response->assertJsonPath('data.reviews.meta.total', 2);
        $rows = $response->json('data.reviews.data');
        $this->assertEqualsCanonicalizing(
            [$this->product->product_code, $other->product_code],
            array_column(array_column($rows, 'product'), 'product_code')
        );
        $this->assertArrayHasKey('name', $rows[0]['product']);
        $this->assertArrayHasKey('thumbnail_url', $rows[0]['product']);
        $this->assertSame('홍*동', $rows[0]['author_name']);
        $this->assertArrayNotHasKey('user_id', $rows[0]);
    }

    /**
     * 숨김 상품·삭제된 상품의 후기와 비노출 후기는 빠진다.
     *
     * @scenario surface=review_feed, visibility=hidden_product_or_review
     *
     * @effects feed_excludes_hidden_product_reviews, feed_excludes_deleted_product_reviews, feed_excludes_hidden_reviews
     *
     * @return void 반환값 없음
     */
    public function test_feed_excludes_hidden_and_deleted_products_and_hidden_reviews(): void
    {
        $visible = $this->createReview([]);

        $hiddenProduct = Product::factory()->onSale()->create(['display_status' => ProductDisplayStatus::HIDDEN->value]);
        $this->createReview(['product_id' => $hiddenProduct->id]);

        $deletedProduct = Product::factory()->onSale()->create();
        $this->createReview(['product_id' => $deletedProduct->id]);
        $deletedProduct->delete();

        $this->createReview(['status' => ReviewStatus::HIDDEN->value]);

        $rows = $this->getJson(self::URL)->assertOk()->json('data.reviews.data');

        $this->assertSame([$visible->id], array_column($rows, 'id'));
    }

    /**
     * 사진 후기만·별점 필터.
     *
     * @scenario surface=review_feed, filter=photo_only_and_rating
     *
     * @effects feed_filters_photo_only, feed_filters_rating
     *
     * @return void 반환값 없음
     */
    public function test_feed_filters_photo_only_and_rating(): void
    {
        $withPhoto = $this->createReview(['rating' => 5]);
        ProductReviewImage::factory()->create(['review_id' => $withPhoto->id]);
        $plain = $this->createReview(['rating' => 3]);

        $photoRows = $this->getJson(self::URL.'?photo_only=1')->assertOk()->json('data.reviews.data');
        $this->assertSame([$withPhoto->id], array_column($photoRows, 'id'));

        $ratingRows = $this->getJson(self::URL.'?rating=3')->assertOk()->json('data.reviews.data');
        $this->assertSame([$plain->id], array_column($ratingRows, 'id'));
    }

    /**
     * 정렬 네 가지.
     *
     * @scenario surface=review_feed, filter=sort
     *
     * @effects feed_sorts_by_four_orders
     *
     * @return void 반환값 없음
     */
    public function test_feed_supports_four_sort_orders(): void
    {
        $old = $this->createReview(['rating' => 5, 'created_at' => Carbon::now()->subDays(3)]);
        $mid = $this->createReview(['rating' => 1, 'created_at' => Carbon::now()->subDays(2)]);
        $new = $this->createReview(['rating' => 3, 'created_at' => Carbon::now()->subDay()]);

        $ids = fn (string $sort) => array_column(
            $this->getJson(self::URL.'?sort='.$sort)->assertOk()->json('data.reviews.data'),
            'id'
        );

        $this->assertSame([$new->id, $mid->id, $old->id], $ids('created_at_desc'));
        $this->assertSame([$old->id, $mid->id, $new->id], $ids('created_at_asc'));
        $this->assertSame([$old->id, $new->id, $mid->id], $ids('rating_desc'));
        $this->assertSame([$mid->id, $new->id, $old->id], $ids('rating_asc'));
    }

    /**
     * 페이지 크기 상한과 잘못된 값.
     *
     * @scenario surface=review_feed, filter=invalid
     *
     * @effects feed_rejects_invalid_params
     *
     * @return void 반환값 없음
     */
    public function test_feed_rejects_invalid_params(): void
    {
        $this->getJson(self::URL.'?per_page=51')->assertStatus(422);
        $this->getJson(self::URL.'?rating=6')->assertStatus(422);
        $this->getJson(self::URL.'?sort=popular')->assertStatus(422);
        $this->getJson(self::URL.'?per_page=50')->assertOk();
    }

    /**
     * 확장이 검증 규칙을 더할 수 있다.
     *
     * @scenario surface=review_feed, filter=extension_rule
     *
     * @effects feed_validation_rules_are_filterable
     *
     * @return void 반환값 없음
     */
    public function test_feed_validation_rules_can_be_extended(): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.review.public_feed_validation_rules',
            fn (array $rules) => $rules + ['brand' => ['nullable', 'integer']]
        );

        $this->getJson(self::URL.'?brand=abc')->assertStatus(422);
        $this->getJson(self::URL.'?brand=3')->assertOk();
    }

    /**
     * 노출 상태 후기를 하나 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return ProductReview 만든 후기
     */
    private function createReview(array $attributes): ProductReview
    {
        return ProductReview::factory()->create(array_merge([
            'product_id' => $this->product->id,
            'order_option_id' => null,
            'status' => ReviewStatus::VISIBLE->value,
        ], $attributes));
    }
}
