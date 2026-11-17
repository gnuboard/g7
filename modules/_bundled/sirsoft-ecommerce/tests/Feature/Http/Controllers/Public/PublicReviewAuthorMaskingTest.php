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
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 상품 후기 응답의 작성자 이름 가림 테스트
 *
 * 공개 후기 목록은 인증 없이 열린다 — 실명과 회원 uuid 를 그대로 싣지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/public-review-author-masking-and-feed.yaml
 */
class PublicReviewAuthorMaskingTest extends ModuleTestCase
{
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
     * 기본 설정에서 회원 후기의 이름 두 곳이 가려지고 회원 uuid 는 실리지 않는다.
     *
     * @scenario surface=product_review_list, masking=middle, author=member
     *
     * @effects public_review_name_masked, public_review_omits_member_uuid
     *
     * @return void 반환값 없음
     */
    public function test_member_review_name_is_masked_and_uuid_is_absent(): void
    {
        $author = User::factory()->create(['name' => '홍길동']);
        $this->createReview(['user_id' => $author->id]);

        $row = $this->fetchFirstReview();

        $this->assertSame('홍*동', $row['author_name']);
        $this->assertSame('홍*동', $row['user']['name']);
        $this->assertArrayNotHasKey('user_id', $row);
        $this->assertArrayNotHasKey('uuid', $row['user']);
        $this->assertStringNotContainsString($author->uuid, json_encode($row));
    }

    /**
     * 이름 가림을 끄면 실명이 나간다(회원 uuid 는 여전히 싣지 않는다).
     *
     * @scenario surface=product_review_list, masking=none, author=member
     *
     * @effects public_review_name_plain_when_masking_off, public_review_omits_member_uuid
     *
     * @return void 반환값 없음
     */
    public function test_masking_none_returns_real_name(): void
    {
        app(EcommerceSettingsService::class)->setSetting('review_settings.author_name_masking', 'none');

        $author = User::factory()->create(['name' => '홍길동']);
        $this->createReview(['user_id' => $author->id]);

        $row = $this->fetchFirstReview();

        $this->assertSame('홍길동', $row['author_name']);
        $this->assertSame('홍길동', $row['user']['name']);
        $this->assertArrayNotHasKey('user_id', $row);
    }

    /**
     * 회원 없이 등록한 후기(이관 후기)의 저장된 이름도 가린다.
     *
     * @scenario surface=product_review_list, masking=middle, author=imported
     *
     * @effects public_review_name_masked
     *
     * @return void 반환값 없음
     */
    public function test_imported_review_name_is_masked(): void
    {
        $this->createReview(['user_id' => null, 'order_option_id' => null, 'author_name' => '남궁민수']);

        $row = $this->fetchFirstReview();

        $this->assertSame('남**수', $row['author_name']);
        $this->assertTrue($row['is_imported']);
    }

    /**
     * 두 글자·한 글자 이름 경계.
     *
     * @scenario surface=product_review_list, masking=middle, author=short_name
     *
     * @effects public_review_name_masked
     *
     * @return void 반환값 없음
     */
    public function test_short_names_follow_boundary_rule(): void
    {
        $two = User::factory()->create(['name' => '김철']);
        $this->createReview(['user_id' => $two->id, 'rating' => 5]);
        $one = User::factory()->create(['name' => '김']);
        $this->createReview(['user_id' => $one->id, 'rating' => 1]);

        $rows = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews?sort=rating_desc"
        )->assertOk()->json('data.reviews.data');

        $this->assertSame('김*', $rows[0]['author_name']);
        $this->assertSame('김', $rows[1]['author_name']);
    }

    /**
     * 노출 상태 후기를 하나 만듭니다.
     *
     * 주문옵션당 활성 후기는 1건만 둘 수 있어(DB 유니크) 후기마다 새 주문옵션을 만든다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return ProductReview 만든 후기
     */
    private function createReview(array $attributes): ProductReview
    {
        return ProductReview::factory()->create(array_merge([
            'product_id' => $this->product->id,
            'order_option_id' => OrderOption::factory()->create(['product_id' => $this->product->id])->id,
            'status' => ReviewStatus::VISIBLE->value,
        ], $attributes));
    }

    /**
     * 공개 후기 목록의 첫 행을 가져옵니다.
     *
     * @return array<string, mixed> 첫 후기 행
     */
    private function fetchFirstReview(): array
    {
        return $this->getJson("/api/modules/sirsoft-ecommerce/products/{$this->product->id}/reviews")
            ->assertOk()
            ->json('data.reviews.data.0');
    }
}
