<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 확장 오토로드·훅·config 캐시가 「지금 다시 구워도 같은가」 판정 검증.
 *
 * 일괄 업데이트의 항목 프로세스는 이 판정이 참일 때만 시작 때의 재굽기(소스 3천여 파일 훑기 약 1.5초 + config 캐시 + 훅 캐시)를
 * 건너뛴다. 그 재굽기는 낡은 매핑으로 뜬 자식이 「Class not found」로 죽던 결함(beta.4)을 막으려고 들어왔으므로, 판정은
 * 조금이라도 다르면 거짓이어야 한다 — 수집 입력(PSR-4·classmap·files·확장 vendor)·확장 파일 지문·형식·훅 캐시·config 캐시 순서.
 */
class ExtensionCachesFreshnessTest extends TestCase
{
    /** 임시 디렉토리 */
    private string $dir;

    /**
     * 임시 디렉토리를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/ext_cache_fresh_'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    /**
     * 임시 디렉토리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * 지금 상태로 구운 캐시는 신선하다 — 기준 시각 이후에 구웠으면 그 조건도 통과한다.
     *
     * @effects fresh_caches_detected
     *
     * @return void 반환값 없음
     */
    public function test_caches_written_from_current_state_are_fresh(): void
    {
        $manager = $this->manager();
        $manager->writeCurrent();
        $this->hookCache(fresh: true);

        $this->assertTrue($manager->extensionCachesFreshSince(null));
        $this->assertTrue($manager->extensionCachesFreshSince(time() - 60));
    }

    /**
     * 기준 시각(묶음 시작) 전에 구운 오토로드 파일은 낡은 것으로 본다 — 앞 단계의 옛 코드가 구웠을 수 있다.
     *
     * @effects stale_caches_detected
     *
     * @return void 반환값 없음
     */
    public function test_file_written_before_batch_start_is_not_fresh(): void
    {
        $manager = $this->manager();
        $manager->writeCurrent();
        touch($manager->path(), time() - 600);
        clearstatcache();
        $this->hookCache(fresh: true);

        $this->assertFalse($manager->extensionCachesFreshSince(time() - 60));
        $this->assertTrue($manager->extensionCachesFreshSince(null));
    }

    /**
     * 파일이 없거나, 지문이 없는 옛 형식(7.1.2 이하)이거나, 수집 입력·지문이 지금과 다르면 낡았다.
     *
     * @effects stale_caches_detected
     *
     * @return void 반환값 없음
     */
    public function test_missing_old_format_or_diverged_inputs_are_not_fresh(): void
    {
        $this->hookCache(fresh: true);
        $manager = $this->manager();
        $this->assertFalse($manager->extensionCachesFreshSince(null), '파일이 없는데 신선하다고 했다');

        $manager->writeCurrent(fingerprint: []);
        $this->assertFalse($manager->extensionCachesFreshSince(null), '지문 없는 옛 형식을 신선하다고 했다');

        $manager->writeCurrent(psr4: ['Modules\\Other\\' => 'modules/probe-other/src/']);
        $this->assertFalse($manager->extensionCachesFreshSince(null), '다른 PSR-4 매핑을 신선하다고 했다');

        $manager->writeCurrent(fingerprint: ['modules:probe-module' => '1:2:3']);
        $this->assertFalse($manager->extensionCachesFreshSince(null), '다른 확장 파일 지문을 신선하다고 했다');

        $manager->writeCurrent(vendorAutoloads: ['modules/probe-module/vendor/autoload.php']);
        $this->assertFalse($manager->extensionCachesFreshSince(null), '다른 확장 vendor 목록을 신선하다고 했다');
    }

    /**
     * 훅 캐시가 없거나 낡았으면, 또는 config 캐시가 오토로드 파일보다 먼저 구워졌으면 낡았다. config 캐시가 아예 없으면 낡을 것이 없다.
     *
     * @effects stale_caches_detected
     *
     * @return void 반환값 없음
     */
    public function test_hook_and_config_caches_take_part(): void
    {
        $manager = $this->manager();
        $manager->writeCurrent();

        $this->hookCache(fresh: false);
        $this->assertFalse($manager->extensionCachesFreshSince(null), '낡은 훅 캐시를 신선하다고 했다');

        $this->hookCache(fresh: true);
        File::put($manager->configPath, '<?php return [];');
        touch($manager->configPath, time() - 600);
        clearstatcache();
        $this->assertFalse($manager->extensionCachesFreshSince(null), '오토로드보다 먼저 구운 config 캐시를 신선하다고 했다');

        touch($manager->configPath, time() + 5);
        clearstatcache();
        $this->assertTrue($manager->extensionCachesFreshSince(null));

        File::delete($manager->configPath);
        $this->assertTrue($manager->extensionCachesFreshSince(null), 'config 캐시가 없는 사이트를 낡았다고 했다');
    }

