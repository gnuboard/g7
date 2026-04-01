<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class TemplateServiceInstallationTest extends TestCase
{
    use RefreshDatabase;

    private TemplateService $templateService;

    private TemplateRepository $templateRepository;

    private TemplateManagerInterface $templateManager;

    private ModuleManagerInterface $moduleManager;

    private PluginManagerInterface $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        // TemplateManager Mock 생성
        $this->templateManager = Mockery::mock(TemplateManagerInterface::class);

        // loadTemplates() 호출 허용 (생성자에서 호출됨)
        $this->templateManager->shouldReceive('loadTemplates')
            ->zeroOrMoreTimes()
            ->andReturnNull();

        // ModuleManager Mock 생성
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);

        // PluginManager Mock 생성
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);

        // TemplateRepository 인스턴스 생성
        $this->templateRepository = new TemplateRepository;

        // TemplateService 인스턴스 생성 (Mock Manager 주입)
        $this->templateService = new TemplateService(
            $this->templateRepository,
            $this->templateManager,
            $this->moduleManager,
            $this->pluginManager
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * getAllTemplates 메서드가 설치된 템플릿과 미설치 템플릿을 모두 반환하는지 테스트
     */
    public function test_get_all_templates_returns_installed_and_uninstalled(): void
    {
        // Arrange
        $installedTemplates = [
            [
                'identifier' => 'installed-template',
                'vendor' => 'test-vendor',
                'name' => 'Installed Template',
                'type' => 'admin',
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ],
        ];

        $uninstalledTemplates = [
            [
                'identifier' => 'uninstalled-template',
                'vendor' => 'test-vendor',
                'name' => 'Uninstalled Template',
                'type' => 'admin',
                'version' => '1.0.0',
            ],
        ];

        $this->templateManager
            ->shouldReceive('getInstalledTemplatesWithDetails')
            ->once()
            ->andReturn($installedTemplates);

        $this->templateManager
            ->shouldReceive('getUninstalledTemplates')
            ->once()
            ->andReturn($uninstalledTemplates);

        // Act
        $result = $this->templateService->getAllTemplates();

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('installed', $result);
        $this->assertArrayHasKey('uninstalled', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertCount(1, $result['installed']);
        $this->assertCount(1, $result['uninstalled']);
        $this->assertEquals(2, $result['total']);
    }

    /**
     * getAllTemplates 메서드가 타입별로 필터링하는지 테스트
     */
    public function test_get_all_templates_filters_by_type(): void
    {
        // Arrange
        $installedTemplates = [
            [
                'identifier' => 'admin-template',
                'type' => 'admin',
            ],
            [
                'identifier' => 'user-template',
                'type' => 'user',
            ],
        ];

        $this->templateManager
            ->shouldReceive('getInstalledTemplatesWithDetails')
            ->once()
            ->andReturn($installedTemplates);

        $this->templateManager
            ->shouldReceive('getUninstalledTemplates')
            ->once()
            ->andReturn([]);

        // Act
        $result = $this->templateService->getAllTemplates('admin');

        // Assert
        $this->assertCount(1, $result['installed']);
        $this->assertEquals('admin-template', $result['installed'][0]['identifier']);
    }

    /**
     * getInstalledTemplatesOnly 메서드가 설치된 템플릿만 반환하는지 테스트
     */
    public function test_get_installed_templates_only(): void
    {
        // Arrange
        $installedTemplates = [
            ['identifier' => 'template1', 'type' => 'admin'],
            ['identifier' => 'template2', 'type' => 'user'],
        ];

        $this->templateManager
            ->shouldReceive('getInstalledTemplatesWithDetails')
            ->once()
            ->andReturn($installedTemplates);

        // Act
        $result = $this->templateService->getInstalledTemplatesOnly();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * getUninstalledTemplatesOnly 메서드가 미설치 템플릿만 반환하는지 테스트
     */
    public function test_get_uninstalled_templates_only(): void
    {
        // Arrange
        $uninstalledTemplates = [
            ['identifier' => 'template3', 'type' => 'admin'],
        ];

        $this->templateManager
            ->shouldReceive('getUninstalledTemplates')
            ->once()
            ->andReturn($uninstalledTemplates);

        // Act
        $result = $this->templateService->getUninstalledTemplatesOnly();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    /**
     * getTemplateInfo 메서드가 특정 템플릿 정보를 반환하는지 테스트
     */
    public function test_get_template_info(): void
    {
        // Arrange
        $identifier = 'test-template';
        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
        ];

        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        // Act
        $result = $this->templateService->getTemplateInfo($identifier);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals($identifier, $result['identifier']);
    }

    /**
     * installTemplate 메서드가 템플릿을 설치하는지 테스트
     */
    public function test_install_template_successfully(): void
    {
        // Arrange
        $identifier = 'test-template';
        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
        ];

        $this->templateManager
            ->shouldReceive('installTemplate')
            ->once()
            ->with($identifier)
            ->andReturn(true);

        // installTemplate은 getTemplateInfo를 호출함
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        // Act
        $result = $this->templateService->installTemplate($identifier);

        // Assert: installTemplate은 배열을 반환함 (templateInfo)
        $this->assertIsArray($result);
        $this->assertEquals($identifier, $result['identifier']);
    }

    /**
     * installTemplate 메서드가 실패 시 예외를 발생시키는지 테스트
     */
    public function test_install_template_throws_exception_on_failure(): void
    {
        // Arrange
        $identifier = 'test-template';

        $this->templateManager
            ->shouldReceive('installTemplate')
            ->once()
            ->with($identifier)
            ->andThrow(new \Exception('Installation failed'));

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->installTemplate($identifier);
    }

    /**
     * uninstallTemplate 메서드가 템플릿을 제거하는지 테스트
     */
    public function test_uninstall_template_successfully(): void
    {
        // Arrange
        $identifier = 'test-template';
        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
        ];

        // uninstallTemplate은 getTemplateInfo를 먼저 호출함
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        $this->templateManager
            ->shouldReceive('uninstallTemplate')
            ->once()
            ->with($identifier)
            ->andReturn(true);

        // Act
        $result = $this->templateService->uninstallTemplate($identifier);

        // Assert: uninstallTemplate은 배열을 반환함 (templateInfo)
        $this->assertIsArray($result);
        $this->assertEquals($identifier, $result['identifier']);
    }

    /**
     * uninstallTemplate 메서드가 실패 시 예외를 발생시키는지 테스트
     */
    public function test_uninstall_template_throws_exception_on_failure(): void
    {
        // Arrange
        $identifier = 'test-template';
        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
        ];

        // getTemplateInfo가 먼저 호출됨
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        $this->templateManager
            ->shouldReceive('uninstallTemplate')
            ->once()
            ->with($identifier)
            ->andThrow(new \Exception('Uninstallation failed'));

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->uninstallTemplate($identifier);
    }

    /**
     * deactivateTemplate 메서드가 템플릿을 비활성화하는지 테스트
     *
     * 참고: 실제 deactivate 로직은 TemplateServiceActivationTest에서 테스트됨
     * 이 테스트는 Mock 기반으로 Manager 호출이 올바르게 이루어지는지 확인
     */
    public function test_deactivate_template_successfully(): void
    {
        // Arrange
        $identifier = 'test-template';
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'status' => ExtensionStatus::Inactive->value,
        ];

        $this->templateManager
            ->shouldReceive('deactivateTemplate')
            ->once()
            ->with($identifier)
            ->andReturnUsing(function () use ($template) {
                $template->update(['status' => ExtensionStatus::Inactive->value]);

                return true;
            });

        // deactivateTemplate은 getTemplateInfo()를 호출함
        // loadTemplates()는 setUp에서 zeroOrMoreTimes로 설정됨
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        // Act
        $result = $this->templateService->deactivateTemplate($identifier);

        // Assert: deactivateTemplate은 배열을 반환함
        $this->assertIsArray($result);
        $this->assertEquals(ExtensionStatus::Inactive->value, $result['status']);
        $this->assertEquals(ExtensionStatus::Inactive->value, $template->fresh()->status);
    }

    /**
     * deactivateTemplate 메서드가 실패 시 예외를 발생시키는지 테스트
     */
    public function test_deactivate_template_throws_exception_on_failure(): void
    {
        // Arrange
        $identifier = 'test-template';

        Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);

        $this->templateManager
            ->shouldReceive('deactivateTemplate')
            ->once()
            ->with($identifier)
            ->andThrow(new \Exception('Deactivation failed'));

        // Act & Assert
        $this->expectException(ValidationException::class);
        $this->templateService->deactivateTemplate($identifier);
    }
}
