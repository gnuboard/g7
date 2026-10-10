<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CartOperationException;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 구매할 수 있는 옵션이 없는 상품의 담기·바로 구매 거절 테스트
 *
 * 옵션 행이 없거나 전부 비활성인 상품은 옵션을 지정하지 않은 담기·바로 구매에서 422 와 번역된 안내로
 * 거절되어야 한다. 바로 구매는 종전에 `Failed to create checkout.` 500 으로 끝났고, 담기는 안내 대신
 * 번역 키 원문을 돌려줬다.
 */
class NoPurchasableOptionRejectionTest extends ModuleTestCase
{
    /**
     * 테스트 공통 로케일을 한국어로 맞춥니다.
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
     * 옵션 행이 하나도 없는 상품을 바로 구매하면 서버 오류(500)가 아니라 422 와 번역된 안내로 거절한다.
     *
     * @scenario entry=direct_checkout, option_rows=none
     *
     * @effects direct_checkout_rejects_without_purchasable_option, rejection_message_is_translated, rejection_creates_nothing
     *
     * @return void 반환값 없음
     */
    public function test_direct_checkout_rejects_product_without_option_rows_with_422(): void
    {
        $user = $this->createUser();
        $product = $this->createOnSaleProduct();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'no_purchasable_option')
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.no_purchasable_option', [], 'ko'));
        $this->assertSame(0, TempOrder::where('user_id', $user->id)->count());
        $this->assertSame(0, Cart::where('user_id', $user->id)->count());
    }

    /**
     * 옵션이 모두 판매 중지(비활성)인 상품도 바로 구매를 422 로 거절한다.
     *
     * @scenario entry=direct_checkout, option_rows=all_inactive
     *
     * @effects direct_checkout_rejects_without_purchasable_option, rejection_creates_nothing
     *
     * @return void 반환값 없음
     */
    public function test_direct_checkout_rejects_product_with_only_inactive_options_with_422(): void
    {
        $user = $this->createUser();
        $product = $this->createOnSaleProduct();
        ProductOptionFactory::new()->forProduct($product)->inactive()->create(['stock_quantity' => 100]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'no_purchasable_option');
        $this->assertSame(0, TempOrder::where('user_id', $user->id)->count());
    }

    /**
     * 옵션을 지정하지 않은 바로 구매는 비활성 옵션을 건너뛰고 판매 중인 옵션으로 임시 주문을 만든다.
     *
     * @scenario entry=direct_checkout, option_rows=active_present
     *
     * @effects default_option_skips_inactive_options
     *
     * @return void 반환값 없음
     */
    public function test_direct_checkout_uses_active_option_when_inactive_option_listed_first(): void
    {
        $user = $this->createUser();
        $product = $this->createOnSaleProduct();
        ProductOptionFactory::new()->forProduct($product)->inactive()->create(['stock_quantity' => 100]);
        $activeOption = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(201);
        $tempOrder = TempOrder::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($activeOption->id, (int) ($tempOrder->items[0]['product_option_id'] ?? 0));
    }

    /**
     * 옵션 행이 하나도 없는 상품을 옵션 지정 없이 담으면 422 와 번역된 안내로 거절한다.
     *
     * 회귀: 담기 응답 안내가 번역되지 않고 키 원문으로 나갔다 — 이미 모듈 네임스페이스가 붙은 메시지 키에
     * 모듈 도메인을 한 번 더 붙였기 때문이다.
     *
     * @scenario entry=cart_add, option_rows=none
     *
     * @effects cart_add_rejects_without_purchasable_option, rejection_message_is_translated, rejection_creates_nothing
     *
     * @return void 반환값 없음
     */
    public function test_cart_add_rejects_product_without_option_rows_with_422(): void
    {
        $product = $this->createOnSaleProduct();
        $cartKey = 'ck_'.str_repeat('p', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [['quantity' => 1]],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'no_purchasable_option')
            ->assertJsonPath('message', __('sirsoft-ecommerce::exceptions.no_purchasable_option', [], 'ko'));
        $this->assertSame(0, Cart::where('cart_key', $cartKey)->count());
    }

    /**
     * 옵션이 모두 판매 중지(비활성)인 상품을 옵션 지정 없이 담아도 422 로 거절한다.
     *
     * @scenario entry=cart_add, option_rows=all_inactive
     *
     * @effects cart_add_rejects_without_purchasable_option, rejection_creates_nothing
     *
     * @return void 반환값 없음
     */
    public function test_cart_add_rejects_product_with_only_inactive_options_with_422(): void
    {
        $product = $this->createOnSaleProduct();
        ProductOptionFactory::new()->forProduct($product)->inactive()->create(['stock_quantity' => 100]);
        $cartKey = 'ck_'.str_repeat('q', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [['quantity' => 1]],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'no_purchasable_option');
        $this->assertSame(0, Cart::where('cart_key', $cartKey)->count());
    }

    /**
     * 옵션을 지정하지 않은 담기는 비활성 옵션을 건너뛰고 판매 중인 옵션으로 담는다.
     *
     * @scenario entry=cart_add, option_rows=active_present
     *
     * @effects default_option_skips_inactive_options
     *
     * @return void 반환값 없음
     */
    public function test_cart_add_uses_active_option_when_inactive_option_listed_first(): void
    {
        $product = $this->createOnSaleProduct();
        ProductOptionFactory::new()->forProduct($product)->inactive()->create(['stock_quantity' => 100]);
        $activeOption = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $cartKey = 'ck_'.str_repeat('r', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [['quantity' => 1]],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(201);
        $this->assertSame(
            [$activeOption->id],
            Cart::where('cart_key', $cartKey)->pluck('product_option_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    /**
     * 구매할 수 있는 옵션이 없다는 예외 사유는 422 와 전용 메시지 키로 매핑되고, 메시지는 번역 문구다.
     *
     * @effects no_purchasable_option_maps_to_422_message_key
     *
     * @return void 반환값 없음
     */
    public function test_no_purchasable_option_reason_maps_to_422_and_message_key(): void
    {
        $e = new CartOperationException('no_purchasable_option');

        $this->assertSame('no_purchasable_option', $e->getReason());
        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame('sirsoft-ecommerce::exceptions.no_purchasable_option', $e->getMessageKey());
        $this->assertSame(__('sirsoft-ecommerce::exceptions.no_purchasable_option'), $e->getMessage());
        $this->assertNotSame($e->getMessageKey(), $e->getMessage());
    }

    /**
     * 판매 중지(비활성) 옵션을 직접 지정해 담으면 판매 불가로 거절한다.
     *
     * 종전에는 옵션을 지정하지 않을 때만 비활성 옵션을 건너뛰고, 옵션 id 를 지정하면 상품 소속인지만 봐서
     * 판매 중지 옵션이 장바구니에 담겼다.
     *
     * @scenario entry=cart_add, option_rows=explicit_inactive
     *
     * @effects explicit_inactive_option_rejected_as_unavailable, rejection_creates_nothing
     *
     * @return void 반환값 없음
     */
    public function test_cart_add_rejects_explicit_inactive_option(): void
    {
        $product = $this->createOnSaleProduct();
        $inactive = ProductOptionFactory::new()->forProduct($product)->inactive()->create(['stock_quantity' => 100]);
        $cartKey = 'ck_'.str_repeat('s', 32);

        $response = $this->postJson('/api/modules/sirsoft-ecommerce/cart', [
            'product_id' => $product->id,
            'items' => [['product_option_id' => $inactive->id, 'quantity' => 1]],
        ], ['X-Cart-Key' => $cartKey]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'cart_unavailable')
            ->assertJsonPath('errors.has_status_issue', true);
        $this->assertSame(0, Cart::where('cart_key', $cartKey)->count());
    }

    /**
     * 판매 중지 옵션을 직접 지정한 바로 구매도 판매 불가로 거절하고 임시 주문을 만들지 않는다.
     *
     * @scenario entry=direct_checkout, option_rows=explicit_inactive
     *
     * @effects explicit_inactive_option_rejected_as_unavailable, rejection_creates_nothing
     *
     * @return void 반환값 없음
     */
    public function test_direct_checkout_rejects_explicit_inactive_option(): void
    {
        $user = $this->createUser();
        $product = $this->createOnSaleProduct();
        $inactive = ProductOptionFactory::new()->forProduct($product)->inactive()->create(['stock_quantity' => 100]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'direct_items' => [
                    ['product_id' => $product->id, 'product_option_id' => $inactive->id, 'quantity' => 1],
                ],
            ]);

        // 주문서 생성은 판매 불가 품목을 400 + cart_unavailable(판매 상태 사유)로 돌려준다(상품 판매 중지와 같은 계약).
        $response->assertStatus(400)
            ->assertJsonPath('errors.code', 'cart_unavailable')
            ->assertJsonPath('errors.has_status_issue', true);
        $this->assertSame(0, TempOrder::where('user_id', $user->id)->count());
    }

    /**
     * 담은 뒤 판매 중지된 옵션은 장바구니에서 구매 불가로 표시되고, 그 행으로 주문서를 만들 수 없다.
     *
     * 종전에는 장바구니가 정상 상품처럼 체크된 채 보였고 주문까지 만들어졌다.
     *
     * @scenario entry=cart_add, option_rows=explicit_inactive
     *
     * @effects cart_marks_inactive_option_unavailable, explicit_inactive_option_rejected_as_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_cart_item_with_option_deactivated_after_adding_is_unavailable(): void
    {
        $user = $this->createUser();
        $product = $this->createOnSaleProduct();
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $cart = Cart::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 1,
        ]);
        $option->update(['is_active' => false]);

        $query = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/cart/query', []);
        $query->assertOk();
        $row = collect($query->json('data.items'))->firstWhere('id', $cart->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['available']);
        $this->assertSame('suspended', $row['unavailable_reason']);

        $checkout = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]]);
        $checkout->assertStatus(400)
            ->assertJsonPath('errors.code', 'cart_unavailable')
            ->assertJsonPath('errors.has_status_issue', true);
        $this->assertSame(0, TempOrder::where('user_id', $user->id)->count());
    }

    /**
     * 무료 배송정책을 둔 판매중 상품을 옵션 없이 만듭니다.
     *
     * @return Product 생성한 상품 (옵션 행 없음)
     */
    private function createOnSaleProduct(): Product
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $policy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'is_active' => true,
        ]);

        return ProductFactory::new()->onSale()->create([
            'shipping_policy_id' => $policy->id,
        ]);
    }
}
