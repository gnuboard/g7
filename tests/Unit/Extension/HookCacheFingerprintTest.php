<?php

namespace Tests\Unit\Extension;

use App\Extension\CoreVersionChecker;
use App\Extension\ExtensionManager;
use App\Extension\HookCacheManager;
use App\Extension\Support\ExtensionFingerprint;
use App\Extension\Support\LifecycleWarnings;
use App\Listeners\LanguagePack\RunSeedersOnLanguagePackLifecycle;
use App\Models\Module;
use App\Providers\CoreServiceProvider;
use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 훅·확장 오토로드 캐시 자기 복구 — 활성 확장 파일 지문 (7.1.3).
 *
 * 훅 캐시는 「형식·코어 버전」만 보고, 캐시에 확장 항목이 있으면 그 확장의 getHookListeners() 를 다시 보지 않았다.
 * 그래서 설치된 확장의 파일만 교체하면 새 리스너가 빠진 채 남았다. 캐시에 활성 확장의 매니페스트·진입 파일·
 * composer.json 수정 시각을 기록하고, 다르면 스캔 폴백 + 요청 끝 재생성으로 스스로 고친다. 확장 오토로드 캐시도
 * 같은 지문으로 부팅 때 다시 만들고, 사라진 클래스는 classmap 에서 null 로 덮어 PSR-4 로 찾게 한다.
 *
 * @scenario-manifest tests/scenarios/hook-cache-fingerprint.yaml
 */
class HookCacheFingerprintTest extends TestCase
{
    use DatabaseTransactions;

    private const ID = 'zztest-fingerprint';

    private string $moduleDir;

    private string $tempDir;

    /**
     * 지문 대상 시험 모듈 디렉터리와 임시 디렉터리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleDir = base_path('modules/'.self::ID);
        File::ensureDirectoryExists($this->moduleDir);
        File::put($this->moduleDir.'/module.json', '{"identifier":"'.self::ID.'","version":"1.0.0"}');
        File::put($this->moduleDir.'/module.php', '<?php');
        File::put($this->moduleDir.'/composer.json', '{}');
        touch($this->moduleDir.'/module.php', time() - 100);
        $this->tempDir = storage_path('app/temp/hook-fingerprint-'.uniqid());
        File::ensureDirectoryExists($this->tempDir);
        ExtensionFingerprint::forget();
        LifecycleWarnings::current()->clear();
        $this->resetHookCacheMemo();
    }

    /**
     * 시험 디렉터리를 지우고 훅 캐시 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->moduleDir);
        File::deleteDirectory($this->tempDir);
        ExtensionFingerprint::forget();
        $this->resetHookCacheMemo();
        parent::tearDown();
    }

    /**
     * 확장 진입 파일만 바꿔도 지문이 달라진다.
     *
     * @scenario cache=fingerprint, change=entry_file_replaced
     *
     * @effects fingerprint_detects_file_only_replacement
     *
     * @return void 반환값 없음
     */
    public function test_fingerprint_detects_entry_file_replacement(): void
    {
        $stored = ExtensionFingerprint::compute([self::ID], []);
        $this->assertTrue(ExtensionFingerprint::matches($stored));

        touch($this->moduleDir.'/module.php', time() + 5);
        ExtensionFingerprint::forget();

        $this->assertFalse(ExtensionFingerprint::matches($stored));
        $this->assertSame(['modules:'.self::ID], ExtensionFingerprint::changedKeys($stored));
    }

    /**
     * 기록할 때와 확장 파일이 달라진 훅 캐시는 쓰지 않고 스캔 폴백으로 넘긴다(그래야 새 리스너가 등록된다).
     *
     * @scenario cache=hooks, change=entry_file_replaced
     *
     * @effects stale_hook_cache_falls_back_to_scan
     *
     * @return void 반환값 없음
     */
    public function test_hook_cache_with_changed_extension_files_is_not_used(): void
    {
        $manager = $this->hookCacheAt($this->tempDir.'/hooks.php');
        $this->writeHookCache($this->tempDir.'/hooks.php', ExtensionFingerprint::compute([self::ID], []));

        $this->assertIsArray($manager->read(), '지문이 같으면 캐시를 쓴다');

        touch($this->moduleDir.'/module.php', time() + 5);
        ExtensionFingerprint::forget();
        $this->resetHookCacheMemo();

        $this->assertNull($manager->read(), '확장 파일만 바뀐 캐시는 쓰지 않는다(스캔 폴백)');
    }

    /**
     * 지문이 같은 확장은 그대로 캐시를 쓴다.
     *
     * @scenario cache=hooks, change=none
     *
     * @effects unchanged_cache_still_used
     *
     * @return void 반환값 없음
     */
    public function test_hook_cache_without_changes_is_used(): void
    {
        $manager = $this->hookCacheAt($this->tempDir.'/hooks.php');
        $this->writeHookCache($this->tempDir.'/hooks.php', ExtensionFingerprint::compute([self::ID], []));

        $this->assertIsArray($manager->read());
    }

    /**
     * 리스너 일부가 실패한 확장은 캐시에 넣지 않고(스캔 폴백으로 남게) 경고로 알린다.
     *
     * @scenario cache=hooks, change=listener_broken
     *
     * @effects partially_failed_extension_left_out_of_cache
     *
     * @return void 반환값 없음
     */
    public function test_extension_with_broken_listener_is_left_out_of_cache(): void
    {
        $extension = new class
        {
            /**
             * 식별자를 반환합니다.
             *
             * @return string 확장 식별자
             */
            public function getIdentifier(): string
            {
                return 'zztest-broken-listener';
            }

            /**
             * 정상 리스너 하나와 없는 리스너 하나를 반환합니다.
             *
             * @return array<int, string> 리스너 클래스 목록
             */
            public function getHookListeners(): array
            {
                return [RunSeedersOnLanguagePackLifecycle::class, 'Zztest\\Missing\\Listener'];
            }
        };

        $result = (fn () => $this->collectExtensionListeners(['zztest-broken-listener' => $extension]))->call(new HookCacheManager);

        $this->assertArrayNotHasKey('zztest-broken-listener', $result);
        $this->assertSame('hook_cache', LifecycleWarnings::current()->all()[0]['step'] ?? null);
    }

