<?php

namespace Tests\Feature\Extension;

use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Traits\ReloadsChangedExtensionClass;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

/**
 * 옛 진입 클래스(옛 선언)를 쥔 프로세스의 훅 캐시 재생성이 새 캐시를 덮지 못한다.
 *
 * 확장 파일이 교체되는 동안 부팅한 웹 요청은 지문이 바뀐 것을 보고 「요청 끝에 다시 굽기」를 예약한다. 그 요청이 쥔 진입 클래스가
 * 옛 정의이고(새 파일을 다시 읽지 못한 경우) 교체 쪽이 새 선언으로 캐시를 구운 뒤에 굽기가 돌면, 지운 리스너가 캐시에 돌아오고
 * 지문은 지금 디스크로 적혀 스스로 고쳐지지도 않았다. 쓰기 직전에 각 확장이 실제로 쓴 정의의 지문을 지금 디스크와 다시 비교해
 * 다르면 쓰지 않는다 — 다음 요청(새 정의로 부팅)이 굽는다.
 *
 * @scenario-manifest tests/scenarios/hook-cache-stale-declaration-write-guard.yaml
 */
class HookCacheStaleDeclarationWriteGuardTest extends TestCase
{
    /** @var string 시험 루트 */
    private string $base;

    /**
     * 시험 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->base = storage_path('app/test-hookcache-guard-'.uniqid());
        File::ensureDirectoryExists($this->base);
    }

    /**
     * 시험 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 옛 선언을 쓴 확장이 있으면 이미 있는(새) 캐시를 덮지 않는다.
     *
     * @scenario case=stale_declaration
     *
     * @effects stale_process_does_not_overwrite_hook_cache
     *
     * @return void 반환값 없음
     */
    public function test_stale_declaration_does_not_overwrite_cache(): void
    {
        $cachePath = $this->base.'/hooks.php';
        File::put($cachePath, '<?php return ["fresh" => true];');

        $hookCache = new HookCacheManager;
        (new \ReflectionProperty(HookCacheManager::class, 'cacheFilePath'))->setValue($hookCache, $cachePath);

        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([]);
        $modules->shouldReceive('staleDeclarationIdentifiers')->andReturn(['vendor-stale']);
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([]);
        $plugins->shouldReceive('staleDeclarationIdentifiers')->andReturn([]);

        $hookCache->generate($modules, $plugins);

        $this->assertSame('<?php return ["fresh" => true];', File::get($cachePath), '옛 선언을 쥔 프로세스가 새 훅 캐시를 덮었다');
    }

    /**
     * 모든 확장이 지금 디스크의 정의를 썼으면 종전대로 쓴다.
     *
     * @scenario case=current_declaration
     *
     * @effects stale_process_does_not_overwrite_hook_cache
     *
     * @return void 반환값 없음
     */
    public function test_current_declaration_writes_cache(): void
    {
        $cachePath = $this->base.'/hooks.php';
        File::put($cachePath, '<?php return ["old" => true];');

        $hookCache = new HookCacheManager;
        (new \ReflectionProperty(HookCacheManager::class, 'cacheFilePath'))->setValue($hookCache, $cachePath);

        $modules = Mockery::mock(ModuleManager::class);
        $modules->shouldReceive('getActiveModules')->andReturn([]);
        $modules->shouldReceive('staleDeclarationIdentifiers')->andReturn([]);
        $plugins = Mockery::mock(PluginManager::class);
        $plugins->shouldReceive('getActivePlugins')->andReturn([]);
        $plugins->shouldReceive('staleDeclarationIdentifiers')->andReturn([]);

        $hookCache->generate($modules, $plugins);

        $this->assertStringContainsString('extensions_fingerprint', File::get($cachePath));
    }

