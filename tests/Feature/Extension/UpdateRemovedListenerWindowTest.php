<?php

namespace Tests\Feature\Extension;

use App\Console\Helpers\ArtisanCommandRunner;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\Support\CarriedClassFileLedger;
use App\Services\StorageLeftoverPruneService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 새 판이 훅 리스너 클래스를 지운 판일 때, 폴더 교체~훅 캐시 재생성 창의 요청이 500 이 나지 않는다 (FX-U 잔여).
 *
 * 그 창의 요청은 옛 훅 캐시로 옛 리스너를 등록하고, 옛 소스 classmap(autoload-extensions.php `src_classmap`)이 가리키는 파일을
 * include 한다. 교체가 그 파일을 지웠으면 include 경고가 예외로 바뀌어 500 이었다(`listenerClassExists()` 의 존재 검사도
 * classmap include 를 거치므로 막지 못함). 교체 순서를 「지워질 클래스 파일을 새 트리에 함께 실어 교체 → 훅 캐시 재생성 →
 * 그 파일 정리」로 바꾼다 — 교체가 원자적 rename 이든 Windows 파일 단위 폴백이든 새 트리에 처음부터 들어 있다.
 *
 * @scenario-manifest tests/scenarios/extension-update-removed-listener-window.yaml
 */
class UpdateRemovedListenerWindowTest extends TestCase
{
    /** @var string 시험용 확장 루트 (modules 흉내) */
    private string $base;

    /** @var string 옛 판 리스너 FQCN */
    private string $listener;

    /** @var callable|null 옛 소스 classmap 흉내 로더 */
    private $loader = null;

    /**
     * 옛 판 활성 폴더(리스너 있음)와 새 판 스테이징(리스너 없음)을 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = 'W'.substr(md5(uniqid('', true)), 0, 8);
        $this->listener = "RemovedListenerFixture\\{$suffix}\\OldListener";
        $this->base = storage_path('app/test-e04-'.$suffix);

        $active = $this->base.'/vendor-e04';
        File::ensureDirectoryExists($active.'/src/Listeners');
        File::put($active.'/module.php', '<?php // old');
        File::put($active.'/src/Listeners/OldListener.php', "<?php\nnamespace RemovedListenerFixture\\{$suffix};\nclass OldListener { public function handle(\$v) { return \$v.'+old'; } }\n");
        File::put($active.'/src/Listeners/Kept.php', '<?php // kept');

        $staging = $this->base.'/_pending/vendor-e04_staging';
        File::ensureDirectoryExists($staging.'/src/Listeners');
        File::put($staging.'/module.php', '<?php // new');
        File::put($staging.'/src/Listeners/Kept.php', '<?php // kept new');

        // 옛 소스 classmap 흉내 — Composer classmap 처럼 파일 존재를 확인하지 않고 include 한다
        $map = [$this->listener => $active.'/src/Listeners/OldListener.php'];
        $this->loader = static function (string $class) use ($map): void {
            if (isset($map[$class])) {
                include $map[$class];
            }
        };
        spl_autoload_register($this->loader);
    }

    /**
     * 로더·폴더·훅을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        spl_autoload_unregister($this->loader);
        HookManager::clearFilter('e04.window.filter');
        HookListenerRegistrar::clear();
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /**
     * 교체 직후(훅 캐시 재생성 전) 옛 구독의 훅을 발화해도 실패하지 않고, 재생성 뒤 정리에서 지워질 파일만 지운다.
     *
     * @scenario case=listener_removed_in_new_version
     *
     * @effects window_dispatch_does_not_fail, carried_files_removed_after_regeneration
     *
     * @return void 반환값 없음
     */
    public function test_dispatch_in_replace_window_does_not_fail(): void
    {
        $active = $this->base.'/vendor-e04';
        $staging = $this->base.'/_pending/vendor-e04_staging';

        // 업데이트 순서: 지워질 클래스 파일을 새 트리에 싣고 → 교체
        $carried = ExtensionPendingHelper::carryOverRemovedClassFiles($active, $staging);
        ExtensionPendingHelper::copyToActive($staging, $active);

        $this->assertSame(['src/Listeners/OldListener.php'], $carried);
        $this->assertSame('<?php // new', File::get($active.'/module.php'), '새 판으로 교체됐다');

        // 교체~훅 캐시 재생성 창: 옛 훅 캐시로 등록된 구독이 발화한다
        HookListenerRegistrar::registerFromCache($this->listener, ['e04.window.filter' => ['method' => 'handle', 'type' => 'filter']], 'vendor-e04');
        $this->assertSame('v+old', HookManager::applyFilters('e04.window.filter', 'v'), '교체 직후 옛 리스너 발화가 실패했다(지워진 클래스 include)');

        // 훅 캐시 재생성 뒤 정리 — 새 판에 없는 파일만 지운다
        $removed = ExtensionPendingHelper::removeCarriedOverClassFiles($active, $carried);
        $this->assertSame(['src/Listeners/OldListener.php'], $removed);
        $this->assertFileDoesNotExist($active.'/src/Listeners/OldListener.php');
        $this->assertSame('<?php // kept new', File::get($active.'/src/Listeners/Kept.php'), '새 판에 있는 파일은 건드리지 않는다');
    }

