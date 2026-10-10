<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Product;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 판매가 일괄 변경 「인하 + 금액」 회귀 테스트 (#703)
 *
 * 회귀 배경: 상품 목록의 판매가 일괄 변경 창에서 「인하」+금액을 고르면 화면이 `method: 'subtract'` 를
 * 보내는데, 두 일괄 수정 요청은 `set,add,percent` 만 받아 422 로 막혔고 서비스에도 인하 분기가 없었다.
 * 인하는 옵션 판매가(상품 판매가 + 옵션 조정액)가 0 아래로 내려가지 않도록 하한을 둔다.
 */
class BulkPriceSubtractTest extends ModuleTestCase
{
    private User $adminUser;

    private Product $product;

    private ProductOption $option;

    /**
     * 판매가 10,000 상품과 조정액 1,000 옵션을 준비합니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.update']);

        $this->product = Product::create([
            'name' => ['ko' => '인하 대상 상품', 'en' => 'Discount Product'],
            'product_code' => 'SUB-001',
            'selling_price' => 10000,
            'list_price' => 12000,
            'stock_quantity' => 10,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);

        $this->option = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'SUB-OPT-001',
            'option_values' => ['색상' => '빨강'],
            'option_name' => '빨강',
            'price_adjustment' => 1000,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    /**
     * 상품 일괄 수정에서 금액 인하를 보내면 조정액이 그만큼 줄어든다.
     *
     * @scenario endpoint=products_bulk_update, method=subtract, result=within_floor
     *
     * @effects subtract_accepted_and_applied
     *
     * @return void 없음
     */
    public function test_product_bulk_update_subtract_decreases_price_adjustment(): void
    {
        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', [
                'ids' => [$this->product->id],
                'option_bulk_changes' => [
                    'price_adjustment' => ['method' => 'subtract', 'value' => 3000],
                ],
            ])
            ->assertStatus(200);

        $this->assertEquals(-2000, (float) $this->option->refresh()->price_adjustment);
    }

    /**
     * 옵션 일괄 수정에서 금액 인하를 보내도 같은 규칙으로 적용된다.
     *
     * @scenario endpoint=options_bulk_update, method=subtract, result=within_floor
     *
     * @effects subtract_accepted_and_applied
     *
     * @return void 없음
     */
    public function test_option_bulk_update_subtract_decreases_price_adjustment(): void
    {
        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', [
                'ids' => [$this->product->id.'-'.$this->option->id],
                'bulk_changes' => [
                    'price_adjustment' => ['method' => 'subtract', 'value' => 500],
                ],
            ])
            ->assertStatus(200);

        $this->assertEquals(500, (float) $this->option->refresh()->price_adjustment);
    }

    /**
     * 판매가보다 큰 금액을 인하해도 옵션 판매가는 0 아래로 내려가지 않는다.
     *
     * @scenario endpoint=products_bulk_update, method=subtract, result=clamped_to_zero
     *
     * @effects option_selling_price_never_negative
     *
     * @return void 없음
     */
    public function test_subtract_beyond_selling_price_is_clamped_to_zero_price(): void
    {
        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', [
                'ids' => [$this->product->id],
                'option_bulk_changes' => [
                    'price_adjustment' => ['method' => 'subtract', 'value' => 50000],
                ],
            ])
            ->assertStatus(200);

        // 옵션 판매가 = 상품 판매가(10,000) + 조정액 → 하한 0 이면 조정액은 -10,000
        $this->assertEquals(-10000, (float) $this->option->refresh()->price_adjustment);
    }

    /**
     * 비율 변경으로 음수 조정액이 커져도 옵션 판매가 하한을 지킨다.
     *
     * @scenario endpoint=options_bulk_update, method=percent, result=clamped_to_zero
     *
     * @effects option_selling_price_never_negative
     *
     * @return void 없음
     */
    public function test_percent_decrease_respects_zero_price_floor(): void
    {
        $this->option->update(['price_adjustment' => -9000]);

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', [
                'ids' => [$this->product->id.'-'.$this->option->id],
                'bulk_changes' => [
                    'price_adjustment' => ['method' => 'percent', 'value' => -150],
                ],
            ])
            ->assertStatus(200);

        // 옵션 판매가 1,000(10,000 − 9,000) 의 150% 인하 = -500 → 판매가 0 아래로는 내려가지 않아 조정액 -10,000
        $this->assertEquals(-10000, (float) $this->option->refresh()->price_adjustment);
    }

    /**
     * 상품 목록 「판매가 일괄 변경 — 인하 + %」는 옵션 판매가(상품 판매가 + 조정액)를 그 비율만큼 내린다.
     *
     * 조정액이 0 인 옵션에도 판매가가 실제로 내려가야 한다. 종전에는 조정액에만 비율을 곱해(0 × 0.9 = 0)
     * 화면 안내(「판매가를 10% 인하합니다」)와 달리 판매가가 그대로였다(EC-699).
     *
     * @scenario endpoint=products_bulk_update, method=percent, result=within_floor
     *
     * @effects percent_based_on_option_selling_price
     *
     * @return void 반환값 없음
     */
    public function test_product_bulk_update_percent_decrease_lowers_option_selling_price(): void
    {
        $this->option->update(['price_adjustment' => 0]);

        $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/products/bulk-update', [
                'ids' => [$this->product->id],
                'option_bulk_changes' => [
                    'price_adjustment' => ['method' => 'percent', 'value' => -10],
                ],
            ])
            ->assertStatus(200);

        // 옵션 판매가 10,000 → 9,000 (조정액 -1,000)
        $this->assertEquals(-1000, (float) $this->option->refresh()->price_adjustment);
    }
}
