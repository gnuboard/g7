<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Modules\Sirsoft\Ecommerce\Listeners\SyncOptionGroupsListener;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * SyncOptionGroupsListener 테스트
 *
 * 옵션 변경 후 option_groups 자동 동기화 리스너의 동작을 검증합니다.
 */
class SyncOptionGroupsListenerTest extends ModuleTestCase
{
    protected SyncOptionGroupsListener $listener;

    protected ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = app(ProductService::class);
        $this->listener = app(SyncOptionGroupsListener::class);
    }

    // ========================================
    // rebuildOptionGroups 기본 동작 테스트
    // ========================================

    /**
     * 옵션이 없는 상품의 option_groups는 빈 배열이 되어야 한다
     */
    public function test_rebuilds_empty_option_groups_when_no_options(): void
    {
        // Given: 옵션 없는 상품
        $product = Product::factory()->create([
            'has_options' => false,
            'option_groups' => [['name' => '색상', 'values' => ['빨강']]],
        ]);

        // When: rebuildOptionGroups 실행
        $this->productService->rebuildOptionGroups($product);

        // Then: option_groups가 빈 배열
        $product->refresh();
        $this->assertEquals([], $product->option_groups);
    }

    /**
     * 객체 형식 option_values에서 option_groups 재생성
     */
    public function test_rebuilds_option_groups_from_object_format(): void
    {
        // Given: 상품과 옵션들
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => [],
        ]);

        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '빨강', '사이즈' => 'S'],
            'is_active' => true,
        ]);
        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '빨강', '사이즈' => 'M'],
            'is_active' => true,
        ]);
        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '파랑', '사이즈' => 'S'],
            'is_active' => true,
        ]);

        // When: rebuildOptionGroups 실행
        $this->productService->rebuildOptionGroups($product);

        // Then: option_groups가 올바르게 생성됨
        $product->refresh();
        $optionGroups = $product->option_groups;

        $this->assertCount(2, $optionGroups);

        // 색상 그룹 확인
        $colorGroup = collect($optionGroups)->firstWhere('name', '색상');
        $this->assertNotNull($colorGroup);
        $this->assertContains('빨강', $colorGroup['values']);
        $this->assertContains('파랑', $colorGroup['values']);
        $this->assertCount(2, $colorGroup['values']);

        // 사이즈 그룹 확인
        $sizeGroup = collect($optionGroups)->firstWhere('name', '사이즈');
        $this->assertNotNull($sizeGroup);
        $this->assertContains('S', $sizeGroup['values']);
        $this->assertContains('M', $sizeGroup['values']);
        $this->assertCount(2, $sizeGroup['values']);
    }

    /**
     * 비활성화된 옵션은 option_groups에서 제외된다
     */
    public function test_excludes_inactive_options_from_option_groups(): void
    {
        // Given: 활성/비활성 옵션
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => [],
        ]);

        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '빨강'],
            'is_active' => true,
        ]);
        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '파랑'],
            'is_active' => false, // 비활성
        ]);

        // When: rebuildOptionGroups 실행
        $this->productService->rebuildOptionGroups($product);

        // Then: 활성 옵션만 포함
        $product->refresh();
        $colorGroup = collect($product->option_groups)->firstWhere('name', '색상');

        $this->assertContains('빨강', $colorGroup['values']);
        $this->assertNotContains('파랑', $colorGroup['values']);
        $this->assertCount(1, $colorGroup['values']);
    }

    /**
     * 중복 값은 option_groups에서 한 번만 나타난다
     */
    public function test_deduplicates_values_in_option_groups(): void
    {
        // Given: 중복 값을 가진 옵션들
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => [],
        ]);

        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '빨강'],
            'is_active' => true,
        ]);
        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '빨강'], // 중복
            'is_active' => true,
        ]);

        // When: rebuildOptionGroups 실행
        $this->productService->rebuildOptionGroups($product);

        // Then: 중복 제거됨
        $product->refresh();
        $colorGroup = collect($product->option_groups)->firstWhere('name', '색상');

        $this->assertCount(1, $colorGroup['values']);
        $this->assertEquals('빨강', $colorGroup['values'][0]);
    }

    // ========================================
    // 리스너 훅 통합 테스트
    // ========================================

    /**
     * syncOptionGroupsFromOptions: 옵션 생성 시 동기화
     */
    public function test_sync_option_groups_from_options_on_create(): void
    {
        // Given: 옵션이 있는 상품
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => [],
        ]);

        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => ['색상' => '빨강'],
            'is_active' => true,
        ]);

        // When: 리스너 메서드 호출 (생성 1개)
        $this->listener->syncOptionGroupsFromOptions($product, 1, 0, 0);

        // Then: option_groups가 동기화됨
        $product->refresh();
        $this->assertNotEmpty($product->option_groups);
    }

    /**
     * syncOptionGroupsFromOptions: 변경 없으면 동기화 안 함
     */
    public function test_sync_option_groups_from_options_skips_when_no_changes(): void
    {
        // Given: 기존 option_groups가 있는 상품
        $originalGroups = [['name' => '기존', 'values' => ['값']]];
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => $originalGroups,
        ]);

        // When: 리스너 메서드 호출 (변경 0개)
        $this->listener->syncOptionGroupsFromOptions($product, 0, 0, 0);

        // Then: option_groups가 변경되지 않음
        $product->refresh();
        $this->assertEquals($originalGroups, $product->option_groups);
    }

    /**
     * syncOptionGroupsFromBulkUpdate: option_values 변경 없으면 동기화 안 함
     */
    public function test_sync_option_groups_from_bulk_update_skips_when_no_option_values_change(): void
    {
        // Given: 기존 option_groups가 있는 상품
        $originalGroups = [['name' => '기존', 'values' => ['값']]];
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => $originalGroups,
        ]);

        // When: option_values 변경 없는 bulk_update
        $result = ['options_updated' => 1];
        $data = [
            'product_ids' => [$product->id],
            'bulk_changes' => ['stock_quantity' => ['method' => 'set', 'value' => 10]],
            'items' => [],
        ];
        $this->listener->syncOptionGroupsFromBulkUpdate($result, $data);

        // Then: option_groups가 변경되지 않음
        $product->refresh();
        $this->assertEquals($originalGroups, $product->option_groups);
    }

    // ========================================
    // 배열 형식 option_values 테스트 (다국어 대응)
    // ========================================

    /**
     * 배열 형식 option_values에서 option_groups 재생성
     */
    public function test_rebuilds_option_groups_from_array_format(): void
    {
        // Given: 배열 형식 option_values를 가진 옵션
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => [],
        ]);

        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => [
                ['key' => '색상', 'value' => '빨강'],
                ['key' => '사이즈', 'value' => 'S'],
            ],
            'is_active' => true,
        ]);

        // When: rebuildOptionGroups 실행
        $this->productService->rebuildOptionGroups($product);

        // Then: option_groups가 올바르게 생성됨
        $product->refresh();
        $optionGroups = $product->option_groups;

        $this->assertCount(2, $optionGroups);

        $colorGroup = collect($optionGroups)->firstWhere('name', '색상');
        $this->assertNotNull($colorGroup);
        $this->assertContains('빨강', $colorGroup['values']);
    }

    /**
     * 다국어 객체 형식 option_values에서 option_groups 재생성
     */
    public function test_rebuilds_option_groups_from_multilingual_format(): void
    {
        // Given: 다국어 형식 option_values를 가진 옵션
        $product = Product::factory()->create([
            'has_options' => true,
            'option_groups' => [],
        ]);

        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => [
                ['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => '빨강', 'en' => 'Red']],
            ],
            'is_active' => true,
        ]);
        ProductOption::factory()->create([
            'product_id' => $product->id,
            'option_values' => [
                ['key' => ['ko' => '색상', 'en' => 'Color'], 'value' => ['ko' => '파랑', 'en' => 'Blue']],
            ],
            'is_active' => true,
        ]);

        // When: rebuildOptionGroups 실행
        $this->productService->rebuildOptionGroups($product);

        // Then: option_groups가 올바르게 생성됨 (다국어 객체 유지)
        $product->refresh();
        $optionGroups = $product->option_groups;

        $this->assertCount(1, $optionGroups);

        // 색상 그룹 확인 (name은 다국어 객체)
        $colorGroup = $optionGroups[0];
        $this->assertEquals(['ko' => '색상', 'en' => 'Color'], $colorGroup['name']);
        $this->assertCount(2, $colorGroup['values']);
    }
}
