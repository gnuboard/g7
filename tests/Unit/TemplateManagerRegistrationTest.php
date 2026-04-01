<?php

namespace Tests\Unit;

use App\Extension\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateManagerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TemplateManager가 서비스 컨테이너에 싱글톤으로 등록되는지 테스트
     */
    public function test_template_manager_is_registered_as_singleton(): void
    {
        $instance1 = app(TemplateManager::class);
        $instance2 = app(TemplateManager::class);

        $this->assertInstanceOf(TemplateManager::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /**
     * 서비스 컨테이너에서 TemplateManager를 올바르게 가져올 수 있는지 테스트
     */
    public function test_can_resolve_template_manager_from_container(): void
    {
        $templateManager = app(TemplateManager::class);

        $this->assertInstanceOf(TemplateManager::class, $templateManager);
    }

    /**
     * TemplateManager가 필수 메서드들을 가지고 있는지 테스트
     */
    public function test_template_manager_has_required_methods(): void
    {
        $templateManager = app(TemplateManager::class);

        $this->assertTrue(method_exists($templateManager, 'loadTemplates'));
        $this->assertTrue(method_exists($templateManager, 'scanTemplates'));
        $this->assertTrue(method_exists($templateManager, 'getAllTemplates'));
        $this->assertTrue(method_exists($templateManager, 'getActiveTemplate'));
        $this->assertTrue(method_exists($templateManager, 'installTemplate'));
        $this->assertTrue(method_exists($templateManager, 'activateTemplate'));
        $this->assertTrue(method_exists($templateManager, 'deactivateTemplate'));
        $this->assertTrue(method_exists($templateManager, 'uninstallTemplate'));
        $this->assertTrue(method_exists($templateManager, 'validateTemplate'));
        $this->assertTrue(method_exists($templateManager, 'getTemplatesByType'));
    }
}