    /**
     * 진입 파일이 바뀌었는데 새 정의를 읽지 못해 옛 클래스로 대신했으면 「지금 디스크와 다름」으로 판정한다.
     *
     * @scenario case=stale_declaration
     *
     * @effects used_declaration_tracked_per_class
     *
     * @return void 반환값 없음
     */
    public function test_fallback_to_old_class_is_reported_stale(): void
    {
        $file = $this->base.'/module.php';
        File::put($file, '<?php // v1');

        $loader = new class
        {
            use ReloadsChangedExtensionClass;

            /**
             * 진입 클래스를 올린 것처럼 기록하고 인스턴스를 만듭니다.
             *
             * @param  string  $file  진입 파일
             * @param  bool  $evalSucceeds  새 정의 읽기 성공 여부
             * @return object|null 인스턴스
             */
            public function load(string $file, bool $evalSucceeds): ?object
            {
                return $this->instantiateCurrentExtensionClass(\stdClass::class, $file, fn () => $evalSucceeds ? new \ArrayObject : null);
            }

            /**
             * 처음 올린 정의의 지문을 적습니다.
             *
             * @param  string  $file  진입 파일
             * @return void 반환값 없음
             */
            public function boot(string $file): void
            {
                $this->rememberLoadedExtensionClassFile(\stdClass::class, $file);
            }

            /**
             * 쓴 정의가 지금 디스크와 같은지.
             *
             * @param  string  $file  진입 파일
             * @return bool 같으면 true
             */
            public function current(string $file): bool
            {
                return $this->usedDeclarationIsCurrent(\stdClass::class, $file);
            }
        };

        $loader->boot($file);
        $loader->load($file, true);
        $this->assertTrue($loader->current($file));

        File::put($file, '<?php // v2 — 리스너 하나를 뺀 판');
        $loader->load($file, false);
        $this->assertFalse($loader->current($file), '옛 클래스로 대신한 인스턴스를 지금 정의로 보았다');

        $loader->load($file, true);
        $this->assertTrue($loader->current($file), '새 정의를 읽은 뒤에는 지금 정의다');
    }

    /**
     * 진입 파일을 실은(require) 직후 파일이 교체되면, 실은 뒤에 뜬 지문은 새 파일 것이지만 메모리 클래스는 옛것이다 —
     * 그 프로세스를 「지금 정의」로 보면 안 된다(요청 끝 재생성이 옛 선언으로 캐시를 덮던 실제 원인).
     *
     * @scenario case=stale_declaration
     *
     * @effects used_declaration_tracked_per_class
     *
     * @return void 반환값 없음
     */
    public function test_file_replaced_right_after_require_is_reported_stale(): void
    {
        $suffix = 'G'.substr(md5(uniqid('', true)), 0, 8);
        $class = "RemovedListenerFixture\\{$suffix}\\Module";
        $file = $this->base.'/module.php';
        File::put($file, "<?php\nnamespace RemovedListenerFixture\\{$suffix};\nclass Module { public function listeners() { return ['Old']; } }\n");

        $loader = $this->entryLoader();

        // 매니저와 같은 순서: 싣기 전 지문 → require → (그 사이 교체) → 기록
        $before = $loader->hashBeforeLoad($file);
        require_once $file;
        File::put($file, "<?php\nnamespace RemovedListenerFixture\\{$suffix};\nclass Module { public function listeners() { return []; } }\n");
        $loader->remember($class, $file, $before);

        // 새 정의를 읽지 못해 옛 클래스로 대신한 상황
        $instance = $loader->instantiate($class, $file, fn () => null);
        $this->assertSame(['Old'], $instance->listeners(), '시험 전제: 메모리 클래스는 옛 정의');
        $this->assertFalse($loader->current($class, $file), '실은 직후 바뀐 파일의 지문을 실은 정의의 지문으로 적었다 — 옛 선언으로 훅 캐시를 덮는다');
    }

    /**
     * 부팅 오토로드가 실은 진입 클래스(매니저가 직접 싣지 않은 것)도 싣기 전·후 지문으로 판정한다 — 싣는 사이 교체되면 「다름」,
     * 그대로면 새 파일을 다시 읽지 않고 「같음」(매 요청 재평가 없음).
     *
     * @scenario case=stale_declaration
     *
     * @effects used_declaration_tracked_per_class
     *
     * @return void 반환값 없음
     */
    public function test_boot_autoloaded_entry_class_uses_load_time_hash(): void
    {
        $loader = $this->entryLoader();

        foreach ([true, false] as $replacedDuringLoad) {
            $suffix = 'B'.substr(md5(uniqid('', true)), 0, 8);
            $class = "RemovedListenerFixture\\{$suffix}\\Plugin";
            $file = $this->base.'/plugin-'.$suffix.'.php';
            File::put($file, "<?php\nnamespace RemovedListenerFixture\\{$suffix};\nclass Plugin { public function listeners() { return ['Old']; } }\n");

            $before = md5_file($file);
            require_once $file;
            if ($replacedDuringLoad) {
                File::put($file, "<?php\nnamespace RemovedListenerFixture\\{$suffix};\nclass Plugin { public function listeners() { return []; } }\n");
            }
            ExtensionManager::rememberEntryFileLoad($file, $before);

            $evaluated = 0;
            $loader->instantiate($class, $file, function () use (&$evaluated) {
                $evaluated++;

                return null;
            });

            if ($replacedDuringLoad) {
                $this->assertFalse($loader->current($class, $file), '부팅 오토로드가 실은 직후 교체된 진입 클래스를 지금 정의로 보았다');
            } else {
                $this->assertTrue($loader->current($class, $file));
                $this->assertSame(0, $evaluated, '바뀌지 않은 진입 파일을 매번 다시 읽었다(요청마다 재평가)');
            }
        }
    }