    /**
     * 대조 — 싣지 않고 교체하면 같은 창의 발화가 지워진 파일 include 로 실패한다(종전 동작, 결함 재현).
     *
     * @scenario case=listener_removed_in_new_version
     *
     * @effects window_dispatch_does_not_fail
     *
     * @return void 반환값 없음
     */
    public function test_without_carry_over_the_window_dispatch_fails(): void
    {
        ExtensionPendingHelper::copyToActive($this->base.'/_pending/vendor-e04_staging', $this->base.'/vendor-e04');
        HookListenerRegistrar::registerFromCache($this->listener, ['e04.window.filter' => ['method' => 'handle', 'type' => 'filter']], 'vendor-e04');

        $this->expectException(\ErrorException::class);
        HookManager::applyFilters('e04.window.filter', 'v');
    }

    /**
     * 모듈·플러그인 업데이트가 교체 전에 싣고, 훅 캐시 재생성 뒤에 정리한다(순서 계약).
     *
     * @scenario case=manager_order
     *
     * @effects managers_carry_before_swap_and_clean_after_regeneration
     *
     * @return void 반환값 없음
     */
    public function test_module_and_plugin_update_order(): void
    {
        foreach (['ModuleManager' => 'function updateModule(', 'PluginManager' => 'function updatePlugin('] as $class => $signature) {
            $body = self::methodBody(app_path("Extension/{$class}.php"), $signature);

            $carry = strpos($body, 'carryOverRemovedClassFiles(');
            $swap = strpos($body, 'ExtensionPendingHelper::copyToActive($stagingPath');
            $regen = strrpos($body, 'regenerateHookCache()');
            $finish = strpos($body, 'finishCarriedOverClassFiles(');

            $this->assertNotFalse($carry, "{$class}: 교체 전에 지워질 클래스 파일을 새 트리에 싣지 않는다");
            $this->assertNotFalse($finish, "{$class}: 훅 캐시 재생성 뒤 정리가 없다");
            $this->assertLessThan($swap, $carry, "{$class}: 싣기가 교체보다 늦다");
            $this->assertGreaterThan($regen, $finish, "{$class}: 정리가 훅 캐시 재생성보다 이르다");
        }
    }

    /**
     * 같은 판 강제 재적용(`*:install --force` 의 번들·대기 재복사, `extension:reset-files-from-bundled`)도 교체 때 실어 두고
     * 오토로드·훅 캐시 재생성 뒤에 정리한다 — 번들 내용이 같은 판 번호로 바뀌어도 같은 창이 생긴다(순서 계약).
     *
     * @scenario case=same_version_force_order
     *
     * @effects managers_carry_before_swap_and_clean_after_regeneration
     *
     * @return void 반환값 없음
     */
    public function test_same_version_force_paths_order(): void
    {
        foreach (['ModuleManager' => ['function installModule(', 'modules'], 'PluginManager' => ['function installPlugin(', 'plugins']] as $class => [$signature]) {
            $file = app_path("Extension/{$class}.php");

            $copy = self::methodBody($file, 'function copyFromPendingOrBundled(');
            $this->assertStringContainsString('retainRemovedClassFiles: $force', $copy, "{$class}: 강제 재복사가 지워질 클래스 파일을 새 트리에 싣지 않는다");

            $install = self::methodBody($file, $signature);
            $autoload = strpos($install, 'updateComposerAutoload()');
            $finish = strpos($install, 'finishCarriedOverClassFiles(');
            $this->assertNotFalse($finish, "{$class}: 강제 재설치가 훅 캐시 재생성 뒤 정리를 하지 않는다");
            $this->assertGreaterThan($autoload, $finish, "{$class}: 정리가 오토로드·훅 캐시 재생성보다 이르다");
        }

        $sync = self::methodBody(app_path('Services/Extension/ExtensionFileSyncService.php'), 'function sync(');
        $this->assertStringContainsString('retainRemovedClassFiles: true', $sync, 'reset-files-from-bundled 교체가 지워질 클래스 파일을 싣지 않는다');
        $finish = strpos($sync, 'finishCarriedOverClassFiles(');
        $this->assertNotFalse($finish, 'reset-files-from-bundled 가 훅 캐시 재생성 뒤 정리를 하지 않는다');
        $this->assertGreaterThan(strpos($sync, 'updateComposerAutoload()'), $finish);
    }

