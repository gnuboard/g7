<?php

namespace Tests\Unit\Services;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\CoreUpdate\CoreUpdatePreflight;
use App\CoreUpdate\CoreUpdateRunState;
use App\CoreUpdate\CoreUpdateTimeBudget;
use App\Execution\CommandRunnerResolver;
use App\Extension\Helpers\BundledShippedManifest;
use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 코어 업데이트의 번들 출하 목록 대조 (7.2.0).
 *
 * 기본(증분) 모드는 새 판이 지운 번들 파일(`{modules,plugins,templates,lang-packs}/_bundled/**`)을 남겼다 — 뒤이은 확장
 * 업데이트가 그 옛 파일을 활성 폴더로 옮겨 지운 화면 조각이 다시 등록됐다. 업데이트·설치가 놓은 번들 파일의 목록
 * (경로·본문 해시)을 사이트에 남기고, 다음 업데이트가 「직전 목록에는 있고 새 목록에는 없는」 파일만 지운다.
 *
 * 시나리오 SSoT: tests/scenarios/core-update-bundled-shipped-manifest.yaml
 */
class CoreUpdateBundledShippedManifestTest extends TestCase
{
    /** 임시 경로 */
    private string $root;

    /**
     * 임시 경로를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/bundled-shipped-'.uniqid());
        File::ensureDirectoryExists($this->root);
    }

    /**
     * 임시 경로를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 직전 목록에는 있고 새 목록에는 없는 파일만 지운다 — 출하 그대로인 파일은 지우고, 운영자가 고친 파일·운영자가 넣은
     * 파일(직전 목록 밖)·확장 클래스 파일·확장째 사라진 디렉토리는 남긴다. 비게 된 하위 폴더는 확장 루트 아래까지만 걷는다.
     *
     * @scenario case=prune_rules
     *
     * @effects shipped_then_removed_file_deleted, modified_shipped_file_kept, operator_file_kept, extension_class_file_retained, vanished_extension_untouched
     *
     * @return void 반환값 없음
     */
    public function test_prune_removes_only_unmodified_files_the_new_version_dropped(): void
    {
        $site = $this->root.'/site';
        $gone = 'modules/_bundled/acme-shop/resources/extensions/register-currency-field.json';
        $edited = 'modules/_bundled/acme-shop/resources/extensions/old-edited.json';
        $operator = 'modules/_bundled/acme-shop/resources/extensions/operator-added.json';
        $class = 'modules/_bundled/acme-shop/src/Listeners/OldListener.php';
        $vanished = 'plugins/_bundled/acme-custom/resources/extensions/x.json';
        $kept = 'modules/_bundled/acme-shop/module.json';

        foreach ([$gone => '{"v":1}', $edited => '{"v":1,"operator":true}', $operator => '{"mine":1}', $class => '<?php', $vanished => '{}', $kept => '{"version":"2"}'] as $path => $body) {
            File::ensureDirectoryExists(dirname($site.'/'.$path));
            File::put($site.'/'.$path, $body);
        }

        $previous = [
            $gone => md5('{"v":1}'),
            $edited => md5('{"v":1}'),
            $class => md5('<?php'),
            $vanished => md5('{}'),
            $kept => md5('{"version":"1"}'),
        ];
        $next = [$kept => md5('{"version":"2"}')];

        $report = BundledShippedManifest::pruneRemoved($site, $previous, $next);

        $this->assertSame([$gone], $report['removed']);
        $this->assertFileDoesNotExist($site.'/'.$gone, '새 판이 지운 출하 그대로의 파일이 남았다');
        $this->assertFileExists($site.'/'.$edited, '운영자가 고친 출하 파일을 지웠다');
        $this->assertSame([$edited], $report['kept_modified']);
        $this->assertFileExists($site.'/'.$operator, '운영자가 넣은 파일(직전 목록 밖)을 지웠다');
        $this->assertFileExists($site.'/'.$class, '확장 클래스 파일을 확장 갱신 전에 지웠다');
        $this->assertSame([$class], $report['retained_classes']);
        $this->assertFileExists($site.'/'.$vanished, '새 배포본에 없는 확장 디렉토리 안을 지웠다');
    }

