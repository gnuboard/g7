<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class TemplateServiceLanguageMergeTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;

    private TemplateRepository $templateRepository;

    private TemplateManagerInterface $templateManager;

    private ModuleManagerInterface $moduleManager;

    private PluginManagerInterface $pluginManager;

    /** @var bool 활성 디렉토리가 테스트 전에 이미 존재했는지 (tearDown에서 정리 판단용) */
    private bool $boardExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 모듈 다국어 파일 테스트를 위해 _bundled에서 활성 디렉토리로 복사
        $activePath = base_path('modules/sirsoft-board');
        $bundledPath = base_path('modules/_bundled/sirsoft-board');
        $this->boardExistedBefore = File::isDirectory($activePath);
        if (! $this->boardExistedBefore && File::isDirectory($bundledPath)) {
            File::copyDirectory($bundledPath, $activePath);
        }

        // TemplateManager Mock 생성
        $this->templateManager = Mockery::mock(TemplateManagerInterface::class);
        $this->templateManager->shouldReceive('loadTemplates')
            ->zeroOrMoreTimes()
            ->andReturnNull();

        // ModuleManager Mock 생성
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);

        // PluginManager Mock 생성
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);

        // TemplateRepository 인스턴스 생성
        $this->templateRepository = new TemplateRepository;

        // TemplateService 인스턴스 생성
        $this->templateService = new TemplateService(
            $this->templateRepository,
            $this->templateManager,
            $this->moduleManager,
            $this->pluginManager,
            app(LayoutVersionRepositoryInterface::class)
        );
    }

    protected function tearDown(): void
    {
        // 테스트에서 생성한 활성 디렉토리만 정리 (기존에 있었으면 건드리지 않음)
        if (! $this->boardExistedBefore) {
            $activePath = base_path('modules/sirsoft-board');
            if (File::isDirectory($activePath)) {
                File::deleteDirectory($activePath);
            }
        }

        Mockery::close();
        parent::tearDown();
    }

    /**
     * 템플릿 다국어 데이터가 올바르게 반환되는지 테스트
     */
    public function test_get_language_data_returns_template_data(): void
    {
        // Arrange
        $identifier = 'sirsoft-admin_basic';
        $locale = 'ko';

        // 템플릿 DB 레코드 생성
        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // TemplateManager Mock 설정
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        // 모듈/플러그인이 없는 경우
        $this->moduleManager
            ->shouldReceive('getActiveModules')
            ->andReturn([]);

        $this->pluginManager
            ->shouldReceive('getActivePlugins')
            ->andReturn([]);

        // Act
        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
        $this->assertArrayHasKey('auth', $result['data']);
        $this->assertArrayHasKey('admin', $result['data']);
    }

    /**
     * 모듈 다국어 데이터가 템플릿 데이터와 병합되는지 테스트
     */
    public function test_module_language_data_is_merged_with_template_data(): void
    {
        // Arrange
        $identifier = 'sirsoft-admin_basic';
        $locale = 'ko';

        // 템플릿 DB 레코드 생성
        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // TemplateManager Mock 설정
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        // 활성화된 모듈 Mock 생성
        $mockModule = Mockery::mock(ModuleInterface::class);
        $mockModule->shouldReceive('getIdentifier')
            ->andReturn('sirsoft-board');

        $this->moduleManager
            ->shouldReceive('getActiveModules')
            ->andReturn(['sirsoft-board' => $mockModule]);

        $this->pluginManager
            ->shouldReceive('getActivePlugins')
            ->andReturn([]);

        // Act
        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
        // 템플릿 데이터
        $this->assertArrayHasKey('auth', $result['data']);
        // 모듈 데이터 (sirsoft-board 키로 병합)
        $this->assertArrayHasKey('sirsoft-board', $result['data']);
        $this->assertArrayHasKey('messages', $result['data']['sirsoft-board']);
        $this->assertArrayHasKey('boards', $result['data']['sirsoft-board']['messages']);
    }

    /**
     * 플러그인 다국어 데이터도 병합되는지 테스트
     */
    public function test_plugin_language_data_is_merged(): void
    {
        // Arrange
        $identifier = 'sirsoft-admin_basic';
        $locale = 'ko';

        // 템플릿 DB 레코드 생성
        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // TemplateManager Mock 설정
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        $this->moduleManager
            ->shouldReceive('getActiveModules')
            ->andReturn([]);

        // 활성화된 플러그인 Mock (다국어 파일이 없는 경우)
        $mockPlugin = Mockery::mock(PluginInterface::class);
        $mockPlugin->shouldReceive('getIdentifier')
            ->andReturn('sirsoft-analytics');

        $this->pluginManager
            ->shouldReceive('getActivePlugins')
            ->andReturn(['sirsoft-analytics' => $mockPlugin]);

        // Act
        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
        // 플러그인 다국어 파일이 없으면 키가 추가되지 않음
        $this->assertArrayNotHasKey('sirsoft-analytics', $result['data']);
    }

    /**
     * 존재하지 않는 템플릿에 대한 에러 처리 테스트
     */
    public function test_returns_error_for_nonexistent_template(): void
    {
        // Arrange
        $identifier = 'nonexistent-template';
        $locale = 'ko';

        // Act
        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('template_not_found', $result['error']);
    }

    /**
     * 지원하지 않는 로케일에 대한 에러 처리 테스트
     */
    public function test_returns_error_for_unsupported_locale(): void
    {
        // Arrange
        $identifier = 'sirsoft-admin_basic';
        $locale = 'jp';

        // 템플릿 DB 레코드 생성
        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        // TemplateManager Mock 설정 (jp를 지원하지 않음)
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        // Act
        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('locale_not_supported', $result['error']);
    }

    /**
     * 병합된 데이터에서 모듈 식별자가 키로 사용되는지 테스트
     */
    public function test_module_identifier_is_used_as_key(): void
    {
        // Arrange
        $identifier = 'sirsoft-admin_basic';
        $locale = 'ko';

        // 템플릿 DB 레코드 생성
        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        // 활성화된 모듈 Mock 생성
        $mockModule = Mockery::mock(ModuleInterface::class);
        $mockModule->shouldReceive('getIdentifier')
            ->andReturn('sirsoft-board');

        $this->moduleManager
            ->shouldReceive('getActiveModules')
            ->andReturn(['sirsoft-board' => $mockModule]);

        $this->pluginManager
            ->shouldReceive('getActivePlugins')
            ->andReturn([]);

        // Act
        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        // Assert
        $this->assertTrue($result['success']);
        // 모듈 식별자가 키로 사용됨
        $this->assertArrayHasKey('sirsoft-board', $result['data']);
        // 모듈 내부 데이터 접근 (sirsoft-board의 messages.boards.menu_added_success)
        $this->assertEquals(
            '관리자 메뉴에 추가되었습니다.',
            $result['data']['sirsoft-board']['messages']['boards']['menu_added_success']
        );
    }

    /**
     * 코어 프론트엔드 다국어 자원(`lang/{locale}.json`)이 베이스 레이어로 병합되어
     * 어떤 템플릿이 부팅되든 `core.*` 키가 응답에 포함되는지 검증.
     */
    public function test_core_frontend_lang_data_is_loaded_as_base_layer(): void
    {
        $identifier = 'sirsoft-admin_basic';
        $locale = 'ko';

        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
        // 코어 프론트엔드 키가 베이스로 병합됨
        $this->assertArrayHasKey('core', $result['data']);
        $this->assertArrayHasKey('errors', $result['data']['core']);
        // 한국어 번역 확인
        $this->assertEquals(
            '활성화된 템플릿이 없습니다',
            $result['data']['core']['errors']['template_not_found']
        );
        // 템플릿 키도 동시에 존재
        $this->assertArrayHasKey('auth', $result['data']);
    }

    /**
     * en 로케일에서도 코어 프론트엔드 키가 영어로 해석되는지 검증.
     */
    public function test_core_frontend_lang_data_resolved_in_english_locale(): void
    {
        $identifier = 'sirsoft-admin_basic';
        $locale = 'en';

        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        $this->assertTrue($result['success']);
        $this->assertEquals(
            'No active template found',
            $result['data']['core']['errors']['template_not_found']
        );
    }

    /**
     * 결함 A 회귀 테스트.
     *
     * 코어의 `layout_editor.chrome.*` 가 살아남으면서 sirsoft-basic 템플릿이 자체
     * 정의한 `layout_editor.palette.*` 도 동시에 노출되어야 한다. 과거 array_merge
     * shallow 는 템플릿 partial 의 `layout_editor` 가 코어 `layout_editor` 를 통째
     * 덮어써서 chrome/device/zoom/save 키가 누락되었다.
     */
    public function test_deep_merge_preserves_core_layout_editor_chrome_when_template_defines_palette(): void
    {
        $identifier = 'sirsoft-basic';
        $locale = 'ko';

        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Basic',
            'type' => 'user',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        $this->assertTrue($result['success']);
        $this->assertIsArray($result['data']);
        $this->assertArrayHasKey('layout_editor', $result['data']);

        // 코어의 chrome / device / zoom / preview / save 키가 모두 살아남아야 함
        $layoutEditor = $result['data']['layout_editor'];
        $this->assertArrayHasKey('chrome', $layoutEditor, 'core layout_editor.chrome 누락');
        $this->assertArrayHasKey('device', $layoutEditor, 'core layout_editor.device 누락');
        $this->assertArrayHasKey('zoom', $layoutEditor, 'core layout_editor.zoom 누락');
        $this->assertArrayHasKey('preview', $layoutEditor, 'core layout_editor.preview 누락');
        $this->assertArrayHasKey('save', $layoutEditor, 'core layout_editor.save 누락');

        // 동시에 템플릿이 정의한 palette 트리도 그대로 노출
        $this->assertArrayHasKey('palette', $layoutEditor, 'template layout_editor.palette 누락');
        $this->assertIsArray($layoutEditor['palette']);
    }

    /**
     * deep merge — 양쪽이 assoc 일 때 leaf 병합 + 충돌 leaf 는 뒤가 우선.
     */
    public function test_deep_merge_recursive_leaf_resolution(): void
    {
        $identifier = 'sirsoft-admin_basic';
        $locale = 'ko';

        Template::create([
            'identifier' => $identifier,
            'vendor' => 'sirsoft',
            'name' => 'Admin Basic',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->with($identifier)
            ->andReturn([
                'identifier' => $identifier,
                'locales' => ['ko', 'en'],
            ]);

        $this->moduleManager->shouldReceive('getActiveModules')->andReturn([]);
        $this->pluginManager->shouldReceive('getActivePlugins')->andReturn([]);

        // template.language.merge 훅으로 인공적인 deep merge 검증 입력 합류
        $overrideCallback = function ($data) {
            return array_replace_recursive($data, [
                'core' => [
                    'errors' => [
                        'template_not_found' => '<<HOOK_OVERRIDE>>',
                    ],
                ],
            ]);
        };
        HookManager::addFilter('template.language.merge', $overrideCallback);

        $result = $this->templateService->getLanguageDataWithModules($identifier, $locale);

        HookManager::removeFilter('template.language.merge', $overrideCallback);

        $this->assertTrue($result['success']);
        $this->assertEquals(
            '<<HOOK_OVERRIDE>>',
            $result['data']['core']['errors']['template_not_found']
        );
        // 동시에 같은 core.errors 트리의 다른 leaf 는 코어 값이 살아남아야 함
        // (deep merge 검증 — 충돌 leaf 만 override, 동기 트리의 다른 형제는 보존)
        $this->assertArrayHasKey('layout_load_failed', $result['data']['core']['errors']);
    }
}
