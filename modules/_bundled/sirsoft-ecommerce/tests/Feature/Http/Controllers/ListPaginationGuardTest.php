<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers;

use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 목록 조회 페이지네이션 게이트 회귀 테스트 (#492 6차)
 *
 * 상한만 있고 하한이 없던 목록 엔드포인트에서 per_page=0 / 음수 / 비숫자가
 * 500 이 되거나 모델 기본값으로 조용히 바뀌었고, 과대값은 테이블 전량을 반환했다.
 * 게이트를 되돌리면 이 테스트가 실패한다.
 */
class ListPaginationGuardTest extends ModuleTestCase
{
    /**
     * 찜 목록: per_page 경계값이 전부 422 로 거부되는지 검증합니다.
     *
     * @return void
     */
    public function test_wishlist_rejects_out_of_range_per_page(): void
    {
        $user = $this->createUser();

        foreach (['0', '-5', 'abc', '99999'] as $value) {
            $this->actingAs($user)
                ->getJson('/api/modules/sirsoft-ecommerce/wishlist?per_page='.$value)
                ->assertStatus(422);
        }
    }

    /**
     * 찜 목록: 허용 범위 per_page 는 그대로 적용되는지 검증합니다.
     *
     * @return void
     */
    public function test_wishlist_accepts_in_range_per_page(): void
    {
        $user = $this->createUser();
        $products = Product::factory()->count(3)->create();

        foreach ($products as $product) {
            ProductWishlist::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
            ]);
        }

        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/wishlist?per_page=2')
            ->assertOk();

        $this->assertSame(2, $response->json('data.pagination.per_page'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    /**
     * 상품 문의 목록(공개): per_page 경계값이 전부 422 로 거부되는지 검증합니다.
     *
     * @return void
     */
    public function test_product_inquiry_list_rejects_out_of_range_per_page(): void
    {
        $product = Product::factory()->create();

        foreach (['0', '-5', 'abc', '99999'] as $value) {
            $this->getJson(
                '/api/modules/sirsoft-ecommerce/products/'.$product->product_code.'/inquiries?per_page='.$value
            )->assertStatus(422);
        }
    }

    /**
     * 마이페이지 내 문의 목록: per_page 경계값이 전부 422 로 거부되는지 검증합니다.
     *
     * @return void
     */
    public function test_user_inquiry_list_rejects_out_of_range_per_page(): void
    {
        $user = $this->createUser();

        foreach (['0', '-5', 'abc', '99999'] as $value) {
            $this->actingAs($user)
                ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries?per_page='.$value)
                ->assertStatus(422);
        }
    }

    /**
     * 상품 문의 목록(공개): 쿼리 스트링의 불리언 표기 4종을 모두 받아들이는지 검증합니다.
     *
     * 쿼리는 언제나 문자열로 도착하므로 `boolean` 규칙만 두면 화면이 보내는
     * `exclude_secret=false` 에서 목록 전체가 422 가 되고 문의 탭이 비어 보인다 (#492 7차 D-38).
     *
     * @return void
     */
    public function test_product_inquiry_list_accepts_boolean_query_spellings(): void
    {
        $product = Product::factory()->create();

        foreach (['true', 'false', '1', '0'] as $value) {
            $this->getJson(
                '/api/modules/sirsoft-ecommerce/products/'.$product->product_code
                .'/inquiries?per_page=10&exclude_secret='.$value
            )->assertOk();
        }
    }

    /**
     * 상품 문의 목록(공개): 불리언으로 해석할 수 없는 값은 여전히 거부되는지 검증합니다.
     *
     * 정규화가 게이트를 통째로 무력화하지 않아야 한다.
     *
     * @return void
     */
    public function test_product_inquiry_list_rejects_non_boolean_exclude_secret(): void
    {
        $product = Product::factory()->create();

        $this->getJson(
            '/api/modules/sirsoft-ecommerce/products/'.$product->product_code
            .'/inquiries?exclude_secret=maybe'
        )->assertStatus(422);
    }

    /**
     * 마이페이지 내 문의 목록: 쿼리 스트링의 불리언 표기 4종을 모두 받아들이는지 검증합니다.
     *
     * @return void
     */
    public function test_user_inquiry_list_accepts_boolean_query_spellings(): void
    {
        $user = $this->createUser();

        foreach (['true', 'false', '1', '0'] as $value) {
            $this->actingAs($user)
                ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries?is_answered='.$value)
                ->assertOk();
        }
    }
}
