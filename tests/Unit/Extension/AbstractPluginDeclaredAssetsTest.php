<?php

namespace Tests\Unit\Extension;

use App\Extension\AbstractPlugin;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AbstractPlugin::getDeclaredAssetAbsolutePaths() 단위 테스트
 *
 * 모듈 축과 동형이다 — 한쪽만 고쳐지면 플러그인 번들의 소실 판정이 조용히 침묵한다.
 *
 * @see AbstractModuleDeclaredAssetsTest
 */
class AbstractPluginDeclaredAssetsTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pluginDir = storage_path('framework/testing/declared-assets-plugin/vendor-sample');
        File::ensureDirectoryExists($this->pluginDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->pluginDir));
        parent::tearDown();
    }

    /**
     * 임시 디렉토리를 플러그인 루트로 쓰는 익명 서브클래스를 만든다.
     *
     * @param  array<string, mixed>  $assets  매니페스트 assets 선언
     */
    private function pluginWithAssets(array $assets): AbstractPlugin
    {
        File::put(
            $this->pluginDir.'/plugin.json',
            json_encode(['name' => 'sample', 'version' => '1.0.0', 'assets' => $assets], JSON_UNESCAPED_UNICODE)
        );

        $root = $this->pluginDir;

        return new class($root) extends AbstractPlugin
        {
            public function __construct(private readonly string $root) {}

            protected function getPluginPath(): string
            {
                return $this->root;
            }
        };
    }

    /**
     * 산출물이 하나도 없어도 선언한 kind 마다 절대 경로가 나온다.
     */
    public function test_returns_declared_paths_even_when_files_are_absent(): void
    {
        $plugin = $this->pluginWithAssets([
            'js' => ['output' => 'dist/js/plugin.iife.js'],
            'css' => ['output' => 'dist/css/plugin.css'],
        ]);

        $this->assertSame(
            [
                'js' => $this->pluginDir.'/dist/js/plugin.iife.js',
                'css' => $this->pluginDir.'/dist/css/plugin.css',
            ],
            $plugin->getDeclaredAssetAbsolutePaths()
        );
        $this->assertSame([], $plugin->getBuiltAssetAbsolutePaths());
    }

    /**
     * 선언하지 않은 kind 는 키 자체가 없다.
     */
    public function test_omits_undeclared_kinds(): void
    {
        $plugin = $this->pluginWithAssets(['css' => ['output' => 'dist/css/plugin.css']]);

        $declared = $plugin->getDeclaredAssetAbsolutePaths();

        $this->assertArrayHasKey('css', $declared);
        $this->assertArrayNotHasKey('js', $declared);
    }

    /**
     * `output` 이 비어 있으면 선언으로 보지 않는다.
     */
    public function test_treats_empty_output_as_undeclared(): void
    {
        $plugin = $this->pluginWithAssets([
            'js' => ['output' => ''],
            'css' => [],
        ]);

        $this->assertSame([], $plugin->getDeclaredAssetAbsolutePaths());
    }

    /**
     * 산출물이 존재하되 0바이트여도 경로는 그대로 나온다.
     */
    public function test_returns_path_for_present_zero_byte_artifact(): void
    {
        $plugin = $this->pluginWithAssets(['css' => ['output' => 'dist/css/plugin.css']]);
        File::ensureDirectoryExists($this->pluginDir.'/dist/css');
        File::put($this->pluginDir.'/dist/css/plugin.css', '');

        $path = $plugin->getDeclaredAssetAbsolutePaths()['css'];

        $this->assertSame($this->pluginDir.'/dist/css/plugin.css', $path);
        $this->assertTrue(is_file($path));
        $this->assertSame(0, filesize($path));
    }
}
