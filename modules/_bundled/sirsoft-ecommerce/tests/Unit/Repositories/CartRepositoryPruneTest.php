<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Repositories\CartRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 장바구니 보관기간 만료 정리 테스트 (A2)
 */
class CartRepositoryPruneTest extends ModuleTestCase
{
    protected CartRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CartRepository(new Cart);
    }

    /**
     * updated_at 을 지정해 장바구니 항목을 생성합니다.
     *
     * @param  int  $daysAgo  현재로부터 며칠 전으로 updated_at 을 백데이트할지
     * @param  User|null  $user  소유 회원 (null = 비회원)
     */
    private function makeCart(int $daysAgo, ?User $user = null): Cart
    {
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $factory = CartFactory::new()->forOption($option);
        $factory = $user ? $factory->forUser($user) : $factory->guest();

        $cart = $factory->create();
        // updated_at 백데이트 (timestamps 자동 갱신 우회)
        Cart::withoutTimestamps(fn () => $cart->forceFill([
            'updated_at' => Carbon::now()->subDays($daysAgo),
        ])->save());

        return $cart->fresh();
    }

    public function test_prunes_only_items_past_boundary(): void
    {
        // Given: 보관기간 30일 기준 — 31일전(삭제), 30일전(경계 보존), 29일전(보존)
        $over = $this->makeCart(31);
        $atBoundary = $this->makeCart(30);
        $before = $this->makeCart(29);

        // When
        $deleted = $this->repository->pruneExpiredItems(30);

        // Then: 초과만 삭제
        $this->assertSame(1, $deleted);
        $this->assertNull(Cart::find($over->id));
        $this->assertNotNull(Cart::find($atBoundary->id));
        $this->assertNotNull(Cart::find($before->id));
    }

    public function test_prunes_both_member_and_guest_items(): void
    {
        // Given
        $user = User::factory()->create();
        $memberOld = $this->makeCart(40, $user);
        $guestOld = $this->makeCart(40);
        $memberFresh = $this->makeCart(5, $user);

        // When
        $deleted = $this->repository->pruneExpiredItems(30);

        // Then: 회원/비회원 모두 만료 대상 삭제, 최신은 보존
        $this->assertSame(2, $deleted);
        $this->assertNull(Cart::find($memberOld->id));
        $this->assertNull(Cart::find($guestOld->id));
        $this->assertNotNull(Cart::find($memberFresh->id));
    }

    public function test_returns_zero_when_days_below_one(): void
    {
        // Given: 만료 비활성 정책 — days < 1 이면 전체삭제 차단
        $this->makeCart(100);
        $this->makeCart(200);

        // When
        $deletedZero = $this->repository->pruneExpiredItems(0);
        $deletedNeg = $this->repository->pruneExpiredItems(-5);

        // Then: 한 건도 삭제하지 않음
        $this->assertSame(0, $deletedZero);
        $this->assertSame(0, $deletedNeg);
        $this->assertSame(2, Cart::count());
    }

    public function test_respects_limit_chunk(): void
    {
        // Given: 만료 대상 5건
        for ($i = 0; $i < 5; $i++) {
            $this->makeCart(40);
        }

        // When: limit 2 → 최대 2건만 삭제
        $deleted = $this->repository->pruneExpiredItems(30, 2);

        // Then
        $this->assertSame(2, $deleted);
        $this->assertSame(3, Cart::count());
    }
}
