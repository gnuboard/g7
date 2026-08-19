<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductOptionResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 옵션 응답의 무게/부피 직렬화 테스트 (공개 #94 / N8)
 *
 * 저장된 무게·부피가 응답에 실리지 않으면 관리자 수정 화면이 0 을 표시하고,
 * 그 상태로 저장하면 실측값이 덮어써진다. 배송비 계산이 이 값을 읽으므로
 * 왕복(read → edit → save)에서 값이 살아남는지가 이 필드의 계약이다.
 *
 * @scenario charge_policy=range_weight, unit_source=g
 *
 * @effects product_option_dimensions_round_trip
 */
class ProductOptionResourceDimensionsTest extends ModuleTestCase
{
    /**
     * 옵션을 리소스 배열로 변환합니다.
     *
     * @param  array  $attributes  옵션 속성 오버라이드
     * @return array 리소스 배열
     */
    private function toResourceArray(array $attributes): array
    {
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(array_merge([
            'product_id' => $product->id,
        ], $attributes));

        return (new ProductOptionResource($option))->toArray(Request::create('/'));
    }

    public function test_it_serializes_weight_and_volume_in_storage_units(): void
    {
        // 상품 옵션의 저장 단위는 g / cm³ (배송정책의 kg/L 환산은 배송비 계산 시점에만)
        $data = $this->toResourceArray(['weight' => 500, 'volume' => 1200]);

        $this->assertArrayHasKey('weight', $data);
        $this->assertArrayHasKey('volume', $data);
        $this->assertEquals(500.0, $data['weight']);
        $this->assertEquals(1200.0, $data['volume']);
    }

    public function test_it_preserves_null_instead_of_coercing_to_zero(): void
    {
        // null 을 0 으로 내리면 "미입력"과 "무게 0" 이 구분되지 않는다
        $data = $this->toResourceArray(['weight' => null, 'volume' => null]);

        $this->assertNull($data['weight']);
        $this->assertNull($data['volume']);
    }

    public function test_it_preserves_decimal_precision(): void
    {
        $data = $this->toResourceArray(['weight' => 12.5, 'volume' => 0.75]);

        $this->assertEquals(12.5, $data['weight']);
        $this->assertEquals(0.75, $data['volume']);
    }
}