    /**
     * 출하 목록은 트리의 번들 파일 경로·md5 를 모으고(제외 경로·이번에 놓지 않는 번들은 빼고), 나눠 만들어도 결과가 같다.
     * 기록할 때 직전 목록을 남겨 되돌리기가 돌려놓는다(직전에 목록이 없었으면 지운다).
     *
     * @scenario case=collect_and_restore
     *
     * @effects manifest_collects_bundled_files, chunked_collect_matches_full, rollback_restores_previous_manifest
     *
     * @return void 반환값 없음
     */
    public function test_collect_chunks_and_restore_previous(): void
    {
        $tree = $this->root.'/release';
        foreach (['modules/_bundled/acme-shop/a.json', 'modules/_bundled/acme-shop/node_modules/x.js', 'plugins/_bundled/acme-skip/b.json', 'templates/_bundled/acme-tpl/c.json', 'app/NotBundled.php'] as $i => $path) {
            File::ensureDirectoryExists(dirname($tree.'/'.$path));
            File::put($tree.'/'.$path, 'body'.$i);
        }

        $full = BundledShippedManifest::collect($tree, ['plugins/_bundled/acme-skip']);
        $this->assertSame(['modules/_bundled/acme-shop/a.json', 'templates/_bundled/acme-tpl/c.json'], array_keys($full));
        $this->assertSame(md5('body0'), $full['modules/_bundled/acme-shop/a.json']);

        $progress = [];
        $pauses = 0;
        do {
            $progress = BundledShippedManifest::collectChunk($tree, $progress, function () use (&$pauses): bool {
                $pauses++;

                return true;
            }, ['plugins/_bundled/acme-skip']);
        } while (! $progress['done']);
        $this->assertSame($full, $progress['files']);

        $dir = $this->root.'/state';
        $this->assertTrue(BundledShippedManifest::write(['a' => '1'], '7.2.0', $dir));
        BundledShippedManifest::restorePrevious($dir);
        $this->assertNull(BundledShippedManifest::read($dir), '처음 기록을 되돌리면 목록이 없어야 한다');

        BundledShippedManifest::write(['a' => '1'], '7.2.0', $dir);
        BundledShippedManifest::beginRun($dir); // 다음 실행
        BundledShippedManifest::write(['b' => '2'], '7.2.1', $dir);
        BundledShippedManifest::restorePrevious($dir);
        $this->assertSame(['a' => '1'], BundledShippedManifest::read($dir));
    }

    /**
     * 관리자 화면 업데이트: 적용 대상 산출 단계(나눠 이어 감)가 끝나면 이번 배포본의 출하 목록을 사이드카에 남기고, 적용 단계가
     * 그것을 서비스에 넘긴다(명령줄과 같은 대조).
     *
     * @scenario case=web_plan_step
     *
     * @effects web_plan_stores_shipped_manifest
     *
     * @return void 반환값 없음
     */
    public function test_web_plan_step_stores_the_release_shipped_manifest(): void
    {
        $pending = $this->root.'/pending';
        $backup = $this->root.'/backup';
        File::ensureDirectoryExists($pending.'/modules/_bundled/sirsoft-board');
        File::put($pending.'/modules/_bundled/sirsoft-board/module.json', '{"identifier":"sirsoft-board","version":"9.9.9"}');
        File::ensureDirectoryExists($backup);

        $state = new CoreUpdateRunState($this->root.'/state/'.CoreUpdateRunState::FILENAME);
        $state->start(new CoreUpdateOptions, '7.2.0', '7.2.1', 1);
        $state->mergeContext(['pending_path' => $pending, 'backup_path' => $backup]);
        config(['app.update.targets' => ['modules/_bundled'], 'app.update.excludes' => [], 'app.update.protected_paths' => []]);

        $service = app(CoreUpdateService::class);
        $orchestrator = new CoreUpdateOrchestrator($service, $state, new CoreUpdatePreflight($service), app(CommandRunnerResolver::class), new CoreUpdateTimeBudget(null, 300));
        (new \ReflectionMethod($orchestrator, 'stepPlan'))->invoke($orchestrator);

        $this->assertSame(
            ['modules/_bundled/sirsoft-board/module.json' => md5('{"identifier":"sirsoft-board","version":"9.9.9"}')],
            $state->loadShippedFiles(),
            '적용 대상 산출 단계가 이번 배포본의 출하 목록을 남기지 않았다'
        );
    }

