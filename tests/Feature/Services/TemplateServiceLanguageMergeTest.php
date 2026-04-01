<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
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
            $this->pluginManager
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
}