    /**
     * 훅 캐시 관리자를 대역으로 바꿉니다.
     *
     * @param  bool  $fresh  캐시가 쓸 수 있는 상태인지
     * @return void 반환값 없음
     */
    private function hookCache(bool $fresh): void
    {
        $hook = Mockery::mock(HookCacheManager::class);
        $hook->shouldReceive('read')->andReturn($fresh ? ['format' => HookCacheManager::FORMAT] : null);
        $this->app->instance(HookCacheManager::class, $hook);
    }

    /**
     * 임시 경로·고정 수집 입력을 쓰는 확장 매니저를 만듭니다.
     *
     * @return ExtensionManager&object{configPath: string} 확장 매니저
     */
    private function manager(): ExtensionManager
    {
        $dir = $this->dir;

        return new class($this->app->make(ModuleRepositoryInterface::class), $this->app->make(PluginRepositoryInterface::class), $dir) extends ExtensionManager
        {
            /** 임시 config 캐시 경로 */
            public string $configPath;

            /**
             * 캐시 경로를 임시 디렉토리로 돌린다.
             *
             * @param  ModuleRepositoryInterface  $modules  모듈 리포지토리
             * @param  PluginRepositoryInterface  $plugins  플러그인 리포지토리
             * @param  string  $dir  임시 디렉토리
             */
            public function __construct(ModuleRepositoryInterface $modules, PluginRepositoryInterface $plugins, string $dir)
            {
                parent::__construct($modules, $plugins);
                $this->autoloadFilePath = $dir.DIRECTORY_SEPARATOR.'autoload-extensions.php';
                $this->configPath = $dir.DIRECTORY_SEPARATOR.'config.php';
            }

            /**
             * 오토로드 파일 경로.
             *
             * @return string 경로
             */
            public function path(): string
            {
                return $this->autoloadFilePath;
            }

            /**
             * 지금 수집 입력으로 오토로드 파일을 씁니다 — 인자로 준 조각만 바꿔 쓸 수 있다.
             *
             * @param  array|null  $psr4  PSR-4 (null 이면 지금 값)
             * @param  array|null  $fingerprint  지문 (null 이면 지금 값)
             * @param  array|null  $vendorAutoloads  확장 vendor 목록 (null 이면 지금 값)
             * @return void 반환값 없음
             */
            public function writeCurrent(?array $psr4 = null, ?array $fingerprint = null, ?array $vendorAutoloads = null): void
            {
                $modules = $this->collectModuleAutoloads();
                $currentPsr4 = $modules['psr4'];
                File::put($this->autoloadFilePath, $this->buildAutoloadFileContent(
                    $psr4 ?? $currentPsr4,
                    $modules['classmap'],
                    $modules['files'],
                    $vendorAutoloads ?? $modules['vendor_autoloads'],
                    [],
                    $fingerprint ?? $this->fingerprintForPsr4($currentPsr4),
                ));
                clearstatcache();
            }

            /**
             * 고정 모듈 하나의 수집 입력을 돌려준다.
             *
             * @return array 수집 결과
             */
            protected function collectModuleAutoloads(): array
            {
                return [
                    'psr4' => ['Modules\\Probe\\' => 'modules/probe-module/src/'],
                    'classmap' => ['modules/probe-module/module.php'],
                    'files' => [],
                    'vendor_autoloads' => [],
                ];
            }

            /**
             * 플러그인은 없다.
             *
             * @return array 수집 결과
             */
            protected function collectPluginAutoloads(): array
            {
                return ['psr4' => [], 'classmap' => [], 'files' => [], 'vendor_autoloads' => []];
            }

            /**
             * 임시 config 캐시 경로.
             *
             * @return string 경로
             */
            protected function cachedConfigPath(): string
            {
                return $this->configPath;
            }
        };
    }
}
