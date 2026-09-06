<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\GeneratesComponentManifest;
use Tests\TestCase;

/**
 * components.json 종결 개행이 빌드 OS 에 좌우되지 않는지 확인합니다.
 *
 * 이 매니페스트는 `module:build` / `plugin:build` 산출물이면서 Git 추적 대상이다.
 * 종결 개행을 PHP_EOL 로 쓰면 Windows 빌드에서만 "\r\n" 이 되어, 소스를 하나도
 * 고치지 않고 다시 빌드하기만 해도 변경으로 잡힌다. 줄 내용은 같으므로 diff 는
 * 비어 보이고, 무엇이 바뀐 것인지 알 수 없는 변경만 남는다.
 */
class GeneratesComponentManifestNewlineTest extends TestCase
{
    private string $buildPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildPath = sys_get_temp_dir().'/g7-cm-'.uniqid();
        mkdir($this->buildPath.'/resources/js/components/basic', 0o777, true);
        file_put_contents($this->buildPath.'/plugin.json', json_encode(['version' => '1.2.3']));
        file_put_contents($this->buildPath.'/resources/js/components/basic/Foo.tsx', 'export const Foo = () => null;');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->buildPath);

        parent::tearDown();
    }

    /**
     * 트레이트를 쓰는 익명 클래스로 생성기를 호출합니다.
     *
     * @return array{written: bool, count: int, path: string} 작성 결과
     */
    private function generate(): array
    {
        $generator = new class
        {
            use GeneratesComponentManifest;

            /**
             * protected 생성기를 테스트에서 호출하기 위한 통로입니다.
             *
             * @param  string  $buildPath  빌드 경로
             * @return array{written: bool, count: int, path: string} 작성 결과
             */
            public function run(string $buildPath): array
            {
                return $this->generateComponentManifest($buildPath, 'sirsoft-sample');
            }
        };

        return $generator->run($this->buildPath);
    }

    /**
     * 디렉토리를 재귀 삭제합니다.
     *
     * @param  string  $dir  삭제할 디렉토리
     * @return void
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    public function test_manifest_ends_with_lf_not_platform_eol(): void
    {
        $result = $this->generate();

        $this->assertTrue($result['written']);

        $raw = file_get_contents($result['path']);

        $this->assertStringEndsWith("}\n", $raw, 'LF 로 끝나야 한다');
        $this->assertStringNotContainsString("\r", $raw, '어느 줄에도 CR 이 없어야 한다 — 있으면 Windows 빌드에서만 변경으로 잡힌다');
    }

    public function test_rebuilding_the_same_source_produces_identical_bytes(): void
    {
        $first = file_get_contents($this->generate()['path']);
        $second = file_get_contents($this->generate()['path']);

        $this->assertSame($first, $second, '같은 소스를 다시 빌드하면 바이트까지 같아야 한다');
    }
}
