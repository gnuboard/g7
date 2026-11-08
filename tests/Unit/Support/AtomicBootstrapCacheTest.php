<?php

namespace Tests\Unit\Support;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\AtomicBootstrapCache;
use App\Support\RouteCacheHelper;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * 부팅 캐시 파일을 비는 순간 없이 바꿔 끼우기 (#882)
 *
 * 확장을 켜고 끌 때 라우트·설정 캐시를 「지운 뒤 다시 굽는」 1~2초 사이에 다른 요청이 들어오면 `file_exists()` 를 통과한 뒤
 * `require` 직전에 파일이 지워져 500(`routes-v7.php: No such file`)이 났다 — 관리자 화면은 「초기화 실패 Failed to reload
 * routes」로 멈췄다. 훅·확장 오토로드 캐시는 제자리 덮어쓰기라 반쯤 쓰인 파일을 읽을 수 있었다. 이제 임시 파일에 다 쓴 뒤
 * `rename` 으로 바꿔 끼운다.
 */
class AtomicBootstrapCacheTest extends TestCase
{
    /**
     * 시험용 캐시 폴더(앱 폴더 안 — 환경 값은 앱 폴더 기준 상대 경로만 받는다).
     */
    private string $dir;

    /**
     * 시험 폴더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/atomic-cache-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * 시험 폴더를 지우고 환경을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        app()->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    /**
     * 굽는 동안 실제 파일은 옛 내용 그대로 남고, 다 구우면 새 내용으로 바뀐다 — 임시 파일·돌린 환경 값은 남지 않는다.
     *
     * @scenario case=regenerate_success
     *
     * @effects bootstrap_cache_never_absent_during_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_regenerate_keeps_old_file_until_new_one_is_swapped_in(): void
    {
        $target = $this->dir.'/routes-v7.php';
        File::put($target, "<?php return 'old';");
        $envBefore = Env::get('APP_ROUTES_CACHE');
        $seenDuring = null;
        $cleared = 0;

        $swapped = AtomicBootstrapCache::regenerate(
            'APP_ROUTES_CACHE',
            $target,
            function () use ($target, &$seenDuring) {
                $seenDuring = File::exists($target) ? File::get($target) : null;
                $out = app()->getCachedRoutesPath();
                $this->assertNotSame(realpath($target), realpath($out) ?: $out, '굽는 출력이 실제 파일로 가면 안 된다');
                $this->assertSame(realpath($this->dir), realpath(dirname($out)), '임시 파일은 실제 파일과 같은 폴더여야 한다');
                File::put($out, "<?php return 'new';");
            },
            function () use (&$cleared) {
                $cleared++;
            },
        );

        $this->assertTrue($swapped);
        $this->assertSame("<?php return 'old';", $seenDuring, '굽는 동안 실제 파일이 사라졌거나 바뀌었다');
        $this->assertSame("<?php return 'new';", File::get($target));
        $this->assertSame(0, $cleared, '성공하면 낡은 캐시를 지우지 않는다');
        $this->assertSame(['routes-v7.php'], array_map('basename', File::files($this->dir)), '임시 파일이 남았다');
        $this->assertSame($envBefore, Env::get('APP_ROUTES_CACHE'), '돌린 환경 값이 굽기 전 값으로 돌아오지 않았다');
    }

    /**
     * 굽기가 아무것도 만들지 못하면 낡은 캐시를 지우는 작업을 부른다 — 낡은 라우트 캐시는 폴백이 없다.
     *
     * @scenario case=regenerate_failure
     *
     * @effects stale_bootstrap_cache_removed_when_rebuild_fails
     *
     * @return void 반환값 없음
     */
    public function test_regenerate_clears_stale_when_nothing_was_generated(): void
    {
        $target = $this->dir.'/config.php';
        File::put($target, '<?php return [];');
        $envBefore = Env::get('APP_CONFIG_CACHE');
        $cleared = 0;

        $swapped = AtomicBootstrapCache::regenerate('APP_CONFIG_CACHE', $target, fn () => null, function () use (&$cleared) {
            $cleared++;
        });

        $this->assertFalse($swapped);
        $this->assertSame(1, $cleared);
        $this->assertSame($envBefore, Env::get('APP_CONFIG_CACHE'));
    }

    /**
     * 굽기가 예외를 던져도 환경 값을 되돌리고 낡은 캐시를 지운 뒤 예외를 다시 던진다.
     *
     * @scenario case=regenerate_failure
     *
     * @effects stale_bootstrap_cache_removed_when_rebuild_fails
     *
     * @return void 반환값 없음
     */
    public function test_regenerate_restores_env_and_clears_stale_on_exception(): void
    {
        $target = $this->dir.'/config.php';
        $envBefore = Env::get('APP_CONFIG_CACHE');
        $cleared = 0;

        try {
            AtomicBootstrapCache::regenerate('APP_CONFIG_CACHE', $target, function () {
                File::put(app()->getCachedConfigPath(), '<?php return [];');
                throw new RuntimeException('굽기 실패 대역');
            }, function () use (&$cleared) {
                $cleared++;
            });
            $this->fail('예외가 다시 던져지지 않았다');
        } catch (RuntimeException $e) {
            $this->assertSame('굽기 실패 대역', $e->getMessage());
        }

        $this->assertSame(1, $cleared);
        $this->assertSame($envBefore, Env::get('APP_CONFIG_CACHE'));
        $this->assertSame([], File::files($this->dir), '실패한 임시 파일이 남았다');
    }

    /**
     * write() 는 임시 파일에 다 쓴 뒤 바꿔 끼운다 — 결과 내용이 맞고 임시 파일이 남지 않는다.
     *
     * @scenario case=write
     *
     * @effects bootstrap_cache_never_absent_during_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_write_swaps_in_full_content_without_leftovers(): void
    {
        $target = $this->dir.'/hooks.php';
        File::put($target, "<?php return ['old' => true];");

        AtomicBootstrapCache::write($target, "<?php return ['new' => true];");

        $this->assertSame("<?php return ['new' => true];", File::get($target));
        $this->assertSame(['hooks.php'], array_map('basename', File::files($this->dir)));
    }

    /**
     * 다른 요청이 파일을 붙잡고 있어 바꿔 끼우기가 실패하면 짧게 다시 시도한다(Windows 「Access is denied」).
     *
     * @scenario case=promote_retry
     *
     * @effects bootstrap_cache_swap_retries_while_file_is_held
     *
     * @return void 반환값 없음
     */
    public function test_promote_retries_while_target_is_held(): void
    {
        $target = $this->dir.'/routes-v7.php';
        $temp = $this->dir.'/routes-v7.tmp.php';
        File::put($target, 'old');
        File::put($temp, 'new');
        $attempts = 0;

        File::partialMock()->shouldReceive('move')->andReturnUsing(function (string $from, string $to) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \ErrorException('rename(): Access is denied (code: 5)');
            }

            return rename($from, $to);
        });

        $this->assertTrue(AtomicBootstrapCache::promote($temp, $target));
        $this->assertSame(3, $attempts);
        $this->assertSame('new', file_get_contents($target));
    }

    /**
     * 설치된 운영 환경의 RouteCacheHelper::rebuild() 는 먼저 지우지 않는다 — route:cache 를 임시 경로로 돌려 굽고,
     * 아무것도 만들지 못했을 때만(여기서는 명령 대역) route:clear 로 낡은 캐시를 지운다. 시험 환경은 종전대로 지우기만 한다.
     *
     * @scenario case=route_helper_installed
     *
     * @effects bootstrap_cache_never_absent_during_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_route_helper_caches_into_temp_path_before_any_clear_when_installed(): void
    {
        $realPath = app()->getCachedRoutesPath();
        app()->detectEnvironment(fn () => 'production');
        config(['app.installer_completed' => true]);

        $calls = [];
        $pathDuringCache = null;
        Artisan::shouldReceive('call')->andReturnUsing(function ($command) use (&$calls, &$pathDuringCache) {
            $calls[] = $command;
            if ($command === 'route:cache') {
                $pathDuringCache = app()->getCachedRoutesPath();
            }

            return 0;
        });

        RouteCacheHelper::rebuild();

        $this->assertSame(['route:cache', 'route:clear'], $calls);
        $this->assertNotSame($realPath, $pathDuringCache);
        $this->assertSame(dirname($realPath), dirname($pathDuringCache));
        $this->assertSame($realPath, app()->getCachedRoutesPath());
    }

    /**
     * 훅 캐시 생성(HookCacheManager::generate)은 실제 파일을 제자리에 덮어쓰지 않는다 — 같은 폴더 임시 파일에 다 쓴 뒤
     * 실제 파일로 바꿔 끼운다. 제자리 덮어쓰기로 되돌리면 실제 경로에 직접 쓰기가 생겨 실패한다.
     *
     * @scenario case=hook_cache_generate
     *
     * @effects bootstrap_cache_never_absent_during_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_hook_cache_generate_writes_via_temp_file_then_swaps(): void
    {
        $target = $this->dir.'/hooks.php';
        File::put($target, "<?php return ['old' => true];");
        $manager = new HookCacheManager;
        (fn () => $this->cacheFilePath = $target)->call($manager);
        $writes = $this->recordFileWrites();

        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([]);
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([]);

        $manager->generate($modules, $plugins);

        $this->assertSwappedInViaTempFile($writes, $target);
        $this->assertStringContainsString("'format'", File::get($target), '새 훅 캐시 내용으로 바뀌어야 합니다.');
    }

    /**
     * 확장 오토로드 파일 생성(ExtensionManager::generateAutoloadFile)도 제자리에 덮어쓰지 않는다 — 임시 파일에 다 쓴 뒤
     * 바꿔 끼운다. 제자리 덮어쓰기로 되돌리면 실제 경로에 직접 쓰기가 생겨 실패한다.
     *
     * @scenario case=autoload_generate
     *
     * @effects bootstrap_cache_never_absent_during_rebuild
     *
     * @return void 반환값 없음
     */
    public function test_extension_autoload_generate_writes_via_temp_file_then_swaps(): void
    {
        $target = $this->dir.'/autoload-extensions.php';
        File::put($target, "<?php return ['old' => true];");
        $manager = new class(app(ModuleRepositoryInterface::class), app(PluginRepositoryInterface::class)) extends ExtensionManager
        {
            /**
             * DB·디스크 대신 가짜 모듈 매핑 1건을 돌려줍니다(0건이면 기존 파일 보존 갈래로 빠져 쓰지 않는다).
             *
             * @return array 오토로드 수집 결과
             */
            protected function collectModuleAutoloads(): array
            {
                return ['psr4' => ['I882\\Fake\\' => 'modules/i882-fake/src/'], 'classmap' => [], 'files' => [], 'vendor_autoloads' => []];
            }

            /**
             * 플러그인 매핑은 비웁니다.
             *
             * @return array 오토로드 수집 결과
             */
            protected function collectPluginAutoloads(): array
            {
                return ['psr4' => [], 'classmap' => [], 'files' => [], 'vendor_autoloads' => []];
            }

            /**
             * 소스 classmap 스캔을 건너뜁니다(가짜 경로라 스캔할 것이 없다).
             *
             * @param  array  $psr4  PSR-4 매핑
             * @return array 빈 classmap
             */
            protected function buildSourceClassmap(array $psr4): array
            {
                return [];
            }

            /**
             * 파일 지문 계산을 건너뜁니다.
             *
             * @param  array  $psr4  PSR-4 매핑
             * @return array 빈 지문
             */
            protected function fingerprintForPsr4(array $psr4): array
            {
                return [];
            }
        };
        (fn () => $this->autoloadFilePath = $target)->call($manager);
        $writes = $this->recordFileWrites();

        $manager->generateAutoloadFile();

        $this->assertSwappedInViaTempFile($writes, $target);
        $this->assertStringContainsString('I882', File::get($target), '새 오토로드 내용으로 바뀌어야 합니다.');
    }

    /**
     * File 파사드의 put·move 호출을 기록합니다(실제 동작은 그대로 수행).
     *
     * @return \ArrayObject<int, array{0: string, 1: string, 2: string}> [동작, 경로, 대상] 기록
     */
    private function recordFileWrites(): \ArrayObject
    {
        $log = new \ArrayObject;
        $mock = File::partialMock();
        $mock->shouldReceive('put')->andReturnUsing(function (string $path, $contents, $lock = false) use ($log) {
            $log[] = ['put', $path, ''];

            return file_put_contents($path, $contents, $lock ? LOCK_EX : 0);
        });
        $mock->shouldReceive('move')->andReturnUsing(function (string $from, string $to) use ($log) {
            $log[] = ['move', $from, $to];

            return rename($from, $to);
        });

        return $log;
    }

    /**
     * 실제 파일에 직접 쓰지 않았고, 같은 폴더 임시 파일에 쓴 뒤 그 파일을 실제 경로로 옮겼으며, 임시 파일이 남지 않았는지 확인합니다.
     *
     * @param  \ArrayObject<int, array{0: string, 1: string, 2: string}>  $writes  기록
     * @param  string  $target  실제 파일 경로
     * @return void 반환값 없음
     */
    private function assertSwappedInViaTempFile(\ArrayObject $writes, string $target): void
    {
        $norm = fn (string $p) => str_replace('\\', '/', $p);
        $puts = array_values(array_filter($writes->getArrayCopy(), fn ($w) => $w[0] === 'put'));
        $moves = array_values(array_filter($writes->getArrayCopy(), fn ($w) => $w[0] === 'move'));

        $this->assertNotEmpty($puts, '파일 쓰기가 한 번도 기록되지 않았습니다 — 기록 장치가 동작하지 않습니다.');
        $this->assertNotContains($norm($target), array_map(fn ($w) => $norm($w[1]), $puts), '실제 파일에 제자리로 쓰면 쓰는 사이 다른 요청이 반쯤 쓰인 파일을 읽습니다.');
        $this->assertCount(1, $moves, '임시 파일을 실제 파일로 한 번 바꿔 끼워야 합니다.');
        $this->assertSame($norm($target), $norm($moves[0][2]));
        $this->assertSame(dirname($norm($target)), dirname($norm($moves[0][1])), '임시 파일은 실제 파일과 같은 폴더여야 합니다.');
        $this->assertContains($norm($moves[0][1]), array_map(fn ($w) => $norm($w[1]), $puts), '바꿔 끼운 파일은 방금 다 쓴 임시 파일이어야 합니다.');
        $this->assertSame([basename($target)], array_map('basename', File::files($this->dir)), '임시 파일이 남으면 안 됩니다.');
    }
}
