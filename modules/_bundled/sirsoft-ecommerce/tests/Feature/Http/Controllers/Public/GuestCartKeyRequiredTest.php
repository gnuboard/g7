<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 쓰기 요청의 장바구니 키(X-Cart-Key) 필수 — 주인 없는 행 방지
 *
 * 임시 주문 조회·연장·삭제 요청은 CartKeyRequest 로(422), 장바구니 조회·담기는 컨트롤러 검사로(400)
 * 비회원 키를 요구하는데, 임시 주문 생성·갱신과 장바구니 수량·옵션 변경·여러 개 삭제에는 그 가드가 없었다.
 * 키 없는 비회원 바로 구매가 user_id·cart_key 가 모두 null 인 임시 주문을 만들었고(누구도 조회·삭제 못 함),
 * 장바구니 소유 판정(null === null)이 참이라 키 없는 요청이 주인 없는 행을 고치거나 지울 수 있었다.
 * 각 묶음의 기존 응답 계약(장바구니 400 · 임시 주문 422)을 그대로 따른다.
 */
class GuestCartKeyRequiredTest extends ModuleTestCase
{
    /**
     * 응답 문구를 한국어로 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');
        $this->withHeaders(['Accept-Language' => 'ko']);
    }

    /**
     * 판매 중 상품·옵션(무료 배송 정책)을 만든다.
     *
     * @return array{product: Product, option: ProductOption} 상품과 재고 100 옵션
     */
    private function createProductWithOption(): array
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR', 'shipping_method' => 'parcel', 'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FREE, 'base_fee' => 0, 'is_active' => true,
        ]);

        $product = ProductFactory::new()->onSale()->create(['shipping_policy_id' => $policy->id]);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        return ['product' => $product, 'option' => $option];
    }

    /**
     * 주인 없는(user_id·cart_key 모두 null) 장바구니 행을 만든다 — 종전 결함으로 생기던 행.
     *
     * @param  ProductOption  $option  옵션
     * @return Cart 주인 없는 행
     */
    private function createOrphanCart(ProductOption $option): Cart
    {
        return CartFactory::new()->forOption($option)->create([
            'user_id' => null,
            'cart_key' => null,
            'quantity' => 2,
        ]);
    }

    /**
     * 비회원이 키 없이 담으면 400(장바구니 기존 계약) 이고 주인 없는 행이 생기지 않는다 — 회귀 잠금.
     *
     * @scenario case=add_without_key
     *
     * @effects guest_write_without_cart_key_rejected
     *
     * @return void 반환값 없음
     */
    public function test_guest_add_to_cart_without_key_is_rejected(): void
    {
        $data = $this->createProductWithOption();

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $data['product']->id,
            'items' => [['product_option_id' => $data['option']->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(400);
        $this->assertSame(0, Cart::whereNull('user_id')->whereNull('cart_key')->count(), '키 없는 비회원 담기가 주인 없는 장바구니 행을 만들었습니다.');
    }

    /**
     * 비회원이 키 없이 바로 구매 임시 주문을 만들면 422 이고 주인 없는 임시 주문이 생기지 않는다.
     *
     * @scenario case=checkout_store_without_key
     *
     * @effects guest_write_without_cart_key_rejected
     *
     * @return void 반환값 없음
     */
    public function test_guest_checkout_store_without_key_is_rejected(): void
    {
        $data = $this->createProductWithOption();

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/checkout', [
            'direct_items' => [['product_id' => $data['product']->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['cart_key']);
        $this->assertSame(0, TempOrder::whereNull('user_id')->whereNull('cart_key')->count(), '키 없는 비회원 요청이 주인 없는 임시 주문을 만들었습니다.');
    }

    /**
     * 비회원이 키 없이 임시 주문을 갱신하면 422 다.
     *
     * @scenario case=checkout_update_without_key
     *
     * @effects guest_write_without_cart_key_rejected
     *
     * @return void 반환값 없음
     */
    public function test_guest_checkout_update_without_key_is_rejected(): void
    {
        $response = $this->putJson('/api/modules/sirsoft-ecommerce/checkout', ['use_points' => 0]);

        $response->assertStatus(422)->assertJsonValidationErrors(['cart_key']);
    }

    /**
     * 키 없는 비회원이 주인 없는 행의 수량·옵션을 바꾸거나 지울 수 없다.
     *
     * @scenario case=orphan_row_write_without_key
     *
     * @effects orphan_cart_row_untouched_without_key
     *
     * @return void 반환값 없음
     */
    public function test_guest_without_key_cannot_touch_orphan_cart_rows(): void
    {
        $data = $this->createProductWithOption();
        $orphan = $this->createOrphanCart($data['option']);

        $this->patchJson("/api/modules/sirsoft-ecommerce/cart/{$orphan->id}/quantity", ['quantity' => 7])
            ->assertStatus(400);
        $this->putJson("/api/modules/sirsoft-ecommerce/cart/{$orphan->id}/option", ['product_option_id' => $data['option']->id, 'quantity' => 5])
            ->assertStatus(400);
        $this->deleteJson('/api/modules/sirsoft-ecommerce/cart', ['ids' => [$orphan->id]])
            ->assertStatus(400);

        $this->assertSame(2, $orphan->fresh()?->quantity, '키 없는 비회원 요청이 주인 없는 행을 바꾸거나 지웠습니다.');
    }

    /**
     * 형식이 틀린 키는 임시 주문 생성에서도 거절하고(422), 올바른 키의 비회원·키 없는 회원은 종전대로 담기고 임시 주문을 만든다.
     *
     * @scenario case=valid_key_and_member_unchanged
     *
     * @effects guest_with_valid_key_and_member_unaffected
     *
     * @return void 반환값 없음
     */
    public function test_valid_key_guest_and_member_are_unaffected(): void
    {
        $data = $this->createProductWithOption();
        $payload = [
            'product_id' => $data['product']->id,
            'items' => [['product_option_id' => $data['option']->id, 'quantity' => 1]],
        ];

        $this->postJson('/api/modules/sirsoft-ecommerce/checkout', ['direct_items' => [['product_id' => $data['product']->id, 'quantity' => 1]]], ['X-Cart-Key' => 'bad-key'])
            ->assertStatus(422)->assertJsonValidationErrors(['cart_key']);

        $this->postJson('/api/modules/sirsoft-ecommerce/cart', $payload, ['X-Cart-Key' => 'ck_'.str_repeat('a', 32)])
            ->assertStatus(201);
        $this->postJson('/api/modules/sirsoft-ecommerce/checkout', ['direct_items' => [['product_id' => $data['product']->id, 'quantity' => 1]]], ['X-Cart-Key' => 'ck_'.str_repeat('b', 32)])
            ->assertStatus(201);

        /** @var User $member */
        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/cart', $payload)
            ->assertStatus(201);
    }
}
