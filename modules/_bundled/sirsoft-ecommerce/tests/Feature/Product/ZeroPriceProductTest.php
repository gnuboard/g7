<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Product;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Contracts\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateProductsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 0원 상품 허용 테스트
 *
 * 상품 등록·수정·일괄 수정이 정가·판매가 0 을 받는다. 0원 주문은 결제할 것이 없으므로
 * 무료 결제수단으로 곧바로 결제완료가 된다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/zero-price-product.yaml
 */
class ZeroPriceProductTest extends ModuleTestCase
{
    /**
     * FormRequest 규칙으로 검증기를 실행합니다.
     *
     * @param  class-string  $requestClass  FormRequest 클래스
     * @param  array<string, mixed>  $data  요청 데이터
     * @return Validator 실행된 검증기
     */
    private function validateWith(string $requestClass, array $data): Validator
    {
        $request = $requestClass::create('/', 'POST', $data);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);

        $validator = $this->app['validator']->make($data, $request->rules());
        $validator->passes();

        return $validator;
    }

    /**
     * 상품 등록 규칙이 정가·판매가 0 을 받는다.
     *
     * @scenario surface=store_request, price=zero
     *
     * @effects store_accepts_zero_price
     *
     * @return void 반환값 없음
     */
    public function test_store_request_accepts_zero_prices(): void
    {
        $errors = $this->validateWith(StoreProductRequest::class, ['list_price' => 0, 'selling_price' => 0])->errors();

        $this->assertFalse($errors->has('list_price'));
        $this->assertFalse($errors->has('selling_price'));
    }

    /**
     * 음수 가격은 여전히 막는다.
     *
     * @scenario surface=store_request, price=negative
     *
     * @effects store_rejects_negative_price
     *
     * @return void 반환값 없음
     */
    public function test_store_request_rejects_negative_prices(): void
    {
        $errors = $this->validateWith(StoreProductRequest::class, ['list_price' => -1, 'selling_price' => -1])->errors();

        $this->assertTrue($errors->has('list_price'));
        $this->assertTrue($errors->has('selling_price'));
    }

    // 아래 메서드가 함께 재는 두 번째 조합 (docblock 은 @scenario 를 1건만 인식하므로 라인 주석으로 병기).
    // @scenario surface=bulk_request, price=negative

    /**
     * 일괄 수정 규칙도 0 을 받고 음수는 막는다.
     *
     * @scenario surface=bulk_request, price=zero
     *
     * @effects bulk_accepts_zero_price, bulk_rejects_negative_price
     *
     * @return void 반환값 없음
     */
    public function test_bulk_request_accepts_zero_and_rejects_negative(): void
    {
        $zero = $this->validateWith(BulkUpdateProductsRequest::class, [
            'items' => [['id' => 1, 'list_price' => 0, 'selling_price' => 0]],
        ])->errors();
        $this->assertFalse($zero->has('items.0.list_price'));
        $this->assertFalse($zero->has('items.0.selling_price'));

        $negative = $this->validateWith(BulkUpdateProductsRequest::class, [
            'items' => [['id' => 1, 'list_price' => -5, 'selling_price' => -5]],
        ])->errors();
        $this->assertTrue($negative->has('items.0.list_price'));
        $this->assertTrue($negative->has('items.0.selling_price'));
    }

    /**
     * 0원 상품의 할인율은 0 이다(0 나누기 없음).
     *
     * @scenario surface=product_model, price=zero
     *
     * @effects zero_price_discount_rate_is_zero
     *
     * @return void 반환값 없음
     */
    public function test_zero_price_product_has_zero_discount_rate(): void
    {
        $product = ProductFactory::new()->create(['list_price' => 0, 'selling_price' => 0]);

        $this->assertSame(0.0, (float) $product->getDiscountRate());
    }

    /**
     * 가격 낮은 순 공개 목록에서 0원 상품이 맨 앞에 온다.
     *
     * @scenario surface=public_list, price=zero
     *
     * @effects zero_price_product_sorts_first_by_price_asc
     *
     * @return void 반환값 없음
     */
    public function test_zero_price_product_comes_first_in_price_asc_list(): void
    {
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            ['name' => ['ko' => '상품 조회', 'en' => 'Read Products'], 'type' => PermissionType::User]
        );
        Role::where('identifier', 'guest')->first()?->permissions()->syncWithoutDetaching([$permission->id]);
        Role::where('identifier', 'user')->first()->permissions()->syncWithoutDetaching([$permission->id]);

        Product::factory()->onSale()->create(['list_price' => 5000, 'selling_price' => 5000]);
        $free = Product::factory()->onSale()->create(['list_price' => 0, 'selling_price' => 0]);

        $rows = $this->getJson('/api/modules/sirsoft-ecommerce/products?sort=price_asc')->assertOk()->json('data.data');

        $this->assertSame($free->id, $rows[0]['id']);
    }

    /**
     * 0원 상품만 담은 주문은 무료 결제수단으로 곧바로 결제완료가 된다.
     *
     * @scenario surface=order_flow, price=zero
     *
     * @effects zero_total_order_is_paid_with_free_method
     *
     * @return void 반환값 없음
     */
    public function test_zero_price_order_completes_with_free_payment(): void
    {
        $user = $this->createUser();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => 0,
            'selling_price' => 0,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 0,
            'price_adjustment' => 0,
            'stock_quantity' => 10,
        ]);
        $cart = CartFactory::new()->forOption($option)->forUser($user)->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', [
                'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'member@test.com'],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'payment_method' => PaymentMethodEnum::FREE->value,
                'expected_total_amount' => 0,
            ])
            ->assertStatus(201);

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $this->assertSame(0.0, (float) $order->total_amount);
    }

    /**
     * 주문서가 기본 선택(카드)을 그대로 보내도, 결제할 것이 없는 0원 주문의 결제수단은 「무료」로 남는다.
     *
     * 결제 기록이 카드로 남으면 마이페이지·관리자 주문에 「신용카드」로 보이고 PG 가 붙은 것처럼 읽힌다.
     *
     * @scenario surface=order_flow, price=zero
     *
     * @effects zero_total_order_records_free_even_if_card_selected
     *
     * @return void 반환값 없음
     */
    public function test_zero_price_order_records_free_method_even_when_card_is_selected(): void
    {
        $user = $this->createUser();
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => 0,
            'selling_price' => 0,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 0,
            'price_adjustment' => 0,
            'stock_quantity' => 10,
        ]);
        $cart = CartFactory::new()->forOption($option)->forUser($user)->create(['quantity' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]])
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', [
                'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'member@test.com'],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'payment_method' => PaymentMethodEnum::CARD->value,
                'expected_total_amount' => 0,
            ])
            ->assertStatus(201);

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
        $payment = $order->payment()->firstOrFail();
        $method = $payment->payment_method instanceof PaymentMethodEnum ? $payment->payment_method->value : $payment->payment_method;
        $this->assertSame(PaymentMethodEnum::FREE->value, $method);
    }
}
