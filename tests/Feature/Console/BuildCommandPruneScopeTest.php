<?php

namespace Tests\Feature\Console;

use App\Console\Commands\Concerns\PrunesBuildOutput;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 빌드 산출물 정리의 경로 범위 테스트 (#122 C1).
 *
 * `pruneBuildOutput()` 은 빌드 **전에** 실행된다. 웹이 서빙 중인 활성 디렉토리의 `dist/` 를
 * 비우면 prune~빌드 완료 구간 전체가 서빙 공백이 되고, 빌드가 실패하면 빈 채로 남는다.
 * 그래서 소스 디렉토리(`_bundled` / `_pending`)만 정리 대상이다.
 */
class BuildCommandPruneScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/prune-scope');
        File::deleteDirectory($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 트레이트를 노출하는 테스트 더블 — 커맨드 전체를 띄우지 않고 판정만 검사한다.
     */
    private function harness(): object
    {
        return new class
        {
            use PrunesBuildOutput;

            /** @var array<int, string> */
            public array $warnings = [];

            /**
             * 커맨드의 warn() 을 대체합니다.
             *
             * @param  string  $message  경고 문구
             */
            public function warn($message, $verbosity = null): void
            {
                $this->warnings[] = (string) $message;
            }

            /**
             * @param  string  $path  빌드 경로
             * @return array<int, string> 삭제된 항목
             */
            public function prune(string $path): array
            {
                return $this->pruneBuildOutput($path);
            }
        };
    }

    /**
     * 산출물이 있는 dist 를 만듭니다.
     *
     * @param  string  $buildPath  확장 루트 경로
     */
    private function seedDist(string $buildPath): void
    {
        File::ensureDirectoryExists($buildPath.'/dist/js');
        File::put($buildPath.'/dist/js/components.iife.js', '(function(){})()');
        File::ensureDirectoryExists($buildPath.'/dist/vendor/lib/1.0.0');
        File::put($buildPath.'/dist/vendor/lib/1.0.0/lib.js', 'vendor');
    }

    /**
     * `_bundled` 경로는 종전대로 정리한다 (동봉 vendor 는 보존).
     *
     * @effects prune_cleans_bundled_source_path
     */
    public function test_bundled_path_is_pruned_but_vendor_preserved(): void
    {
        $path = $this->root.'/templates/_bundled/vendor-tpl';
        $this->seedDist($path);

        $harness = $this->harness();
        $removed = $harness->prune($path);

        $this->assertContains('js', $removed);
        $this->assertDirectoryDoesNotExist($path.'/dist/js');
        $this->assertDirectoryExists($path.'/dist/vendor', '동봉 vendor 가 삭제됐다');
        $this->assertSame([], $harness->warnings);
    }

    /**
     * `_pending` 경로도 소스 보관소다 — 정리 대상.
     *
     * @effects prune_cleans_pending_source_path
     */
    public function test_pending_path_is_pruned(): void
    {
        $path = $this->root.'/modules/_pending/vendor-mod';
        $this->seedDist($path);

        $removed = $this->harness()->prune($path);

        $this->assertContains('js', $removed);
    }

    /**
     * 활성 디렉토리는 정리하지 않고 경고한다 — 서빙 공백 차단.
     *
     * @effects prune_skips_active_serving_path
     */
    public function test_active_directory_is_not_pruned_and_warns(): void
    {
        $path = $this->root.'/templates/vendor-tpl';
        $this->seedDist($path);

        $harness = $this->harness();
        $removed = $harness->prune($path);

        $this->assertSame([], $removed);
        $this->assertDirectoryExists(
            $path.'/dist/js',
            '활성 디렉토리의 산출물이 삭제됐다 — 빌드 완료까지 서빙이 끊긴다'
        );
        $this->assertNotEmpty($harness->warnings, '조용히 건너뛰면 stale 누적을 알 수 없다');
    }

    /**
     * Windows 역슬래시 경로도 같은 판정을 받는다.
     *
     * @effects prune_scope_normalizes_path_separators
     */
    public function test_backslash_paths_are_normalized(): void
    {
        // 백슬래시 리터럴을 소스에 직접 두지 않는다 — 도구 체인마다 이스케이프 해석이
        // 달라 픽스처 자신이 조용히 다른 문자열을 검사하게 된다.
        $sep = chr(92);
        $path = $this->root.'/templates/_bundled/vendor-tpl';
        $this->seedDist($path);

        $windowsStyle = str_replace('/', $sep, $path);

        $removed = $this->harness()->prune($windowsStyle);

        $this->assertContains('js', $removed, '역슬래시 경로가 _bundled 로 인식되지 않았다');
    }

    /**
     * dist 가 없으면 아무 일도 하지 않는다 (경고도 없다).
     *
     * @effects prune_is_noop_without_dist
     */
    public function test_missing_dist_is_noop(): void
    {
        $harness = $this->harness();

        $this->assertSame([], $harness->prune($this->root.'/templates/vendor-tpl'));
        $this->assertSame([], $harness->warnings);
    }
}
