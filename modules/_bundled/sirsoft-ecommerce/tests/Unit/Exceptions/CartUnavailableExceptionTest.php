<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Exceptions;

use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 장바구니 구매불가 예외 테스트
 *
 * 재고/판매상태/구매대상제한 사유를 구분하는 헬퍼를 검증합니다.
 * 컨트롤러(CheckoutController/OrderController)가 이 헬퍼로 응답 메시지를
 * 분기하므로, 사유 구분이 정확해야 사용자에게 올바른 안내가 노출됩니다.
 */
class CartUnavailableExceptionTest extends ModuleTestCase
{
    public function test_구매대상제한_사유를_감지한다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => 'A', 'reason' => 'restricted'],
        ]);

        $this->assertTrue($e->hasRestrictionIssue());
        $this->assertFalse($e->hasStockIssue());
        $this->assertFalse($e->hasStatusIssue());
    }

    public function test_재고_판매상태_사유는_구매대상제한으로_오인되지_않는다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => 'A', 'reason' => 'stock'],
            ['product_id' => 2, 'name' => 'B', 'reason' => 'status'],
        ]);

        $this->assertTrue($e->hasStockIssue());
        $this->assertTrue($e->hasStatusIssue());
        $this->assertFalse($e->hasRestrictionIssue());
    }

    public function test_혼합_사유_시_각각_독립적으로_감지된다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => 'A', 'reason' => 'restricted'],
            ['product_id' => 2, 'name' => 'B', 'reason' => 'stock'],
        ]);

        $this->assertTrue($e->hasRestrictionIssue());
        $this->assertTrue($e->hasStockIssue());
        $this->assertFalse($e->hasStatusIssue());
    }

    public function test_to_array에_제한_플래그가_포함된다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => 'A', 'reason' => 'restricted'],
        ]);

        $array = $e->toArray();

        $this->assertArrayHasKey('has_restriction_issue', $array);
        $this->assertTrue($array['has_restriction_issue']);
        $this->assertSame(1, $array['item_count']);
    }

    public function test_get_user_message_최대수량_초과_시_한도와_상품명을_포함한다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => '스커트', 'reason' => 'max_qty', 'limit' => 3, 'requested' => 6],
        ]);

        $message = $e->getUserMessage();

        // 상품명 prefix + 한도(3) + 요청(6) 치환이 포함되어야 한다
        $this->assertStringContainsString('스커트', $message);
        $this->assertStringContainsString('3', $message);
        $this->assertStringContainsString('6', $message);
        // generic 문구로 묻히지 않는다
        $this->assertNotSame(__('sirsoft-ecommerce::exceptions.cart_unavailable'), $message);
    }

    public function test_get_user_message_최소수량_미달_시_한도를_포함한다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => '스커트', 'reason' => 'min_qty', 'limit' => 5, 'requested' => 2],
        ]);

        $message = $e->getUserMessage();

        $this->assertStringContainsString('스커트', $message);
        $this->assertStringContainsString('5', $message);
    }

    public function test_get_user_message_판매상태_사유는_전용_안내를_반환한다(): void
    {
        $e = CartUnavailableException::fromItems([
            ['product_id' => 1, 'name' => '스커트', 'reason' => 'status'],
        ]);

        $message = $e->getUserMessage();

        $this->assertStringContainsString(
            __('sirsoft-ecommerce::exceptions.product_unavailable'),
            $message
        );
    }

    public function test_get_user_message_항목_없으면_일반_메시지로_폴백한다(): void
    {
        $e = CartUnavailableException::fromItems([]);

        $this->assertSame(
            __('sirsoft-ecommerce::exceptions.cart_unavailable'),
            $e->getUserMessage()
        );
    }
}
