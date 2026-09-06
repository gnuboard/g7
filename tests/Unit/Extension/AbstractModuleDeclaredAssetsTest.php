<?php

namespace Tests\Unit\Extension;

use App\Extension\AbstractModule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AbstractModule::getDeclaredAssetAbsolutePaths() 단위 테스트
 *
 * 이 게터는 **선언 축**이다 — 매니페스트가 선언한 산출물의 절대 경로를 파일 존재와
 * 무관하게 돌려준다. `getBuiltAssetAbsolutePaths()` 는 `file_exists()` 게이트라
 * 소실된 산출물이 목록에서 사라져 "선언은 있는데 파일이 없다" 를 셀 수 없다.
 */
class AbstractModuleDeclaredAssetsTest extends TestCase
{
    private string $moduleDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleDir = storage_path('framework/testing/declared-assets-module/vendor-sample');
        File::ensureDirectoryExists($this->moduleDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->moduleDir));
        parent::tearDown();
    }

    /**
     * 임시 디렉토리를 모듈 루트로 쓰는 익명 서브클래스를 만든다.
     *
     * @param  array<string, mixed>  $assets  매니페스트 assets 선언
     */
    private function moduleWithAssets(array $assets): AbstractModule
    {
        File::put(
            $this->moduleDir.'/module.json',
            json_encode(['name' => 'sample', 'version' => '1.0.0', 'assets' => $assets], JSON_UNESCAPED_UNICODE)
        );

        $root = $this->moduleDir;

        return new class($root) extends AbstractModule
        {
            public function __construct(private readonly string $root) {}

            protected function getModulePath(): string
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
        $module = $this->moduleWithAssets([
            'js' => ['output' => 'dist/js/module.iife.js'],
            'css' => ['output' => 'dist/css/module.css'],
        ]);

        $declared = $module->getDeclaredAssetAbsolutePaths();

        $this->assertSame(
            [
                'js' => $this->moduleDir.'/dist/js/module.iife.js',
                'css' => $this->moduleDir.'/dist/css/module.css',
            ],
            $declared
        );
        // 존재 게이트를 타는 게터는 같은 상태에서 비어 있다 — 두 축이 다르다
        $this->assertSame([], $module->getBuiltAssetAbsolutePaths());
    }

    /**
     * 선언하지 않은 kind 는 키 자체가 없다.
     */
    public function test_omits_undeclared_kinds(): void
    {
        $module = $this->moduleWithAssets(['js' => ['output' => 'dist/js/module.iife.js']]);

        $declared = $module->getDeclaredAssetAbsolutePaths();

        $this->assertArrayHasKey('js', $declared);
        $this->assertArrayNotHasKey('css', $declared);
    }

    /**
     * `output` 이 비어 있으면 선언으로 보지 않는다 (빈 문자열·빈 배열).
     */
    public function test_treats_empty_output_as_undeclared(): void
    {
        $module = $this->moduleWithAssets([
            'js' => ['output' => ''],
            'css' => [],
        ]);

        $this->assertSame([], $module->getDeclaredAssetAbsolutePaths());
    }

    /**
     * 산출물이 존재하되 0바이트여도 경로는 그대로 나온다 — 존재 판정은 호출자 몫이다.
     */
    public function test_returns_path_for_present_zero_byte_artifact(): void
    {
        $module = $this->moduleWithAssets(['css' => ['output' => 'dist/css/module.css']]);
        File::ensureDirectoryExists($this->moduleDir.'/dist/css');
        File::put($this->moduleDir.'/dist/css/module.css', '');

        $path = $module->getDeclaredAssetAbsolutePaths()['css'];

        $this->assertSame($this->moduleDir.'/dist/css/module.css', $path);
        $this->assertTrue(is_file($path));
        $this->assertSame(0, filesize($path));
    }
}