    /**
     * 서비스 통합: 증분 적용이 사이트의 직전 목록과 대조해 지운 번들 파일을 정리하고 새 목록을 기록한다. 직전 목록이 없으면
     * 지우지 않고 목록만 남기며, `--prune`(전체 덮어쓰기)에서는 대조하지 않는다. 되돌리기는 지운 파일을 백업에서 되살리고
     * 목록을 업데이트 전 것으로 돌려놓는다.
     *
     * @scenario case=apply_and_rollback
     *
     * @effects incremental_apply_prunes_and_records, first_update_records_without_prune, rollback_restores_files_and_manifest, manifest_records_release_version
     *
     * @return void 반환값 없음
     */
    public function test_apply_update_prunes_against_the_site_manifest_and_rollback_restores(): void
    {
        $source = $this->root.'/src';
        $base = $this->root.'/base';
        $backup = $this->root.'/backup';
        $keep = 'modules/_bundled/acme-shop/module.json';
        $gone = 'modules/_bundled/acme-shop/resources/extensions/gone.json';

        File::ensureDirectoryExists(dirname($source.'/'.$keep));
        File::put($source.'/'.$keep, '{"version":"2"}');
        // 배포본의 판 번호 (목록에 그 판을 적는다)
        File::ensureDirectoryExists($source.'/config');
        File::put($source.'/config/app.php', "<?php\n\nreturn [\n    'version' => env('APP_VERSION', '7.2.1'),\n];\n");
        foreach ([$keep => '{"version":"2"}', $gone => '{"old":1}'] as $path => $body) {
            File::ensureDirectoryExists(dirname($base.'/'.$path));
            File::put($base.'/'.$path, $body);
            File::ensureDirectoryExists(dirname($backup.'/'.$path));
            File::put($backup.'/'.$path, $body);
        }

        $originalBase = base_path();
        $originalLang = app()->langPath();
        app()->setBasePath($base);
        app()->useLangPath($originalLang);
        config([
            'app.update.targets' => ['modules/_bundled'],
            'app.update.excludes' => [],
            'app.update.protected_paths' => ['.env', 'storage', 'vendor', 'node_modules', '.git'],
            'app.update.backup_only' => [],
        ]);

        try {
            $service = app(CoreUpdateService::class);
            $shipped = BundledShippedManifest::collect($source);

            // 직전 목록이 없는 첫 업데이트 — 지우지 않고 기록만
            $first = $service->applyUpdate($source, null, prune: false, applyList: [], shippedFiles: $shipped);
            $this->assertSame([], $first['bundled_prune']['removed']);
            $this->assertTrue($first['bundled_prune']['recorded']);
            $this->assertFileExists($base.'/'.$gone);

            // 직전 목록에 그 파일이 있던 사이트(이전 판이 출하) — 새 판에서 빠졌으니 지운다
            BundledShippedManifest::write([$keep => md5('{"version":"1"}'), $gone => md5('{"old":1}')]);
            BundledShippedManifest::beginRun(); // 다음 실행 시작
            $second = $service->applyUpdate($source, null, prune: false, applyList: [], shippedFiles: $shipped);
            $this->assertSame([$gone], $second['bundled_prune']['removed']);
            $this->assertFileDoesNotExist($base.'/'.$gone, '새 판이 지운 번들 파일이 남았다');
            $this->assertSame($shipped, BundledShippedManifest::read());
            $recorded = json_decode((string) file_get_contents(BundledShippedManifest::path()), true);
            $this->assertSame('7.2.1', $recorded['version'] ?? null, '목록에 이번 배포본의 판을 적지 않았다');

            // 되돌리기 — 지운 파일이 돌아오고 목록이 업데이트 전 것으로
            $service->restoreFromBackup($backup);
            $this->assertFileExists($base.'/'.$gone, '되돌리기가 지운 번들 파일을 되살리지 않았다');
            $this->assertSame([$keep => md5('{"version":"1"}'), $gone => md5('{"old":1}')], BundledShippedManifest::read());
        } finally {
            app()->setBasePath($originalBase);
            app()->useLangPath($originalLang);
        }
    }

