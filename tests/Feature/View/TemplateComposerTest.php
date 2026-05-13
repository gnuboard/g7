<?php

namespace Tests\Feature\View;

use App\Http\View\Composers\TemplateComposer;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\View;
use Tests\TestCase;

class TemplateComposerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TemplateComposer가 활성화된 템플릿 ID를 뷰에 전달하는지 테스트
     */
    public function test_template_composer_provides_active_admin_template(): void
    {
        // Arrange: 활성화된 admin 템플릿 생성
        $activeTemplate = Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => 'active',
        ]);

        // 비활성화된 템플릿도 생성 (이건 반환되지 않아야 함)
        Template::factory()->create([
            'identifier' => 'another-admin',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        // Act: View Composer 실행
        // TemplateComposer 는 activeAdminTemplate 외에도 frontendSettings 를 전달함
        $view = $this->mock(View::class);
        $view->shouldReceive('with')
            ->once()
            ->with('activeAdminTemplate', 'sirsoft-admin_basic');
        // 나머지 with() 호출은 임의 인자 허용 (frontendSettings, pluginSettings, moduleSettings 등 다수)
        $view->shouldReceive('with')->withAnyArgs();

        $composer = app(TemplateComposer::class);
        $composer->compose($view);

        // Assert: View에 올바른 템플릿 ID가 전달되었는지 확인 (이미 mock으로 검증됨)
        $this->assertTrue(true);
    }

    /**
     * 활성화된 템플릿이 없을 때 null을 반환하는지 테스트
     */
    public function test_template_composer_provides_default_when_no_active_template(): void
    {
        // Arrange: 활성화된 템플릿 없음
        Template::factory()->create([
            'identifier' => 'inactive-template',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        // Act: View Composer 실행
        $view = $this->mock(View::class);
        $view->shouldReceive('with')
            ->once()
            ->with('activeAdminTemplate', null); // 활성화된 템플릿이 없으면 null
        // 나머지 with() 호출은 임의 인자 허용 (frontendSettings, pluginSettings, moduleSettings 등 다수)
        $view->shouldReceive('with')->withAnyArgs();

        $composer = app(TemplateComposer::class);
        $composer->compose($view);

        // Assert: View에 null이 전달되었는지 확인 (이미 mock으로 검증됨)
        $this->assertTrue(true);
    }

    /**
     * TemplateService의 getActiveTemplateIdentifier 메서드가 정상 작동하는지 테스트
     */
    public function test_template_service_returns_active_template_identifier(): void
    {
        // Arrange: 활성화된 admin 템플릿 생성
        $activeTemplate = Template::factory()->create([
            'identifier' => 'test-admin-template',
            'type' => 'admin',
            'status' => 'active',
        ]);

        // Act
        $templateService = app(TemplateService::class);
        $identifier = $templateService->getActiveTemplateIdentifier('admin');

        // Assert
        $this->assertEquals('test-admin-template', $identifier);
    }

    /**
     * TemplateService가 활성화된 템플릿이 없을 때 예외를 던지는지 테스트
     */
    public function test_template_service_returns_null_when_no_active_template(): void
    {
        // Arrange: 활성화된 템플릿 없음
        Template::factory()->create([
            'identifier' => 'inactive-template',
            'type' => 'admin',
            'status' => 'inactive',
        ]);

        // Act & Assert: 예외가 던져져야 함
        $this->expectException(\App\Exceptions\TemplateNotFoundException::class);

        $templateService = app(TemplateService::class);
        $templateService->getActiveTemplateIdentifier('admin');
    }
}
