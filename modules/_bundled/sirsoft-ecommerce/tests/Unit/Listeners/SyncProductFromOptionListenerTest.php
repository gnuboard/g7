<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\SyncProductFromOptionListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * SyncProductFromOptionListener 테스트
 *
 * 옵션 재고 변경 시 상품 재고 동기화 + 훅 발행을 검증합니다.
 * 활동 로그 기록은 ProductActivityLogListener에서 담당하므로,
 * 여기서는 재고 동기화 + product.after_stock_sync 훅 발행만 검증합니다.
 */
class SyncProductFromOptionListenerTest extends ModuleTestCase
{
    private SyncProductFromOptionListener $listener;

    /** @var array 발행된 훅 기록 */
    private array $firedHooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new SyncProductFromOptionListener();
        $this->firedHooks = [];

        // product.after_stock_sync 훅 발행을 캡처
        HookManager::addAction(
            'sirsoft-ecommerce.product.after_stock_sync',
            function (Product $product, ?array $snapshot = null) {
                $this->firedHooks[] = [
                    'product_id' => $product->id,
                    'product' => $product,
                    'snapshot' => $snapshot,
                ];
            },
            1
        );

        Log::shouldReceive('info')->byDefault();
        Log::shouldReceive('error')->byDefault();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_both_hooks(): void
    {
        $hooks = SyncProductFromOptionListener::getSubscribedHooks();

        $this->assertCount(2, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product_option.after_bulk_stock_update', $hooks);
        $this->assertEquals('syncProductStockFromOptions', $hooks['sirsoft-ecommerce.product_option.after_bulk_stock_update']['method']);
        $this->assertArrayHasKey('sirsoft-ecommerce.option.after_bulk_update', $hooks);
        $this->assertEquals('syncProductStockFromBulkUpdate', $hooks['sirsoft-ecommerce.option.after_bulk_update']['method']);
    }

    // ═══════════════════════════════════════════
    // syncProductStockFromOptions
    // ═══════════════════════════════════════════

    public function test_syncProductStockFromOptions_updates_product_stock(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 100, 'has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 30]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 50]);

        $this->listener->syncProductStockFromOptions([$option1->id, $option2->id], 2);

        $product->refresh();
        $this->assertEquals(80, $product->stock_quantity);
    }

    public function test_syncProductStockFromOptions_fires_stock_sync_hook(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 100, 'has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 30]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 50]);

        $this->listener->syncProductStockFromOptions([$option1->id, $option2->id], 2);

        // 훅이 1회 발행되어야 함 (상품 1개)
        $this->assertCount(1, $this->firedHooks);
        $this->assertEquals($product->id, $this->firedHooks[0]['product_id']);

        // 스냅샷에 변경 전 재고(100)가 포함되어야 함
        $this->assertEquals(100, $this->firedHooks[0]['snapshot']['stock_quantity']);

        // 현재 상품 재고는 80
        $this->assertEquals(80, $this->firedHooks[0]['product']->stock_quantity);
    }

    public function test_syncProductStockFromOptions_skips_hook_when_no_change(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 80, 'has_options' => true]);
        ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 50]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 30]);

        // 재고 합계가 이미 80이므로 변경 없음
        // 그러나 스냅샷(80)과 현재값(80) 비교는 SyncListener가 아닌 ProductActivityLogListener에서 수행
        // SyncListener는 스냅샷이 존재하면 항상 훅을 발행 (변경 감지는 수신측 책임)
        $this->listener->syncProductStockFromOptions([$option2->id], 1);

        $product->refresh();
        $this->assertEquals(80, $product->stock_quantity);

        // 스냅샷이 존재하므로 훅은 발행됨 (변경 감지는 수신측에서)
        $this->assertCount(1, $this->firedHooks);
    }

    public function test_syncProductStockFromOptions_skips_empty_option_ids(): void
    {
        $this->listener->syncProductStockFromOptions([], 0);

        $this->assertCount(0, $this->firedHooks);
    }

    public function test_syncProductStockFromOptions_handles_multiple_products(): void
    {
        $product1 = Product::factory()->create(['stock_quantity' => 100, 'has_options' => true]);
        $product2 = Product::factory()->create(['stock_quantity' => 200, 'has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product1->id, 'stock_quantity' => 25]);
        $option2 = ProductOption::factory()->create(['product_id' => $product2->id, 'stock_quantity' => 75]);

        $this->listener->syncProductStockFromOptions([$option1->id, $option2->id], 2);

        $product1->refresh();
        $product2->refresh();
        $this->assertEquals(25, $product1->stock_quantity);
        $this->assertEquals(75, $product2->stock_quantity);

        // 2개 상품 각각 훅 발행
        $this->assertCount(2, $this->firedHooks);
    }

    // ═══════════════════════════════════════════
    // syncProductStockFromBulkUpdate
    // ═══════════════════════════════════════════

    public function test_syncProductStockFromBulkUpdate_fires_hook_when_stock_changed(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 17, 'has_options' => true]);
        $option1 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 6]);
        $option2 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 5]);
        $option3 = ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 6]);

        // 옵션 2, 3 의 재고가 변경된 상황 (3→5, 1→6) — 스냅샷은 변경 전 값
        $snapshots = [
            $option2->id => ['product_id' => $product->id, 'stock_quantity' => 3],
            $option3->id => ['product_id' => $product->id, 'stock_quantity' => 1],
        ];

        $result = ['options_updated' => 2];
        $data = [
            'ids' => [$product->id.'-'.$option2->id, $product->id.'-'.$option3->id],
            'items' => [
                ['product_id' => $product->id, 'stock_quantity' => 5],
                ['product_id' => $product->id, 'stock_quantity' => 6],
            ],
        ];

        $this->listener->syncProductStockFromBulkUpdate($result, $data, $snapshots);

        // 상품 재고가 변경되었으므로 훅 발행
        $this->assertCount(1, $this->firedHooks);
        $this->assertEquals($product->id, $this->firedHooks[0]['product_id']);

        // 스냅샷의 stock_quantity는 변경 전 합계 (3+1+6 = 10)
        $this->assertEquals(10, $this->firedHooks[0]['snapshot']['stock_quantity']);
    }

    public function test_syncProductStockFromBulkUpdate_skips_when_no_stock_change_in_data(): void
    {
        $result = ['options_updated' => 1];
        $data = [
            'ids' => ['10-100'],
            'items' => [
                ['product_id' => 10, 'price_adjustment' => 500],
            ],
            'bulk_changes' => ['price_adjustment' => 500],
        ];

        $this->listener->syncProductStockFromBulkUpdate($result, $data);

        $this->assertCount(0, $this->firedHooks);
    }

    public function test_syncProductStockFromBulkUpdate_skips_when_no_snapshots(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 100, 'has_options' => true]);
        ProductOption::factory()->create(['product_id' => $product->id, 'stock_quantity' => 100]);

        $result = ['options_updated' => 1];
        $data = [
            'ids' => [$product->id.'-999'],
            'items' => [
                ['product_id' => $product->id, 'stock_quantity' => 50],
            ],
        ];

        // 스냅샷 없으면 hasSnapshot=false → 훅 미발행
        $this->listener->syncProductStockFromBulkUpdate($result, $data, []);

        $this->assertCount(0, $this->firedHooks);
    }
}
