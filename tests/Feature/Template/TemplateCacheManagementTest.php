<?php

namespace Tests\Feature\Template;

use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Helpers\ProtectsExtensionDirectories;
use Tests\TestCase;

class TemplateCacheManagementTest extends TestCase
{
    use ProtectsExtensionDirectories;
    use RefreshDatabase;

    protected TemplateManager $templateManager;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 레이아웃 JSON 파일 생성 (보호 활성화 전에 수행)
        $this->createTestLayoutFiles();

        // 확장 디렉토리 보호 활성화
        $this->setUpExtensionProtection();

        $this->templateManager = app(TemplateManager::class);
        $this->templateManager->loadTemplates();
    }

    protected function tearDown(): void
    {
        // 캐시 부작용 방지: 테스트 중 생성된 캐시를 정리
        // RefreshDatabase는 DB만 롤백하므로, 캐시는 수동 정리 필수
        Cache::flush();

        // 확장 디렉토리 보호 해제
        $this->tearDownExtensionProtection();

        // 테스트용 레이아웃 파일 정리
        $this->cleanupTestLayoutFiles();

        parent::tearDown();
    }

    /**
     * 템플릿 활성화 시 캐시 워밍 테스트
     */
    public function test_activate_template_warms_cache(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();

        // 캐시 비우기
        Cache::flush();

        // Act
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        // warmTemplateCache()는 버전 포함 캐시 키를 생성함
        $cacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // Assert - 주요 레이아웃 캐시 확인
        $mainLayouts = ['_admin_base', 'dashboard', 'admin_login'];
        foreach ($mainLayouts as $layoutName) {
            $layoutExists = TemplateLayout::where('template_id', $template->id)
                ->where('name', $layoutName)
                ->exists();

            if ($layoutExists) {
                $cacheKey = "layout.sirsoft-admin_basic.{$layoutName}.v{$cacheVersion}";
                $this->assertTrue(
                    Cache::has($cacheKey),
                    "활성화 후 {$layoutName} 레이아웃 캐시가 생성되어야 합니다."
                );
            }
        }

        // Routes 캐시 확인
        $routesFile = base_path('templates/sirsoft-admin_basic/routes.json');
        if (file_exists($routesFile)) {
            $this->assertTrue(
                Cache::has("template.routes.sirsoft-admin_basic.v{$cacheVersion}"),
                '활성화 후 routes 캐시가 생성되어야 합니다.'
            );
        }

        // 다국어 파일 캐시 확인
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            $langFile = base_path("templates/sirsoft-admin_basic/lang/{$locale}.json");
            if (file_exists($langFile)) {
                $this->assertTrue(
                    Cache::has("template.language.sirsoft-admin_basic.{$locale}.v{$cacheVersion}"),
                    "활성화 후 {$locale} 다국어 캐시가 생성되어야 합니다."
                );
            }
        }
    }

    /**
     * 템플릿 비활성화 시 캐시 버전 증가 테스트 (deactivateTemplate 메서드)
     *
     * 버전 포함 캐시(routes, language)는 능동 삭제 대신 캐시 버전 증가 + TTL 자연 만료로 무효화됩니다.
     */
    public function test_deactivate_template_increments_cache_version(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $activateVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 캐시가 존재하는지 확인 (버전 포함 키)
        $this->assertTrue(
            Cache::has("template.routes.sirsoft-admin_basic.v{$activateVersion}"),
            '활성화 후 캐시가 존재해야 합니다.'
        );

        // 캐시 버전을 과거 값으로 설정하여 비활성화 후 증가를 검증
        Cache::put('g7:core:ext.cache_version', 1000);

        // Act
        $this->templateManager->deactivateTemplate('sirsoft-admin_basic');

        // Assert - 캐시 버전이 증가했는지 확인
        $deactivateVersion = ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertGreaterThan(
            1000,
            $deactivateVersion,
            '비활성화 후 캐시 버전이 증가해야 합니다.'
        );
    }

    /**
     * 템플릿 비활성화 시 캐시 삭제 테스트 (deactivateTemplatesByType 메서드)
     */
    public function test_activate_different_type_template_deactivates_and_clears_cache(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $firstVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 캐시가 존재하는지 확인 (버전 포함 키)
        $this->assertTrue(
            Cache::has("template.routes.sirsoft-admin_basic.v{$firstVersion}"),
            '첫 번째 템플릿 활성화 후 캐시가 존재해야 합니다.'
        );

        // Act - 다른 같은 타입 템플릿 활성화 (기존 템플릿 자동 비활성화)
        // Note: 실제로 두 번째 admin 템플릿이 없으므로 같은 템플릿을 재설치/재활성화
        $this->templateManager->deactivateTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        // Assert - 이전 캐시는 삭제되고 새로운 캐시가 생성됨
        // deactivate → activate 시 캐시 버전이 증가하므로 새 버전으로 확인
        $newVersion = ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertTrue(
            Cache::has("template.routes.sirsoft-admin_basic.v{$newVersion}"),
            '재활성화 후 캐시가 다시 생성되어야 합니다.'
        );
    }

    /**
     * 템플릿 제거 시 캐시 버전 증가 테스트
     *
     * 버전 포함 캐시는 능동 삭제 대신 캐시 버전 증가 + TTL 자연 만료로 무효화됩니다.
     */
    public function test_uninstall_template_increments_cache_version(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $activateVersion = ClearsTemplateCaches::getExtensionCacheVersion();

        // 캐시가 존재하는지 확인 (버전 포함 키)
        $this->assertTrue(
            Cache::has("template.routes.sirsoft-admin_basic.v{$activateVersion}"),
            '활성화 후 캐시가 존재해야 합니다.'
        );

        // 캐시 버전을 과거 값으로 설정하여 제거 후 증가를 검증
        Cache::put('g7:core:ext.cache_version', 1000);

        // Act (spy가 활성 디렉토리 삭제/이동 차단)
        $this->templateManager->uninstallTemplate('sirsoft-admin_basic');

        // Assert - 캐시 버전이 증가했는지 확인
        $uninstallVersion = ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertGreaterThan(
            1000,
            $uninstallVersion,
            '제거 후 캐시 버전이 증가해야 합니다.'
        );
    }

    /**
     * 템플릿 설치 시에는 캐시 워밍이 되지 않는지 테스트
     *
     * 설치 시 캐시 버전은 증가하지만, 캐시 워밍(routes, language 캐시 생성)은 수행되지 않습니다.
     */
    public function test_install_template_does_not_warm_cache(): void
    {
        // Arrange
        Cache::flush();

        // Act
        $this->templateManager->installTemplate('sirsoft-admin_basic');

        // Assert - 캐시 버전은 증가하지만 워밍은 안 됨
        $cacheVersion = ClearsTemplateCaches::getExtensionCacheVersion();
        $this->assertGreaterThan(0, $cacheVersion, '설치 후 캐시 버전이 설정되어야 합니다.');

        // 설치 시에는 캐시가 생성되지 않아야 함 (비활성 상태로 설치됨)
        $this->assertFalse(
            Cache::has("template.routes.sirsoft-admin_basic.v{$cacheVersion}"),
            '설치 시에는 routes 캐시가 생성되지 않아야 합니다 (비활성 상태).'
        );

        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            $this->assertFalse(
                Cache::has("template.language.sirsoft-admin_basic.{$locale}.v{$cacheVersion}"),
                "설치 시에는 {$locale} 다국어 캐시가 생성되지 않아야 합니다."
            );
        }
    }

    /**
     * 레이아웃 내부 캐시 삭제 테스트
     *
     * 버전 없는 내부 캐시 키(template.{id}.layout.{name})가 비활성화 시 삭제되는지 확인합니다.
     */
    public function test_deactivate_template_clears_internal_layout_caches(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $layouts = TemplateLayout::where('template_id', $template->id)->get();

        // 수동으로 내부 레이아웃 캐시 생성 (버전 없는 LayoutService 캐시 키)
        foreach ($layouts as $layout) {
            $cacheKey = "g7:core:template.{$template->id}.layout.{$layout->name}";
            Cache::put($cacheKey, ['test' => 'data'], 3600);
        }

        // Act
        $this->templateManager->deactivateTemplate('sirsoft-admin_basic');

        // Assert - 내부 레이아웃 캐시가 삭제되었는지 확인
        foreach ($layouts as $layout) {
            $cacheKey = "g7:core:template.{$template->id}.layout.{$layout->name}";
            $this->assertFalse(
                Cache::has($cacheKey),
                "비활성화 후 {$layout->name} 내부 레이아웃 캐시가 삭제되어야 합니다."
            );
        }
    }

    /**
     * 테스트용 레이아웃 JSON 파일 생성
     */
    protected function createTestLayoutFiles(): void
    {
        $layoutsPath = base_path('templates/sirsoft-admin_basic/layouts');

        if (! File::exists($layoutsPath)) {
            File::makeDirectory($layoutsPath, 0755, true);
        }

        // 테스트용 레이아웃 JSON 생성
        $testLayout = [
            'version' => '1.0.0',
            'layout_name' => 'test_cache_layout',
            'meta' => [
                'title' => 'Test Cache Layout',
                'description' => 'Test layout for cache tests',
            ],
            'components' => [
                [
                    'id' => 'root',
                    'type' => 'basic',
                    'name' => 'div',
                    'props' => [
                        'className' => 'container',
                    ],
                ],
            ],
        ];

        File::put(
            "{$layoutsPath}/test_cache_layout.json",
            json_encode($testLayout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 테스트용 레이아웃 파일 정리
     */
    protected function cleanupTestLayoutFiles(): void
    {
        $layoutsPath = base_path('templates/sirsoft-admin_basic/layouts');
        $testLayoutFile = "{$layoutsPath}/test_cache_layout.json";

        if (File::exists($testLayoutFile)) {
            File::delete($testLayoutFile);
        }
    }
}
