<?php

namespace Tests\Unit\Services;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LayoutServiceCachingTest extends TestCase
{
    use RefreshDatabase;

    private LayoutService $layoutService;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layoutService = app(LayoutService::class);
        $this->template = Template::factory()->create();
    }

    /**
     * 병합된 레이아웃이 캐싱되는지 테스트
     */
    public function test_merged_layout_is_cached(): void
    {
        // Arrange
        $parentLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'extends' => null,
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [
                    ['type' => 'Slot', 'slot' => 'content'],
                ],
            ],
        ]);

        $childLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard',
            'extends' => '_base',
            'content' => [
                'extends' => '_base',
                'meta' => ['title' => 'Dashboard'],
                'slots' => [
                    'content' => [
                        ['type' => 'Text', 'content' => 'Dashboard Content'],
                    ],
                ],
            ],
        ]);

        // Act: 첫 번째 호출 (캐시 미스)
        $result1 = $this->layoutService->loadAndMergeLayout(
            $this->template->id,
            'dashboard'
        );

        // Assert: 캐시에 저장되었는지 확인
        $cacheKey = "template.{$this->template->id}.layout.dashboard";
        $this->assertTrue(Cache::has($cacheKey));

        // Act: 두 번째 호출 (캐시 히트)
        $result2 = $this->layoutService->loadAndMergeLayout(
            $this->template->id,
            'dashboard'
        );

        // Assert: 두 결과가 동일
        $this->assertEquals($result1, $result2);

        // Assert: 캐시 통계 확인
        // 첫 호출: _base 미스, dashboard 미스 (총 2회 미스)
        // 두 번째 호출: dashboard 히트 (총 1회 히트)
        $stats = $this->layoutService->getCacheStats();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(2, $stats['misses']); // _base + dashboard
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(33.33, $stats['hit_rate']);
    }

    /**
     * 레이아웃 캐시가 올바르게 무효화되는지 테스트
     */
    public function test_layout_cache_is_cleared(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'extends' => null,
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [],
            ],
        ]);

        // 캐시 생성
        $this->layoutService->loadAndMergeLayout($this->template->id, '_base');

        $cacheKey = "template.{$this->template->id}.layout._base";
        $this->assertTrue(Cache::has($cacheKey));

        // Act: 캐시 무효화
        $this->layoutService->clearLayoutCache($this->template->id, '_base');

        // Assert: 캐시가 삭제됨
        $this->assertFalse(Cache::has($cacheKey));
    }

    /**
     * 부모 레이아웃 수정 시 자식 레이아웃 캐시도 재귀적으로 무효화되는지 테스트
     */
    public function test_dependent_layouts_cache_is_cleared_recursively(): void
    {
        // Arrange: 3단계 레이아웃 상속 구조
        // _base → dashboard → dashboard_custom
        $baseLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'extends' => null,
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [
                    ['type' => 'Slot', 'slot' => 'content'],
                ],
            ],
        ]);

        $dashboardLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard',
            'extends' => '_base',
            'content' => [
                'extends' => '_base',
                'meta' => ['title' => 'Dashboard'],
                'components' => [
                    ['type' => 'Slot', 'slot' => 'sub_content'],
                ],
                'slots' => [
                    'content' => [
                        ['type' => 'Text', 'content' => 'Dashboard'],
                    ],
                ],
            ],
        ]);

        $customLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard_custom',
            'extends' => 'dashboard',
            'content' => [
                'extends' => 'dashboard',
                'meta' => ['title' => 'Custom Dashboard'],
                'slots' => [
                    'sub_content' => [
                        ['type' => 'Text', 'content' => 'Custom'],
                    ],
                ],
            ],
        ]);

        // 모든 레이아웃 캐시 생성
        $this->layoutService->loadAndMergeLayout($this->template->id, '_base');
        $this->layoutService->loadAndMergeLayout($this->template->id, 'dashboard');
        $this->layoutService->loadAndMergeLayout($this->template->id, 'dashboard_custom');

        $baseCacheKey = "template.{$this->template->id}.layout._base";
        $dashboardCacheKey = "template.{$this->template->id}.layout.dashboard";
        $customCacheKey = "template.{$this->template->id}.layout.dashboard_custom";

        $this->assertTrue(Cache::has($baseCacheKey));
        $this->assertTrue(Cache::has($dashboardCacheKey));
        $this->assertTrue(Cache::has($customCacheKey));

        // Act: 부모 레이아웃 캐시 무효화
        $this->layoutService->clearDependentLayoutsCache($this->template->id, '_base');

        // Assert: 부모와 모든 자식의 캐시가 삭제됨
        $this->assertFalse(Cache::has($baseCacheKey));
        $this->assertFalse(Cache::has($dashboardCacheKey));
        $this->assertFalse(Cache::has($customCacheKey));
    }

    /**
     * 캐시 히트율 통계가 올바르게 계산되는지 테스트
     */
    public function test_cache_hit_rate_is_calculated_correctly(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'extends' => null,
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [],
            ],
        ]);

        $this->layoutService->resetCacheStats();

        // Act: 1회 캐시 미스 + 3회 캐시 히트
        $this->layoutService->loadAndMergeLayout($this->template->id, '_base'); // miss
        $this->layoutService->loadAndMergeLayout($this->template->id, '_base'); // hit
        $this->layoutService->loadAndMergeLayout($this->template->id, '_base'); // hit
        $this->layoutService->loadAndMergeLayout($this->template->id, '_base'); // hit

        // Assert
        $stats = $this->layoutService->getCacheStats();
        $this->assertEquals(3, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(75.0, $stats['hit_rate']);
    }

    /**
     * 캐시 만료 후 재생성되는지 테스트
     */
    public function test_cache_expires_and_regenerates(): void
    {
        // Arrange
        $layout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'extends' => null,
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [],
            ],
        ]);

        // Act: 첫 호출로 캐시 생성
        $result1 = $this->layoutService->loadAndMergeLayout($this->template->id, '_base');

        $cacheKey = "template.{$this->template->id}.layout._base";
        $this->assertTrue(Cache::has($cacheKey));

        // 캐시 수동 삭제 (만료 시뮬레이션)
        Cache::forget($cacheKey);
        $this->assertFalse(Cache::has($cacheKey));

        // Act: 캐시 만료 후 재호출
        $result2 = $this->layoutService->loadAndMergeLayout($this->template->id, '_base');

        // Assert: 캐시 재생성됨
        $this->assertTrue(Cache::has($cacheKey));
        $this->assertEquals($result1, $result2);
    }

    /**
     * 중첩된 부모 레이아웃도 캐싱되는지 테스트
     */
    public function test_nested_parent_layouts_are_cached(): void
    {
        // Arrange
        $baseLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_base',
            'extends' => null,
            'content' => [
                'meta' => ['title' => 'Base'],
                'components' => [
                    ['type' => 'Slot', 'slot' => 'content'],
                ],
            ],
        ]);

        $dashboardLayout = TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard',
            'extends' => '_base',
            'content' => [
                'extends' => '_base',
                'meta' => ['title' => 'Dashboard'],
                'slots' => [
                    'content' => [
                        ['type' => 'Text', 'content' => 'Dashboard'],
                    ],
                ],
            ],
        ]);

        $this->layoutService->resetCacheStats();

        // Act: 자식 레이아웃 로드 (부모도 자동 로드됨)
        $this->layoutService->loadAndMergeLayout($this->template->id, 'dashboard');

        // Assert: 부모와 자식 모두 캐싱됨
        $baseCacheKey = "template.{$this->template->id}.layout._base";
        $dashboardCacheKey = "template.{$this->template->id}.layout.dashboard";

        $this->assertTrue(Cache::has($baseCacheKey));
        $this->assertTrue(Cache::has($dashboardCacheKey));

        // 다시 호출 시 부모와 자식 모두 캐시에서 조회됨
        $this->layoutService->loadAndMergeLayout($this->template->id, 'dashboard');

        $stats = $this->layoutService->getCacheStats();
        $this->assertEquals(1, $stats['hits']); // dashboard 캐시 히트
        $this->assertEquals(2, $stats['misses']); // _base, dashboard 최초 미스
    }
}