    /**
     * 매니저·부팅 오토로드 어느 쪽도 기록하지 않은 경로(Composer 오토로드 — 확장 PHPUnit 이 시험 대상 클래스를 먼저 싣는다)로
     * 올라온 진입 클래스는 지금 파일 그대로의 정의로 보고 원래 클래스의 인스턴스를 만든다. 「알 수 없음」으로 두면 매번 새 정의를
     * eval 해 `Plugin_fresh_*` 같은 다른 클래스가 나가 타입 선언·instanceof 가 깨졌다.
     *
     * @scenario case=current_declaration
     *
     * @effects used_declaration_tracked_per_class
     *
     * @return void 반환값 없음
     */
    public function test_entry_class_loaded_without_any_record_keeps_its_own_class(): void
    {
        $suffix = 'C'.substr(md5(uniqid('', true)), 0, 8);
        $class = "RemovedListenerFixture\\{$suffix}\\Plugin";
        $file = $this->base.'/plugin-'.$suffix.'.php';
        File::put($file, "<?php\nnamespace RemovedListenerFixture\\{$suffix};\nclass Plugin {}\n");
        require_once $file;

        $evaluated = 0;
        $instance = $this->entryLoader()->instantiate($class, $file, function () use (&$evaluated) {
            $evaluated++;

            return new \ArrayObject;
        });

        $this->assertInstanceOf($class, $instance, '기록 없이 올라온 진입 클래스를 다른 클래스(새 정의 eval)로 바꿔 냈다');
        $this->assertSame(0, $evaluated, '바뀌지 않은 진입 파일을 다시 읽었다');
    }

    /**
     * 매니저가 진입 파일을 싣기 전에 지문을 떠 기록에 넘긴다(실은 뒤 지문만 쓰지 않는다 — 순서 계약).
     *
     * @scenario case=stale_declaration
     *
     * @effects used_declaration_tracked_per_class
     *
     * @return void 반환값 없음
     */
    public function test_managers_hash_entry_file_before_require(): void
    {
        foreach (['ModuleManager' => '$moduleFile', 'PluginManager' => '$pluginFile'] as $class => $var) {
            $source = (string) file_get_contents(app_path("Extension/{$class}.php"));
            $count = preg_match_all('/\$(\w+) = md5_file\('.preg_quote($var, '/').'\)(?: \?: null)?;\s*require_once '.preg_quote($var, '/').';\s*\$this->rememberLoadedExtensionClassFile\(\$\w+, '.preg_quote($var, '/').', \$\1\);/', $source);
            $this->assertSame(2, $count, "{$class}: 진입 파일을 싣기 전에 지문을 떠 기록에 넘기지 않는다");
        }
    }

    /**
     * 진입 클래스 싣기·기록을 흉내 내는 대역을 만듭니다.
     *
     * @return object 대역
     */
    private function entryLoader(): object
    {
        return new class
        {
            use ReloadsChangedExtensionClass;

            /**
             * 싣기 전 지문을 뜹니다.
             *
             * @param  string  $file  진입 파일
             * @return string|null 지문
             */
            public function hashBeforeLoad(string $file): ?string
            {
                $hash = @md5_file($file);

                return is_string($hash) ? $hash : null;
            }

            /**
             * 실은 정의를 기록합니다.
             *
             * @param  string  $class  진입 클래스
             * @param  string  $file  진입 파일
             * @param  string|null  $before  싣기 전 지문
             * @return void 반환값 없음
             */
            public function remember(string $class, string $file, ?string $before): void
            {
                $this->rememberLoadedExtensionClassFile($class, $file, $before);
            }

            /**
             * 인스턴스를 만듭니다.
             *
             * @param  string  $class  진입 클래스
             * @param  string  $file  진입 파일
             * @param  callable  $evalFresh  새 정의 읽기
             * @return object|null 인스턴스
             */
            public function instantiate(string $class, string $file, callable $evalFresh): ?object
            {
                return $this->instantiateCurrentExtensionClass($class, $file, $evalFresh);
            }

            /**
             * 쓴 정의가 지금 디스크와 같은지.
             *
             * @param  string  $class  진입 클래스
             * @param  string  $file  진입 파일
             * @return bool 같으면 true
             */
            public function current(string $class, string $file): bool
            {
                return $this->usedDeclarationIsCurrent($class, $file);
            }
        };
    }
}
