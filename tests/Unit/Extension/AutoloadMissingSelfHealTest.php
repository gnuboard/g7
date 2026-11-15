<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Support\CacheSelfHeal;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 오토로드·훅 캐시 파일이 **없을 때**의 자기 복구 (7.1.6).
 *
 * 낡은 캐시를 알아채는 복구(7.1.3)는 파일이 있을 때만 돌았다. 서버를 옮기거나 배포 도구가 `bootstrap/cache` 를 비워
 * 파일이 통째로 없으면 확장별 폴백이 PSR-4 만 등록해 확장의 composer 의존성·헬퍼 파일이 빠진 채 500 이 났고,
 * 훅 캐시는 매 요청 스캔만 하고 다시 만들지 않았다.
 *
 * 실제 캐시 파일과 `bootstrap/cache` 는 건드리지 않는다 — 캐시 경로와 표식 디렉토리를 임시 디렉토리로 돌리고,
 * 생성은 기록만 하는 대역으로 바꾼다.
 *
 * @scenario-manifest tests/scenarios/cache-missing-self-heal.yaml
 */
class AutoloadMissingSelfHealTest extends TestCase
{
    private string $tempDir;

    /** @var array<string, mixed> 시험 전의 정적 상태 (끝나면 되돌린다) */
    private array $originalStatics = [];

    /**
     * 임시 디렉토리를 만들고 표식 디렉토리를 그리로 돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = storage_path('framework/testing/self-heal-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
        CacheSelfHeal::resetForTesting();
        CacheSelfHeal::useMarkerDirectoryForTesting($this->tempDir);
        CacheSelfHeal::fakeRootProcessForTesting(false);
        config(['app.cache_self_heal' => true]);
        $this->originalStatics = [
            'autoload' => $this->staticProperty(ExtensionManager::class, 'registeredAutoloadState')->getValue(),
            'memo' => $this->staticProperty(HookCacheManager::class, 'memo')->getValue(),
            'scheduled' => $this->staticProperty(HookCacheManager::class, 'regenerationScheduled')->getValue(),
        ];
        $this->setAutoloadState(null);
        $this->setHookStatics();
    }

    /**
     * 정적 상태와 임시 디렉토리를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->staticProperty(ExtensionManager::class, 'registeredAutoloadState')->setValue(null, $this->originalStatics['autoload']);
        $this->staticProperty(HookCacheManager::class, 'memo')->setValue(null, $this->originalStatics['memo']);
        $this->staticProperty(HookCacheManager::class, 'regenerationScheduled')->setValue(null, $this->originalStatics['scheduled']);
        CacheSelfHeal::resetForTesting();
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * 오토로드 캐시 파일이 없으면 다시 만들고, 끝나면 표식을 지운다.
     *
     * @scenario case=autoload_missing
     *
     * @effects missing_autoload_cache_is_regenerated
     *
     * @return void 반환값 없음
     */
    public function test_missing_autoload_file_is_regenerated(): void
    {
        $manager = $this->manager(writes: true);

        $this->assertTrue($manager->healStaleAutoload());
        $this->assertSame(1, $manager->generated);
        $this->assertFileExists($this->tempDir.'/autoload-extensions.php');
        $this->assertFileDoesNotExist(CacheSelfHeal::markerPath('autoload-extensions'));
    }

    /**
     * 파일이 있으면(등록만 안 된 프로세스) 다시 만들지 않는다.
     *
     * @scenario case=autoload_present
     *
     * @effects present_autoload_cache_is_left_alone
     *
     * @return void 반환값 없음
     */
    public function test_existing_autoload_file_is_not_regenerated(): void
    {
        File::put($this->tempDir.'/autoload-extensions.php', '<?php return [];');
        $manager = $this->manager(writes: true);

        $this->assertFalse($manager->healStaleAutoload());
        $this->assertSame(0, $manager->generated);
    }

