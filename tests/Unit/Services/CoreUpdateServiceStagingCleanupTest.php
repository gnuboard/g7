<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 코어 업데이트 격리 디렉토리(`{pending_path}/core_{ts}`) 정리 회귀 테스트.
 *
 * 실사례(2026-09-06, 7.0.9 → 7.0.10 sudo 업데이트): ZIP 추출은 `core_{ts}/extracted/{루트}/`
 * 를 소스로 돌려주는데 정리 단계가 그 안쪽만 지워 `core_{ts}/extracted/` 껍데기가 남았고,
 * 스냅샷이 그 껍데기를 root 소유로 기록해 복원이 root 로 되돌렸다. 같은 껍데기가
 * 7.0.0 부터의 설치본마다 하나씩 남아 있었다.
 *
 * 세 층을 잠근다:
 *   1. cleanupPending 이 소스 경로를 받아도 격리 디렉토리 루트를 지운다 (부모 정리)
 *   2. 자식이 부르는 sweep 이 빈 껍데기만 치우고 파일이 있는 격리 디렉토리는 남긴다
 *   3. 스냅샷이 이번 실행의 격리 디렉토리를 제외한다 (잔존물이 root 로 되돌아가지 않는다)
 */
class CoreUpdateServiceStagingCleanupTest extends TestCase
{
    private string $base;

    private string $outside;

    private CoreUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/staging-cleanup-'.uniqid());
        $this->outside = storage_path('framework/testing/staging-outside-'.uniqid());
        File::ensureDirectoryExists($this->base);
        File::ensureDirectoryExists($this->outside);
        config(['app.update.pending_path' => $this->base]);

        $this->service = app(CoreUpdateService::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        File::deleteDirectory($this->outside);

        parent::tearDown();
    }

    // ── 1. 격리 디렉토리 루트 해석 + 정리 ─────────────────────────────────

    public function test_resolve_staging_root_은_안쪽_소스_경로에서_격리_디렉토리_루트를_돌려준다(): void
    {
        $inner = $this->base.DIRECTORY_SEPARATOR.'core_20260906_095309'
            .DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'dev-g7-develop';

        $this->assertSame(
            $this->base.DIRECTORY_SEPARATOR.'core_20260906_095309',
            $this->service->resolveStagingRoot($inner),
        );
        $this->assertSame(
            $this->base.DIRECTORY_SEPARATOR.'core_20260906_095309',
            $this->service->resolveStagingRoot($this->base.DIRECTORY_SEPARATOR.'core_20260906_095309'),
            '격리 디렉토리 자체를 넘기면 그대로',
        );
    }

    public function test_resolve_staging_root_은_pending_기준_밖_경로와_기준_자체는_건드리지_않는다(): void
    {
        $external = $this->outside.DIRECTORY_SEPARATOR.'src';

        $this->assertSame($external, $this->service->resolveStagingRoot($external), '--source 외부 경로는 위로 올라가지 않는다');
        $this->assertSame($this->base, $this->service->resolveStagingRoot($this->base), 'pending 기준 디렉토리 자체는 그대로');
        $this->assertSame($this->base.DIRECTORY_SEPARATOR.'..', $this->service->resolveStagingRoot($this->base.DIRECTORY_SEPARATOR.'..'));
    }

    /**
     * @effects cleanupPending_removes_whole_staging_root_when_given_inner_source_path
     */
    public function test_cleanup_pending_은_소스_경로를_받아도_격리_디렉토리를_통째로_지운다(): void
    {
        $staging = $this->base.DIRECTORY_SEPARATOR.'core_20260906_095309';
        $source = $staging.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'dev-g7-develop';
        File::ensureDirectoryExists($source.DIRECTORY_SEPARATOR.'app');
        File::put($source.DIRECTORY_SEPARATOR.'composer.json', '{}');
        File::put($staging.DIRECTORY_SEPARATOR.'core_update.zip', 'zip');

        $this->service->cleanupPending($source);

        $this->assertDirectoryDoesNotExist($staging, '껍데기(extracted)까지 남지 않아야 한다');
        $this->assertDirectoryExists($this->base, 'pending 기준 디렉토리는 남는다');
    }

