<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Cart;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Exceptions\CartQuantityLimitException;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 장바구니 수량 상한 · 구매수량 한도 테스트 (E7 · E8)
 *
 * - E7: `config('sirsoft-ecommerce.cart.max_quantity')`(99)가 죽어 있고 Request 3종이 9999 를
 *   하드코딩했다. 누적 합산 경로에는 상한 자체가 없었다.
 * - E8: 상품 구매수량 한도(`max_purchase_qty`)가 수량 변경 경로에서만 검사되고
 *   담기·옵션변경·비회원 병합 경로에서는 검사되지 않았다.
 */
class CartQuantityAndPurchaseLimitTest extends ModuleTestCase
{
    /**
     * 판매중 상품 옵션을 만듭니다.
     *
     * @param  int  $maxPurchaseQty  구매수량 한도 (0 = 무제한)
     * @param  int  $stock  재고
     * @return ProductOption 생성된 옵션
     */
    private function makeOption(int $maxPurchaseQty = 0, int $stock = 1000): ProductOption
    {
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => 10000,
            'selling_price' => 10000,
            'max_purchase_qty' => $maxPurchaseQty,
            'stock_quantity' => $stock,
        ]);

        return ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => 10000,
            'price_adjustment' => 0,
            'stock_quantity' => $stock,
        ]);
    }

    // ========================================
    // E7 — 장바구니 수량 상한
    // ========================================

    /**
     * 설정 상한을 넘는 담기는 차단되는지 테스트합니다.
     */
    public function test_add_over_cart_quantity_limit_is_blocked(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption();

        $this->expectException(CartQuantityLimitException::class);

        app(CartService::class)->bulkAddToCart([
            'user_id' => $user->id,
            'product_id' => $option->product_id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 100],
            ],
        ]);
    }

    /**
     * 기존 수량과 합산해 상한을 넘으면 차단되는지 테스트합니다.
     *
     * 필드 규칙(`max:`)은 이번 요청분만 보므로 누적 경로는 서버가 따로 판정해야 한다.
     */
    public function test_accumulated_quantity_over_limit_is_blocked(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption();

        CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 90]);

        $this->expectException(CartQuantityLimitException::class);

        app(CartService::class)->bulkAddToCart([
            'user_id' => $user->id,
            'product_id' => $option->product_id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 20],
            ],
        ]);
    }

    /**
     * 상한과 정확히 같은 합산 수량은 통과하는지 테스트합니다 (경계).
     */
    public function test_accumulated_quantity_at_limit_passes(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption();

        CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 90]);

        app(CartService::class)->bulkAddToCart([
            'user_id' => $user->id,
            'product_id' => $option->product_id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 9],
            ],
        ]);

        $this->assertSame(99, (int) Cart::where('user_id', $user->id)->sum('quantity'));
    }

    // ========================================
    // E8 — 구매수량 한도
    // ========================================

    /**
     * 담기 경로에서 구매수량 한도가 강제되는지 테스트합니다.
     */
    public function test_add_to_cart_enforces_purchase_quantity_limit(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption(maxPurchaseQty: 5);

        $this->expectException(CartUnavailableException::class);

        app(CartService::class)->bulkAddToCart([
            'user_id' => $user->id,
            'product_id' => $option->product_id,
            'items' => [
                ['product_option_id' => $option->id, 'quantity' => 8],
            ],
        ]);
    }

    /**
     * 옵션 변경 경로에서 구매수량 한도가 강제되는지 테스트합니다.
     *
     * @scenario entry=change_option
     *
     * @effects change_option_blocks_above_max_qty
     */
    public function test_change_option_enforces_purchase_quantity_limit(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption(maxPurchaseQty: 5);
        $other = ProductOptionFactory::new()
            ->forProduct($option->product)
            ->create(['selling_price' => 10000, 'price_adjustment' => 0, 'stock_quantity' => 1000]);

        $cart = CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 1]);

        $this->expectException(CartUnavailableException::class);

        app(CartService::class)->changeOption($cart->id, $other->id, 8, $user->id, null);
    }

    /**
     * 한도 이내 옵션 변경은 통과하는지 테스트합니다.
     */
    public function test_change_option_within_limit_passes(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption(maxPurchaseQty: 5);
        $other = ProductOptionFactory::new()
            ->forProduct($option->product)
            ->create(['selling_price' => 10000, 'price_adjustment' => 0, 'stock_quantity' => 1000]);

        $cart = CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 1]);

        $updated = app(CartService::class)->changeOption($cart->id, $other->id, 5, $user->id, null);

        $this->assertSame(5, $updated->quantity);
    }

    /**
     * 수량 상한 초과 안내 문구가 실제 상한값을 말하는지 테스트합니다.
     *
     * 규칙은 `config('sirsoft-ecommerce.cart.max_quantity')` 를 읽는데 안내 문구가 다른 숫자를
     * 고정하고 있으면, 사용자는 "최대 N개까지 가능" 이라는 안내를 보면서 그보다 적은 수량에서
     * 차단당한다 — 차단 사유와 안내가 서로 다른 말을 하는 상태다.
     */
    public function test_quantity_limit_message_states_the_configured_limit(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption();

        $cart = CartFactory::new()->forOption($option)->create([
            'user_id' => $user->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/modules/sirsoft-ecommerce/cart/{$cart->id}/quantity", ['quantity' => 100])
            ->assertStatus(422);

        $message = json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('99', $message, '안내 문구가 설정 상한을 말하지 않습니다.');
        $this->assertStringNotContainsString(
            '9,999',
            $message,
            '안내 문구가 실제 상한(99)이 아닌 옛 하드코딩 값(9,999)을 말하고 있습니다.'
        );
    }

    /**
     * 비회원 병합은 예외 대신 상한까지 클램프하고 조정 내역을 남기는지 테스트합니다.
     *
     * 로그인 시 자동 병합이라 예외를 던지면 로그인 자체가 실패한다.
     */
    public function test_guest_merge_clamps_instead_of_throwing(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 5]);

        $user = User::factory()->create();
        $option = $this->makeOption();
        $cartKey = 'ck_'.str_repeat('m', 32);

        CartFactory::new()->forOption($option)->create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'quantity' => 10,
        ]);

        $service = app(CartService::class);
        $merged = $service->mergeGuestCartToUser($cartKey, $user->id);

        $this->assertSame(1, $merged);

        // 조용한 클램프 금지 — 조정 내역이 반환되어야 한다
        $adjustments = $service->getLastMergeAdjustments();
        $this->assertCount(1, $adjustments);
        $this->assertSame(10, $adjustments[0]['requested']);
        $this->assertSame(5, $adjustments[0]['applied']);
    }

    /**
     * 병합 응답이 조정 내역을 실제로 사용자에게 전달하는지 테스트합니다.
     *
     * 서비스가 내역을 들고 있어도 응답에 실리지 않으면 사용자 관점에서는 여전히 조용한
     * 클램프다 — 계약의 양 끝을 모두 고정한다.
     *
     * @scenario entry=guest_merge
     *
     * @effects guest_merge_reports_clamp_adjustments
     */
    public function test_merge_endpoint_exposes_clamp_adjustments(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 5]);

        $user = $this->createUser();
        $option = $this->makeOption();
        $cartKey = 'ck_'.str_repeat('n', 32);

        CartFactory::new()->forOption($option)->create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Cart-Key' => $cartKey])
            ->postJson('/api/modules/sirsoft-ecommerce/cart/merge')
            ->assertStatus(200);

        $adjustments = $response->json('data.adjustments');

        $this->assertIsArray($adjustments, '병합 응답에 조정 내역이 없습니다 — 사용자는 수량이 줄어든 것을 알 수 없습니다.');
        $this->assertCount(1, $adjustments);
        $this->assertSame(10, $adjustments[0]['requested']);
        $this->assertSame(5, $adjustments[0]['applied']);
    }

    /**
     * 비회원 병합이 상품별 구매수량 한도까지 클램프하는지 테스트합니다.
     *
     * 병합은 장바구니 전역 상한(`cart.max_quantity`)만 보고 상품 정책(`max_purchase_qty`)을
     * 보지 않으면, 한도 5짜리 상품을 비회원으로 10개 담고 로그인하는 것만으로 한도를
     * 통과시킬 수 있다 — 담기·수량변경·옵션변경 경로를 모두 막아도 남는 우회로다.
     *
     * @scenario entry=guest_merge
     *
     * @effects guest_merge_clamps_to_product_limit
     */
    public function test_guest_merge_clamps_to_product_purchase_limit(): void
    {
        // 전역 상한은 넉넉히 — 상품 한도만으로 클램프되어야 한다
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = User::factory()->create();
        $option = $this->makeOption(maxPurchaseQty: 5);
        $cartKey = 'ck_'.str_repeat('p', 32);

        CartFactory::new()->forOption($option)->create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'quantity' => 10,
        ]);

        $service = app(CartService::class);
        $service->mergeGuestCartToUser($cartKey, $user->id);

        $merged = Cart::where('user_id', $user->id)->first();
        $this->assertNotNull($merged);
        $this->assertSame(
            5,
            (int) $merged->quantity,
            '병합이 상품 구매수량 한도를 넘겼습니다 — 비회원으로 담고 로그인하면 한도가 무력화됩니다.'
        );

        $adjustments = $service->getLastMergeAdjustments();
        $this->assertCount(1, $adjustments);
        $this->assertSame(10, $adjustments[0]['requested']);
        $this->assertSame(5, $adjustments[0]['applied']);
    }

    /**
     * 병합의 한도 판정이 같은 상품의 기존 회원 장바구니 수량까지 합산하는지 테스트합니다.
     *
     * 담기 경로는 옵션을 나눠 담는 우회를 막으려고 상품 총수량으로 판정한다. 병합만 라인
     * 단위로 판정하면 "회원 장바구니에 4개 + 비회원 장바구니에 다른 옵션 4개" 로 한도 5를
     * 넘길 수 있다.
     *
     * @scenario entry=guest_merge
     *
     * @effects guest_merge_limit_counts_existing_lines
     */
    public function test_guest_merge_limit_counts_existing_member_cart_lines(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = User::factory()->create();
        $optionA = $this->makeOption(maxPurchaseQty: 5);
        $optionB = ProductOptionFactory::new()->forProduct($optionA->product)->create([
            'selling_price' => 10000,
            'price_adjustment' => 0,
            'stock_quantity' => 1000,
        ]);
        $cartKey = 'ck_'.str_repeat('q', 32);

        CartFactory::new()->forOption($optionA)->create([
            'user_id' => $user->id,
            'cart_key' => 'ck_'.str_repeat('r', 32),
            'quantity' => 4,
        ]);
        CartFactory::new()->forOption($optionB)->create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'quantity' => 4,
        ]);

        $service = app(CartService::class);
        $service->mergeGuestCartToUser($cartKey, $user->id);

        $total = (int) Cart::where('user_id', $user->id)
            ->where('product_id', $optionA->product_id)
            ->sum('quantity');

        $this->assertSame(
            5,
            $total,
            '옵션을 나눠 담으면 병합에서 상품 한도를 넘길 수 있습니다.'
        );
    }

    /**
     * 재주문에서 구매수량 한도를 넘는 항목이 사유와 함께 제외되는지 테스트합니다.
     *
     * 재주문은 여러 항목을 한 번에 담는 조작이라, 한 항목 때문에 전체를 실패시키면 나머지
     * 정상 항목까지 담기지 않는다. 품절·단종과 동일하게 항목 단위로 제외하고 사유를
     * 돌려주되, 한도를 넘긴 수량이 장바구니에 들어가서는 안 된다.
     *
     * @scenario entry=reorder
     *
     * @effects reorder_skips_items_over_limit_with_reason
     */
    public function test_reorder_skips_items_over_purchase_limit_with_reason(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption(maxPurchaseQty: 5);

        $order = OrderFactory::new()->create(['user_id' => $user->id]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $option->product_id,
            'product_option_id' => $option->id,
            'parent_option_id' => null,
            'quantity' => 8,
        ]);

        $result = app(CartService::class)->reorderFromOrder($order->id, $user->id);

        $this->assertSame(0, $result['added_count']);
        $this->assertCount(1, $result['skipped']);
        $this->assertNotSame('', (string) $result['skipped'][0]['reason'], '제외 사유가 비어 있으면 사용자가 이유를 알 수 없습니다.');
        $this->assertSame(0, Cart::where('user_id', $user->id)->count(), '한도를 넘는 수량이 장바구니에 들어갔습니다.');
    }

    /**
     * 실제 로그인 경로에서 한도 초과 비회원 장바구니가 로그인을 깨뜨리지 않는지 테스트합니다.
     *
     * 병합이 클램프를 택한 유일한 근거가 "로그인 시 자동 병합이라 예외를 던지면 로그인 자체가
     * 실패한다" 였다. 그 근거를 서비스 직접 호출로만 검증하면 실제 로그인 왕복은 무가드다.
     *
     * 동시에 병합이 **실제로 일어났는지**까지 단언한다. 로그인이 200 이어도 장바구니가
     * 넘어오지 않으면 사용자는 담아둔 것을 잃은 채 로그인만 된다 — 조용한 소실이다.
     *
     * @scenario entry=guest_merge
     *
     * @effects guest_merge_clamps_to_product_limit
     */
    public function test_login_with_over_limit_guest_cart_succeeds_and_merges_clamped(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = User::factory()->create([
            'email' => 'merge-on-login@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);
        $option = $this->makeOption(maxPurchaseQty: 5);
        $cartKey = 'ck_'.str_repeat('s', 32);

        CartFactory::new()->forOption($option)->create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'quantity' => 10,
        ]);

        $this->withHeaders(['X-Cart-Key' => $cartKey])
            ->postJson('/api/auth/login', [
                'email' => 'merge-on-login@example.com',
                'password' => 'correct-password',
            ])
            ->assertStatus(200);

        $merged = Cart::where('user_id', $user->id)->first();

        $this->assertNotNull(
            $merged,
            '로그인은 성공했으나 비회원 장바구니가 회원 계정으로 넘어오지 않았습니다 — 담아둔 항목이 조용히 사라집니다.'
        );
        $this->assertSame(
            5,
            (int) $merged->quantity,
            '병합이 상품 구매수량 한도를 넘겼습니다.'
        );
        $this->assertSame(0, Cart::whereNull('user_id')->where('cart_key', $cartKey)->count());
    }

    /**
     * 한도 이내 항목은 재주문으로 정상 담기는지 테스트합니다 (제외 로직이 과잉 차단하지 않음).
     */
    public function test_reorder_adds_items_within_purchase_limit(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $user = $this->createUser();
        $option = $this->makeOption(maxPurchaseQty: 5);

        $order = OrderFactory::new()->create(['user_id' => $user->id]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $option->product_id,
            'product_option_id' => $option->id,
            'parent_option_id' => null,
            'quantity' => 3,
        ]);

        $result = app(CartService::class)->reorderFromOrder($order->id, $user->id);

        $this->assertSame(1, $result['added_count']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(3, (int) Cart::where('user_id', $user->id)->sum('quantity'));
    }

    /**
     * 무통장 입금기한이 클라이언트 입력이 아니라 서버 설정을 따르는지 테스트합니다 (E6).
     *
     * 수정 전: `dbank.due_days` 를 클라이언트가 보내면 그대로 반영되어 자동취소 스케줄러와
     * 안내 기한이 어긋났다. VBANK 는 이미 서버 설정 단일 SSoT 였다.
     */
    public function test_dbank_due_date_uses_server_setting_not_client_input(): void
    {
        config(['sirsoft-ecommerce.cart.max_quantity' => 99]);

        $settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsDir)) {
            mkdir($settingsDir, 0755, true);
        }
        file_put_contents($settingsDir.'/order_settings.json', json_encode(['auto_cancel_days' => 1]));

        try {
            $option = $this->makeOption();
            $user = $this->createUser();

            $cart = CartFactory::new()->forUser($user)->forOption($option)->create(['quantity' => 1]);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/modules/sirsoft-ecommerce/checkout', ['item_ids' => [$cart->id]])
                ->assertStatus(201);

            $this->actingAs($user, 'sanctum')->postJson(
                '/api/modules/sirsoft-ecommerce/user/orders',
                [
                    'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'dbank@test.com'],
                    'shipping' => [
                        'recipient_name' => '김철수',
                        'recipient_phone' => '010-9876-5432',
                        'country_code' => 'KR',
                        'zipcode' => '12345',
                        'address' => '서울시 강남구 테헤란로 123',
                        'address_detail' => '101동 1001호',
                    ],
                    'payment_method' => 'dbank',
                    'expected_total_amount' => 10000,
                    'depositor_name' => '홍길동',
                    'dbank' => [
                        'bank_code' => 'KB',
                        'bank_name' => '국민은행',
                        'account_number' => '123-456-789012',
                        'account_holder' => '주식회사 테스트',
                        // 클라이언트가 30일을 보내도 서버 설정(1일)이 이겨야 한다
                        'due_days' => 30,
                    ],
                ]
            )->assertStatus(201);

            $order = Order::where('user_id', $user->id)->latest('id')->first();
            $dueAt = $order->payment->deposit_due_at;

            $this->assertNotNull($dueAt);
            $this->assertSame(1, (int) round(now()->diffInDays($dueAt, false)));
        } finally {
            $path = $settingsDir.'/order_settings.json';
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