    /**
     * 강제 재적용의 원본(`_bundled`)은 건드리지 않고 교체된 활성 폴더에만 옛 클래스 파일을 싣는다.
     *
     * @scenario case=same_version_force_order
     *
     * @effects window_dispatch_does_not_fail
     *
     * @return void 반환값 없음
     */
    public function test_copy_to_active_retains_without_touching_source(): void
    {
        $source = $this->base.'/_pending/vendor-e04_staging';
        $active = $this->base.'/vendor-e04';

        $carried = ExtensionPendingHelper::copyToActive($source, $active, null, retainRemovedClassFiles: true);

        $this->assertSame(['src/Listeners/OldListener.php'], $carried);
        $this->assertFileExists($active.'/src/Listeners/OldListener.php', '교체된 활성 폴더에 옛 클래스 파일이 없다');
        $this->assertFileDoesNotExist($source.'/src/Listeners/OldListener.php', '원본(번들)을 오염시켰다');

        HookListenerRegistrar::registerFromCache($this->listener, ['e04.window.filter' => ['method' => 'handle', 'type' => 'filter']], 'vendor-e04');
        $this->assertSame('v+old', HookManager::applyFilters('e04.window.filter', 'v'));
    }

    /**
     * 시험용 정리 대기 목록·명령 실행기를 컨테이너에 묶습니다.
     *
     * @return object{fresh: array<int, string>} 새 프로세스로 부른 명령 기록
     */
    private function bindLedgerAndRunner(): object
    {
        $ledgerPath = $this->base.'/carried-ledger.json';
        $this->app->instance(CarriedClassFileLedger::class, new class($ledgerPath) extends CarriedClassFileLedger
        {
            /**
             * 시험 경로를 쓰는 대기 목록을 만듭니다.
             *
             * @param  string  $file  대기 목록 파일 경로
             */
            public function __construct(private string $file) {}

            /**
             * 시험 경로를 돌려줍니다.
             *
             * @return string 경로
             */
            public function path(): string
            {
                return $this->file;
            }
        });

        $calls = new \stdClass;
        $calls->fresh = [];
        $this->app->instance(ArtisanCommandRunner::class, new class($calls) extends ArtisanCommandRunner
        {
            /**
             * 새 프로세스 호출을 기록하는 실행기를 만듭니다.
             *
             * @param  \stdClass  $calls  호출 기록
             */
            public function __construct(private \stdClass $calls) {}

            /**
             * 새 프로세스 실행을 기록만 합니다.
             *
             * @param  string  $command  명령
             * @param  array<string, mixed>  $parameters  인자
             * @return array{exit_code: int, output: string} 성공
             */
            public function runFresh(string $command, array $parameters = []): array
            {
                $this->calls->fresh[] = $command;

                return ['exit_code' => 0, 'output' => ''];
            }
        });

        return $calls;
    }

