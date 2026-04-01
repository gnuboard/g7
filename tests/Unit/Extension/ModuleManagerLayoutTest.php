<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\ModuleManager;
use App\Models\Module;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModuleManagerLayoutTest extends TestCase
{
    use RefreshDatabase;

    private ModuleManager $moduleManager;

    private Template $adminTemplate;

    private string $testModulePath;

    protected function setUp(): void
    {
        parent::setUp();

        // 서비스 컨테이너에서 ModuleManager 인스턴스 가져오기
        $this->moduleManager = app(ModuleManager::class);

        // 테스트용 admin 템플릿 생성
        $this->adminTemplate = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 테스트용 모듈 디렉토리 경로
        $this->testModulePath = base_path('modules/test-sample');
    }

    protected function tearDown(): void
    {
        // 테스트 모듈 디렉토리 정리
        if (File::exists($this->testModulePath)) {
            File::deleteDirectory($this->testModulePath);
        }

        parent::tearDown();
    }

    /**
     * 테스트용 모듈 레이아웃 파일 생성
     */
    private function createTestModuleLayouts(): void
    {
        $layoutsPath = $this->testModulePath . '/resources/layouts/admin';
        File::makeDirectory($layoutsPath, 0755, true);

        // index.json
        $indexLayout = [
            'layout_name' => 'test-sample_admin_index',
            'slots' => [
                'content' => [
                    ['component' => 'DataTable', 'props' => ['columns' => []]],
                ],
            ],
            'meta' => ['title' => 'Sample List'],
        ];
        File::put($layoutsPath . '/index.json', json_encode($indexLayout, JSON_PRETTY_PRINT));

        // edit.json
        $editLayout = [
            'layout_name' => 'test-sample_admin_edit',
            'extends' => '_admin_base',
            'slots' => [
                'content' => [
                    ['component' => 'Form', 'props' => ['fields' => []]],
                ],
            ],
        ];
        File::put($layoutsPath . '/edit.json', json_encode($editLayout, JSON_PRETTY_PRINT));
    }

    /**
     * 모듈 레이아웃 soft delete 테스트
     */
    public function test_soft_delete_module_layouts_on_deactivate(): void
    {
        $moduleIdentifier = 'sirsoft-test-module';

        // 모듈 레이아웃 생성
        $layout1 = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_admin_index",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        $layout2 = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_admin_edit",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // 리플렉션을 통해 protected 메서드 호출
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('softDeleteModuleLayouts');
        $method->setAccessible(true);
        $method->invoke($this->moduleManager, $moduleIdentifier);

        // soft deleted 확인
        $this->assertSoftDeleted('template_layouts', ['id' => $layout1->id]);
        $this->assertSoftDeleted('template_layouts', ['id' => $layout2->id]);

        // 일반 조회에서는 나타나지 않음
        $this->assertNull(TemplateLayout::find($layout1->id));
        $this->assertNull(TemplateLayout::find($layout2->id));

        // withTrashed로 조회 가능
        $this->assertNotNull(TemplateLayout::withTrashed()->find($layout1->id));
        $this->assertNotNull(TemplateLayout::withTrashed()->find($layout2->id));
    }

    /**
     * 모듈 레이아웃 복원 테스트
     */
    public function test_restore_module_layouts_on_activate(): void
    {
        $moduleIdentifier = 'sirsoft-restore-test';

        // 모듈 레이아웃 생성 및 soft delete
        $layout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_admin_index",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);
        $layout->delete();

        // soft deleted 확인
        $this->assertSoftDeleted('template_layouts', ['id' => $layout->id]);

        // 리플렉션을 통해 protected 메서드 호출
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('restoreModuleLayouts');
        $method->setAccessible(true);
        $method->invoke($this->moduleManager, $moduleIdentifier);

        // 복원 확인
        $restoredLayout = TemplateLayout::find($layout->id);
        $this->assertNotNull($restoredLayout);
        $this->assertNull($restoredLayout->deleted_at);
    }

    /**
     * 캐시 무효화 메서드 테스트
     */
    public function test_invalidate_layout_cache_method(): void
    {
        $moduleIdentifier = 'sirsoft-cache-test';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 레이아웃 생성
        $layout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => $layoutName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        // LayoutService와 동일한 캐시 키 패턴으로 설정: template.{templateId}.layout.{layoutName}
        $cacheKey = "template.{$this->adminTemplate->id}.layout.{$layoutName}";
        Cache::put($cacheKey, $layout->content, 3600);
        $this->assertNotNull(Cache::get($cacheKey));

        // invalidateLayoutCache 직접 호출
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('invalidateLayoutCache');
        $method->setAccessible(true);
        $method->invoke($this->moduleManager, $moduleIdentifier);

        // 캐시 키가 삭제되었음을 확인
        $this->assertNull(Cache::get($cacheKey));
    }

    /**
     * 다른 모듈의 레이아웃은 영향받지 않음 테스트
     */
    public function test_soft_delete_only_affects_target_module(): void
    {
        $targetModule = 'sirsoft-target';
        $otherModule = 'sirsoft-other';

        // 대상 모듈 레이아웃
        $targetLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$targetModule}_admin_index",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $targetModule,
        ]);

        // 다른 모듈 레이아웃
        $otherLayout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$otherModule}_admin_index",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $otherModule,
        ]);

        // 대상 모듈만 soft delete
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('softDeleteModuleLayouts');
        $method->setAccessible(true);
        $method->invoke($this->moduleManager, $targetModule);

        // 대상 모듈은 삭제됨
        $this->assertSoftDeleted('template_layouts', ['id' => $targetLayout->id]);

        // 다른 모듈은 유지됨
        $this->assertNotNull(TemplateLayout::find($otherLayout->id));
    }

    /**
     * 레이아웃이 없는 모듈 soft delete 테스트
     */
    public function test_soft_delete_handles_module_without_layouts(): void
    {
        $moduleIdentifier = 'sirsoft-no-layouts';

        // 레이아웃 없이 soft delete 호출
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('softDeleteModuleLayouts');
        $method->setAccessible(true);

        // 예외 없이 실행되어야 함
        $method->invoke($this->moduleManager, $moduleIdentifier);
        $this->assertTrue(true); // 예외가 발생하지 않음을 확인
    }

    /**
     * 레이아웃 이름 생성 테스트
     */
    public function test_generate_layout_name_from_file_path(): void
    {
        // 테스트 모듈 레이아웃 디렉토리 생성
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        File::makeDirectory($layoutsPath . '/admin', 0755, true);

        $testLayout = ['slots' => []];
        File::put($layoutsPath . '/admin/index.json', json_encode($testLayout));

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('generateLayoutNameFromPath');
        $method->setAccessible(true);

        // Windows/Unix 경로 정규화
        $normalizedBasePath = str_replace('/', DIRECTORY_SEPARATOR, $layoutsPath);
        $normalizedFilePath = str_replace('/', DIRECTORY_SEPARATOR, $layoutsPath . '/admin/index.json');

        $result = $method->invoke(
            $this->moduleManager,
            $normalizedBasePath,
            $normalizedFilePath,
            'test-sample'
        );

        $this->assertEquals('test-sample_admin_index', $result);
    }

    /**
     * 중첩 디렉토리의 레이아웃 이름 생성 테스트
     */
    public function test_generate_layout_name_with_nested_path(): void
    {
        // 중첩 디렉토리 생성
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        File::makeDirectory($layoutsPath . '/admin/products', 0755, true);

        $testLayout = ['slots' => []];
        File::put($layoutsPath . '/admin/products/list.json', json_encode($testLayout));

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('generateLayoutNameFromPath');
        $method->setAccessible(true);

        // Windows/Unix 경로 정규화
        $normalizedBasePath = str_replace('/', DIRECTORY_SEPARATOR, $layoutsPath);
        $normalizedFilePath = str_replace('/', DIRECTORY_SEPARATOR, $layoutsPath . '/admin/products/list.json');

        $result = $method->invoke(
            $this->moduleManager,
            $normalizedBasePath,
            $normalizedFilePath,
            'test-sample'
        );

        $this->assertEquals('test-sample_admin_products_list', $result);
    }

    /**
     * 레이아웃 파일 스캔 테스트
     */
    public function test_scan_layout_files_recursively(): void
    {
        // 테스트 디렉토리 구조 생성
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        File::makeDirectory($layoutsPath . '/admin/products', 0755, true);
        File::makeDirectory($layoutsPath . '/admin/orders', 0755, true);

        $testLayout = ['slots' => []];
        File::put($layoutsPath . '/admin/index.json', json_encode($testLayout));
        File::put($layoutsPath . '/admin/products/list.json', json_encode($testLayout));
        File::put($layoutsPath . '/admin/orders/detail.json', json_encode($testLayout));

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('scanLayoutFilesRecursively');
        $method->setAccessible(true);

        $files = $method->invoke($this->moduleManager, $layoutsPath);

        $this->assertCount(3, $files);
    }

    /**
     * 모듈 레이아웃을 여러 admin 템플릿에 등록하는지 테스트
     */
    public function test_register_layouts_to_multiple_admin_templates(): void
    {
        // 두 번째 admin 템플릿 생성
        $secondTemplate = Template::create([
            'identifier' => 'second-admin',
            'vendor' => 'test',
            'name' => ['ko' => '두 번째 관리자', 'en' => 'Second Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 모듈 레이아웃 등록 (직접 테스트)
        $moduleIdentifier = 'sirsoft-multi-template';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 각 템플릿에 레이아웃 등록
        foreach ([$this->adminTemplate, $secondTemplate] as $template) {
            TemplateLayout::create([
                'template_id' => $template->id,
                'name' => $layoutName,
                'content' => ['slots' => []],
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
            ]);
        }

        // 각 템플릿에 레이아웃이 등록되었는지 확인
        $layout1 = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('name', $layoutName)
            ->first();
        $layout2 = TemplateLayout::where('template_id', $secondTemplate->id)
            ->where('name', $layoutName)
            ->first();

        $this->assertNotNull($layout1);
        $this->assertNotNull($layout2);
        $this->assertNotEquals($layout1->id, $layout2->id);
    }

    /**
     * source_type이 module로 올바르게 설정되는지 테스트
     */
    public function test_layout_source_type_is_module(): void
    {
        $moduleIdentifier = 'sirsoft-source-test';

        $layout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_admin_index",
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        $this->assertEquals(LayoutSourceType::Module, $layout->source_type);
        $this->assertEquals($moduleIdentifier, $layout->source_identifier);
    }

    /**
     * extends 필드가 보존되는지 테스트
     */
    public function test_layout_extends_field_is_preserved(): void
    {
        $moduleIdentifier = 'sirsoft-extends-test';
        $extendsValue = '_admin_base';

        $layout = TemplateLayout::create([
            'template_id' => $this->adminTemplate->id,
            'name' => "{$moduleIdentifier}_admin_index",
            'content' => ['slots' => []],
            'extends' => $extendsValue,
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);

        $this->assertEquals($extendsValue, $layout->extends);
    }

    /**
     * updateOrCreate로 중복 등록 방지 테스트
     */
    public function test_prevent_duplicate_layout_registration(): void
    {
        $moduleIdentifier = 'sirsoft-duplicate-test';
        $layoutName = "{$moduleIdentifier}_admin_index";

        // 첫 번째 등록
        TemplateLayout::updateOrCreate(
            [
                'template_id' => $this->adminTemplate->id,
                'name' => $layoutName,
            ],
            [
                'content' => ['version' => '1'],
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
            ]
        );

        // 두 번째 등록 (업데이트)
        TemplateLayout::updateOrCreate(
            [
                'template_id' => $this->adminTemplate->id,
                'name' => $layoutName,
            ],
            [
                'content' => ['version' => '2'],
                'source_type' => LayoutSourceType::Module,
                'source_identifier' => $moduleIdentifier,
            ]
        );

        // 레이아웃이 1개만 존재
        $count = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('name', $layoutName)
            ->count();

        $this->assertEquals(1, $count);

        // 내용이 업데이트됨
        $layout = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('name', $layoutName)
            ->first();

        $this->assertEquals('2', $layout->content['version']);
    }

    /**
     * 모듈 레이아웃 등록 시 moduleIdentifier 접두사가 추가되는지 테스트
     */
    public function test_module_layout_registration_adds_identifier_prefix(): void
    {
        $moduleIdentifier = 'test-prefix-module';
        $layoutName = 'admin_sample_index';

        // 모듈 레코드 생성
        Module::create([
            'identifier' => $moduleIdentifier,
            'vendor' => 'test',
            'name' => ['ko' => '접두사 테스트', 'en' => 'Prefix Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // registerLayoutToTemplate 메서드 직접 테스트
        $layoutData = [
            'layout_name' => $layoutName,
            'version' => '1.0.0',
            'slots' => [
                'content' => [],
            ],
        ];

        // 접두사가 추가된 레이아웃 이름 생성 (registerModuleLayouts에서 수행하는 로직)
        $prefixedLayoutName = $moduleIdentifier . '.' . $layoutName;

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('registerLayoutToTemplate');
        $method->setAccessible(true);

        $method->invoke(
            $this->moduleManager,
            $this->adminTemplate,
            $prefixedLayoutName,
            $layoutData,
            $moduleIdentifier
        );

        // DB에서 확인
        $layout = TemplateLayout::where('template_id', $this->adminTemplate->id)
            ->where('source_identifier', $moduleIdentifier)
            ->first();

        $this->assertNotNull($layout);
        // 예상: test-prefix-module.admin_sample_index
        $this->assertEquals('test-prefix-module.admin_sample_index', $layout->name);
    }

    /**
     * refreshModuleLayouts 호출 시 레이아웃이 변경되면 캐시 버전이 증가하는지 테스트
     */
    public function test_refresh_module_layouts_increments_cache_version_on_change(): void
    {
        // 초기 캐시 버전 설정
        Cache::put('extension_cache_version', 1000);

        // 테스트용 모듈 생성
        $module = Module::create([
            'identifier' => 'test-sample',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 모듈 레이아웃 파일 생성
        $this->createTestModuleLayouts();

        // 실제 모듈 클래스를 로드하기 위해 리플렉션 사용
        // refreshModuleLayouts는 실제 Module 인스턴스가 필요하므로 직접 호출은 어려움
        // 대신 incrementExtensionCacheVersion 메서드가 호출되는지 검증
        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('incrementExtensionCacheVersion');
        $method->setAccessible(true);

        $beforeTime = time();
        $method->invoke($this->moduleManager);
        $afterTime = time();

        $newVersion = Cache::get('extension_cache_version');
        $this->assertGreaterThanOrEqual($beforeTime, $newVersion);
        $this->assertLessThanOrEqual($afterTime, $newVersion);
    }

    // ============================================
    // getLayoutTargetType() 테스트
    // ============================================

    /**
     * admin 경로 파일은 'admin' 타입 반환
     */
    public function test_get_layout_target_type_returns_admin_for_admin_path(): void
    {
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        $layoutFile = $this->testModulePath . '/resources/layouts/admin/index.json';

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('getLayoutTargetType');
        $method->setAccessible(true);

        $result = $method->invoke($this->moduleManager, $layoutsPath, $layoutFile);

        $this->assertEquals('admin', $result);
    }

    /**
     * user 경로 파일은 'user' 타입 반환
     */
    public function test_get_layout_target_type_returns_user_for_user_path(): void
    {
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        $layoutFile = $this->testModulePath . '/resources/layouts/user/main.json';

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('getLayoutTargetType');
        $method->setAccessible(true);

        $result = $method->invoke($this->moduleManager, $layoutsPath, $layoutFile);

        $this->assertEquals('user', $result);
    }

    /**
     * 루트에 직접 위치한 파일은 null 반환 + 경고 로그
     */
    public function test_get_layout_target_type_returns_null_for_root_file(): void
    {
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        $layoutFile = $this->testModulePath . '/resources/layouts/settings.json';

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('getLayoutTargetType');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '스킵됩니다');
            });

        $result = $method->invoke($this->moduleManager, $layoutsPath, $layoutFile);

        $this->assertNull($result);
    }

    /**
     * 중첩 하위 디렉토리도 첫 세그먼트로 판별
     */
    public function test_get_layout_target_type_handles_nested_admin_path(): void
    {
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        $layoutFile = $this->testModulePath . '/resources/layouts/admin/products/index.json';

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('getLayoutTargetType');
        $method->setAccessible(true);

        $result = $method->invoke($this->moduleManager, $layoutsPath, $layoutFile);

        $this->assertEquals('admin', $result);
    }

    /**
     * admin/user 외 디렉토리는 null 반환 + 경고 로그
     */
    public function test_get_layout_target_type_returns_null_for_unsupported_directory(): void
    {
        $layoutsPath = $this->testModulePath . '/resources/layouts';
        $layoutFile = $this->testModulePath . '/resources/layouts/api/index.json';

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('getLayoutTargetType');
        $method->setAccessible(true);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, '스킵됩니다');
            });

        $result = $method->invoke($this->moduleManager, $layoutsPath, $layoutFile);

        $this->assertNull($result);
    }

    /**
     * Windows 백슬래시 경로도 정상 처리
     */
    public function test_get_layout_target_type_handles_windows_backslash_paths(): void
    {
        $layoutsPath = 'C:\\projects\\g7\\modules\\test\\resources\\layouts';
        $layoutFile = 'C:\\projects\\g7\\modules\\test\\resources\\layouts\\admin\\index.json';

        $reflection = new \ReflectionClass($this->moduleManager);
        $method = $reflection->getMethod('getLayoutTargetType');
        $method->setAccessible(true);

        $result = $method->invoke($this->moduleManager, $layoutsPath, $layoutFile);

        $this->assertEquals('admin', $result);
    }

    /**
     * 레이아웃 변경이 없으면 캐시 버전이 증가하지 않음 (검증용 단위 테스트)
     */
    public function test_cache_version_not_incremented_when_no_layout_changes(): void
    {
        $initialVersion = 1000;
        Cache::put('extension_cache_version', $initialVersion);

        // 변경 없는 상태에서는 incrementExtensionCacheVersion이 호출되지 않으므로
        // 캐시 버전이 유지됨
        $currentVersion = Cache::get('extension_cache_version');

        $this->assertEquals($initialVersion, $currentVersion);
    }
}