    /**
     * 씨앗(설치·전환 스텝)은 되돌릴 사본을 남기지 않는다 — 그 뒤 업데이트가 파일 적용 전에 실패해 되돌리면 목록은 그대로다.
     * 종전에는 씨앗이 「없음」 사본을 남겨, 적용 전 실패의 되돌리기가 목록을 지웠다.
     *
     * @scenario case=rollback_before_apply
     *
     * @effects seeded_manifest_survives_rollback_before_apply
     *
     * @return void 반환값 없음
     */
    public function test_rollback_before_apply_keeps_the_seeded_manifest(): void
    {
        $dir = $this->root.'/state';
        BundledShippedManifest::write(['a' => '1'], '7.2.0', $dir, keepPrevious: false);
        BundledShippedManifest::beginRun($dir);

        // 적용 전에 실패한 되돌리기
        BundledShippedManifest::restorePrevious($dir);

        $this->assertSame(['a' => '1'], BundledShippedManifest::read($dir), '적용 전 실패의 되돌리기가 씨앗한 목록을 지웠다');
    }

    /**
     * 실행이 시작되면 지난 실행이 남긴 사본을 버린다 — 이번 실행이 적용 전에 실패해도 두 판 전 목록으로 후퇴하지 않는다. 같은
     * 실행에서 적용을 다시 하면(관리자 화면의 적용 재시도) 첫 쓰기 때의 사본을 지킨다 — 「새 목록」이 사본을 덮지 않는다.
     *
     * @scenario case=rollback_before_apply
     *
     * @effects stale_previous_dropped_at_run_start, rerun_apply_keeps_first_previous
     *
     * @return void 반환값 없음
     */
    public function test_run_start_drops_stale_previous_and_rerun_keeps_the_first_one(): void
    {
        $dir = $this->root.'/state';
        BundledShippedManifest::write(['old' => '1'], '7.2.0', $dir, keepPrevious: false);
        BundledShippedManifest::write(['mid' => '1'], '7.2.1', $dir);   // 지난 실행(성공) — 사본 = old

        BundledShippedManifest::beginRun($dir);                       // 이번 실행 시작
        BundledShippedManifest::restorePrevious($dir);                // 적용 전 실패
        $this->assertSame(['mid' => '1'], BundledShippedManifest::read($dir), '지난 실행의 사본으로 목록이 후퇴했다');

        BundledShippedManifest::beginRun($dir);
        BundledShippedManifest::write(['new' => '1'], '7.2.2', $dir); // 적용
        BundledShippedManifest::write(['new' => '1'], '7.2.2', $dir); // 적용 재시도
        BundledShippedManifest::restorePrevious($dir);                // 적용 뒤 실패
        $this->assertSame(['mid' => '1'], BundledShippedManifest::read($dir), '적용 재시도가 사본을 새 목록으로 덮었다');
    }

