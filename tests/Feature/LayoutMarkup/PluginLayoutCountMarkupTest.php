<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 미설치 플러그인의 레이아웃 개수 집계가 소스를 세는지 확인합니다 (S18 P1).
 *
 * 설치 화면은 설치 전에 파일 시스템을 직접 세어 "레이아웃 N건" 을 보여주는데, 집계가 `.json` 만
 * 훑었다. 그래서 **마크업 전용 플러그인은 0건으로 보였고**, 짝이 병존하는 플러그인은 반대로
 * 중복 계상 위험이 있었다. 설치 경로와 같은 소스 수집 규약을 쓰면 둘 다 해소된다.
 */
class PluginLayoutCountMarkupTest extends TestCase
{
    private string $pluginDirName;

    private string $pluginPath;

    protected function setUp(): void
    {
        parent::setUp();

        // 실 개발 디렉토리를 건드리지 않도록 고유 이름을 쓰고, 만든 것만 지운다.
        $this->pluginDirName = 'g7test-markupcount_'.uniqid();
        $this->pluginPath = base_path("plugins/{$this->pluginDirName}");

        File::ensureDirectoryExists($this->pluginPath.'/resources/layouts/partials');
    }

    protected function tearDown(): void
    {
        if (str_starts_with(basename($this->pluginPath), 'g7test-markupcount_')) {
            File::deleteDirectory($this->pluginPath);
        }

        parent::tearDown();
    }

    /**
     * 마크업 전용 플러그인의 레이아웃 개수가 0 이 아니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects plugin_layout_count_includes_markup_only_layouts
     */
    public function test_counts_markup_only_layouts(): void
    {
        $this->writeLayoutMarkup('admin_dashboard');
        $this->writeLayoutMarkup('admin_settings');
        $this->writePartialMarkup('_row');

        $this->assertSame(2, $this->layoutCount(),
            '마크업 전용 플러그인도 레이아웃 개수가 잡히고, 조각(<partial>)은 제외된다');
    }

    /**
     * 짝이 병존해도 같은 논리 레이아웃은 1건으로 센다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects plugin_layout_count_does_not_double_count_pairs
     */
    public function test_does_not_double_count_authoring_pairs(): void
    {
        $this->writeLayoutMarkup('admin_dashboard');
        $this->writeLayoutJson('admin_dashboard');

        $this->assertSame(1, $this->layoutCount(),
            '소스와 산출 JSON 이 병존해도 논리 레이아웃은 하나다');
    }

    /**
     * `.json` 전용 플러그인의 집계는 종전과 같다 (회귀 방지).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects plugin_layout_count_keeps_json_only_behaviour
     */
    public function test_keeps_json_only_behaviour(): void
    {
        $this->writeLayoutJson('admin_dashboard');
        $this->writePartialJson('_row');

        $this->assertSame(1, $this->layoutCount());
    }

    /**
     * 모듈 집계도 같은 규약을 쓴다 (두 매니저가 각자 `.json` 사본을 갖고 있던 자리).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects module_layout_count_includes_markup_only_layouts
     */
    public function test_module_count_shares_the_same_convention(): void
    {
        $moduleDirName = 'g7test-markupcount_'.uniqid();
        $modulePath = base_path("modules/{$moduleDirName}");

        File::ensureDirectoryExists($modulePath.'/resources/layouts/partials');

        try {
            File::put($modulePath.'/resources/layouts/admin_dashboard.g7.html',
                "<layout name=\"admin_dashboard\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
            File::put($modulePath.'/resources/layouts/partials/_row.g7.html',
                "<partial>\n  <div/>\n</partial>\n");

            $manager = app(ModuleManager::class);
            $method = new \ReflectionMethod($manager, 'countModuleLayoutFiles');
            $method->setAccessible(true);

            $this->assertSame(1, $method->invoke($manager, $moduleDirName, null),
                '마크업 전용 모듈도 레이아웃 개수가 잡히고 조각은 제외된다');
        } finally {
            if (str_starts_with(basename($modulePath), 'g7test-markupcount_')) {
                File::deleteDirectory($modulePath);
            }
        }
    }

    /**
     * `countPluginLayoutFiles` 를 호출합니다 (protected — 설치 화면 전용 집계).
     *
     * @return int 레이아웃 개수
     */
    private function layoutCount(): int
    {
        $manager = app(PluginManager::class);

        $method = new \ReflectionMethod($manager, 'countPluginLayoutFiles');
        $method->setAccessible(true);

        return $method->invoke($manager, $this->pluginDirName);
    }

    /**
     * 본 레이아웃 소스를 씁니다.
     *
     * @param  string  $name  레이아웃 이름
     */
    private function writeLayoutMarkup(string $name): void
    {
        File::put("{$this->pluginPath}/resources/layouts/{$name}.g7.html",
            "<layout name=\"{$name}\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
    }

    /**
     * 본 레이아웃 `.json` 을 씁니다.
     *
     * @param  string  $name  레이아웃 이름
     */
    private function writeLayoutJson(string $name): void
    {
        File::put("{$this->pluginPath}/resources/layouts/{$name}.json", json_encode([
            'version' => '1.0.0',
            'layout_name' => $name,
            'components' => [['type' => 'basic', 'name' => 'Div']],
        ]));
    }

    /**
     * 조각 소스를 씁니다.
     *
     * @param  string  $name  조각 이름
     */
    private function writePartialMarkup(string $name): void
    {
        File::put("{$this->pluginPath}/resources/layouts/partials/{$name}.g7.html",
            "<partial>\n  <div/>\n</partial>\n");
    }

    /**
     * 조각 `.json` 을 씁니다.
     *
     * @param  string  $name  조각 이름
     */
    private function writePartialJson(string $name): void
    {
        File::put("{$this->pluginPath}/resources/layouts/partials/{$name}.json", json_encode([
            'meta' => ['is_partial' => true],
            'type' => 'basic',
            'name' => 'Div',
        ]));
    }
}
