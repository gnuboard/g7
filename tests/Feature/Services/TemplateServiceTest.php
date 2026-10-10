<?php

namespace Tests\Feature\Services;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
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

    /**
     * 테스트 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // TemplateManager Mock 생성
        $this->templateManager = Mockery::mock(TemplateManagerInterface::class);

        // loadTemplates() 호출 허용 (생성자에서 호출됨)
        $this->templateManager->shouldReceive('loadTemplates')->andReturn(null);
        $this->templateManager->shouldReceive('ensureLoaded')->andReturn(null);

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
            $this->pluginManager,
            app(LayoutVersionRepositoryInterface::class)
        );
    }

    /**
     * 테스트 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 템플릿 활성화 시 Service 가 생명주기 훅을 직접 발화하지 않는지 검증
     *
     * before_activate·after_activate 모두 TemplateManager 가 발화한다(#138 — CLI·어드민 UI 공통 단일 발화).
     * 여기서는 Manager 를 mock 했으므로 Service 가 두 훅을 중복 발화하지 않는 것까지 잠근다.
     * Manager 쪽 발화는 ExtensionLifecycleHookContractTest 가 실제 설치로 검증한다.
     *
     * @scenario operation=activate
     *
     * @effects activates_template_with_hook_system
     *
     * @return void 반환값 없음
     */
    public function test_activates_template_with_hook_system(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
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
        // before_activate 도 Manager(여기서는 mock)의 몫 — Service 가 발화하면 CLI 와 어드민 UI 의 발화 횟수가 갈린다
        $beforeActivateCalls = array_filter($hookCalls, fn ($call) => $call['hook'] === 'before_activate');
        $this->assertEmpty($beforeActivateCalls, 'Service 가 before_activate 를 중복 발화한다');

        // after_activate 는 Manager(여기서는 mock)의 몫 — Service 는 발화하지 않는다
        $afterActivateCalls = array_filter($hookCalls, fn ($call) => $call['hook'] === 'after_activate');
        $this->assertEmpty($afterActivateCalls, 'Service 가 after_activate 를 중복 발화하면 활동 로그가 두 줄 남는다');

        // activateTemplate은 ['success' => true, 'template_info' => $templateInfo] 반환
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals(ExtensionStatus::Active->value, $result['template_info']['status']);
    }

    /**
     * Manager의 activateTemplate() 메서드가 정확히 한 번 호출되는지 확인
     *
     * @scenario operation=activate
     *
     * @effects delegates_file_copy_to_manager
     *
     * @return void 반환값 없음
     */
    public function test_delegates_file_copy_to_manager(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
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
     * filter_activate_data 훅은 Service 가 아니라 Manager 가 직전 훅 뒤에 발화한다 — Service 는 중복으로 적용하지 않는다.
     *
     * 직전 훅을 CLI·어드민 UI 공통으로 Manager 에 옮기면서 Service 의 필터가 직전 훅보다 먼저 돌게 됐다(before → filter
     * 순서 역전). 필터도 Manager 로 옮겼으므로(#138 후속 — 설계 결정) Manager 를 mock 한 이 경로에서는 필터가 돌지 않아야 한다.
     * 실제 순서는 ExtensionLifecycleHookContractTest 가 잠근다.
     *
     * @scenario operation=activate
     *
     * @effects filter_activate_data_left_to_manager_not_applied_by_service
     *
     * @return void 반환값 없음
     */
    public function test_filter_activate_data_is_left_to_manager(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
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
        $this->assertFalse($filterApplied, 'Service 가 활성화 데이터 필터를 직접 적용하면 Manager 발화와 겹쳐 두 번 돈다');
    }

    /**
     * Manager에서 예외 발생 시 Service가 적절히 처리하는지 테스트
     *
     * @scenario operation=activate
     *
     * @effects throws_exception_on_manager_failure
     *
     * @return void 반환값 없음
     */
    public function test_throws_exception_on_manager_failure(): void
    {
        // Arrange - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $identifier = 'test-template-'.uniqid();
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
     *
     * @scenario operation=activate
     *
     * @effects throws_exception_when_template_not_found
     *
     * @return void 반환값 없음
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