    public function test_cleanup_pending_은_pending_기준_밖_경로를_넘기면_그_경로만_지운다(): void
    {
        $external = $this->outside.DIRECTORY_SEPARATOR.'src';
        File::ensureDirectoryExists($external);
        File::put($external.DIRECTORY_SEPARATOR.'a.txt', 'a');
        File::put($this->outside.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        $this->service->cleanupPending($external);

        $this->assertDirectoryDoesNotExist($external);
        $this->assertFileExists($this->outside.DIRECTORY_SEPARATOR.'keep.txt', '형제 항목이 남아야 한다 — 위로 올라가 지우지 않는다');
    }

    // ── 2. 자식 청소 ────────────────────────────────────────────────────────

    /**
     * @effects sweepEmptyStagingDirectories_removes_empty_core_dirs_and_keeps_dirs_with_files
     */
    public function test_sweep_은_빈_격리_디렉토리만_치우고_파일이_있는_것과_core_외_디렉토리는_남긴다(): void
    {
        $empty = $this->base.DIRECTORY_SEPARATOR.'core_20260713_065730';
        File::ensureDirectoryExists($empty.DIRECTORY_SEPARATOR.'extracted');

        $emptyDeep = $this->base.DIRECTORY_SEPARATOR.'core_20260710_111102';
        File::ensureDirectoryExists($emptyDeep.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'nested');

        $live = $this->base.DIRECTORY_SEPARATOR.'core_20260906_120000';
        File::ensureDirectoryExists($live.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'root');
        File::put($live.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'root'.DIRECTORY_SEPARATOR.'composer.json', '{}');

        $foreign = $this->base.DIRECTORY_SEPARATOR.'local_source';
        File::ensureDirectoryExists($foreign);
        File::put($this->base.DIRECTORY_SEPARATOR.'bundled-updates-manifest_x.json', '{}');

        $swept = $this->service->sweepEmptyStagingDirectories();

        $this->assertSame(2, $swept);
        $this->assertDirectoryDoesNotExist($empty);
        $this->assertDirectoryDoesNotExist($emptyDeep);
        $this->assertFileExists($live.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'root'.DIRECTORY_SEPARATOR.'composer.json', '부모가 쓰는 중인 격리 디렉토리는 남는다');
        $this->assertDirectoryExists($foreign, 'core_ 접두사가 아닌 디렉토리는 대상이 아니다');
        $this->assertFileExists($this->base.DIRECTORY_SEPARATOR.'bundled-updates-manifest_x.json');
    }

    public function test_sweep_은_pending_기준_디렉토리가_없으면_0_을_돌려준다(): void
    {
        config(['app.update.pending_path' => $this->base.DIRECTORY_SEPARATOR.'missing']);

        $this->assertSame(0, $this->service->sweepEmptyStagingDirectories());
    }

    // ── 3. 스냅샷 제외 ──────────────────────────────────────────────────────

    /**
     * @effects snapshotOwnershipDetailed_excludes_current_run_staging_root
     */
    public function test_snapshot_detailed_은_제외_경로와_그_하위를_기록하지_않는다(): void
    {
        $staging = $this->base.DIRECTORY_SEPARATOR.'core_20260906_095309';
        File::ensureDirectoryExists($staging.DIRECTORY_SEPARATOR.'extracted');
        File::put($staging.DIRECTORY_SEPARATOR.'extracted'.DIRECTORY_SEPARATOR.'composer.json', '{}');
        $older = $this->base.DIRECTORY_SEPARATOR.'core_20260713_065730';
        File::ensureDirectoryExists($older);
        File::put($this->base.DIRECTORY_SEPARATOR.'.gitignore', '*');

        $snapshot = $this->service->snapshotOwnershipDetailed([$this->base], excludes: [$staging]);

        $keys = array_map(static fn (string $k): string => str_replace('\\', '/', $k), array_keys($snapshot));
        $stagingNorm = str_replace('\\', '/', $staging);

        $this->assertNotEmpty($snapshot, '기준 디렉토리·형제 항목은 기록된다');
        $this->assertContains(str_replace('\\', '/', $older), $keys);
        $this->assertContains(str_replace('\\', '/', $this->base.DIRECTORY_SEPARATOR.'.gitignore'), $keys);
        foreach ($keys as $key) {
            $this->assertFalse(
                $key === $stagingNorm || str_starts_with($key, $stagingNorm.'/'),
                "제외 경로 하위가 스냅샷에 실렸다: {$key}",
            );
        }

        $without = $this->service->snapshotOwnershipDetailed([$this->base]);
        $this->assertGreaterThan(count($snapshot), count($without), '제외 인자를 빼면 격리 디렉토리가 기록된다 (되돌리면 red)');
    }

    public function test_부모_커맨드는_이번_실행의_격리_디렉토리를_스냅샷에서_제외한다(): void
    {
        $src = File::get(base_path('app/Console/Commands/Core/CoreUpdateCommand.php'));

        $this->assertMatchesRegularExpression(
            '/snapshotOwnershipDetailed\(\[[^\]]*\'storage\/app\/core_pending\'[^\]]*\],\s*excludes:\s*\[\$service->resolveStagingRoot\(\$pendingPath\)\]\)/s',
            $src,
            'CoreUpdateCommand 의 스냅샷 호출이 격리 디렉토리 루트를 excludes 로 넘겨야 한다',
        );
    }

    public function test_자식_두_커맨드가_모두_빈_격리_디렉토리_청소를_부른다(): void
    {
        foreach ([
            'app/Console/Commands/Core/ExecuteUpgradeStepsCommand.php',
            'app/Console/Commands/Core/ExecuteBundledUpdatesCommand.php',
        ] as $rel) {
            $this->assertStringContainsString(
                'sweepEmptyStagingDirectories()',
                File::get(base_path($rel)),
                "{$rel} 가 sweepEmptyStagingDirectories() 를 호출해야 한다 — 구버전 부모에서 올라오는 업데이트를 덮는 자리",
            );
        }
    }

    // ── 완료 안내문 ─────────────────────────────────────────────────────────

    /**
     * @effects apply_mode_incremental_prune_hint_does_not_reference_rollback_command
     */
    public function test_완료_안내문은_성공_후_동작하지_않는_명령을_가리키지_않는다(): void
    {
        $files = [
            'lang/ko/settings.php',
            'lang/en/settings.php',
            'lang-packs/_bundled/g7-core-ja/backend/ja/settings.php',
        ];

        foreach ($files as $rel) {
            $lang = require base_path($rel);
            $hint = $lang['core_update']['apply_mode_incremental_prune_hint'] ?? null;

            $this->assertIsString($hint, "{$rel} 에 안내문 키가 있어야 한다");
            $this->assertStringContainsString('--prune', $hint, "{$rel}: --prune 재실행 안내는 남긴다");
            $this->assertStringNotContainsString(
                'hotfix:rollback-stale-files',
                $hint,
                "{$rel}: 성공 경로는 백업(manifest 포함)을 지우므로 이 명령은 '백업 없음' 으로 끝난다",
            );
        }
    }
}
