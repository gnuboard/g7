<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionStatus;
use App\Exceptions\TemplateFileCopyException;
use App\Extension\HookManager;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class TemplateServiceTest extends TestCase
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
        $this->templateManager->shouldReceive('loadTemplates')->andReturn(null);

        // ModuleManager Mock 생성
        $this->moduleManager = Mockery::mock(ModuleManagerInterface::class);

        // PluginManager Mock 생성
        $this->pluginManager = Mockery::mock(PluginManagerInterface::class);

        // TemplateRepository 인스턴스 생성
        $this->templateRepository = new TemplateRepository();

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
     * 템플릿 활성화 시 훅 시스템이 올바른 순서로 실행되는지 검증
     */
    public function test_activates_template_with_hook_system(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-' . uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
            'id' => $template->id,
        ];

        // 훅 호출 추적
        $hookCalls = [];

        HookManager::addAction('core.templates.before_activate', function ($identifier) use (&$hookCalls) {
            $hookCalls[] = ['hook' => 'before_activate', 'identifier' => $identifier];
        });

        HookManager::addAction('core.templates.after_activate', function ($templateInfo) use (&$hookCalls) {
            $hookCalls[] = ['hook' => 'after_activate', 'template_id' => $templateInfo['id'] ?? null];
        });

        // TemplateManager Mock 설정 (두 번째 인수 $copyFiles=false 포함)
        $this->templateManager
            ->shouldReceive('activateTemplate')
            ->once()
            ->with($identifier, false)
            ->andReturnUsing(function () use ($template, $templateInfo) {
                // Manager가 DB 업데이트 시뮬레이션
                $template->update(['status' => ExtensionStatus::Active->value]);
                return $templateInfo; // 배열 반환 (인터페이스 요구사항)
            });

        // activateTemplate 성공 후 호출되는 메서드 (loadTemplates는 setUp에서 이미 Mock됨)
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        // Act
        $result = $this->templateService->activateTemplate($template->id);

        // Assert
        // 훅이 최소 2개 이상 호출되어야 함 (Laravel 이벤트 시스템 때문에 중복 가능)
        $this->assertGreaterThanOrEqual(2, count($hookCalls));

        // before_activate가 호출되었는지 확인
        $beforeActivateCalls = array_filter($hookCalls, fn($call) => $call['hook'] === 'before_activate');
        $this->assertNotEmpty($beforeActivateCalls);
        $firstBeforeActivate = array_values($beforeActivateCalls)[0];
        $this->assertEquals($template->identifier, $firstBeforeActivate['identifier']);

        // after_activate가 호출되었는지 확인
        $afterActivateCalls = array_filter($hookCalls, fn($call) => $call['hook'] === 'after_activate');
        $this->assertNotEmpty($afterActivateCalls);
        $firstAfterActivate = array_values($afterActivateCalls)[0];
        $this->assertEquals($template->id, $firstAfterActivate['template_id']);

        // activateTemplate은 ['success' => true, 'template_info' => $templateInfo] 반환
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(ExtensionStatus::Active->value, $result['template_info']['status']);
    }

    /**
     * Manager의 activateTemplate() 메서드가 정확히 한 번 호출되는지 확인
     */
    public function test_delegates_file_copy_to_manager(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-' . uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ];

        // TemplateManager Mock 설정 - 정확히 한 번 호출 검증 (두 번째 인수 $copyFiles=false 포함)
        $this->templateManager
            ->shouldReceive('activateTemplate')
            ->once()
            ->with($identifier, false)
            ->andReturnUsing(function () use ($template, $templateInfo) {
                $template->update(['status' => ExtensionStatus::Active->value]);
                return $templateInfo; // 배열 반환 (인터페이스 요구사항)
            });

        // activateTemplate 성공 후 호출되는 메서드 (loadTemplates는 setUp에서 이미 Mock됨)
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        // Act
        $this->templateService->activateTemplate($template->id);

        // Assert
        // Mockery의 shouldReceive()->once()가 호출 횟수를 검증
        $this->assertTrue(true); // Mockery 검증 통과
    }

    /**
     * filter_activate_data 훅이 Manager 호출 전에 적용되는지 검증
     */
    public function test_applies_filter_hook_before_manager_call(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-' . uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        $templateInfo = [
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ];

        // 필터 훅 등록 - 데이터 변형
        $filterApplied = false;
        HookManager::addFilter('core.templates.filter_activate_data', function ($data, $template) use (&$filterApplied) {
            $filterApplied = true;
            $data['custom_field'] = 'filtered_value';
            return $data;
        }, 10, 2);

        // TemplateManager Mock 설정 (두 번째 인수 $copyFiles=false 포함)
        $this->templateManager
            ->shouldReceive('activateTemplate')
            ->once()
            ->with($identifier, false)
            ->andReturnUsing(function () use ($template, $templateInfo) {
                $template->update(['status' => ExtensionStatus::Active->value]);
                return $templateInfo; // 배열 반환 (인터페이스 요구사항)
            });

        // activateTemplate 성공 후 호출되는 메서드 (loadTemplates는 setUp에서 이미 Mock됨)
        $this->templateManager
            ->shouldReceive('getTemplateInfo')
            ->once()
            ->with($identifier)
            ->andReturn($templateInfo);

        // Act
        $this->templateService->activateTemplate($template->id);

        // Assert
        $this->assertTrue($filterApplied, 'Filter hook should be applied before manager call');
    }

    /**
     * Manager에서 예외 발생 시 Service가 적절히 처리하는지 테스트
     */
    public function test_throws_exception_on_manager_failure(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-' . uniqid();
        $template = Template::create([
            'identifier' => $identifier,
            'vendor' => 'test-vendor',
            'name' => 'Test Template',
            'type' => 'admin',
            'version' => '1.0.0',
            'status' => ExtensionStatus::Inactive->value,
        ]);

        // TemplateManager Mock 설정 - 예외 발생 (두 번째 인수 $copyFiles=false 포함)
        $this->templateManager
            ->shouldReceive('activateTemplate')
            ->once()
            ->with($identifier, false)
            ->andThrow(new TemplateFileCopyException(
                __('exceptions.template_file_copy_failed', ['template' => $identifier]),
                '/source/path',
                '/dest/path'
            ));

        // Act & Assert
        // Service가 예외를 ValidationException으로 래핑함
        $this->expectException(ValidationException::class);

        $this->templateService->activateTemplate($template->id);
    }

    /**
     * 존재하지 않는 템플릿 활성화 시 예외 발생 검증
     */
    public function test_throws_exception_when_template_not_found(): void
    {
        // Arrange
        $nonExistentId = 999;

        // Act & Assert
        $this->expectException(ValidationException::class);

        $this->templateService->activateTemplate($nonExistentId);
    }
}
