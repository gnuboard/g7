<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\CheckoutItemResource;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CheckoutItemResource per_user_limit 중복 비활성화(disabled_coupon_ids) 테스트 (U13b/MP06)
 *
 * 한 주문 내 다른 옵션 라인에서 이미 선택된 per_user_limit 쿠폰을, 이 라인 드롭다운에서
 * 비활성화하도록 서버가 disabled_coupon_ids 를 계산해 내려주는지 검증한다.
 */
class CheckoutItemResourceCouponDisableTest extends ModuleTestCase
{
    /**
     * 두 옵션을 가진 상품을 만들고 [items, productCoupons] 픽스처를 구성합니다.
     *
     * @param  int  $perUserLimit  쿠폰 per_user_limit
     * @return array{items: array, productCoupons: array, optionIds: array}
     */
    private function makeFixture(int $perUserLimit): array
    {
        $product = ProductFactory::new()->create([
            'selling_price' => 50000,
            'list_price' => 50000,
        ]);
        $optA = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 10, 'is_default' => true]);
        $optB = ProductOptionFactory::new()->forProduct($product)->create(['stock_quantity' => 10]);

        $items = [
            ['cart_id' => 1, 'product_id' => $product->id, 'product_option_id' => $optA->id, 'quantity' => 1],
            ['cart_id' => 2, 'product_id' => $product->id, 'product_option_id' => $optB->id, 'quantity' => 1],
        ];

        // 같은 쿠폰(coupon_id=500)의 발급건 2개(issue 9001/9002) — 두 옵션에 동일 쿠폰 후보로 노출
        $productCoupons = [
            $product->id => [
                ['id' => 9001, 'coupon_id' => 500, 'localized_name' => '테스트', 'benefit_formatted' => '5,000원',
                    'target_type' => 'product_amount', 'target_type_short_label' => '상품', 'min_order_amount' => 0,
                    'per_user_limit' => $perUserLimit, 'is_combinable' => true, 'expired_at' => null],
                ['id' => 9002, 'coupon_id' => 500, 'localized_name' => '테스트', 'benefit_formatted' => '5,000원',
                    'target_type' => 'product_amount', 'target_type_short_label' => '상품', 'min_order_amount' => 0,
                    'per_user_limit' => $perUserLimit, 'is_combinable' => true, 'expired_at' => null],
            ],
        ];

        return ['items' => $items, 'productCoupons' => $productCoupons, 'optionIds' => [$optA->id, $optB->id]];
    }

    /**
     * per_user_limit=1 쿠폰을 A 라인에 선택하면, B 라인에서 같은 coupon_id 발급건이 모두 비활성화된다.
     */
    public function test_per_user_limit_coupon_disabled_on_other_line(): void
    {
        $f = $this->makeFixture(perUserLimit: 1);
        [$optAId, $optBId] = $f['optionIds'];

        // A 라인에 발급건 9001 선택
        $selected = [$optAId => [9001]];

        $result = CheckoutItemResource::collectionFromArray($f['items'], [], $f['productCoupons'], $selected);

        $itemA = collect($result)->firstWhere('product_option_id', $optAId);
        $itemB = collect($result)->firstWhere('product_option_id', $optBId);

        // A 라인(선택한 본인)은 비활성화 대상 아님
        $this->assertEquals([], $itemA['disabled_coupon_ids'], 'A 라인은 자기 선택을 비활성화하지 않는다');
        // B 라인은 같은 coupon_id 의 모든 발급건(9001, 9002) 비활성화
        $this->assertContains(9001, $itemB['disabled_coupon_ids']);
        $this->assertContains(9002, $itemB['disabled_coupon_ids']);
    }

    /**
     * per_user_limit=0(무제한)이면 다른 라인 선택이 있어도 비활성화하지 않는다.
     */
    public function test_unlimited_coupon_never_disabled(): void
    {
        $f = $this->makeFixture(perUserLimit: 0);
        [$optAId, $optBId] = $f['optionIds'];

        $selected = [$optAId => [9001]];

        $result = CheckoutItemResource::collectionFromArray($f['items'], [], $f['productCoupons'], $selected);

        $itemB = collect($result)->firstWhere('product_option_id', $optBId);
        $this->assertEquals([], $itemB['disabled_coupon_ids']);
    }

    /**
     * 아무 선택도 없으면 비활성화 목록은 비어 있다.
     */
    public function test_no_selection_no_disable(): void
    {
        $f = $this->makeFixture(perUserLimit: 1);
        [$optAId, $optBId] = $f['optionIds'];

        $result = CheckoutItemResource::collectionFromArray($f['items'], [], $f['productCoupons'], []);

        foreach ($result as $item) {
            $this->assertEquals([], $item['disabled_coupon_ids']);
        }
    }
}