    /**
     * 대소문자를 가리지 않는 파일 시스템: 새 판이 이름의 대소문자만 바꾼 파일은 직전 목록 키(`Foo.json`)로 디스크의 새 파일이
     * 열린다 — 지우면 새 판 파일이 사라진다. 새 목록에 대소문자만 다른 같은 경로가 있으면 지우지 않는다.
     *
     * @scenario case=prune_rules
     *
     * @effects case_only_rename_not_deleted
     *
     * @return void 반환값 없음
     */
    public function test_prune_keeps_a_file_renamed_only_by_case(): void
    {
        $site = $this->root.'/site';
        $old = 'modules/_bundled/acme-shop/resources/layouts/Foo.json';
        $new = 'modules/_bundled/acme-shop/resources/layouts/foo.json';
        File::ensureDirectoryExists(dirname($site.'/'.$new));
        File::put($site.'/'.$new, '{"same":1}');
        File::put($site.'/modules/_bundled/acme-shop/module.json', '{}');

        $report = BundledShippedManifest::pruneRemoved(
            $site,
            [$old => md5('{"same":1}'), 'modules/_bundled/acme-shop/module.json' => md5('{}')],
            [$new => md5('{"same":1}'), 'modules/_bundled/acme-shop/module.json' => md5('{}')],
        );

        $this->assertSame([], $report['removed']);
        $this->assertFileExists($site.'/'.$new, '대소문자만 바뀐 새 판 파일을 지웠다');
    }

    /**
     * 운영자가 업데이트에서 뺀 경로(`app.update.excludes`)는 목록에 넣지 않고, 직전 목록에 있어도 지우지 않는다.
     *
     * @scenario case=prune_rules
     *
     * @effects update_excludes_not_listed_nor_pruned
     *
     * @return void 반환값 없음
     */
    public function test_update_excludes_are_neither_listed_nor_pruned(): void
    {
        $tree = $this->root.'/tree';
        $excluded = 'modules/_bundled/acme-shop/custom/keep.json';
        $normal = 'modules/_bundled/acme-shop/module.json';
        foreach ([$excluded, $normal] as $path) {
            File::ensureDirectoryExists(dirname($tree.'/'.$path));
            File::put($tree.'/'.$path, '{}');
        }
        config(['app.update.excludes' => ['node_modules', '.git', 'modules/_bundled/acme-shop/custom']]);

        $this->assertSame([$normal], array_keys(BundledShippedManifest::collect($tree)), '업데이트에서 뺀 경로를 목록에 넣었다');

        $report = BundledShippedManifest::pruneRemoved($tree, [$excluded => md5('{}'), $normal => md5('{}')], [$normal => md5('{}')]);
        $this->assertSame([], $report['removed']);
        $this->assertFileExists($tree.'/'.$excluded, '업데이트에서 뺀 경로를 지웠다');
    }

    /**
     * 손으로 돌린 `core:deploy-ledger-seed` 는 번들 출하 목록을 디스크로 씨앗하지 않는다 — 디스크의 운영자 파일이 「출하했던
     * 파일」로 기록되면 다음 업데이트가 지울 수 있다. 설치기만(`--fresh-install`) 설치 직후 디스크로 씨앗한다.
     *
     * @scenario case=manual_seed
     *
     * @effects manual_seed_skips_bundled_manifest, installer_seed_writes_bundled_manifest
     *
     * @return void 반환값 없음
     */
    public function test_manual_ledger_seed_does_not_seed_the_bundled_manifest(): void
    {
        $originalStorage = app()->storagePath();
        app()->useStoragePath($this->root.'/storage');
        File::ensureDirectoryExists($this->root.'/storage/app/core-update');

        try {
            $service = $this->mock(CoreUpdateService::class);
            $service->shouldReceive('seedDeployFragmentLedger')->andReturn([]);
            $service->shouldReceive('getLastRootFileWarnings')->andReturn([]);

            $this->artisan('core:deploy-ledger-seed')->assertExitCode(0);
            $this->assertNull(BundledShippedManifest::read(), '손으로 돌린 원장 씨앗이 디스크로 번들 출하 목록을 만들었다');

            $this->artisan('core:deploy-ledger-seed', ['--fresh-install' => true])->assertExitCode(0);
            $this->assertNotNull(BundledShippedManifest::read(), '설치기 씨앗이 번들 출하 목록을 만들지 않았다');
        } finally {
            app()->useStoragePath($originalStorage);
        }
    }
}
