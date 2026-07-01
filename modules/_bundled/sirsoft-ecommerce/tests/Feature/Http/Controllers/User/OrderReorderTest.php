<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 재주문 API Feature 테스트 — POST /user/orders/{id}/reorder
 *
 * 본 테스트는 마이페이지 취소 주문의 "재주문" 동작이 다음 요구사항을
 * 만족하는지 검증한다:
 *   1. 정상 케이스: 주문 옵션을 장바구니에 일괄 추가 + 추가 카운트 반환
 *   2. 권한: 다른 사용자의 주문은 접근 불가 (422)
 *   3. 인증: 비로그인 사용자는 401
 *   4. 부분 추가: 옵션이 더 이상 존재하지 않거나 재고 부족 시 skipped 누적
 *   5. 멱등성: 동일 옵션 재호출 시 기존 cart row 의 quantity 가 누적
 */
class OrderReorderTest extends ModuleTestCase
{
    private User $user;

    private function endpoint(int $orderId): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$orderId}/reorder";
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_reorder_adds_order_options_to_cart(): void
    {
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 100,
        ]);

        $order = OrderFactory::new()->paid()->forUser($this->user)->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added_count', 1);
        $response->assertJsonPath('data.skipped', []);

        $this->assertSame(1, Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $option->id)
            ->count());

        $this->assertSame(2, (int) Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $option->id)
            ->value('quantity'));
    }

    public function test_reorder_rejects_other_users_order(): void
    {
        $other = User::factory()->create();
        $order = OrderFactory::new()->paid()->forUser($other)->create();

        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(422);

        $this->assertSame(0, Cart::query()->where('user_id', $this->user->id)->count());
    }

    public function test_reorder_requires_authentication(): void
    {
        $order = OrderFactory::new()->paid()->forUser($this->user)->create();

        $response = $this->postJson($this->endpoint($order->id));

        $response->assertStatus(401);
    }

    public function test_reorder_skips_out_of_stock_options(): void
    {
        $product = ProductFactory::new()->create();
        $outOfStock = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 0,
        ]);
        $inStock = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 50,
        ]);

        $order = OrderFactory::new()->paid()->forUser($this->user)->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $outOfStock->id,
            'quantity' => 1,
        ]);
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $inStock->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added_count', 1);
        $this->assertCount(1, $response->json('data.skipped'));

        // 재고 있는 옵션만 cart 에 추가됨
        $this->assertSame(1, Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $inStock->id)
            ->count());
        $this->assertSame(0, Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $outOfStock->id)
            ->count());
    }

    public function test_reorder_returns_422_for_nonexistent_order(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint(999999));

        $response->assertStatus(422);
    }

    public function test_reorder_increments_existing_cart_quantity(): void
    {
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 100,
        ]);

        // 기존 cart 에 같은 옵션이 quantity=3 으로 존재
        Cart::create([
            'user_id' => $this->user->id,
            'cart_key' => null,
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 3,
        ]);

        $order = OrderFactory::new()->paid()->forUser($this->user)->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(200);

        // 기존 3 + 신규 2 = 5 (row 1건 유지)
        $this->assertSame(1, Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $option->id)
            ->count());
        $this->assertSame(5, (int) Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $option->id)
            ->value('quantity'));
    }
}