    /**
     * 다시 만들었는데도 파일이 없으면(확장 0건 보존 갈래) 표식이 남아 잠시 쉬고, 간격이 지나면 다시 시도한다.
     *
     * @scenario case=autoload_regeneration_keeps_failing
     *
     * @effects self_heal_backs_off_after_failed_attempt
     *
     * @return void 반환값 없음
     */
    public function test_failed_regeneration_backs_off_then_retries_after_interval(): void
    {
        $manager = $this->manager(writes: false);

        $this->assertFalse($manager->healStaleAutoload());
        $this->assertFalse($manager->healStaleAutoload());
        $this->assertFalse($manager->healStaleAutoload());
        $this->assertSame(1, $manager->generated);

        // 간격(60초)이 지난 것으로 만든다
        touch(CacheSelfHeal::markerPath('autoload-extensions'), time() - CacheSelfHeal::EXTENSION_BACKOFF_SECONDS - 5);
        $this->assertFalse($manager->healStaleAutoload());
        $this->assertSame(2, $manager->generated);
    }

    /**
     * root 프로세스는 만들지 않는다(root 가 만든 캐시 파일은 웹 서버 사용자가 고쳐 쓰지 못한다).
     *
     * @scenario case=autoload_root_process
     *
     * @effects self_heal_skipped_for_root_and_when_switched_off
     *
     * @return void 반환값 없음
     */
    public function test_root_process_does_not_regenerate(): void
    {
        CacheSelfHeal::fakeRootProcessForTesting(true);
        $manager = $this->manager(writes: true);

        $this->assertFalse($manager->healStaleAutoload());
        $this->assertSame(0, $manager->generated);
        $this->assertFileDoesNotExist(CacheSelfHeal::markerPath('autoload-extensions'));
    }

    /**
     * 스위치를 끄면 만들지 않는다.
     *
     * @scenario case=autoload_switched_off
     *
     * @effects self_heal_skipped_for_root_and_when_switched_off
     *
     * @return void 반환값 없음
     */
    public function test_switch_off_does_not_regenerate(): void
    {
        config(['app.cache_self_heal' => false]);
        $manager = $this->manager(writes: true);

        $this->assertFalse($manager->healStaleAutoload());
        $this->assertSame(0, $manager->generated);
    }

    /**
     * 훅 캐시 파일이 없으면 운영 중인 사이트의 웹 요청에서 요청 끝 재생성을 한 번 예약한다.
     *
     * @scenario case=hooks_missing
     *
     * @effects missing_hook_cache_schedules_regeneration_once
     *
     * @return void 반환값 없음
     */
    public function test_missing_hook_cache_schedules_regeneration_in_production_web_request(): void
    {
        $hooks = $this->hookManager(selfHealContext: true);

        $this->assertNull($hooks->read());
        $this->assertSame(1, $hooks->scheduled);

        // 같은 요청의 다음 호출은 기억된 결과를 쓴다 — 다시 예약하지 않는다
        $this->assertNull($hooks->read());
        $this->assertSame(1, $hooks->scheduled);

        // 다음 요청(기억 초기화)이 간격 안이면 예약하지 않는다
        $this->setHookStatics();
        $next = $this->hookManager(selfHealContext: true);
        $this->assertNull($next->read());
        $this->assertSame(0, $next->scheduled);
    }

    /**
     * 개발 환경·콘솔처럼 운영 웹 요청이 아니면 훅 캐시를 스스로 만들지 않는다.
     *
     * @scenario case=hooks_not_production_web
     *
     * @effects hook_cache_self_heal_only_in_production_web
     *
     * @return void 반환값 없음
     */
    public function test_missing_hook_cache_is_not_scheduled_outside_production_web_request(): void
    {
        $hooks = $this->hookManager(selfHealContext: false);

        $this->assertNull($hooks->read());
        $this->assertSame(0, $hooks->scheduled);
        $this->assertFileDoesNotExist(CacheSelfHeal::markerPath('hooks'));
    }