    /**
     * 확장 파일이 바뀐 오토로드 캐시는 부팅 때 다시 만들고, 사라진 클래스는 classmap 에서 null 로 덮는다.
     *
     * @scenario cache=autoload, change=entry_file_replaced
     *
     * @effects stale_autoload_cache_regenerated_and_vanished_class_unmapped
     *
     * @return void 반환값 없음
     */
    public function test_stale_autoload_cache_is_regenerated(): void
    {
        // 설치된 모듈 하나(시험 DB 트랜잭션) + 실제 PSR-4 매핑 — 생성기는 「DB 설치 목록 × 디스크」 교집합만 싣는다
        Module::factory()->create(['identifier' => self::ID, 'status' => 'active']);
        File::ensureDirectoryExists($this->moduleDir.'/src');
        File::put($this->moduleDir.'/composer.json', json_encode(['autoload' => ['psr-4' => ['Modules\\Zztest\\Fingerprint\\' => 'src/']]]));
        touch($this->moduleDir.'/composer.json', time() - 100);

        $path = $this->tempDir.'/autoload-extensions.php';
        $manager = app(ExtensionManager::class);
        (fn () => $this->autoloadFilePath = $path)->call($manager);

        $stale = ExtensionFingerprint::compute([self::ID], []);
        ExtensionManager::rememberRegisteredAutoload([
            'extensions_fingerprint' => $stale,
            'src_classmap' => ['Zztest\\Fingerprint\\Gone' => 'modules/'.self::ID.'/src/Gone.php'],
        ]);
        touch($this->moduleDir.'/module.php', time() + 5);
        ExtensionFingerprint::forget();

        $this->assertTrue($manager->healStaleAutoload());

        $this->assertFileExists($path);
        $this->assertArrayHasKey('extensions_fingerprint', require $path);
        $loader = ClassLoader::getRegisteredLoaders()[array_key_first(ClassLoader::getRegisteredLoaders())];
        $this->assertArrayHasKey('Zztest\\Fingerprint\\Gone', $loader->getClassMap());
        $this->assertNull($loader->getClassMap()['Zztest\\Fingerprint\\Gone'], '사라진 클래스는 PSR-4 로 찾게 null');

        $this->assertFalse($manager->healStaleAutoload(), '다시 만든 뒤에는 지문이 같다');
    }

    /**
     * 진입점(index.php·artisan)이 오토로드를 먼저 등록한 경우에도 부팅이 지문을 기억해 자기 복구가 판정할 수 있다.
     *
     * 웹 요청·artisan 은 진입점이 `G7_EXTENSION_AUTOLOAD_REGISTERED` 를 세우고 오토로드를 등록한다(시험 프로세스도
     * tests/bootstrap.php 가 같은 상수를 세운다). 지문 기억이 그 상수 확인 뒤에 있으면 기억이 비어 자기 복구가 늘 no-op 이다.
     *
     * @scenario cache=autoload, change=entry_point_registered
     *
     * @effects entry_point_boot_remembers_autoload_fingerprint
     *
     * @return void 반환값 없음
     */
    public function test_boot_remembers_fingerprint_when_entry_point_registered_autoload(): void
    {
        $this->assertTrue(defined('G7_EXTENSION_AUTOLOAD_REGISTERED'), '실제 진입점과 같은 상태에서 잰다');
        $file = base_path('bootstrap/cache/autoload-extensions.php');
        $this->assertFileExists($file, '확장 오토로드 캐시가 있어야 기억할 대상이 있다');

        $state = new \ReflectionProperty(ExtensionManager::class, 'registeredAutoloadState');
        $state->setValue(null, null);

        (fn () => $this->registerExtensionAutoload())->call(new CoreServiceProvider($this->app));

        $remembered = $state->getValue();
        $this->assertNotNull($remembered, '진입점이 등록한 경우에도 지문을 기억해야 한다');
        $this->assertSame((require $file)['extensions_fingerprint'] ?? [], $remembered['fingerprint']);
    }

    /**
     * 경로를 바꾼 훅 캐시 관리자를 만듭니다.
     *
     * @param  string  $path  캐시 파일 경로
     * @return HookCacheManager 관리자
     */
    private function hookCacheAt(string $path): HookCacheManager
    {
        $manager = new HookCacheManager;
        (fn () => $this->cacheFilePath = $path)->call($manager);

        return $manager;
    }

    /**
     * 현재 형식·코어 버전·지문으로 빈 훅 캐시 파일을 씁니다.
     *
     * @param  string  $path  캐시 파일 경로
     * @param  array<string, string>  $fingerprint  확장 지문
     * @return void 반환값 없음
     */
    private function writeHookCache(string $path, array $fingerprint): void
    {
        $cache = [
            'format' => HookCacheManager::FORMAT,
            'core_version' => CoreVersionChecker::getCoreVersion(),
            'extensions_fingerprint' => $fingerprint,
            'core' => [],
            'modules' => [],
            'plugins' => [],
        ];
        File::put($path, '<?php return '.var_export($cache, true).';');
    }

    /**
     * 훅 캐시의 요청 안 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    private function resetHookCacheMemo(): void
    {
        $property = new \ReflectionProperty(HookCacheManager::class, 'memo');
        $property->setValue(null, null);
    }
}
