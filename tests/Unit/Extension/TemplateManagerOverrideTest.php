<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TemplateManagerOverrideTest extends TestCase
{
    use RefreshDatabase;

    private TemplateManager $templateManager;

    private string $testTemplatePath;

    protected function setUp(): void
    {
        parent::setUp();
        // 서비스 컨테이너에서 TemplateManager 인스턴스 가져오기
        $this->templateManager = app(TemplateManager::class);

        // 테스트용 템플릿 경로
        $this->testTemplatePath = base_path('templates/test-admin');
    }

    protected function tearDown(): void
    {
        // 테스트 템플릿 디렉토리 정리
        if (File::exists($this->testTemplatePath)) {
            File::deleteDirectory($this->testTemplatePath);
        }

        parent::tearDown();
    }

    /**
     * 테스트용 템플릿 구조 생성
     */
    private function createTestTemplateStructure(): void
    {
        // 기본 디렉토리 생성
        File::makeDirectory($this->testTemplatePath . '/layouts/overrides/sirsoft-sample', 0755, true);

        // template.json 생성
        $templateJson = [
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'description' => ['ko' => '테스트용 템플릿', 'en' => 'Test template'],
        ];
        File::put($this->testTemplatePath . '/template.json', json_encode($templateJson, JSON_PRETTY_PRINT));

        // 기본 레이아웃 생성
        $baseLayout = [
            'layout_name' => '_admin_base',
            'meta' => ['title' => 'Admin Base'],
            'components' => [['component' => 'Container', 'children' => []]],
        ];
        File::put($this->testTemplatePath . '/layouts/_admin_base.json', json_encode($baseLayout, JSON_PRETTY_PRINT));

        // 오버라이드 레이아웃 생성
        $overrideLayout = [
            'layout_name' => 'sirsoft-sample_admin_index',
            'extends' => '_admin_base',
            'slots' => [
                'content' => [
                    ['component' => 'CustomDataTable', 'props' => ['theme' => 'dark']],
                ],
            ],
        ];
        File::put(
            $this->testTemplatePath . '/layouts/overrides/sirsoft-sample/admin_index.json',
            json_encode($overrideLayout, JSON_PRETTY_PRINT)
        );
    }

    /**
     * 오버라이드 레이아웃 등록 테스트
     */
    public function test_register_layout_overrides(): void
    {
        $this->createTestTemplateStructure();

        // 템플릿 로드
        $this->templateManager->loadTemplates();

        // 템플릿 레코드 생성
        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 리플렉션을 통해 protected 메서드 호출
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('registerLayoutOverrides');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, 'test-admin', $template->id);

        // 오버라이드 레이아웃이 등록되었는지 확인
        $override = TemplateLayout::where('template_id', $template->id)
            ->where('name', 'sirsoft-sample_admin_index')
            ->first();

        $this->assertNotNull($override);
        $this->assertEquals(LayoutSourceType::Template, $override->source_type);
        $this->assertEquals('test-admin', $override->source_identifier);
        $this->assertEquals('_admin_base', $override->extends);
    }

    /**
     * 오버라이드 레이아웃 content 추출 테스트
     */
    public function test_extract_layout_content(): void
    {
        $layoutData = [
            'layout_name' => 'test_layout',
            'extends' => '_base',
            'slots' => ['content' => [['component' => 'Test']]],
            'meta' => ['title' => 'Test'],
            'data_sources' => [['id' => 'ds1', 'endpoint' => '/api/test']],
            'version' => '1.0',
        ];

        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('extractLayoutContent');
        $method->setAccessible(true);

        $content = $method->invoke($this->templateManager, $layoutData);

        $this->assertArrayHasKey('slots', $content);
        $this->assertArrayHasKey('meta', $content);
        $this->assertArrayHasKey('data_sources', $content);
        $this->assertArrayHasKey('version', $content);
        $this->assertArrayNotHasKey('layout_name', $content);
        $this->assertArrayNotHasKey('extends', $content);
    }

    /**
     * 오버라이드 레이아웃 이름 생성 테스트
     */
    public function test_generate_override_layout_name(): void
    {
        $basePath = $this->testTemplatePath . '/layouts/overrides/sirsoft-sample';
        $filePath = $basePath . '/admin_index.json';

        // Windows/Unix 경로 정규화
        $normalizedBasePath = str_replace('/', DIRECTORY_SEPARATOR, $basePath);
        $normalizedFilePath = str_replace('/', DIRECTORY_SEPARATOR, $filePath);

        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('generateLayoutNameFromPath');
        $method->setAccessible(true);

        $layoutName = $method->invoke($this->templateManager, $normalizedBasePath, $normalizedFilePath, 'sirsoft-sample');

        $this->assertEquals('sirsoft-sample_admin_index', $layoutName);
    }

    /**
     * 중첩된 경로의 오버라이드 레이아웃 이름 생성 테스트
     */
    public function test_generate_override_layout_name_with_nested_path(): void
    {
        $basePath = $this->testTemplatePath . '/layouts/overrides/sirsoft-sample';
        File::makeDirectory($basePath . '/products', 0755, true);

        $filePath = $basePath . '/products/list.json';

        // Windows/Unix 경로 정규화
        $normalizedBasePath = str_replace('/', DIRECTORY_SEPARATOR, $basePath);
        $normalizedFilePath = str_replace('/', DIRECTORY_SEPARATOR, $filePath);

        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('generateLayoutNameFromPath');
        $method->setAccessible(true);

        $layoutName = $method->invoke($this->templateManager, $normalizedBasePath, $normalizedFilePath, 'sirsoft-sample');

        $this->assertEquals('sirsoft-sample_products_list', $layoutName);
    }

    /**
     * 오버라이드 파일 스캔 테스트
     */
    public function test_scan_override_layout_files(): void
    {
        // 디렉토리 구조 생성
        $overridesPath = $this->testTemplatePath . '/layouts/overrides/sirsoft-sample';
        File::makeDirectory($overridesPath . '/nested', 0755, true);

        $testLayout = ['slots' => []];
        File::put($overridesPath . '/index.json', json_encode($testLayout));
        File::put($overridesPath . '/edit.json', json_encode($testLayout));
        File::put($overridesPath . '/nested/detail.json', json_encode($testLayout));

        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('scanLayoutFilesRecursively');
        $method->setAccessible(true);

        $files = $method->invoke($this->templateManager, $overridesPath);

        $this->assertCount(3, $files);
    }

    /**
     * 오버라이드 캐시 무효화 테스트
     */
    public function test_invalidate_override_layout_caches(): void
    {
        // 템플릿 생성
        $template = Template::create([
            'identifier' => 'cache-test-template',
            'vendor' => 'test',
            'name' => ['ko' => '캐시 테스트', 'en' => 'Cache Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 오버라이드 레이아웃 생성
        $override = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'module_layout_override',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'cache-test-template',
        ]);

        // 캐시 설정
        $cacheKey = "g7:core:template.{$template->id}.layout.{$override->name}";
        Cache::put($cacheKey, $override->content, 3600);
        $this->assertNotNull(Cache::get($cacheKey));

        // 캐시 무효화
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('invalidateOverrideLayoutCaches');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, $template->id);

        // 캐시 삭제 확인
        $this->assertNull(Cache::get($cacheKey));
    }

    /**
     * source_type이 template으로 설정되는지 테스트
     */
    public function test_override_source_type_is_template(): void
    {
        $template = Template::create([
            'identifier' => 'source-type-test',
            'vendor' => 'test',
            'name' => ['ko' => '소스타입 테스트', 'en' => 'Source Type Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 오버라이드 레이아웃 생성
        $override = TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-sample_admin_index',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'source-type-test',
        ]);

        $this->assertEquals(LayoutSourceType::Template, $override->source_type);
        $this->assertNotNull($override->source_identifier);
    }

    /**
     * 여러 모듈의 오버라이드 등록 테스트
     */
    public function test_register_overrides_for_multiple_modules(): void
    {
        // 두 모듈의 오버라이드 디렉토리 생성
        $module1Path = $this->testTemplatePath . '/layouts/overrides/sirsoft-module1';
        $module2Path = $this->testTemplatePath . '/layouts/overrides/sirsoft-module2';
        File::makeDirectory($module1Path, 0755, true);
        File::makeDirectory($module2Path, 0755, true);

        // template.json 생성
        $templateJson = [
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
        ];
        File::put($this->testTemplatePath . '/template.json', json_encode($templateJson));

        // 각 모듈의 오버라이드 레이아웃 생성
        $layout1 = ['layout_name' => 'sirsoft-module1_admin_index', 'slots' => []];
        $layout2 = ['layout_name' => 'sirsoft-module2_admin_index', 'slots' => []];
        File::put($module1Path . '/admin_index.json', json_encode($layout1));
        File::put($module2Path . '/admin_index.json', json_encode($layout2));

        // 템플릿 로드
        $this->templateManager->loadTemplates();

        // 템플릿 레코드 생성
        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '', 'en' => ''],
        ]);

        // 오버라이드 등록
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('registerLayoutOverrides');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, 'test-admin', $template->id);

        // 두 모듈의 오버라이드가 모두 등록되었는지 확인
        $overrides = TemplateLayout::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->whereNotNull('source_identifier')
            ->get();

        $this->assertCount(2, $overrides);
    }

    /**
     * updateOrCreate로 중복 오버라이드 방지 테스트
     */
    public function test_prevent_duplicate_override_registration(): void
    {
        $template = Template::create([
            'identifier' => 'duplicate-test',
            'vendor' => 'test',
            'name' => ['ko' => '중복테스트', 'en' => 'Duplicate Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        $layoutName = 'sirsoft-sample_admin_index';

        // 첫 번째 등록
        TemplateLayout::updateOrCreate(
            [
                'template_id' => $template->id,
                'name' => $layoutName,
            ],
            [
                'content' => ['version' => '1'],
                'source_type' => LayoutSourceType::Template,
                'source_identifier' => 'duplicate-test',
            ]
        );

        // 두 번째 등록 (업데이트)
        TemplateLayout::updateOrCreate(
            [
                'template_id' => $template->id,
                'name' => $layoutName,
            ],
            [
                'content' => ['version' => '2'],
                'source_type' => LayoutSourceType::Template,
                'source_identifier' => 'duplicate-test',
            ]
        );

        // 레이아웃이 1개만 존재
        $count = TemplateLayout::where('template_id', $template->id)
            ->where('name', $layoutName)
            ->count();

        $this->assertEquals(1, $count);

        // 내용이 업데이트됨
        $layout = TemplateLayout::where('template_id', $template->id)
            ->where('name', $layoutName)
            ->first();

        $this->assertEquals('2', $layout->content['version']);
    }

    /**
     * 오버라이드 디렉토리가 없는 경우 테스트
     */
    public function test_handles_missing_overrides_directory(): void
    {
        // 기본 템플릿 구조만 생성 (overrides 없음)
        File::makeDirectory($this->testTemplatePath . '/layouts', 0755, true);

        $templateJson = [
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
        ];
        File::put($this->testTemplatePath . '/template.json', json_encode($templateJson));

        $this->templateManager->loadTemplates();

        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '', 'en' => ''],
        ]);

        // 예외 없이 실행되어야 함
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('registerLayoutOverrides');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, 'test-admin', $template->id);

        // 오버라이드가 등록되지 않음
        $overrides = TemplateLayout::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->whereNotNull('source_identifier')
            ->count();

        $this->assertEquals(0, $overrides);
    }

    /**
     * incrementExtensionCacheVersion 메서드가 캐시 버전을 증가시키는지 테스트
     */
    public function test_increment_extension_cache_version(): void
    {
        // 초기 캐시 버전 설정
        Cache::put('g7:core:ext.cache_version', 1000);

        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('incrementExtensionCacheVersion');
        $method->setAccessible(true);

        $beforeTime = time();
        $method->invoke($this->templateManager);
        $afterTime = time();

        $newVersion = Cache::get('g7:core:ext.cache_version');
        $this->assertGreaterThanOrEqual($beforeTime, $newVersion);
        $this->assertLessThanOrEqual($afterTime, $newVersion);
    }

    /**
     * getExtensionCacheVersion 정적 메서드 테스트
     */
    public function test_get_extension_cache_version(): void
    {
        $expectedVersion = 1735000000;
        Cache::put('g7:core:ext.cache_version', $expectedVersion);

        // ClearsTemplateCaches trait의 정적 메서드 호출
        $version = \App\Extension\Traits\ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertEquals($expectedVersion, $version);
    }

    /**
     * 캐시 버전이 설정되지 않은 경우 0 반환
     */
    public function test_get_extension_cache_version_returns_zero_when_not_set(): void
    {
        Cache::forget('g7:core:ext.cache_version');

        $version = \App\Extension\Traits\ClearsTemplateCaches::getExtensionCacheVersion();

        $this->assertEquals(0, $version);
    }

    /**
     * layout_name 변경 시 이전 이름의 고아 레코드가 삭제되는지 테스트
     */
    public function test_cleanup_orphan_override_when_layout_name_changes(): void
    {
        $this->createTestTemplateStructure();
        $this->templateManager->loadTemplates();

        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 이전 이름으로 오버라이드 레이아웃이 DB에 이미 등록된 상태 시뮬레이션
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-sample_old_name',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
        ]);

        // registerLayoutOverrides 실행 (파일에는 sirsoft-sample_admin_index만 존재)
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('registerLayoutOverrides');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, 'test-admin', $template->id);

        // 새 이름의 오버라이드가 등록되어야 함
        $newOverride = TemplateLayout::where('template_id', $template->id)
            ->where('name', 'sirsoft-sample_admin_index')
            ->first();
        $this->assertNotNull($newOverride);

        // 이전 이름의 고아 레코드가 삭제되어야 함
        $oldOverride = TemplateLayout::where('template_id', $template->id)
            ->where('name', 'sirsoft-sample_old_name')
            ->first();
        $this->assertNull($oldOverride);
    }

    /**
     * 다른 템플릿의 오버라이드는 정리하지 않는지 테스트
     */
    public function test_cleanup_does_not_affect_other_template_overrides(): void
    {
        $this->createTestTemplateStructure();
        $this->templateManager->loadTemplates();

        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 다른 source_identifier를 가진 오버라이드 (다른 템플릿에서 등록)
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-sample_other_layout',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'other-template',
        ]);

        // registerLayoutOverrides 실행
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('registerLayoutOverrides');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, 'test-admin', $template->id);

        // 다른 템플릿의 오버라이드는 삭제되지 않아야 함
        $otherOverride = TemplateLayout::where('template_id', $template->id)
            ->where('name', 'sirsoft-sample_other_layout')
            ->where('source_identifier', 'other-template')
            ->first();
        $this->assertNotNull($otherOverride);
    }

    /**
     * overrides 디렉토리 삭제 시 기존 override가 모두 정리되는지 테스트
     */
    public function test_cleanup_all_overrides_when_directory_removed(): void
    {
        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        // 기존 오버라이드 레이아웃이 DB에 존재
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-sample_admin_index',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
        ]);

        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'sirsoft-sample_admin_edit',
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
        ]);

        // overrides 디렉토리가 없는 상태에서 registerLayoutOverrides 실행
        // (templates/test-admin/layouts/overrides가 존재하지 않음)
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('registerLayoutOverrides');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, 'test-admin', $template->id);

        // 모든 오버라이드가 삭제되어야 함
        $overrides = TemplateLayout::where('template_id', $template->id)
            ->where('source_type', LayoutSourceType::Template)
            ->where('source_identifier', 'test-admin')
            ->count();
        $this->assertEquals(0, $overrides);
    }

    /**
     * 고아 레코드 정리 시 관련 캐시도 삭제되는지 테스트
     */
    public function test_cleanup_orphan_overrides_also_clears_cache(): void
    {
        $template = Template::create([
            'identifier' => 'test-admin',
            'vendor' => 'test',
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Admin'],
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
            'description' => ['ko' => '테스트용', 'en' => 'For testing'],
        ]);

        $orphanName = 'sirsoft-sample_orphan_layout';

        // 고아 레이아웃 DB에 생성
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => $orphanName,
            'content' => ['slots' => []],
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => 'test-admin',
        ]);

        // 캐시 설정
        $cacheKey = "g7:core:template.{$template->id}.layout.{$orphanName}";
        Cache::put($cacheKey, ['cached' => true], 3600);
        $this->assertNotNull(Cache::get($cacheKey));

        // cleanupOrphanOverrideLayouts 실행 (등록된 이름 목록은 비어있음)
        $reflection = new \ReflectionClass($this->templateManager);
        $method = $reflection->getMethod('cleanupOrphanOverrideLayouts');
        $method->setAccessible(true);
        $method->invoke($this->templateManager, $template->id, 'test-admin', []);

        // 캐시도 삭제되어야 함
        $this->assertNull(Cache::get($cacheKey));

        // DB 레코드도 삭제되어야 함
        $orphan = TemplateLayout::where('template_id', $template->id)
            ->where('name', $orphanName)
            ->first();
        $this->assertNull($orphan);
    }
}
