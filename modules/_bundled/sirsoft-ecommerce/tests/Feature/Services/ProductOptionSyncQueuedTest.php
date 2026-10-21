<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Listeners\SyncOptionGroupsListener;
use Modules\Sirsoft\Ecommerce\Listeners\SyncProductFromOptionListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductOptionService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 옵션 일괄 수정 직후 상품 총재고·옵션 그룹이 갱신된다 — 큐 워커와 무관
 *
 * 옵션 재고를 일괄로 바꾸면 상품 총재고가, 옵션값을 바꾸면 상품의 옵션 그룹이 따라 바뀌어야 한다. 이 파생 데이터
 * 갱신이 큐로 가면 저장 응답과 바로 다시 연 목록에 옛 총재고·옛 옵션 그룹이 보인다.
 */
class ProductOptionSyncQueuedTest extends ModuleTestCase
{
    /**
     * 큐를 database 로 바꾸고 워커는 돌리지 않습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /**
     * 옵션 2개를 가진 상품을 만듭니다 (각 재고 10).
     *
     * @return Product 생성된 상품
     */
    private function makeProductWithOptions(): Product
    {
        $product = Product::factory()->create(['has_options' => true, 'stock_quantity' => 20, 'option_groups' => []]);
        ProductOption::factory()->count(2)->create(['product_id' => $product->id, 'stock_quantity' => 10]);

        return $product;
    }

    /**
     * 두 동기화 리스너의 전 구독이 sync 다. (실패-먼저)
     *
     * @scenario listener=option_sync, queue=sync
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_sync_listeners_are_synchronous(): void
    {
        foreach ([SyncOptionGroupsListener::class, SyncProductFromOptionListener::class] as $listener) {
            foreach ($listener::getSubscribedHooks() as $hook => $config) {
                $this->assertTrue(($config['sync'] ?? false) === true, class_basename($listener)." 의 {$hook} 가 sync 가 아닙니다.");
            }
        }
    }

    /**
     * 옵션 재고 일괄 수정 직후 상품 총재고가 옵션 합계로 바뀌고, 재고 동기화 훅은 상품마다 한 번만 난다. (실패-먼저)
     *
     * @scenario listener=option_sync, queue=database
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_product_stock_is_synced_right_after_bulk_stock_update(): void
    {
        $product = $this->makeProductWithOptions();

        app(ProductOptionService::class)->bulkUpdateStockByMixedIds([$product->id], [], 'increase', 5);

        $this->assertSame(30, (int) $product->fresh()->stock_quantity, '옵션 재고를 +5 씩 올렸는데 상품 총재고가 옛 값입니다.');
        $this->assertSame(
            0,
            DB::table('jobs')->where('payload', 'like', '%SyncProductFromOptionListener%')->count(),
            '상품 재고 동기화가 큐 작업으로 쌓였습니다.'
        );
        $this->assertSame(
            1,
            DB::table('jobs')->where('payload', 'like', '%handleProductAfterStockSync%')->count(),
            '재고 동기화 후 활동 로그 훅이 상품 1개에 한 번이 아닙니다.'
        );
    }

    /**
     * 옵션값을 일괄 수정한 직후 상품의 옵션 그룹이 새 값으로 다시 만들어진다. (실패-먼저)
     *
     * @scenario listener=option_sync, queue=database
     *
     * @effects derived_data_visible_in_same_request
     *
     * @return void 반환값 없음
     */
    public function test_option_groups_are_rebuilt_right_after_bulk_option_values_update(): void
    {
        $product = $this->makeProductWithOptions();
        $option = ProductOption::where('product_id', $product->id)->orderBy('id')->firstOrFail();

        app(ProductOptionService::class)->bulkUpdate([
            'product_ids' => [$product->id],
            'items' => [[
                'product_id' => $product->id,
                'option_id' => $option->id,
                'option_values' => [
                    ['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => '보라724', 'en' => 'Purple724']],
                ],
            ]],
        ]);

        $groups = collect($product->fresh()->option_groups);
        $values = $groups->flatMap(fn ($group) => collect($group['values'] ?? [])->map(fn ($value) => is_array($value) ? ($value['ko'] ?? null) : $value));

        $this->assertContains('보라724', $values->all(), '옵션값을 바꿨는데 상품 옵션 그룹이 옛 값입니다.');
    }

    /**
     * 옵션 API 의 `ids`("상품ID-옵션ID") 로 옵션값을 일괄 수정해도 그 상품의 옵션 그룹이 다시 만들어진다. (실패-먼저)
     *
     * 리스너가 `ids` 를 옵션 ID 로 보고 옵션 테이블에서 상품 ID 를 찾으면, 문자열 「상품ID-옵션ID」 가 옵션 ID
     * 자리에 들어가 엉뚱한 상품을 고르거나 아무것도 고르지 못한다.
     *
     * @scenario listener=option_sync, queue=database
     *
     * @effects option_groups_rebuilt_for_mixed_ids_bulk_update
     *
     * @return void 반환값 없음
     */
    public function test_option_groups_are_rebuilt_for_mixed_ids_bulk_update(): void
    {
        $product = $this->makeProductWithOptions();
        $option = ProductOption::where('product_id', $product->id)->orderBy('id')->firstOrFail();

        app(ProductOptionService::class)->bulkUpdate([
            'ids' => ["{$product->id}-{$option->id}"],
            'items' => [[
                'product_id' => $product->id,
                'option_id' => $option->id,
                'option_values' => [
                    ['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => '초록724', 'en' => 'Green724']],
                ],
            ]],
        ]);

        $groups = collect($product->fresh()->option_groups);
        $values = $groups->flatMap(fn ($group) => collect($group['values'] ?? [])->map(fn ($value) => is_array($value) ? ($value['ko'] ?? null) : $value));

        $this->assertContains('초록724', $values->all(), '「상품ID-옵션ID」 로 옵션값을 바꿨는데 상품 옵션 그룹이 옛 값입니다.');
    }
}
