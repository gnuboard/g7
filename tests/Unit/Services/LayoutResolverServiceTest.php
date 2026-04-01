<?php

namespace Tests\Unit\Services;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\Module;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Repositories\LayoutRepository;
use App\Services\LayoutResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LayoutResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private LayoutResolverService $resolverService;

    private LayoutRepository $layoutRepository;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->layoutRepository = app(LayoutRepository::class);
        $this->resolverService = new LayoutResolverService(
            $this->layoutRepository,
            app(ModuleManager::class)
        );

        // 테스트용 템플릿 생성
        $this->template = Template::create([
            'identifier' => 'test-admin-template',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자 템플릿', 'en' => 'Test Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);
    }

    /**
     * 템플릿 오버라이드가 모듈 레이아웃보다 우선하는지 테스트
     */
    public function test_resolve_prioritizes_template_override_over_module_layout(): void
    {
        $layoutName = 'sirsoft-sample_admin_index';

        // 모듈 기본 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'ModuleComponent']]]],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-sample',
        ]);

        // 템플릿 오버라이드 레이아웃 생성
        $override = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'OverrideComponent']]]],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        // 해석 실행
        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        // 오버라이드가 반환되어야 함
        $this->assertNotNull($resolved);
        $this->assertEquals($override->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Template, $resolved->source_type);
        $this->assertStringContainsString('OverrideComponent', json_encode($resolved->content));
    }

    /**
     * 오버라이드가 없을 때 모듈 레이아웃이 반환되는지 테스트
     */
    public function test_resolve_returns_module_layout_when_no_override(): void
    {
        $layoutName = 'sirsoft-sample_admin_edit';

        // 모듈 기본 레이아웃만 생성
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'ModuleEditComponent']]]],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-sample',
        ]);

        // 해석 실행
        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        // 모듈 레이아웃이 반환되어야 함
        $this->assertNotNull($resolved);
        $this->assertEquals($moduleLayout->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Module, $resolved->source_type);
    }

    /**
     * 레이아웃이 존재하지 않을 때 null 반환 테스트
     */
    public function test_resolve_returns_null_when_layout_not_found(): void
    {
        $resolved = $this->resolverService->resolve('non-existent-layout', $this->template->id);

        $this->assertNull($resolved);
    }

    /**
     * 캐시 히트 테스트
     */
    public function test_resolve_uses_cache_on_subsequent_calls(): void
    {
        $layoutName = 'cached-layout';

        // 레이아웃 생성
        $layout = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        // 캐시 통계 초기화
        $this->resolverService->resetCacheStats();

        // 첫 번째 호출 (캐시 미스)
        $resolved1 = $this->resolverService->resolve($layoutName, $this->template->id);
        $stats1 = $this->resolverService->getCacheStats();
        $this->assertEquals(0, $stats1['hits']);
        $this->assertEquals(1, $stats1['misses']);

        // 두 번째 호출 (캐시 히트)
        $resolved2 = $this->resolverService->resolve($layoutName, $this->template->id);
        $stats2 = $this->resolverService->getCacheStats();
        $this->assertEquals(1, $stats2['hits']);
        $this->assertEquals(1, $stats2['misses']);

        // 동일한 결과 반환
        $this->assertEquals($resolved1->id, $resolved2->id);
    }

    /**
     * 캐시 무효화 후 새 데이터 로드 테스트
     */
    public function test_clear_resolution_cache_invalidates_cache(): void
    {
        $layoutName = 'invalidate-test-layout';

        // 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        // 첫 번째 호출로 캐싱
        $this->resolverService->resolve($layoutName, $this->template->id);

        // 캐시 무효화
        $this->resolverService->clearResolutionCache($layoutName, $this->template->id);

        // 캐시가 삭제되었는지 확인
        $cacheKey = "layout_resolver.{$this->template->id}.{$layoutName}";
        $this->assertNull(Cache::get($cacheKey));
    }

    /**
     * 템플릿별 전체 캐시 무효화 테스트
     */
    public function test_clear_all_resolution_cache_by_template(): void
    {
        // 여러 레이아웃 생성
        for ($i = 1; $i <= 3; $i++) {
            TemplateLayout::create([
                'template_id' => $this->template->id,
                'name' => "layout-{$i}",
                'content' => ['slots' => []],
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => 'test-module',
            ]);

            // 캐싱
            $this->resolverService->resolve("layout-{$i}", $this->template->id);
        }

        // 전체 캐시 무효화
        $this->resolverService->clearAllResolutionCacheByTemplate($this->template->id);

        // 모든 캐시가 삭제되었는지 확인
        for ($i = 1; $i <= 3; $i++) {
            $cacheKey = "layout_resolver.{$this->template->id}.layout-{$i}";
            $this->assertNull(Cache::get($cacheKey));
        }
    }

    /**
     * 모듈별 캐시 무효화 테스트
     */
    public function test_clear_resolution_cache_by_module(): void
    {
        $moduleIdentifier = 'sirsoft-ecommerce';

        // 모듈 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => "{$moduleIdentifier}_admin_products",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => "{$moduleIdentifier}_admin_orders",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 캐싱
        $this->resolverService->resolve("{$moduleIdentifier}_admin_products", $this->template->id);
        $this->resolverService->resolve("{$moduleIdentifier}_admin_orders", $this->template->id);

        // 모듈 캐시 무효화
        $this->resolverService->clearResolutionCacheByModule($moduleIdentifier);

        // 캐시가 삭제되었는지 확인
        $cacheKey1 = "layout_resolver.{$this->template->id}.{$moduleIdentifier}_admin_products";
        $cacheKey2 = "layout_resolver.{$this->template->id}.{$moduleIdentifier}_admin_orders";
        $this->assertNull(Cache::get($cacheKey1));
        $this->assertNull(Cache::get($cacheKey2));
    }

    /**
     * 오버라이드 여부 확인 테스트
     */
    public function test_is_overridden_returns_true_when_override_exists(): void
    {
        $layoutName = 'sirsoft-sample_admin_list';

        // 모듈 레이아웃
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-sample',
        ]);

        // 오버라이드 없음
        $this->assertFalse($this->resolverService->isOverridden($layoutName, $this->template->id));

        // 템플릿 오버라이드 추가
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        // 오버라이드 있음
        $this->assertTrue($this->resolverService->isOverridden($layoutName, $this->template->id));
    }

    /**
     * 오버라이드된 레이아웃 목록 조회 테스트
     */
    public function test_get_overridden_layouts_returns_all_overrides(): void
    {
        // 모듈 레이아웃들
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'module_layout_1',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'module_layout_2',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        // 오버라이드 1개만 추가
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'module_layout_1',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $overriddenLayouts = $this->resolverService->getOverriddenLayouts($this->template->id);

        $this->assertCount(1, $overriddenLayouts);
        $this->assertEquals('module_layout_1', $overriddenLayouts->first()->name);
    }

    /**
     * 특정 모듈의 오버라이드된 레이아웃 조회 테스트
     */
    public function test_get_module_layout_overrides(): void
    {
        $moduleIdentifier = 'sirsoft-test';

        // 모듈 레이아웃 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => "{$moduleIdentifier}_layout_1",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => "{$moduleIdentifier}_layout_2",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 오버라이드 추가
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => "{$moduleIdentifier}_layout_1",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $moduleOverrides = $this->resolverService->getModuleLayoutOverrides($moduleIdentifier, $this->template->id);

        $this->assertCount(1, $moduleOverrides);
        $this->assertEquals("{$moduleIdentifier}_layout_1", $moduleOverrides->first()->name);
    }

    /**
     * 캐시 통계 계산 테스트
     */
    public function test_cache_stats_calculation(): void
    {
        $layoutName = 'stats-test-layout';

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        // 초기화
        $this->resolverService->resetCacheStats();

        // 5번 호출 (1 미스 + 4 히트)
        for ($i = 0; $i < 5; $i++) {
            $this->resolverService->resolve($layoutName, $this->template->id);
        }

        $stats = $this->resolverService->getCacheStats();

        $this->assertEquals(4, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(80.0, $stats['hit_rate']);
    }

    /**
     * 캐시에 null 결과도 저장되는지 테스트
     */
    public function test_resolve_caches_null_result(): void
    {
        $layoutName = 'non-existent-layout';

        // 통계 초기화
        $this->resolverService->resetCacheStats();

        // 첫 번째 호출 (캐시 미스)
        $result1 = $this->resolverService->resolve($layoutName, $this->template->id);
        $this->assertNull($result1);

        // 두 번째 호출 (캐시 히트 - null이 캐시됨)
        $result2 = $this->resolverService->resolve($layoutName, $this->template->id);
        $this->assertNull($result2);

        $stats = $this->resolverService->getCacheStats();
        $this->assertEquals(1, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
    }

    /**
     * version_constraint가 없는 오버라이드는 항상 적용됨
     */
    public function test_override_without_version_constraint_is_always_applied(): void
    {
        $layoutName = 'sirsoft-sample.admin_index';

        // 모듈 기본 레이아웃
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'ModuleComponent']]]],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-sample',
        ]);

        // version_constraint 없는 오버라이드
        $override = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'OverrideComponent']]]],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        $this->assertNotNull($resolved);
        $this->assertEquals($override->id, $resolved->id);
    }

    /**
     * version_constraint 호환 시 오버라이드 적용됨
     */
    public function test_override_with_compatible_version_constraint_is_applied(): void
    {
        $layoutName = 'sirsoft-ver-test.admin_index';
        $moduleIdentifier = 'sirsoft-ver-test';

        // 모듈 레코드 생성 (버전 1.2.0)
        Module::create([
            'identifier' => $moduleIdentifier,
            'vendor' => 'sirsoft',
            'name' => ['ko' => '버전 테스트', 'en' => 'Version Test'],
            'version' => '1.2.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 모듈 기본 레이아웃
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'ModuleComponent']]]],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 호환 버전 제약 오버라이드 (>=1.0.0)
        $override = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => [
                'version_constraint' => '>=1.0.0',
                'slots' => ['content' => [['component' => 'OverrideComponent']]],
            ],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        $this->assertNotNull($resolved);
        $this->assertEquals($override->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Template, $resolved->source_type);
    }

    /**
     * version_constraint 비호환 시 모듈 기본 레이아웃으로 폴백
     */
    public function test_override_with_incompatible_version_falls_back_to_module(): void
    {
        $layoutName = 'sirsoft-fallback.admin_index';
        $moduleIdentifier = 'sirsoft-fallback';

        // 모듈 레코드 생성 (버전 0.9.0)
        Module::create([
            'identifier' => $moduleIdentifier,
            'vendor' => 'sirsoft',
            'name' => ['ko' => '폴백 테스트', 'en' => 'Fallback Test'],
            'version' => '0.9.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 모듈 기본 레이아웃
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'ModuleComponent']]]],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 비호환 버전 제약 오버라이드 (>=1.0.0 이지만 모듈은 0.9.0)
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => [
                'version_constraint' => '>=1.0.0',
                'slots' => ['content' => [['component' => 'OverrideComponent']]],
            ],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        // 모듈 기본 레이아웃으로 폴백
        $this->assertNotNull($resolved);
        $this->assertEquals($moduleLayout->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Module, $resolved->source_type);
    }

    /**
     * 모듈 버전 정보 없을 때 오버라이드 적용됨 (하위 호환)
     */
    public function test_override_applied_when_module_version_unknown(): void
    {
        $layoutName = 'sirsoft-unknown.admin_index';

        // 모듈 레코드 없음 (미설치)

        // 모듈 기본 레이아웃
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-unknown',
        ]);

        // version_constraint가 있지만 모듈 버전 정보 없음
        $override = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => [
                'version_constraint' => '>=1.0.0',
                'slots' => [],
            ],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        // 버전 정보 없으면 호환으로 처리
        $this->assertNotNull($resolved);
        $this->assertEquals($override->id, $resolved->id);
    }

    /**
     * 잘못된 version_constraint 포맷 시 오버라이드 적용됨 (안전 폴백)
     */
    public function test_override_applied_when_version_constraint_invalid(): void
    {
        $layoutName = 'sirsoft-invalid.admin_index';
        $moduleIdentifier = 'sirsoft-invalid';

        Module::create([
            'identifier' => $moduleIdentifier,
            'vendor' => 'sirsoft',
            'name' => ['ko' => '잘못된 제약', 'en' => 'Invalid Constraint'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 잘못된 제약 조건
        $override = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => [
                'version_constraint' => '>>>invalid<<<',
                'slots' => [],
            ],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        // 파싱 실패 시 호환으로 처리 (안전 폴백)
        $this->assertNotNull($resolved);
        $this->assertEquals($override->id, $resolved->id);
    }

    /**
     * DOT 포맷 layout_name으로 오버라이드 end-to-end 매칭 테스트
     */
    public function test_dot_format_layout_name_override_matching(): void
    {
        $layoutName = 'sirsoft-sample.admin_index';

        // 모듈 기본 레이아웃 (DOT 포맷)
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'ModuleComponent']]]],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'sirsoft-sample',
        ]);

        // 오버라이드 (DOT 포맷 매칭)
        $override = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['slots' => ['content' => [['component' => 'OverrideComponent']]]],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin-template',
        ]);

        $resolved = $this->resolverService->resolve($layoutName, $this->template->id);

        $this->assertNotNull($resolved);
        $this->assertEquals($override->id, $resolved->id);
    }

    /**
     * 다중 템플릿 간 캐시 분리 테스트
     */
    public function test_cache_separation_between_templates(): void
    {
        $layoutName = 'shared-layout';

        // 두 번째 템플릿 생성
        $template2 = Template::create([
            'identifier' => 'second-template',
            'vendor' => 'test',
            'name' => ['ko' => '두 번째 템플릿', 'en' => 'Second Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 각 템플릿에 동일한 이름의 레이아웃 생성
        $layout1 = TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => $layoutName,
            'content' => ['version' => '1'],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        $layout2 = TemplateLayout::create([
            'template_id' => $template2->id,
            'name' => $layoutName,
            'content' => ['version' => '2'],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => 'test-module',
        ]);

        // 각 템플릿에서 해석
        $resolved1 = $this->resolverService->resolve($layoutName, $this->template->id);
        $resolved2 = $this->resolverService->resolve($layoutName, $template2->id);

        // 서로 다른 레이아웃이 반환되어야 함
        $this->assertNotEquals($resolved1->id, $resolved2->id);
        $this->assertEquals('1', $resolved1->content['version']);
        $this->assertEquals('2', $resolved2->content['version']);
    }
}
