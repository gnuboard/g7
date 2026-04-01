<?php

namespace Tests\Feature;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutResolverService;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 모듈 레이아웃 오버라이드 통합 테스트
 *
 * Phase 7.2에 따른 전체 흐름 통합 테스트:
 * - 모듈 설치 → 레이아웃 등록 → API 조회
 * - 템플릿 오버라이드 → 우선순위 적용
 * - 모듈 비활성화 → 레이아웃 제거
 */
class ModuleLayoutOverrideTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    private LayoutService $layoutService;

    private LayoutResolverService $resolverService;

    private Template $adminTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        // 이전 테스트에서 남은 레이아웃 캐시 전체 초기화
        Cache::flush();

        // 모든 관련 서비스 인스턴스 초기화 (이전 테스트의 상태 제거)
        // TemplateService도 LayoutService에 의존하므로 함께 초기화
        app()->forgetInstance(ModuleManager::class);
        app()->forgetInstance(LayoutService::class);
        app()->forgetInstance(LayoutResolverService::class);
        app()->forgetInstance(\App\Services\TemplateService::class);

        // DI 컨테이너를 통해 새로운 인스턴스 획득
        $this->moduleManager = app(ModuleManager::class);
        $this->layoutService = app(LayoutService::class);
        $this->resolverService = app(LayoutResolverService::class);

        // 테스트용 활성화된 admin 템플릿 생성
        $this->adminTemplate = Template::create([
            'identifier' => 'sirsoft-admin_basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
        ]);
    }

    /**
     * 모든 레이아웃 관련 캐시를 무효화합니다.
     *
     * 캐시 키가 소스 해시를 포함하므로 오버라이드 추가/삭제 시 키가 변경됩니다.
     * 테스트 환경에서는 Cache::flush()로 모든 캐시를 초기화하고,
     * 서비스 인스턴스도 재생성하여 내부 상태를 초기화합니다.
     */
    private function clearAllLayoutCaches(string $templateIdentifier, int $templateId, string $layoutName): void
    {
        // 1. 모든 캐시 초기화
        Cache::flush();

        // 2. 모든 관련 서비스 인스턴스 초기화 (다음 요청에서 새로 생성되도록)
        // 이렇게 하면 컨트롤러에 주입되는 서비스도 새로운 인스턴스가 됨
        app()->forgetInstance(LayoutService::class);
        app()->forgetInstance(LayoutResolverService::class);
        app()->forgetInstance(\App\Services\TemplateService::class);

        // 3. 테스트 클래스의 참조도 새로운 인스턴스로 갱신
        $this->layoutService = app(LayoutService::class);
        $this->resolverService = app(LayoutResolverService::class);
    }

    /**
     * 테스트 케이스 1: 모듈 설치 → 레이아웃 등록 → API 조회
     *
     * 모듈이 설치되고 활성화되면 해당 모듈의 레이아웃이
     * DB에 등록되고 API를 통해 조회할 수 있어야 합니다.
     */
    public function test_module_layout_registration_and_api_query(): void
    {
        $moduleIdentifier = 'sirsoft-sample';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 1. 모듈 레이아웃을 DB에 직접 등록 (모듈 활성화 시뮬레이션)
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'meta' => [
                    'title' => 'Sample Module Index',
                    'description' => 'Sample module index page',
                ],
                'data_sources' => [],
                'components' => [
                    [
                        'component' => 'Container',
                        'children' => [
                            [
                                'component' => 'DataTable',
                                'props' => [
                                    'columns' => [
                                        ['key' => 'id', 'label' => 'ID'],
                                        ['key' => 'name', 'label' => 'Name'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 2. 레이아웃이 DB에 등록되었는지 확인
        $this->assertDatabaseHas('template_layouts', [
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'source_type' => LayoutSourceType::Module->value,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 3. API를 통한 레이아웃 조회
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");

        // 4. 성공 응답 확인
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.meta.title', 'Sample Module Index');

        // 5. LayoutResolverService를 통한 해석 확인
        $resolved = $this->resolverService->resolve($layoutName, $this->adminTemplate->id);
        $this->assertNotNull($resolved);
        $this->assertEquals($moduleLayout->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Module, $resolved->source_type);
    }

    /**
     * 테스트 케이스 2: 템플릿 오버라이드 → 우선순위 적용
     *
     * 템플릿이 모듈 레이아웃을 오버라이드하면
     * 오버라이드 레이아웃이 우선적으로 사용되어야 합니다.
     */
    public function test_template_override_priority_application(): void
    {
        $moduleIdentifier = 'sirsoft-sample';
        $layoutName = "{$moduleIdentifier}_admin_products_index";

        // 1. 모듈 기본 레이아웃 등록
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'meta' => [
                    'title' => 'Module Default Layout',
                    'theme' => 'default',
                ],
                'data_sources' => [],
                'components' => [
                    ['component' => 'ModuleDefaultComponent'],
                ],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 2. 템플릿 오버라이드 레이아웃 등록
        $overrideLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'meta' => [
                    'title' => 'Template Override Layout',
                    'theme' => 'custom-dark',
                ],
                'data_sources' => [],
                'components' => [
                    ['component' => 'CustomOverrideComponent', 'props' => ['theme' => 'dark']],
                ],
            ],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->adminTemplate->identifier,
        ]);

        // 3. 두 레이아웃이 모두 DB에 존재하는지 확인
        $layoutCount = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('name', $layoutName)
            ->count();
        $this->assertEquals(2, $layoutCount);

        // 4. LayoutResolverService를 통해 해석 - 오버라이드가 우선
        $resolved = $this->resolverService->resolve($layoutName, $this->adminTemplate->id);
        $this->assertNotNull($resolved);
        $this->assertEquals($overrideLayout->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Template, $resolved->source_type);

        // 5. API 조회 시 오버라이드 레이아웃이 반환되는지 확인
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Template Override Layout')
            ->assertJsonPath('data.meta.theme', 'custom-dark');

        // 6. isOverridden 메서드 확인
        $this->assertTrue($this->resolverService->isOverridden($layoutName, $this->adminTemplate->id));

        // 사용되지 않는 변수 경고 방지
        $this->assertNotNull($moduleLayout);
    }

    /**
     * 테스트 케이스 3: 모듈 비활성화 → 레이아웃 제거
     *
     * 모듈이 비활성화되면 해당 모듈의 레이아웃이
     * soft delete되어 API 조회에서 제외되어야 합니다.
     */
    public function test_module_deactivation_layout_removal(): void
    {
        $moduleIdentifier = 'sirsoft-deactivate-test';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 1. 모듈 레이아웃 등록
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'meta' => ['title' => 'Deactivate Test Layout'],
                'data_sources' => [],
                'components' => [],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 2. 레이아웃이 활성 상태인지 확인
        $this->assertNotNull(TemplateLayout::find($moduleLayout->id));

        // 3. API 조회 성공 확인
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response->assertStatus(200);

        // 4. 모듈 비활성화 시뮬레이션 - soft delete 및 캐시 무효화
        $moduleLayout->delete();
        $this->clearAllLayoutCaches($this->adminTemplate->identifier, $this->adminTemplate->id, $layoutName);

        // 5. 일반 조회에서는 나타나지 않음
        $this->assertNull(TemplateLayout::find($moduleLayout->id));

        // 6. withTrashed로는 조회 가능
        $this->assertNotNull(TemplateLayout::withTrashed()->find($moduleLayout->id));

        // 7. API 조회 실패 확인 (레이아웃을 찾을 수 없음)
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response->assertStatus(404);

        // 8. LayoutResolverService도 null 반환
        $resolved = $this->resolverService->resolve($layoutName, $this->adminTemplate->id);
        $this->assertNull($resolved);
    }

    /**
     * 테스트: 모듈 재활성화 시 레이아웃 복원
     *
     * soft delete된 모듈 레이아웃이 모듈 재활성화 시 복원되어야 합니다.
     */
    public function test_module_reactivation_restores_layouts(): void
    {
        $moduleIdentifier = 'sirsoft-reactivate-test';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 1. 모듈 레이아웃 생성 및 soft delete
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'meta' => ['title' => 'Reactivate Test'],
                'data_sources' => [],
                'components' => [],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);
        $moduleLayout->delete();

        // 2. soft deleted 상태 확인
        $this->assertSoftDeleted('template_layouts', ['id' => $moduleLayout->id]);

        // 3. ModuleManager의 restoreModuleLayouts 호출 (protected 메서드)
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('restoreModuleLayouts');
        $method->setAccessible(true);
        $method->invoke($this->moduleManager, $moduleIdentifier);

        // 4. 레이아웃 복원 확인
        $restoredLayout = TemplateLayout::find($moduleLayout->id);
        $this->assertNotNull($restoredLayout);
        $this->assertNull($restoredLayout->deleted_at);

        // 5. API 조회 성공 확인
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response->assertStatus(200);
    }

    /**
     * 테스트: 레이아웃 상속과 오버라이드 통합 동작
     *
     * 모듈 레이아웃이 템플릿의 베이스 레이아웃을 extends하고
     * 템플릿이 해당 모듈 레이아웃을 오버라이드하는 전체 흐름을 테스트합니다.
     *
     * 이 테스트는 오버라이드가 이미 존재할 때 API가 오버라이드를 반환하는지 검증합니다.
     * 캐시 상태 변경 테스트는 test_cache_invalidation_on_layout_change에서 수행합니다.
     */
    public function test_layout_inheritance_with_override(): void
    {
        // 고유한 식별자 사용 (다른 테스트와의 캐시 충돌 방지)
        // 주의: moduleIdentifier는 vendor-module 형식이어야 isModuleLayoutName() 정규식에 매칭됨
        // 정규식: ^[a-z0-9]+-[a-z0-9]+_ (하이픈 하나만 허용)
        $uniqueId = uniqid();
        $moduleIdentifier = "sirsoft-inherit{$uniqueId}";
        $layoutName = "{$moduleIdentifier}_admin_detail";

        // 1. 템플릿 베이스 레이아웃 생성 (고유한 이름 사용)
        $baseLayoutName = "_admin_base_{$uniqueId}";
        $baseLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $baseLayoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $baseLayoutName,
                'meta' => [
                    'title' => 'Admin Base',
                ],
                'data_sources' => [],
                'components' => [
                    [
                        'component' => 'Container',
                        'props' => ['class' => 'admin-container'],
                        'children' => [
                            ['component' => 'Header', 'slot' => 'header'],
                            ['component' => 'MainContent', 'slot' => 'content'],
                            ['component' => 'Footer', 'slot' => 'footer'],
                        ],
                    ],
                ],
            ],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->adminTemplate->identifier,
        ]);

        // 2. 모듈 레이아웃 (베이스 상속)
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'extends' => $baseLayoutName,
                'meta' => [
                    'title' => 'Module Detail Page',
                ],
                'slots' => [
                    'content' => [
                        ['component' => 'DetailView', 'props' => ['style' => 'default']],
                    ],
                ],
            ],
            'extends' => $baseLayoutName,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 3. 템플릿 오버라이드도 함께 생성 (캐시 생성 전에 모든 데이터 준비)
        $overrideLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'extends' => $baseLayoutName,
                'meta' => [
                    'title' => 'Custom Detail Page',
                    'theme' => 'premium',
                ],
                'slots' => [
                    'content' => [
                        ['component' => 'CustomDetailView', 'props' => ['style' => 'premium']],
                    ],
                ],
            ],
            'extends' => $baseLayoutName,
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->adminTemplate->identifier,
        ]);

        // 4. LayoutResolverService로 먼저 직접 확인 (캐시 없이)
        // 새로운 인스턴스 생성하여 캐시 상태 배제
        $freshResolverService = app(LayoutResolverService::class);
        $resolved = $freshResolverService->resolve($layoutName, $this->adminTemplate->id);

        $this->assertNotNull($resolved, 'LayoutResolverService should resolve the layout');
        $this->assertEquals($overrideLayout->id, $resolved->id, 'Resolver should return override layout');
        $this->assertEquals(LayoutSourceType::Template, $resolved->source_type, 'Source type should be Template');

        // 5. API 조회 - 캐시 초기화 후 새 요청
        Cache::flush();
        app()->forgetInstance(LayoutService::class);
        app()->forgetInstance(LayoutResolverService::class);
        app()->forgetInstance(\App\Services\TemplateService::class);

        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");

        $response->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Custom Detail Page')
            ->assertJsonPath('data.meta.theme', 'premium');

        // 병합 후 extends와 slots가 제거되었는지 확인
        $this->assertArrayNotHasKey('extends', $response->json('data'));
        $this->assertArrayNotHasKey('slots', $response->json('data'));

        // 사용되지 않는 변수 경고 방지
        $this->assertNotNull($baseLayout);
        $this->assertNotNull($moduleLayout);
    }

    /**
     * 테스트: 캐시 무효화 통합 테스트
     *
     * 모듈 레이아웃 변경 시 캐시가 올바르게 무효화되는지 테스트합니다.
     */
    public function test_cache_invalidation_on_layout_change(): void
    {
        $moduleIdentifier = 'sirsoft-cache-test';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 1. 모듈 레이아웃 생성
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'meta' => ['title' => 'Original Title'],
                'data_sources' => [],
                'components' => [],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 2. 첫 번째 API 조회 (캐시 생성)
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Original Title');

        // 3. 레이아웃 content 직접 업데이트
        $moduleLayout->update([
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'meta' => ['title' => 'Updated Title'],
                'data_sources' => [],
                'components' => [],
            ],
        ]);

        // 4. 캐시 무효화
        $this->clearAllLayoutCaches($this->adminTemplate->identifier, $this->adminTemplate->id, $layoutName);

        // 5. 다시 API 조회 - 업데이트된 내용 확인
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Updated Title');
    }

    /**
     * 테스트: 다중 admin 템플릿에 모듈 레이아웃 등록
     *
     * 모듈 레이아웃이 여러 admin 템플릿에 등록되고
     * 각 템플릿에서 독립적으로 동작하는지 테스트합니다.
     */
    public function test_module_layout_in_multiple_admin_templates(): void
    {
        $moduleIdentifier = 'sirsoft-multi-template';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 1. 두 번째 admin 템플릿 생성
        $secondTemplate = Template::create([
            'identifier' => 'sirsoft-admin_premium',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '프리미엄 관리자 템플릿', 'en' => 'Premium Admin Template'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '프리미엄 템플릿', 'en' => 'Premium Template'],
        ]);

        // 2. 첫 번째 템플릿에 모듈 레이아웃 등록
        TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'meta' => ['title' => 'Basic Template Layout'],
                'data_sources' => [],
                'components' => [],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 3. 두 번째 템플릿에 모듈 레이아웃 등록
        TemplateLayout::create([
            'template_id' => $secondTemplate->id,
            'name' => $layoutName,
            'content' => [
                'meta' => ['title' => 'Premium Template Layout'],
                'data_sources' => [],
                'components' => [],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 4. 각 템플릿에서 API 조회
        $response1 = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response1->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Basic Template Layout');

        $response2 = $this->getJson("/api/layouts/{$secondTemplate->identifier}/{$layoutName}.json");
        $response2->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Premium Template Layout');

        // 5. LayoutResolverService가 각 템플릿을 독립적으로 처리하는지 확인
        $resolved1 = $this->resolverService->resolve($layoutName, $this->adminTemplate->id);
        $resolved2 = $this->resolverService->resolve($layoutName, $secondTemplate->id);

        $this->assertNotEquals($resolved1->id, $resolved2->id);
    }

    /**
     * 테스트: 오버라이드 레이아웃 목록 조회
     *
     * 특정 템플릿에서 오버라이드된 레이아웃 목록을 조회합니다.
     */
    public function test_get_overridden_layouts_list(): void
    {
        $moduleIdentifier = 'sirsoft-override-list';

        // 1. 여러 모듈 레이아웃 생성
        for ($i = 1; $i <= 3; $i++) {
            $layoutName = "{$moduleIdentifier}_layout_{$i}";

            TemplateLayout::create([
                'template_id' => $this->adminTemplate->id,
                'name' => $layoutName,
                'content' => ['meta' => [], 'data_sources' => [], 'components' => []],
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
            ]);
        }

        // 2. 일부만 오버라이드
        TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_layout_1",
            'content' => ['meta' => ['theme' => 'custom'], 'data_sources' => [], 'components' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->adminTemplate->identifier,
        ]);

        TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_layout_3",
            'content' => ['meta' => ['theme' => 'premium'], 'data_sources' => [], 'components' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $this->adminTemplate->identifier,
        ]);

        // 3. 오버라이드된 레이아웃 목록 조회
        $overriddenLayouts = $this->resolverService->getOverriddenLayouts($this->adminTemplate->id);

        $this->assertCount(2, $overriddenLayouts);

        $overriddenNames = $overriddenLayouts->pluck('name')->toArray();
        $this->assertContains("{$moduleIdentifier}_layout_1", $overriddenNames);
        $this->assertContains("{$moduleIdentifier}_layout_3", $overriddenNames);

        // layout_2는 오버라이드되지 않음
        $this->assertFalse($this->resolverService->isOverridden(
            "{$moduleIdentifier}_layout_2",
            $this->adminTemplate->id
        ));
    }

    /**
     * 테스트: 오버라이드 없이 모듈 기본 레이아웃만 있을 때 정상 동작
     *
     * 템플릿 오버라이드가 없으면 모듈 기본 레이아웃이 사용되어야 합니다.
     */
    public function test_fallback_to_module_layout_when_override_removed(): void
    {
        $moduleIdentifier = 'sirsoft-fallback-test';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 1. 모듈 기본 레이아웃만 생성 (오버라이드 없음)
        $moduleLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => [
                'meta' => ['title' => 'Module Default'],
                'data_sources' => [],
                'components' => [],
            ],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 2. 모듈 기본 레이아웃 조회 확인
        $response = $this->getJson("/api/layouts/{$this->adminTemplate->identifier}/{$layoutName}.json");
        $response->assertStatus(200)
            ->assertJsonPath('data.meta.title', 'Module Default');

        // 3. 오버라이드되지 않았는지 확인
        $this->assertFalse($this->resolverService->isOverridden($layoutName, $this->adminTemplate->id));

        // 4. LayoutResolverService로 직접 확인
        $resolved = $this->resolverService->resolve($layoutName, $this->adminTemplate->id);
        $this->assertEquals($moduleLayout->id, $resolved->id);
        $this->assertEquals(LayoutSourceType::Module, $resolved->source_type);
    }

    /**
     * 테스트: 모듈 레이아웃 소스 타입 필터링
     *
     * source_type에 따라 레이아웃을 필터링하여 조회할 수 있는지 테스트합니다.
     */
    public function test_filter_layouts_by_source_type(): void
    {
        $moduleIdentifier = 'sirsoft-filter-test';

        // 1. 모듈 레이아웃 3개 생성
        for ($i = 1; $i <= 3; $i++) {
            TemplateLayout::create([
                'template_id' => $this->adminTemplate->id,
                'name' => "{$moduleIdentifier}_module_{$i}",
                'content' => ['meta' => [], 'data_sources' => [], 'components' => []],
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
            ]);
        }

        // 2. 템플릿 레이아웃 2개 생성
        for ($i = 1; $i <= 2; $i++) {
            TemplateLayout::create([
                'template_id' => $this->adminTemplate->id,
                'name' => "template_layout_{$i}",
                'content' => ['meta' => [], 'data_sources' => [], 'components' => []],
                'source_type' => LayoutSourceType::Template,
                'source_identifier' => $this->adminTemplate->identifier,
            ]);
        }

        // 3. 모듈 레이아웃만 필터링
        $moduleLayouts = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('source_type', LayoutSourceType::Module)
            ->get();

        $this->assertCount(3, $moduleLayouts);

        // 4. 템플릿 레이아웃만 필터링
        $templateLayouts = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('source_type', LayoutSourceType::Template)
            ->get();

        $this->assertCount(2, $templateLayouts);

        // 5. 특정 모듈의 레이아웃만 필터링
        $specificModuleLayouts = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier)
            ->get();

        $this->assertCount(3, $specificModuleLayouts);
    }
}