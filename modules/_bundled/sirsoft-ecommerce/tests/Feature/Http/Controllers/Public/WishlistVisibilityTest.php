<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 찜 목록의 숨김 상품 제외 테스트 (이슈 #524 §4.3)
 *
 * 결함: 운영자가 상품을 「숨김」으로 내려도 찜 목록에는 정상 카드로 남아 클릭까지 됐다.
 * 공개 목록 8곳은 각자 display_status 조건을 리터럴로 적고 있었는데 찜 목록만 빠져 있었다.
 *
 * 처치: Product::scopeVisible() 을 만들어 찜 목록이 그 스코프를 타게 한다.
 * 판매중지·품절(sales_status)은 카드 오버레이가 이미 처리하므로 목록에 그대로 남긴다 —
 * 「숨김」과 「판매중지」는 다른 축이고, 후자는 사용자가 알아야 하는 상태다.
 *
 * URL: GET /api/modules/sirsoft-ecommerce/wishlist
 *
 * @scenario surface=user_wishlist,product_state=hidden
 *
 * @effects wishlist_excludes_hidden_products, wishlist_keeps_suspended_products
 */
#[Group('ecommerce')]
#[Group('wishlist')]
class WishlistVisibilityTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/wishlist';

    private User $member;

    /**
     * 각 테스트 전에 공통 준비를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->member = $this->createUser();
    }

    /**
     * 숨김 상품은 찜 목록에서 빠진다.
     *
     * @effects wishlist_excludes_hidden_products
     *
     * @return void 반환값 없음
     */
    public function test_index_excludes_hidden_products(): void
    {
        $visible = $this->seedWishlist($this->createProduct(ProductDisplayStatus::VISIBLE));
        $this->seedWishlist($this->createProduct(ProductDisplayStatus::HIDDEN));

        $response = $this->actingAs($this->member)->getJson($this->apiBase);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $visible);
    }

    /**
     * 판매중지 상품은 찜 목록에 남는다 (카드 오버레이가 상태를 알린다).
     *
     * 숨김과 판매중지를 한 조건으로 묶으면, 사용자가 담아 둔 상품이 "왜 사라졌는지"
     * 알 길이 없어진다 — 판매중지는 보여 주고 알리는 쪽이 맞다.
     *
     * @effects wishlist_keeps_suspended_products
     *
     * @return void 반환값 없음
     */
    public function test_index_keeps_suspended_products(): void
    {
        $suspended = $this->seedWishlist(
            $this->createProduct(ProductDisplayStatus::VISIBLE, ProductSalesStatus::SUSPENDED)
        );

        $response = $this->actingAs($this->member)->getJson($this->apiBase);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $suspended);
    }

    /**
     * 다시 전시로 돌리면 찜 목록에 되돌아온다 (행 자체는 지워지지 않았다).
     *
     * @effects wishlist_hidden_product_reappears_when_visible_again
     *
     * @return void 반환값 없음
     */
    public function test_hidden_product_reappears_when_visible_again(): void
    {
        $product = $this->createProduct(ProductDisplayStatus::HIDDEN);
        $wishlistId = $this->seedWishlist($product);

        $this->actingAs($this->member)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');

        $product->update(['display_status' => ProductDisplayStatus::VISIBLE->value]);

        $this->actingAs($this->member)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $wishlistId);
    }

    /**
     * 상품을 만듭니다.
     *
     * @param  ProductDisplayStatus  $display  전시 상태
     * @param  ProductSalesStatus|null  $sales  판매 상태
     * @return Product 상품 모델
     */
    private function createProduct(ProductDisplayStatus $display, ?ProductSalesStatus $sales = null): Product
    {
        $attributes = ['display_status' => $display->value];

        if ($sales !== null) {
            $attributes['sales_status'] = $sales->value;
        }

        return Product::factory()->create($attributes);
    }

    /**
     * 찜 행을 시드하고 ID 를 반환합니다.
     *
     * @param  Product  $product  대상 상품
     * @return int 찜 행 ID
     */
    private function seedWishlist(Product $product): int
    {
        return (int) DB::table('ecommerce_product_wishlists')->insertGetId([
            'user_id' => $this->member->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
