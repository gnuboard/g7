<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\GuestRoleResolver;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 구매 흐름 버튼을 잠그는 주문 권한 키 테스트 (7.1.0 abilities 게이트 basic-shop#3)
 *
 * [바로 구매]·장바구니 [주문하기]·주문서 [결제하기] 는 최종적으로 `POST user/orders`(`user-orders.create`)에서 막힌다.
 * 그 권한이 없는 회원·비회원이 결제 직전까지 진행한 뒤에야 403 을 받지 않도록, 상품 상세·장바구니 응답이
 * 같은 권한을 `abilities.can_order` 로 싣는다.
 */
class OrderCreateAbilityTest extends ModuleTestCase
{
    private User $member;

    /**
     * 공개 상품 조회 권한과 회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->createUser();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'Read Products'], 'type' => PermissionType::User],
        );
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /**
     * 회원 역할에서 주문하기 권한을 거둡니다.
     *
     * @return void 반환값 없음
     */
    private function revokeOrderCreate(): void
    {
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.user-orders.create')->first();
        Role::where('identifier', 'user')->first()?->permissions()->detach($permission?->id);
        GuestRoleResolver::flush();
    }

    /**
     * 상품 상세 응답의 `abilities.can_order` 가 주문하기 권한을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=missing
     *
     * @effects user_purchase_responses_expose_can_order
     */
    public function test_product_detail_exposes_can_order(): void
    {
        $product = Product::factory()->onSale()->create();
        $url = "/api/modules/sirsoft-ecommerce/products/{$product->id}";

        $this->actingAs($this->member)->getJson($url)->assertOk()->assertJsonPath('data.abilities.can_order', true);

        $this->revokeOrderCreate();
        $this->actingAs($this->member->fresh())->getJson($url)->assertOk()->assertJsonPath('data.abilities.can_order', false);
    }

    /**
     * 장바구니 조회 응답의 `abilities.can_order` 가 주문하기 권한을 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects user_purchase_responses_expose_can_order
     */
    public function test_cart_query_exposes_can_order(): void
    {
        $url = '/api/modules/sirsoft-ecommerce/cart/query';

        $this->actingAs($this->member)->postJson($url, [])->assertOk()->assertJsonPath('data.abilities.can_order', true);

        $this->revokeOrderCreate();
        $this->actingAs($this->member->fresh())->postJson($url, [])->assertOk()->assertJsonPath('data.abilities.can_order', false);
    }

    /**
     * 주문서 수정 응답도 `abilities.can_order` 를 싣는다.
     *
     * 쿠폰·배송지·마일리지를 바꿀 때마다 화면이 주문서 데이터를 이 응답으로 통째로 바꾼다. 수정 응답에 키가
     * 없으면 [결제하기] 게이트가 권한 값을 잃는다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects user_purchase_responses_expose_can_order
     */
    public function test_checkout_update_exposes_can_order(): void
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'shipping_method' => 'parcel',
            'charge_policy' => ChargePolicyEnum::FREE,
            'base_fee' => 0,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_default' => false,
            'is_active' => true,
        ]);
        $product = ProductFactory::new()->create(['shipping_policy_id' => $policy->id]);
        $option = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 100]);
        $cart = CartFactory::new()->forUser($this->member)->forOption($option)->create(['quantity' => 1]);

        $this->actingAs($this->member, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]])
            ->assertSuccessful();

        $this->actingAs($this->member, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', ['use_points' => 0])
            ->assertOk()
            ->assertJsonPath('data.abilities.can_order', true);
    }
}
