<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\ActivityLog\ChangeDetector;
use Modules\Sirsoft\Ecommerce\Listeners\ProductActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ProductActivityLogListener 테스트
 *
 * 상품 변경 이력 ActivityLog 표준 패턴 리스너의 동작을 검증합니다.
 */
class ProductActivityLogListenerTest extends ModuleTestCase
{
    protected ProductActivityLogListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', \Illuminate\Http\Request::create('/api/admin/sirsoft-ecommerce/test'));
        $this->listener = app(ProductActivityLogListener::class);
    }

    // ========================================
    // getSubscribedHooks() 테스트
    // ========================================

    /**
     * 리스너가 올바른 훅을 구독하는지 확인 (before 훅 제거됨)
     */
    public function test_listener_subscribes_to_correct_hooks(): void
    {
        $hooks = ProductActivityLogListener::getSubscribedHooks();

        $this->assertCount(7, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_create', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_bulk_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_bulk_price_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_bulk_stock_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.product.after_stock_sync', $hooks);

        // before 훅이 제거되었는지 확인
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product.before_update', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product.before_bulk_update', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product.before_bulk_price_update', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.product.before_bulk_stock_update', $hooks);
    }

    /**
     * after 훅들의 우선순위가 기본값보다 높은지 확인
     */
    public function test_after_hooks_have_correct_priority(): void
    {
        $hooks = ProductActivityLogListener::getSubscribedHooks();

        $this->assertEquals(20, $hooks['sirsoft-ecommerce.product.after_create']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-ecommerce.product.after_update']['priority']);
        $this->assertEquals(20, $hooks['sirsoft-ecommerce.product.after_delete']['priority']);
    }

    // ========================================
    // handleProductAfterCreate() 테스트
    // ========================================

    /**
     * 상품 생성 시 활동 로그가 기록되는지 확인
     */
    public function test_handle_product_after_create_records_activity_log(): void
    {
        // Given: 상품 생성
        $product = Product::factory()->create();

        // When: 생성 핸들러 호출
        $this->listener->handleProductAfterCreate($product);

        // Then: activity_logs 테이블에 기록됨
        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Product::class,
            'loggable_id' => $product->id,
            'action' => 'product.create',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_create',
        ]);
    }

    /**
     * 상품 생성 로그에 description_params가 ID 기반으로 저장되는지 확인
     */
    public function test_handle_product_after_create_stores_id_in_description_params(): void
    {
        // Given: 상품 생성
        $product = Product::factory()->create();

        // When
        $this->listener->handleProductAfterCreate($product);

        // Then: description_params에 product_id가 저장됨
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.create')
            ->first();

        $this->assertNotNull($log);
        $params = $log->description_params;
        $this->assertArrayHasKey('product_id', $params);
        $this->assertEquals($product->id, $params['product_id']);
    }

    /**
     * 상품 생성 로그에 product_code가 properties에 저장되는지 확인
     */
    public function test_handle_product_after_create_stores_product_code_in_properties(): void
    {
        // Given: 상품 생성
        $product = Product::factory()->create(['product_code' => 'TEST1234CODE']);

        // When
        $this->listener->handleProductAfterCreate($product);

        // Then
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.create')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('TEST1234CODE', $log->properties['product_code']);
    }

    // ========================================
    // handleProductAfterUpdate() 테스트 (스냅샷 인수 방식)
    // ========================================

    /**
     * 가격 변경 시 ChangeDetector가 변경사항을 감지하는지 확인
     */
    public function test_handle_product_after_update_detects_price_change(): void
    {
        // Given: 상품 생성
        $product = Product::factory()->create(['selling_price' => 35000]);
        $snapshot = $product->toArray();

        // When: 가격 변경 → 스냅샷 인수로 전달
        Product::where('id', $product->id)->update(['selling_price' => 29000]);
        $product->refresh();

        $this->listener->handleProductAfterUpdate($product, $snapshot);

        // Then: activity_logs에 변경 로그 기록
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.update')
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes, 'changes should not be null when fields changed');
    }

    /**
     * 변경사항이 없으면 changes가 null인 로그가 기록되는지 확인
     */
    public function test_handle_product_after_update_records_log_even_without_changes(): void
    {
        // Given: 상품 생성
        $product = Product::factory()->create();
        $snapshot = $product->toArray();

        // When: 변경 없이 스냅샷 인수로 핸들러 호출
        $this->listener->handleProductAfterUpdate($product, $snapshot);

        // Then: 로그는 기록되지만 changes는 null
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.update')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changes);
    }

    /**
     * 스냅샷 없이 handleProductAfterUpdate 호출 시 changes가 null
     */
    public function test_handle_product_after_update_without_snapshot_has_null_changes(): void
    {
        // Given: 상품
        $product = Product::factory()->create();

        // When: 스냅샷 없이 핸들러 호출
        $this->listener->handleProductAfterUpdate($product);

        // Then: 로그는 기록되지만 changes는 null (ChangeDetector가 null 스냅샷 처리)
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.update')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changes);
    }

    // ========================================
    // handleProductAfterDelete() 테스트
    // ========================================

    /**
     * 상품 삭제 시 활동 로그가 기록되는지 확인
     */
    public function test_handle_product_after_delete_records_activity_log(): void
    {
        // Given: 상품
        $product = Product::factory()->create();

        // When: 삭제 핸들러 호출
        $this->listener->handleProductAfterDelete($product);

        // Then: activity_logs에 기록됨
        $this->assertDatabaseHas('activity_logs', [
            'loggable_type' => Product::class,
            'loggable_id' => $product->id,
            'action' => 'product.delete',
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_delete',
        ]);
    }

    /**
     * 상품 삭제 시 properties에 이름 스냅샷이 저장되는지 확인
     */
    public function test_handle_product_after_delete_stores_name_snapshot_in_properties(): void
    {
        // Given: 상품
        $product = Product::factory()->create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'DEL_TEST_CODE',
        ]);

        // When
        $this->listener->handleProductAfterDelete($product);

        // Then: properties에 스냅샷 저장
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.delete')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($product->id, $log->properties['product_id']);
        $this->assertEquals('DEL_TEST_CODE', $log->properties['product_code']);
        $this->assertNotEmpty($log->properties['product_name']);
    }

    // ========================================
    // handleProductAfterStockSync() 테스트
    // ========================================

    /**
     * 옵션 재고 동기화로 상품 재고 변경 시 활동 로그가 기록되는지 확인
     */
    public function test_handle_product_after_stock_sync_records_activity_log(): void
    {
        // Given: 상품 생성 (재고 10 → 17로 변경된 상태)
        $product = Product::factory()->create(['stock_quantity' => 17]);
        $snapshot = $product->toArray();
        $snapshot['stock_quantity'] = 10; // 변경 전 재고

        // When: stock_sync 핸들러 호출
        $this->listener->handleProductAfterStockSync($product, $snapshot);

        // Then: activity_logs에 기록됨
        $log = \App\Models\ActivityLog::where('loggable_type', Product::class)
            ->where('loggable_id', $product->id)
            ->where('action', 'product.stock_sync')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('sirsoft-ecommerce::activity_log.description.product_stock_sync', $log->description_key);
        $this->assertEquals(['product_id' => $product->id], $log->description_params);
        $this->assertEquals($product->id, $log->properties['product_id']);
    }

    /**
     * stock_sync 로그에 변경 전/후 재고가 올바르게 기록되는지 확인
     */
    public function test_handle_product_after_stock_sync_detects_stock_change(): void
    {
        // Given: 상품 A 재고 10개, 옵션 변경으로 17개가 된 상태
        $product = Product::factory()->create(['stock_quantity' => 17]);
        $snapshot = $product->toArray();
        $snapshot['stock_quantity'] = 10;

        // When
        $this->listener->handleProductAfterStockSync($product, $snapshot);

        // Then: changes에 stock_quantity 변경이 포함됨
        $log = \App\Models\ActivityLog::where('action', 'product.stock_sync')
            ->where('loggable_id', $product->id)
            ->first();

        $this->assertNotNull($log->changes);
        $stockChange = collect($log->changes)->firstWhere('field', 'stock_quantity');
        $this->assertNotNull($stockChange, 'stock_quantity 변경이 changes에 포함되어야 합니다');
        $this->assertEquals(10, $stockChange['old']);
        $this->assertEquals(17, $stockChange['new']);
    }

    /**
     * 재고 변경이 없으면 stock_sync 로그가 기록되지 않는지 확인
     */
    public function test_handle_product_after_stock_sync_skips_when_no_change(): void
    {
        // Given: 변경 전/후 재고 동일
        $product = Product::factory()->create(['stock_quantity' => 80]);
        $snapshot = $product->toArray(); // stock_quantity = 80 동일

        // When
        $this->listener->handleProductAfterStockSync($product, $snapshot);

        // Then: 로그 미기록
        $this->assertDatabaseMissing('activity_logs', [
            'loggable_type' => Product::class,
            'loggable_id' => $product->id,
            'action' => 'product.stock_sync',
        ]);
    }

    /**
     * 스냅샷 없이 stock_sync 핸들러 호출 시 로그 미기록
     */
    public function test_handle_product_after_stock_sync_without_snapshot_skips(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 100]);

        $this->listener->handleProductAfterStockSync($product, null);

        $this->assertDatabaseMissing('activity_logs', [
            'loggable_type' => Product::class,
            'loggable_id' => $product->id,
            'action' => 'product.stock_sync',
        ]);
    }

    // ========================================
    // handleProductAfterBulkUpdate() per-item 테스트
    // ========================================

    /**
     * 일괄 수정 시 N건의 상품에 대해 각각 로그가 기록되는지 확인 (bulkUpdateStatus 패턴)
     */
    public function test_handleProductAfterBulkUpdate_creates_per_item_logs(): void
    {
        $products = Product::factory()->count(3)->create();
        $ids = $products->pluck('id')->toArray();

        $this->listener->handleProductAfterBulkUpdate($ids, 3);

        $logs = \App\Models\ActivityLog::where('action', 'product.bulk_update')->get();
        $this->assertCount(3, $logs);

        foreach ($products as $product) {
            $log = $logs->firstWhere('loggable_id', $product->id);
            $this->assertNotNull($log);
            $this->assertEquals(Product::class, $log->loggable_type);
            $this->assertEquals($product->id, $log->properties['product_id']);
        }
    }

    /**
     * 일괄 수정 시 스냅샷 전달하면 changes 감지 확인
     */
    public function test_handleProductAfterBulkUpdate_detects_changes_with_snapshots(): void
    {
        $product = Product::factory()->create(['selling_price' => 35000]);
        $snapshot = $product->toArray();

        Product::where('id', $product->id)->update(['selling_price' => 29000]);
        $product->refresh();

        $this->listener->handleProductAfterBulkUpdate([$product->id], 1, [$product->id => $snapshot]);

        $log = \App\Models\ActivityLog::where('action', 'product.bulk_update')
            ->where('loggable_id', $product->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);

        $priceChange = collect($log->changes)->firstWhere('field', 'selling_price');
        $this->assertNotNull($priceChange);
        $this->assertEquals(35000, $priceChange['old']);
        $this->assertEquals(29000, $priceChange['new']);
    }

    /**
     * 일괄 수정 시 스냅샷 없으면 changes가 null
     */
    public function test_handleProductAfterBulkUpdate_null_changes_without_snapshots(): void
    {
        $product = Product::factory()->create();

        $this->listener->handleProductAfterBulkUpdate([$product->id], 1);

        $log = \App\Models\ActivityLog::where('action', 'product.bulk_update')
            ->where('loggable_id', $product->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changes);
    }

    /**
     * bulkUpdate() 결과 패턴 (연관 배열) 처리 확인
     */
    public function test_handleProductAfterBulkUpdate_handles_bulk_update_result_pattern(): void
    {
        $products = Product::factory()->count(2)->create();
        $ids = $products->pluck('id')->toArray();

        $result = ['products_updated' => 2];
        $data = ['ids' => $ids];

        $this->listener->handleProductAfterBulkUpdate($result, $data);

        $logs = \App\Models\ActivityLog::where('action', 'product.bulk_update')->get();
        $this->assertCount(2, $logs);
    }

    /**
     * 빈 ID 배열 전달 시 로그 미기록
     */
    public function test_handleProductAfterBulkUpdate_skips_empty_ids(): void
    {
        $this->listener->handleProductAfterBulkUpdate([], 0);

        $this->assertDatabaseMissing('activity_logs', ['action' => 'product.bulk_update']);
    }

    // ========================================
    // handleProductAfterBulkPriceUpdate() per-item 테스트
    // ========================================

    /**
     * 일괄 가격 수정 시 per-item 로그 기록 확인
     */
    public function test_handleProductAfterBulkPriceUpdate_creates_per_item_logs(): void
    {
        $products = Product::factory()->count(2)->create();
        $ids = $products->pluck('id')->toArray();

        $this->listener->handleProductAfterBulkPriceUpdate($ids, 2);

        $logs = \App\Models\ActivityLog::where('action', 'product.bulk_price_update')->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(Product::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
        }
    }

    /**
     * 일괄 가격 수정 시 스냅샷으로 changes 감지
     */
    public function test_handleProductAfterBulkPriceUpdate_detects_changes_with_snapshots(): void
    {
        $product = Product::factory()->create(['selling_price' => 50000]);
        $snapshot = $product->toArray();

        Product::where('id', $product->id)->update(['selling_price' => 40000]);
        $product->refresh();

        $this->listener->handleProductAfterBulkPriceUpdate([$product->id], 1, [$product->id => $snapshot]);

        $log = \App\Models\ActivityLog::where('action', 'product.bulk_price_update')
            ->where('loggable_id', $product->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);
    }

    // ========================================
    // handleProductAfterBulkStockUpdate() per-item 테스트
    // ========================================

    /**
     * 일괄 재고 수정 시 per-item 로그 기록 확인
     */
    public function test_handleProductAfterBulkStockUpdate_creates_per_item_logs(): void
    {
        $products = Product::factory()->count(2)->create();
        $ids = $products->pluck('id')->toArray();

        $this->listener->handleProductAfterBulkStockUpdate($ids, 2);

        $logs = \App\Models\ActivityLog::where('action', 'product.bulk_stock_update')->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            $this->assertEquals(Product::class, $log->loggable_type);
            $this->assertContains($log->loggable_id, $ids);
        }
    }

    /**
     * 일괄 재고 수정 시 스냅샷으로 changes 감지
     */
    public function test_handleProductAfterBulkStockUpdate_detects_changes_with_snapshots(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 100]);
        $snapshot = $product->toArray();

        Product::where('id', $product->id)->update(['stock_quantity' => 50]);
        $product->refresh();

        $this->listener->handleProductAfterBulkStockUpdate([$product->id], 1, [$product->id => $snapshot]);

        $log = \App\Models\ActivityLog::where('action', 'product.bulk_stock_update')
            ->where('loggable_id', $product->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->changes);

        $stockChange = collect($log->changes)->firstWhere('field', 'stock_quantity');
        $this->assertNotNull($stockChange);
        $this->assertEquals(100, $stockChange['old']);
        $this->assertEquals(50, $stockChange['new']);
    }

    // ========================================
    // ChangeDetector 연동 테스트
    // ========================================

    /**
     * Product 모델에 $activityLogFields가 정의되어 있는지 확인
     */
    public function test_product_model_has_activity_log_fields(): void
    {
        $this->assertTrue(
            property_exists(Product::class, 'activityLogFields'),
            'Product model should have $activityLogFields property'
        );

        $this->assertNotEmpty(
            Product::$activityLogFields,
            '$activityLogFields should not be empty'
        );
    }

    /**
     * ChangeDetector가 $activityLogFields 기반으로 변경을 감지하는지 확인
     */
    public function test_change_detector_uses_activity_log_fields(): void
    {
        // Given: 상품
        $product = Product::factory()->create([
            'selling_price' => 35000,
            'sales_status' => 'on_sale',
        ]);
        $snapshot = $product->toArray();

        // When: 가격 변경
        Product::where('id', $product->id)->update(['selling_price' => 29000]);
        $product->refresh();

        $changes = ChangeDetector::detect($product, $snapshot);

        // Then: changes에 selling_price가 포함됨 (인덱스 배열 구조)
        $this->assertNotNull($changes);

        $priceChange = collect($changes)->firstWhere('field', 'selling_price');
        $this->assertNotNull($priceChange, 'selling_price change should be detected');
        $this->assertEquals(35000, $priceChange['old']);
        $this->assertEquals(29000, $priceChange['new']);
    }
}