    /**
     * 두 진입점(웹·콘솔)이 확장 오토로드 캐시의 같은 항목을 등록한다.
     *
     * 콘솔 진입점만 확장 소스 classmap 을 빠뜨려, 콘솔 프로세스는 같은 클래스를 파일시스템 탐색으로만 찾았다.
     *
     * @scenario case=autoload_entry_points
     *
     * @effects entry_points_register_same_autoload_sections
     *
     * @return void 반환값 없음
     */
    public function test_web_and_console_entry_points_register_the_same_sections(): void
    {
        $sections = static function (string $path): array {
            preg_match_all('/\$extensionAutoloads\[\'([a-z0-9_]+)\'\]/', (string) file_get_contents($path), $matches);
            $found = array_values(array_unique($matches[1]));
            sort($found);

            return $found;
        };

        $web = $sections(base_path('public/index.php'));
        $console = $sections(base_path('artisan'));

        $this->assertSame($web, $console);
        $this->assertContains('src_classmap', $console);
        $this->assertContains('vendor_autoloads', $console);
    }

    /**
     * 없는 오토로드 캐시는 확장의 서비스 공급자가 등록되기 **전에** 다시 만든다.
     *
     * 확장 공급자는 등록 단계에서 올라간다. 복구가 그 뒤(부팅 단계)에만 돌면 첫 요청은 확장 클래스를 찾지 못해
     * 바인딩이 빠진 채 500 이 된다(로컬 실측: 캐시를 지운 뒤 첫 요청 500 → 순서를 앞당긴 뒤 200).
     *
     * @scenario case=autoload_register_phase
     *
     * @effects missing_autoload_cache_healed_before_extension_providers
     *
     * @return void 반환값 없음
     */
    public function test_missing_autoload_cache_is_healed_before_extension_providers_register(): void
    {
        $provider = str_replace('
', '
', (string) file_get_contents(app_path('Providers/CoreServiceProvider.php')));

        // 코어 공급자의 등록 단계가 복구를 부른다
        $this->assertSame(1, preg_match('/public function register\(\): void\s*\{(.*?)
    \}/s', $provider, $register));
        $this->assertStringContainsString('$this->healMissingExtensionAutoloadBeforeExtensionProviders();', $register[1]);

        // 복구는 파일이 없을 때만, 시험 환경·설치 전에는 하지 않고, 모델의 DB 연결 해석기를 먼저 건다
        $this->assertSame(1, preg_match('/private function healMissingExtensionAutoloadBeforeExtensionProviders\(\): void\s*\{(.*?)
    \}/s', $provider, $method));
        $this->assertStringContainsString("environment('testing')", $method[1]);
        $this->assertStringContainsString("file_exists(base_path('bootstrap/cache/autoload-extensions.php'))", $method[1]);
        $this->assertStringContainsString('isInstallCompleted(base_path())', $method[1]);
        $this->assertLessThan(strpos($method[1], '->healStaleAutoload()'), strpos($method[1], 'setConnectionResolver('));

        // 공급자 목록에서 코어 공급자가 확장 공급자보다 앞이다
        $providers = (string) file_get_contents(base_path('bootstrap/providers.php'));
        $core = strpos($providers, '    CoreServiceProvider::class');
        $this->assertNotFalse($core);
        $this->assertLessThan(strpos($providers, '    ModuleServiceProvider::class'), $core);
        $this->assertLessThan(strpos($providers, '    PluginServiceProvider::class'), $core);
    }

    /**
     * 캐시 경로를 임시 디렉토리로 돌리고 생성을 기록만 하는 확장 관리자를 만듭니다.
     *
     * @param  bool  $writes  생성 대역이 파일을 실제로 쓰는지 (false = 「확장 0건 보존」 갈래 흉내)
     * @return ExtensionManager&object{generated: int} 확장 관리자
     */
    private function manager(bool $writes): ExtensionManager
    {
        $path = $this->tempDir.'/autoload-extensions.php';

        return new class($this->app->make(ModuleRepositoryInterface::class), $this->app->make(PluginRepositoryInterface::class), $path, $writes) extends ExtensionManager
        {
            public int $generated = 0;

            /**
             * 캐시 경로와 생성 대역 동작을 받아 만든다.
             *
             * @param  ModuleRepositoryInterface  $modules  모듈 리포지토리
             * @param  PluginRepositoryInterface  $plugins  플러그인 리포지토리
             * @param  string  $path  임시 캐시 경로
             * @param  bool  $writes  생성 대역이 파일을 쓰는지
             */
            public function __construct(ModuleRepositoryInterface $modules, PluginRepositoryInterface $plugins, string $path, private bool $writes)
            {
                parent::__construct($modules, $plugins);
                $this->autoloadFilePath = $path;
            }

            /**
             * 실제 생성 대신 호출을 세고, 설정에 따라 최소 파일을 쓴다.
             *
             * @return void 반환값 없음
             */
            public function generateAutoloadFile(): void
            {
                $this->generated++;
                if ($this->writes) {
                    file_put_contents($this->autoloadFilePath, "<?php\n\nreturn ['psr4' => [], 'classmap' => [], 'src_classmap' => [], 'files' => [], 'vendor_autoloads' => [], 'extensions_fingerprint' => []];\n");
                }
            }
        };
    }

    /**
     * 캐시 경로를 임시 디렉토리로 돌리고 재생성 예약을 기록만 하는 훅 캐시 관리자를 만듭니다.
     *
     * @param  bool  $selfHealContext  운영 웹 요청으로 볼지
     * @return HookCacheManager&object{scheduled: int} 훅 캐시 관리자
     */
    private function hookManager(bool $selfHealContext): HookCacheManager
    {
        $path = $this->tempDir.'/hooks.php';

        return new class($path, $selfHealContext) extends HookCacheManager
        {
            public int $scheduled = 0;

            /**
             * 캐시 경로와 실행 문맥을 받아 만든다.
             *
             * @param  string  $path  임시 캐시 경로
             * @param  bool  $selfHealContext  운영 웹 요청으로 볼지
             */
            public function __construct(string $path, private bool $selfHealContext)
            {
                parent::__construct();
                $this->cacheFilePath = $path;
            }

            /**
             * 실제 판정 대신 주어진 문맥을 돌려준다.
             *
             * @return bool 운영 웹 요청이면 true
             */
            protected function isSelfHealContext(): bool
            {
                return $this->selfHealContext;
            }

            /**
             * 실제 예약 대신 호출을 센다.
             *
             * @return void 반환값 없음
             */
            protected function scheduleRegeneration(): void
            {
                $this->scheduled++;
            }
        };
    }

    /**
     * 확장 관리자의 「등록된 오토로드 상태」 정적 값을 바꿉니다.
     *
     * @param  array<string, mixed>|null  $state  상태
     * @return void 반환값 없음
     */
    private function setAutoloadState(?array $state): void
    {
        $this->staticProperty(ExtensionManager::class, 'registeredAutoloadState')->setValue(null, $state);
    }

    /**
     * 비공개 정적 속성에 접근합니다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $name  속성 이름
     * @return \ReflectionProperty 접근 가능한 속성
     */
    private function staticProperty(string $class, string $name): \ReflectionProperty
    {
        $property = new \ReflectionProperty($class, $name);
        $property->setAccessible(true);

        return $property;
    }

    /**
     * 훅 캐시 관리자의 요청 수명 기억을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    private function setHookStatics(): void
    {
        foreach (['memo' => null, 'regenerationScheduled' => false] as $name => $value) {
            $this->staticProperty(HookCacheManager::class, $name)->setValue(null, $value);
        }
    }
}
