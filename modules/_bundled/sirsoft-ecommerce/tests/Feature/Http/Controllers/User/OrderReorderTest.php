<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Cart;
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

    /**
     * 재주문 API 주소를 만듭니다.
     *
     * @param  int  $orderId  주문 ID
     * @return string 재주문 API 경로
     */
    private function endpoint(int $orderId): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$orderId}/reorder";
    }

    /**
     * 재주문을 요청할 회원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * 분할을 겪지 않은 일반 주문 옵션이 장바구니에 담기고 added_count 로 집계된다
     *
     * @scenario basis_case=plain_row
     *
     * @effects order_options_added_to_cart_with_added_count
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수량 분할로 생긴 행(`parent_option_id` 가 자기 자신)도 재주문에 담긴다 (S1-D5)
     *
     * 부분취소가 행을 분할하면 분할 가족의 머리 행은 `parent_option_id` 에 **자기 id** 를 담는다.
     * 재주문이 최상위 행을 `parent_option_id === null` 로만 걸러, 분할을 겪은 상품이 통째로
     * 빠지면서 `skipped` 에도 안 잡혀 **조용히 사라졌다**.
     *
     * 실측(주문 20260915-0604289654 · 3상품): 응답 `added_count=1 · skipped=[]` —
     * 분할된 2상품(브이넥·양말)이 사라지고 분할 안 된 텀블러 1건만 담겼다.
     *
     * @scenario basis_case=split_row_self_parent
     *
     * @effects split_head_row_included_in_reorder
     *
     * @return void 반환값 없음
     */
    public function test_reorder_includes_split_head_rows(): void
    {
        $product = ProductFactory::new()->create();
        $optionA = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 100,
        ]);
        $optionB = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 100,
        ]);

        $order = OrderFactory::new()->paid()->forUser($this->user)->create();

        // 분할을 겪지 않은 행
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $optionA->id,
            'quantity' => 1,
        ]);

        // 분할 가족의 머리 행 — parent_option_id 가 자기 자신
        $split = OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $optionB->id,
            'quantity' => 2,
        ]);
        $split->parent_option_id = $split->id;
        $split->source_type = 'split';
        $split->save();

        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(200);
        // 둘 다 담겨야 한다 (수정 전에는 1)
        $response->assertJsonPath('data.added_count', 2);
        $response->assertJsonPath('data.skipped', []);

        $this->assertSame(2, (int) Cart::query()
            ->where('user_id', $this->user->id)
            ->where('product_option_id', $optionB->id)
            ->value('quantity'));
    }

    /**
     * 담지 못한 상품의 사유가 구체 문구로 온다 (S1-D7)
     *
     * 구매불가 사유는 `CartUnavailableException::getUserMessage()` 가 구체 문구를 만든다
     * (필수 추가옵션 미선택·재고 부족·구매수량 한도 등). 재주문은 `getMessage()` 만 담아
     * 전부 일반 문구 「구매할 수 없는 상품이 있습니다.」 로 뭉개져, **왜 못 담았는지 알 수 없었다.**
     *
     * 실측(주문 20260915-0604289654): 필수 A/S 보증이 걸린 브이넥이 그 일반 문구로만 표시됐다.
     *
     * @scenario basis_case=skip_reason_is_specific
     *
     * @effects reorder_skip_reason_uses_user_message
     *
     * @return void 반환값 없음
     */
    public function test_reorder_skip_reason_is_specific(): void
    {
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 0,   // 재고 0 → stock 사유
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
        $response->assertJsonPath('data.added_count', 0);

        $reason = (string) ($response->json('data.skipped.0.reason') ?? '');

        // 일반 폴백 문구로 뭉개지지 않아야 한다
        $this->assertNotSame(
            __('sirsoft-ecommerce::exceptions.cart_unavailable'),
            $reason,
            '구매불가 사유가 일반 문구로 뭉개지면 안 된다'
        );
        $this->assertNotSame('', $reason);
    }

    /**
     * 담지 못한 행의 상품명은 보는 언어 → 앱 폴백 언어(app.fallback_locale) 순으로 고른다 — ko·en 고정 순서가 아니다
     *
     * @scenario basis_case=skip_name_uses_fallback_locale
     *
     * @effects reorder_skip_name_follows_app_fallback_locale
     *
     * @return void 반환값 없음
     */
    public function test_reorder_skip_name_follows_app_fallback_locale(): void
    {
        config(['app.fallback_locale' => 'en']);
        app()->setLocale('fr');

        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->create([
            'product_id' => $product->id,
            'stock_quantity' => 0,
        ]);

        $order = OrderFactory::new()->paid()->forUser($this->user)->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_id' => $product->id,
            'product_option_id' => $option->id,
            'product_name' => ['ko' => '한글 상품명', 'en' => 'English Product'],
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->withHeader('Accept-Language', 'fr')
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(200);
        $response->assertJsonPath('data.skipped.0.product_name', 'English Product');
    }

    /**
     * 남의 주문을 재주문하면 422 이고 장바구니에는 아무것도 담기지 않는다
     *
     * @scenario basis_case=other_users_order
     *
     * @effects foreign_or_missing_order_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_reorder_rejects_other_users_order(): void
    {
        $other = User::factory()->create();
        $order = OrderFactory::new()->paid()->forUser($other)->create();

        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint($order->id));

        $response->assertStatus(422);

        $this->assertSame(0, Cart::query()->where('user_id', $this->user->id)->count());
    }

    /**
     * 비로그인 재주문은 401 로 막힌다
     *
     * @scenario basis_case=unauthenticated
     *
     * @effects reorder_requires_authentication
     *
     * @return void 반환값 없음
     */
    public function test_reorder_requires_authentication(): void
    {
        $order = OrderFactory::new()->paid()->forUser($this->user)->create();

        $response = $this->postJson($this->endpoint($order->id));

        $response->assertStatus(401);
    }

    /**
     * 재고 0 행은 skipped 에 남고 재고 있는 행만 담긴다
     *
     * @scenario basis_case=out_of_stock_row
     *
     * @effects unavailable_rows_reported_in_skipped, order_options_added_to_cart_with_added_count
     *
     * @return void 반환값 없음
     */
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

    /**
     * 없는 주문 id 로 재주문하면 422
     *
     * @scenario basis_case=nonexistent_order
     *
     * @effects foreign_or_missing_order_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_reorder_returns_422_for_nonexistent_order(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson($this->endpoint(999999));

        $response->assertStatus(422);
    }

    /**
     * 같은 옵션이 이미 장바구니에 있으면 행이 늘지 않고 수량만 누적된다 (3 + 2 = 5)
     *
     * @scenario basis_case=existing_cart_row
     *
     * @effects existing_cart_row_quantity_accumulated
     *
     * @return void 반환값 없음
     */
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
