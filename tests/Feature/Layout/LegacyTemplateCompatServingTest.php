<?php

namespace Tests\Feature\Layout;

use App\Enums\ExtensionStatus;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 옛 템플릿 호환 — 7.2.0 확장점이 없는 7.1.x 화면에도 플러그인 조각이 등록되고, 서빙 때 심이 끼운 자리에 붙는지 검증한다.
 */
class LegacyTemplateCompatServingTest extends TestCase
{
    use DatabaseTransactions;

    private Template $template;

    /**
     * 7.1.3 로그인 화면을 가진 활성 사용자 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->template = Template::factory()->create([
            'identifier' => 'legacy-copy-basic',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);

        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/LegacyLayouts/7.1.3/auth-login.json')), true);
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'auth/login',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'auth/login',
                'components' => $fixture['components'],
                'init_actions' => $fixture['initActions'],
            ],
        ]);
    }

    /**
     * 확장점이 없는 옛 화면이라도 호스트 화면이 있으면 그 확장점 조각을 등록 대상으로 봅니다.
     *
     * @scenario template_shape=legacy_7_1, screen=user_login
     *
     * @effects legacy_template_accepts_contract_extension_point_rows
     *
     * @return void 반환값 없음
     */
    public function test_contract_extension_point_is_applicable_to_legacy_template(): void
    {
        $service = app(LayoutExtensionService::class);

        $this->assertTrue($service->isExtensionApplicableToTemplate(['extension_point' => 'auth_login_extensions', 'components' => []], $this->template->id));
        $this->assertFalse($service->isExtensionApplicableToTemplate(['extension_point' => 'auth_register_extensions', 'components' => []], $this->template->id), '호스트 화면(auth/register)이 없으면 등록하지 않는다');
        $this->assertFalse($service->isExtensionApplicableToTemplate(['extension_point' => 'unknown_point', 'components' => []], $this->template->id));
    }

    /**
     * 서빙 응답에서는 심이 끼운 확장점에 플러그인 조각이 붙고 인계 교환이 초기화에 있으며, 편집 모드 응답에는 심이 없습니다.
     *
     * @scenario template_shape=legacy_7_1, screen=user_login
     *
     * @effects plugin_fragment_rendered_into_shimmed_extension_point, editor_response_has_no_shim
     *
     * @return void 반환값 없음
     */
    public function test_plugin_fragment_is_served_into_shimmed_extension_point(): void
    {
        LayoutExtension::factory()->create([
            'template_id' => $this->template->id,
            'extension_type' => LayoutExtensionType::ExtensionPoint,
            'target_name' => 'auth_login_extensions',
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => 'sirsoft-social_login',
            'content' => [
                'extension_point' => 'auth_login_extensions',
                'components' => [['type' => 'basic', 'name' => 'Div', 'id' => 'compat_probe_buttons']],
            ],
            'is_active' => true,
        ]);
        $service = $this->app->make(LayoutExtensionService::class);
        (new \ReflectionProperty(LayoutExtensionService::class, 'activeModules'))->setValue($service, []);
        (new \ReflectionProperty(LayoutExtensionService::class, 'activePlugins'))->setValue($service, ['sirsoft-social_login']);
        $this->app->instance(LayoutExtensionService::class, $service);
        $this->app->forgetInstance(LayoutService::class);

        $served = json_encode(app(LayoutService::class)->getLayout('legacy-copy-basic', 'auth/login'));
        $this->assertStringContainsString('"name":"auth_login_extensions"', (string) $served);
        $this->assertStringContainsString('"id":"compat_probe_buttons"', (string) $served);
        $this->assertStringContainsString('"handler":"loginHandoff"', (string) $served);

        $editor = json_encode(app(LayoutService::class)->getLayout('legacy-copy-basic', 'auth/login', true));
        $this->assertStringNotContainsString('"name":"auth_login_extensions"', (string) $editor);
        $this->assertStringNotContainsString('"handler":"loginHandoff"', (string) $editor);
    }
}