    /**
     * 업데이트 끝의 정리는 바로 지우지 않고 대기 목록에 적는다 — 재생성 전에 부팅해 옛 훅 목록을 쥔 「진행 중 요청」이 정리 뒤에
     * 발화해도 실패하지 않는다. 훅 캐시는 새 프로세스(`hooks:cache`)로 다시 구워 이 프로세스가 쥔 옛 확장 클래스의 선언을 쓰지 않는다.
     *
     * @scenario case=in_flight_after_cleanup
     *
     * @effects in_flight_request_after_cleanup_does_not_fail, hook_cache_regenerated_in_fresh_process
     *
     * @return void 반환값 없음
     */
    public function test_finish_defers_removal_and_regenerates_hooks_in_fresh_process(): void
    {
        $calls = $this->bindLedgerAndRunner();
        $active = $this->base.'/vendor-e04';

        $carried = ExtensionPendingHelper::copyToActive($this->base.'/_pending/vendor-e04_staging', $active, null, retainRemovedClassFiles: true);
        // 진행 중 요청: 정리 전에 부팅해 옛 훅 캐시로 구독을 등록해 둔다
        HookListenerRegistrar::registerFromCache($this->listener, ['e04.window.filter' => ['method' => 'handle', 'type' => 'filter']], 'vendor-e04');

        $this->app->make(ExtensionManager::class)->finishCarriedOverClassFiles($active, $carried);

        $this->assertSame('v+old', HookManager::applyFilters('e04.window.filter', 'v'), '정리 뒤 진행 중 요청의 발화가 지운 파일 include 로 실패했다');
        $this->assertFileExists($active.'/src/Listeners/OldListener.php', '정리가 진행 중 요청보다 먼저 파일을 지웠다');
        $this->assertArrayHasKey(str_replace('\\', '/', $active), $this->app->make(CarriedClassFileLedger::class)->read(), '정리 대기 목록에 적지 않았다');
        $this->assertSame(['hooks:cache'], $calls->fresh, '훅 캐시를 새 프로세스로 다시 굽지 않았다(옛 확장 클래스를 쥔 이 프로세스로 구우면 지운 리스너가 남는다)');
    }

    /**
     * 대기 목록은 안전 지점(예약 정리 `storage:prune-leftovers`)에서 기록 뒤 충분한 시간이 지난 항목만 지운다.
     *
     * @scenario case=in_flight_after_cleanup
     *
     * @effects carried_files_removed_after_regeneration
     *
     * @return void 반환값 없음
     */
    public function test_ledger_is_swept_at_safe_point(): void
    {
        $this->bindLedgerAndRunner();
        $active = $this->base.'/vendor-e04';
        $ledger = $this->app->make(CarriedClassFileLedger::class);

        $ledger->record($active, ['src/Listeners/OldListener.php'], time() - CarriedClassFileLedger::MIN_AGE_SECONDS + 60);
        $result = $this->app->make(StorageLeftoverPruneService::class)->prune();
        $this->assertSame([], $result['carried_class_files'] ?? null, '최장 요청 시간이 지나기 전에 지웠다');
        $this->assertFileExists($active.'/src/Listeners/OldListener.php');

        $ledger->record($active, ['src/Listeners/OldListener.php'], time() - CarriedClassFileLedger::MIN_AGE_SECONDS - 1);
        $result = $this->app->make(StorageLeftoverPruneService::class)->prune();
        $this->assertNotEmpty($result['carried_class_files'] ?? [], '안전 지점에서 대기 목록을 비우지 않았다');
        $this->assertFileDoesNotExist($active.'/src/Listeners/OldListener.php');
        $this->assertSame([], $ledger->read(), '정리한 항목이 대기 목록에 남았다');
    }

    /**
     * 대기 목록 항목을 나이를 지난 것으로 바꿉니다(600초 경과 흉내).
     *
     * @param  string  $active  활성 디렉토리
     * @return void 반환값 없음
     */
    private function ageLedgerEntry(string $active): void
    {
        $ledger = $this->app->make(CarriedClassFileLedger::class);
        $entries = $ledger->read();
        foreach ($entries as $key => $entry) {
            if (($entry['active'] ?? null) === $active) {
                $entries[$key]['recorded_at'] = time() - CarriedClassFileLedger::MIN_AGE_SECONDS - 1;
            }
        }
        File::put($ledger->path(), (string) json_encode($entries));
    }

