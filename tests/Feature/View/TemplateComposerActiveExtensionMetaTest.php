<?php

namespace Tests\Feature\View;

use App\Http\View\Composers\TemplateComposer;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * G7Config 활성 확장 메타 노출 검증
 *
 * TemplateComposer 가 window.G7Config.activeModules / activePlugins 로
 * 노출할 활성 모듈/플러그인 메타({identifier, display_name, version})를
 * 정확히 뷰에 전달하는지 검증한다.
 *
 * 기존 modules/plugins(설정 데이터) 키와 분리된 신규 키이므로
 * 비파괴적 보강을 보장한다.
 */
class TemplateComposerActiveExtensionMetaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 같은 스위트의 GDPR 미들웨어 의존 테스트와 migrate:fresh 정합성을 맞추기 위해.
     *
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    #[Test]
    public function template_composer_emits_active_modules_meta_view_variable(): void
    {
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => 'active',
        ]);

        $capturedActiveModules = null;
        $capturedActivePlugins = null;

        $view = $this->mock(View::class);
        $view->shouldReceive('with')
            ->withArgs(function ($key, $value = null) use (&$capturedActiveModules, &$capturedActivePlugins) {
                if ($key === 'activeModulesMeta') {
                    $capturedActiveModules = $value;
                }
                if ($key === 'activePluginsMeta') {
                    $capturedActivePlugins = $value;
                }

                return true; // 모든 with() 호출 허용
            });

        $composer = app(TemplateComposer::class);
        $composer->compose($view);

        $this->assertIsArray($capturedActiveModules, 'activeModulesMeta 가 배열로 전달되어야 함');
        $this->assertIsArray($capturedActivePlugins, 'activePluginsMeta 가 배열로 전달되어야 함');

        // 각 항목 shape 검증: identifier / display_name / version
        foreach ($capturedActiveModules as $module) {
            $this->assertIsArray($module);
            $this->assertArrayHasKey('identifier', $module);
            $this->assertArrayHasKey('display_name', $module);
            $this->assertArrayHasKey('version', $module);
            $this->assertIsString($module['identifier']);
            $this->assertIsString($module['version']);
        }
        foreach ($capturedActivePlugins as $plugin) {
            $this->assertIsArray($plugin);
            $this->assertArrayHasKey('identifier', $plugin);
            $this->assertArrayHasKey('display_name', $plugin);
            $this->assertArrayHasKey('version', $plugin);
            $this->assertIsString($plugin['identifier']);
            $this->assertIsString($plugin['version']);
        }
    }

    #[Test]
    public function legacy_modules_and_plugins_keys_remain_present_for_backward_compat(): void
    {
        Template::factory()->create([
            'identifier' => 'sirsoft-admin_basic',
            'type' => 'admin',
            'status' => 'active',
        ]);

        $sawModulesKey = false;
        $sawPluginsKey = false;
        $sawModuleAssetsKey = false;
        $sawPluginAssetsKey = false;

        $view = $this->mock(View::class);
        $view->shouldReceive('with')
            ->withArgs(function ($key, $value = null) use (&$sawModulesKey, &$sawPluginsKey, &$sawModuleAssetsKey, &$sawPluginAssetsKey) {
                if ($key === 'moduleSettings') {
                    $sawModulesKey = true;
                }
                if ($key === 'pluginSettings') {
                    $sawPluginsKey = true;
                }
                if ($key === 'moduleAssets') {
                    $sawModuleAssetsKey = true;
                }
                if ($key === 'pluginAssets') {
                    $sawPluginAssetsKey = true;
                }

                return true;
            });

        $composer = app(TemplateComposer::class);
        $composer->compose($view);

        $this->assertTrue($sawModulesKey, '기존 moduleSettings 키 보존');
        $this->assertTrue($sawPluginsKey, '기존 pluginSettings 키 보존');
        $this->assertTrue($sawModuleAssetsKey, '기존 moduleAssets 키 보존');
        $this->assertTrue($sawPluginAssetsKey, '기존 pluginAssets 키 보존');
    }
}
