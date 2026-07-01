<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductOptionResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductOptionResource.is_sold_out 플래그 테스트 (MP07 §2-b, U8/A34)
 *
 * 프론트 옵션 드롭다운 품절 비활성/라벨이 의존하는 백엔드 플래그가
 * 재고 0 이하 또는 비활성 옵션에서 true 임을 가드한다.
 */
class ProductOptionResourceSoldOutTest extends ModuleTestCase
{
    private function toArray(array $attrs): array
    {
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create($attrs);

        return (new ProductOptionResource($option))->toArray(Request::create('/'));
    }

    public function test_in_stock_active_option_is_not_sold_out(): void
    {
        $array = $this->toArray(['stock_quantity' => 10, 'is_active' => true]);
        $this->assertFalse($array['is_sold_out']);
    }

    public function test_zero_stock_option_is_sold_out(): void
    {
        $array = $this->toArray(['stock_quantity' => 0, 'is_active' => true]);
        $this->assertTrue($array['is_sold_out']);
    }

    public function test_inactive_option_is_sold_out_even_with_stock(): void
    {
        $array = $this->toArray(['stock_quantity' => 50, 'is_active' => false]);
        $this->assertTrue($array['is_sold_out']);
    }
}
