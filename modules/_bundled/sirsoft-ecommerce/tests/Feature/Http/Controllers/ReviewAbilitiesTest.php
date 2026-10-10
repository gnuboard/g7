<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers;

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

/**
 * 리뷰 응답의 능력 키 테스트 (7.1.0 abilities 게이트 G12 · basic-shop#8 · ecom-backend#13)
 *
 * - 관리자 리뷰 목록은 일괄 처리·행 선택을 잠글 목록 수준 키를 싣는다(행이 0 건이어도 정해야 한다).
 * - 공개 리뷰 목록은 리뷰마다 작성자 본인 여부(`is_owner`)를 싣는다 — 화면이 본인 리뷰에만 수정·삭제를 붙이는 근거.
 * - 리뷰 이미지의 `can_delete` 는 이미지 삭제 라우트 권한(`user-reviews.write`)과 같은 축이다.
 */
class ReviewAbilitiesTest extends ModuleTestCase
{
    private User $reviewer;

    private Product $product;

    private OrderOption $orderOption;

    /**
     * 공개 상품 조회 권한·리뷰 작성자·상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewer = $this->createUser();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'Read Products'], 'type' => PermissionType::User],
        );
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $this->product = Product::factory()->onSale()->create();
        $this->orderOption = OrderOption::factory()->create(['product_id' => $this->product->id]);
    }

    /**
     * 작성자 소유의 공개 리뷰 한 건을 만듭니다.
     *
     * @return ProductReview 리뷰
     */
    private function review(): ProductReview
    {
        return ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->reviewer->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);
    }

    /**
     * 관리자 리뷰 목록은 목록 수준 `abilities` 를 싣는다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_review_list_exposes_collection_abilities
     */
    public function test_admin_review_list_exposes_collection_abilities(): void
    {
        $this->review();
        $url = '/api/modules/sirsoft-ecommerce/admin/reviews';

        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.reviews.read']))->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', false)
            ->assertJsonPath('data.abilities.can_delete', false)
            ->assertJsonPath('data.data.0.abilities.can_update', false);

        $this->actingAs($this->createAdminUser([
            'sirsoft-ecommerce.reviews.read',
            'sirsoft-ecommerce.reviews.update',
            'sirsoft-ecommerce.reviews.delete',
        ]))->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.abilities.can_update', true)
            ->assertJsonPath('data.abilities.can_delete', true);
    }

    /**
     * 공개 리뷰 목록은 `is_owner` 를 싣고, 리뷰 이미지 `can_delete` 는 리뷰 작성 권한 축이다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects public_review_exposes_is_owner, review_image_delete_follows_the_route_permission
     */
    public function test_public_reviews_expose_is_owner_and_image_delete_axis(): void
    {
        $review = $this->review();
        ProductReviewImage::factory()->create(['review_id' => $review->id]);
        $url = "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews";

        $this->getJson($url)->assertOk()->assertJsonPath('data.reviews.data.0.is_owner', false);

        $mine = $this->actingAs($this->reviewer)->getJson($url)->assertOk();
        $mine->assertJsonPath('data.reviews.data.0.is_owner', true);
        // 회원 역할은 리뷰 작성 권한(`user-reviews.write`)을 가진다 — 관리자 리뷰 삭제 권한이 아니라 이 권한이 이미지 삭제 축이다.
        $this->assertTrue($mine->json('data.reviews.data.0.images.0.abilities.can_delete'));

        $this->actingAs($this->createUser())->getJson($url)->assertOk()->assertJsonPath('data.reviews.data.0.is_owner', false);
    }
}
