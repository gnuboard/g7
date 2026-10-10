<?php

namespace Tests\Feature\LayoutMarkup;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\CollectsFragmentSources;
use App\Models\LayoutExtension;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 템플릿 활성화 시 활성 확장 fragment 의 소스 선택 테스트 (S18 P1).
 *
 * fragment 를 DB 에 등록하는 갈래는 넷인데, **템플릿 활성화**만
 * (`LayoutExtensionService::registerAllActiveExtensionsToTemplate`) `File::get`+`json_decode` 로
 * 직행해 소스(`.g7.html`)을 보지 않았다. 새 템플릿을 켜면 마크업 전용 확장점이 조용히 빠진
 * 채로 등록이 끝나는 자리였다.
 *
 * @see CollectsFragmentSources
 */
class TemplateActivationFragmentSourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 레이아웃/GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을 맞춘다.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = base_path('storage/framework/testing/tpl_activate_fragment_'.uniqid());
        File::ensureDirectoryExists($this->tmp.'/module');
        File::ensureDirectoryExists($this->tmp.'/plugin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 마크업 전용 fragment 도 템플릿 활성화 시 DB 에 등록된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_activation_registers_markup_only_fragments
     */
    public function test_template_activation_registers_markup_only_fragments(): void
    {
        $this->writeMarkupFragment($this->tmp.'/module/widget.g7.html', 'admin.dashboard.widgets');
        $this->writeMarkupFragment($this->tmp.'/plugin/panel.g7.html', 'admin.settings.panel');

        $this->bindActiveExtensions();

        $template = Template::factory()->create([
            'identifier' => 'test-markup-admin',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
        // 붙을 자리가 있는 템플릿에만 등록된다 (C10) — 두 확장점을 가진 호스트 레이아웃을 둔다.
        $this->createHostWithExtensionPoints($template, ['admin.dashboard.widgets', 'admin.settings.panel']);

        $stats = app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($template->id);

        $this->assertSame(1, $stats['modules'], '마크업 전용 모듈 fragment 가 등록된다');
        $this->assertSame(1, $stats['plugins'], '마크업 전용 플러그인 fragment 가 등록된다');

        $this->assertEqualsCanonicalizing(
            ['admin.dashboard.widgets', 'admin.settings.panel'],
            LayoutExtension::where('template_id', $template->id)->pluck('target_name')->all()
        );
    }

    /**
     * 병존하면 소스 SSoT(`.g7.html`)의 내용이 등록된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_activation_prefers_markup_over_json_sibling
     */
    public function test_template_activation_prefers_markup_over_json_sibling(): void
    {
        $this->writeMarkupFragment($this->tmp.'/module/widget.g7.html', 'admin.dashboard.widgets');
        File::put($this->tmp.'/module/widget.json', json_encode([
            'extension_point' => 'admin.dashboard.widgets',
            'mode' => 'replace',
            'priority' => 100,
            'components' => [['type' => 'basic', 'name' => 'Span', 'props' => ['text' => 'from-json']]],
        ]));

        $this->bindActiveExtensions();

        $template = Template::factory()->create([
            'identifier' => 'test-markup-admin2',
            'type' => 'admin',
            'status' => ExtensionStatus::Active->value,
        ]);
        $this->createHostWithExtensionPoints($template, ['admin.dashboard.widgets']);

        app(LayoutExtensionService::class)->registerAllActiveExtensionsToTemplate($template->id);

        $content = LayoutExtension::where('template_id', $template->id)->firstOrFail()->content;

        $this->assertSame('Div', $content['components'][0]['name'] ?? null,
            '짝이 병존하면 소스가 등록된다 (Span 이면 .json 이 이긴 것)');
    }

    /**
     * 활성 모듈·플러그인 매니저를 임시 fragment 디렉토리로 바인딩합니다.
     */
    private function bindActiveExtensions(): void
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn('sirsoft-markupmodule');
        $module->method('getExtensionsPath')->willReturn($this->tmp.'/module');
        $module->method('getLayoutExtensions')->willReturn(
            File::glob($this->tmp.'/module/*.json') ?: []
        );

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getActiveModules')->willReturn(['sirsoft-markupmodule' => $module]);

        $plugin = $this->createMock(PluginInterface::class);
        $plugin->method('getIdentifier')->willReturn('sirsoft-markupplugin');
        $plugin->method('getExtensionsPath')->willReturn($this->tmp.'/plugin');
        $plugin->method('getLayoutExtensions')->willReturn(
            File::glob($this->tmp.'/plugin/*.json') ?: []
        );

        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getActivePlugins')->willReturn(['sirsoft-markupplugin' => $plugin]);

        $this->app->instance(ModuleManager::class, $moduleManager);
        $this->app->instance(PluginManager::class, $pluginManager);
    }

    /**
     * 확장점 노드를 가진 호스트 레이아웃을 만듭니다 (적용 필터가 이 자리를 본다).
     *
     * @param  Template  $template  템플릿
     * @param  array<int, string>  $points  확장점 이름
     */
    private function createHostWithExtensionPoints(Template $template, array $points): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => 'admin_dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'admin_dashboard',
                'components' => array_map(
                    static fn (string $point): array => ['type' => 'extension_point', 'name' => $point],
                    $points,
                ),
            ],
        ]);
    }

    /**
     * fragment 소스를 씁니다.
     *
     * @param  string  $path  파일 경로
     * @param  string  $point  확장점 이름
     */
    private function writeMarkupFragment(string $path, string $point): void
    {
        File::put($path,
            "<extension extension-point=\"{$point}\" mode=\"replace\" priority=\"100\">\n  <div/>\n</extension>\n");
    }
}
