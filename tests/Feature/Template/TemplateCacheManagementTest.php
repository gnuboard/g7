<?php

namespace Tests\Feature\Template;

use App\Contracts\Extension\CacheInterface;
use App\Extension\Cache\CoreCacheDriver;
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
     * 코어 캐시 드라이버를 반환합니다.
     *
     * 검증 대상 키(`template.*`, `layout.*`)는 모두 코어 소유이며
     * TemplateManager 가 CoreCacheDriver(`g7:core:` 네임스페이스)로 기록한다.
     * 컨테이너의 CacheInterface 바인딩은 선행 모듈/플러그인 테스트가
     * PluginCacheDriver 로 재바인딩할 수 있어 네임스페이스가 어긋날 수 있으므로,
     * 테스트도 프로덕션과 동일하게 CoreCacheDriver 로 조회한다.
     */
    private function coreCache(): CacheInterface
    {
        return new CoreCacheDriver(config('cache.default', 'array'));
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
                    $this->coreCache()->get($cacheKey) !== null,
                    "활성화 후 {$layoutName} 레이아웃 캐시가 생성되어야 합니다."
                );
            }
        }

        // Routes 캐시 확인
        $routesFile = base_path('templates/sirsoft-admin_basic/routes.json');
        if (file_exists($routesFile)) {
            $this->assertTrue(
                $this->coreCache()->get("template.routes.sirsoft-admin_basic.v{$cacheVersion}") !== null,
                '활성화 후 routes 캐시가 생성되어야 합니다.'
            );
        }

        // 다국어 파일 캐시 확인
        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            $langFile = base_path("templates/sirsoft-admin_basic/lang/{$locale}.json");
            if (file_exists($langFile)) {
                $this->assertTrue(
                    $this->coreCache()->get("template.language.sirsoft-admin_basic.{$locale}.v{$cacheVersion}") !== null,
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
            $this->coreCache()->get("template.routes.sirsoft-admin_basic.v{$activateVersion}") !== null,
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
            $this->coreCache()->get("template.routes.sirsoft-admin_basic.v{$firstVersion}") !== null,
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
            $this->coreCache()->get("template.routes.sirsoft-admin_basic.v{$newVersion}") !== null,
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
            $this->coreCache()->get("template.routes.sirsoft-admin_basic.v{$activateVersion}") !== null,
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
            $this->coreCache()->get("template.routes.sirsoft-admin_basic.v{$cacheVersion}") !== null,
            '설치 시에는 routes 캐시가 생성되지 않아야 합니다 (비활성 상태).'
        );

        $supportedLocales = config('app.supported_locales', ['ko', 'en']);
        foreach ($supportedLocales as $locale) {
            $this->assertFalse(
                $this->coreCache()->get("template.language.sirsoft-admin_basic.{$locale}.v{$cacheVersion}") !== null,
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
                $this->coreCache()->get($cacheKey) !== null,
                "비활성화 후 {$layout->name} 내부 레이아웃 캐시가 삭제되어야 합니다."
            );
        }
    }

    /**
     * template:cache-clear 커맨드가 공개 config 캐시를 삭제하는지 테스트 (#588, 공개 #119)
     *
     * `template.config.{identifier}` 는 버전 접미사 없는 고정 키라 캐시 버전 bump 로
     * 무효화되지 않는다 — 커맨드가 능동 forget 하지 않으면 TTL(1시간) 동안
     * 이전 manifest 가 계속 서빙된다.
     *
     * @scenario cache_state=warm_stale,lifecycle_trigger=cache_clear_command_single,template_type=admin
     *
     * @effects cache_clear_command_forgets_template_config_key
     */
    public function test_template_cache_clear_command_forgets_public_config_cache(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');

        $this->coreCache()->put('template.config.sirsoft-admin_basic', [
            'success' => true,
            'data' => ['version' => '0.0.1-stale'],
        ], 3600);

        // Act
        $this->artisan('template:cache-clear', ['identifier' => 'sirsoft-admin_basic'])
            ->assertExitCode(0);

        // Assert
        $this->assertNull(
            $this->coreCache()->get('template.config.sirsoft-admin_basic'),
            'template:cache-clear 후 공개 config 캐시(template.config.{identifier})가 삭제되어야 합니다.'
        );
    }

    /**
     * template:cache-clear 전체 모드가 공개 config 캐시를 삭제하는지 테스트 (#588, 공개 #119)
     *
     * @scenario cache_state=warm_stale,lifecycle_trigger=cache_clear_command_all,template_type=admin
     *
     * @effects cache_clear_command_forgets_template_config_key
     */
    public function test_template_cache_clear_all_forgets_public_config_cache(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');

        $this->coreCache()->put('template.config.sirsoft-admin_basic', [
            'success' => true,
            'data' => ['version' => '0.0.1-stale'],
        ], 3600);

        // Act (무인자 = 전체 모드)
        $this->artisan('template:cache-clear')
            ->assertExitCode(0);

        // Assert
        $this->assertNull(
            $this->coreCache()->get('template.config.sirsoft-admin_basic'),
            'template:cache-clear 전체 모드 후 공개 config 캐시가 삭제되어야 합니다.'
        );
    }

    /**
     * clearTemplateCache() 가 config + components_manifest 고정 키를 삭제하는지 테스트 (#588)
     *
     * components_manifest 는 숫자 id / identifier 두 변종 키가 실재한다
     * (ComponentExists 는 int|string 을 수용). 템플릿 업데이트로 components.json 이
     * 변해도 이 키가 남으면 최대 1시간 구 매니페스트로 레이아웃을 검증한다.
     *
     * @scenario cache_state=warm_stale,lifecycle_trigger=template_update,template_type=admin
     *
     * @effects template_update_invalidates_public_config_cache,clear_template_cache_forgets_components_manifest_variants
     */
    public function test_clear_template_cache_forgets_config_and_components_manifest(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();

        $this->coreCache()->put('template.config.sirsoft-admin_basic', [
            'success' => true,
            'data' => ['version' => '0.0.1-stale'],
        ], 3600);
        $this->coreCache()->put("template.{$template->id}.components_manifest", ['components' => []], 3600);
        $this->coreCache()->put('template.sirsoft-admin_basic.components_manifest', ['components' => []], 3600);

        // Act — 라이프사이클(update/deactivate/uninstall/cache-clear)이 공유하는 무효화 단일 지점
        $this->templateManager->clearTemplateCache('sirsoft-admin_basic');

        // Assert
        $this->assertNull(
            $this->coreCache()->get('template.config.sirsoft-admin_basic'),
            'clearTemplateCache() 후 공개 config 캐시가 삭제되어야 합니다.'
        );
        $this->assertNull(
            $this->coreCache()->get("template.{$template->id}.components_manifest"),
            'clearTemplateCache() 후 숫자 id 변종 components_manifest 캐시가 삭제되어야 합니다.'
        );
        $this->assertNull(
            $this->coreCache()->get('template.sirsoft-admin_basic.components_manifest'),
            'clearTemplateCache() 후 identifier 변종 components_manifest 캐시가 삭제되어야 합니다.'
        );
    }

    /**
     * 비활성화/삭제 라이프사이클이 공개 config 캐시를 삭제하는지 테스트 (#588, 공개 #119)
     *
     * 활성 시절 warm 된 config 캐시가 남으면 비활성/삭제된 템플릿의 manifest 를
     * 최대 1시간 200 으로 계속 서빙한다 (캐시 히트가 상태 검사보다 먼저).
     *
     * @scenario cache_state=warm_stale,lifecycle_trigger=deactivate,template_type=admin
     *
     * @effects deactivate_uninstall_invalidate_public_config_cache
     */
    public function test_template_deactivate_and_uninstall_invalidate_public_config_cache(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $warmConfig = function (): void {
            $this->coreCache()->put('template.config.sirsoft-admin_basic', [
                'success' => true,
                'data' => ['version' => '0.0.1-stale'],
            ], 3600);
        };

        // Act & Assert — 비활성화 경로
        $warmConfig();
        $this->templateManager->deactivateTemplate('sirsoft-admin_basic');
        $this->assertNull(
            $this->coreCache()->get('template.config.sirsoft-admin_basic'),
            '비활성화 후 공개 config 캐시가 삭제되어야 합니다.'
        );

        // Act & Assert — 삭제 경로
        $warmConfig();
        $this->templateManager->uninstallTemplate('sirsoft-admin_basic');
        $this->assertNull(
            $this->coreCache()->get('template.config.sirsoft-admin_basic'),
            '삭제 후 공개 config 캐시가 삭제되어야 합니다.'
        );
    }

    /**
     * 비활성화 시 편집기용 `.with_source_meta` 변종 레이아웃 캐시도 삭제되는지 테스트 (#588)
     *
     * LayoutService 는 편집기 병합 응답을 `.with_source_meta` 접미사 키로 별도 캐싱한다.
     * 라이프사이클 무효화(forgetLayoutCacheKeys)가 접미사 없는 키만 지우면
     * activate/deactivate/uninstall/refresh 후에도 편집기용 병합 캐시가 잔존한다.
     *
     * @scenario cache_state=warm_stale,lifecycle_trigger=uninstall,template_type=admin
     *
     * @effects with_source_meta_layout_cache_variants_forgotten
     */
    public function test_deactivate_template_clears_with_source_meta_layout_caches(): void
    {
        // Arrange
        $this->templateManager->installTemplate('sirsoft-admin_basic');
        $this->templateManager->activateTemplate('sirsoft-admin_basic');

        $template = Template::where('identifier', 'sirsoft-admin_basic')->first();
        $layouts = TemplateLayout::where('template_id', $template->id)->get();
        $this->assertGreaterThan(0, $layouts->count());

        $warmedKeys = [];
        foreach ($layouts as $layout) {
            $key = "template.{$template->id}.layout.{$layout->name}.with_source_meta";
            $this->coreCache()->put($key, ['test' => 'meta'], 3600);
            $warmedKeys[] = $key;

            // 소스 해시 변종 (LayoutService::getMergedLayoutCacheKey 와 동형 — 접미사는 해시 뒤)
            if ($layout->source_type && $layout->source_identifier) {
                $sourceHash = md5($layout->source_type->value.$layout->source_identifier);
                $hashKey = "template.{$template->id}.layout.{$layout->name}.{$sourceHash}.with_source_meta";
                $this->coreCache()->put($hashKey, ['test' => 'meta'], 3600);
                $warmedKeys[] = $hashKey;
            }
        }

        // Act
        $this->templateManager->deactivateTemplate('sirsoft-admin_basic');

        // Assert
        foreach ($warmedKeys as $key) {
            $this->assertNull(
                $this->coreCache()->get($key),
                "비활성화 후 편집기용 병합 캐시({$key})가 삭제되어야 합니다."
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