    /**
     * 리스너를 뺀 판 → 대기 목록 → 600초 경과 → 그 파일을 같은 내용으로 다시 출하한 판으로 업데이트하면, 앞머리 정리가 새 판의
     * 파일을 지우지 않는다(지우면 선언·훅 캐시에는 리스너가 있는데 파일이 없어 조용히 건너뛴다).
     *
     * @scenario case=reshipped_after_safe_age
     *
     * @effects reshipped_file_survives_ledger_sweep
     *
     * @return void 반환값 없음
     */
    public function test_reshipped_file_survives_next_update_sweep(): void
    {
        $this->bindLedgerAndRunner();
        $active = $this->base.'/vendor-e04';
        $oldContent = File::get($active.'/src/Listeners/OldListener.php');

        // 리스너를 뺀 판으로 업데이트 → 대기 목록에 오름
        $carried = ExtensionPendingHelper::copyToActive($this->base.'/_pending/vendor-e04_staging', $active, null, retainRemovedClassFiles: true);
        $this->app->make(ExtensionManager::class)->finishCarriedOverClassFiles($active, $carried);
        $this->ageLedgerEntry($active);

        // 같은 내용으로 다시 출하한 판
        $next = $this->base.'/_pending/vendor-e04_next';
        File::ensureDirectoryExists($next.'/src/Listeners');
        File::put($next.'/module.php', '<?php // next');
        File::put($next.'/src/Listeners/Kept.php', '<?php // kept new');
        File::put($next.'/src/Listeners/OldListener.php', $oldContent);

        $carried = ExtensionPendingHelper::copyToActive($next, $active, null, retainRemovedClassFiles: true);
        $this->app->make(ExtensionManager::class)->finishCarriedOverClassFiles($active, $carried);

        $this->assertFileExists($active.'/src/Listeners/OldListener.php', '새 판이 출하한 파일을 앞머리 정리가 지웠다');
        HookListenerRegistrar::registerFromCache($this->listener, ['e04.window.filter' => ['method' => 'handle', 'type' => 'filter']], 'vendor-e04');
        $this->assertSame('v+old', HookManager::applyFilters('e04.window.filter', 'v'), '다시 출하한 리스너가 동작하지 않는다');
    }

    /**
     * 예약 정리도 — 대기 목록에 오른 뒤 다른 경로(재설치 등)가 그 확장 폴더를 같은 내용의 파일로 다시 채웠으면 지우지 않는다.
     *
     * @scenario case=reshipped_after_safe_age
     *
     * @effects reshipped_file_survives_ledger_sweep
     *
     * @return void 반환값 없음
     */
    public function test_reshipped_file_survives_scheduled_prune(): void
    {
        $this->bindLedgerAndRunner();
        $active = $this->base.'/vendor-e04';
        $oldContent = File::get($active.'/src/Listeners/OldListener.php');

        $carried = ExtensionPendingHelper::copyToActive($this->base.'/_pending/vendor-e04_staging', $active, null, retainRemovedClassFiles: true);
        $this->app->make(ExtensionManager::class)->finishCarriedOverClassFiles($active, $carried);
        $this->ageLedgerEntry($active);

        // 재설치처럼 정리 기록 없이 같은 내용의 판으로 폴더를 교체
        $reinstall = $this->base.'/_pending/vendor-e04_reinstall';
        File::ensureDirectoryExists($reinstall.'/src/Listeners');
        File::put($reinstall.'/module.php', '<?php // reinstall');
        File::put($reinstall.'/src/Listeners/OldListener.php', $oldContent);
        ExtensionPendingHelper::copyToActive($reinstall, $active);

        $this->app->make(StorageLeftoverPruneService::class)->prune();

        $this->assertFileExists($active.'/src/Listeners/OldListener.php', '예약 정리가 다시 출하된 파일을 지웠다');
    }

    /**
     * 파일에서 메서드 본문을 잘라 냅니다 — 다음 public 메서드 앞까지(CRLF 파일 대응).
     *
     * @param  string  $file  PHP 파일 경로
     * @param  string  $signature  메서드 시그니처 앞부분 (예: `function sync(`)
     * @return string 메서드 본문
     */
    private static function methodBody(string $file, string $signature): string
    {
        $source = (string) file_get_contents($file);
        $start = strpos($source, $signature);
        if ($start === false) {
            return '';
        }
        $end = preg_match('~(?:\r\n|\r|\n)    (?:public|protected|private) function ~', $source, $m, PREG_OFFSET_CAPTURE, $start + 10) === 1 ? $m[0][1] : strlen($source);

        return substr($source, $start, $end - $start);
    }
}
