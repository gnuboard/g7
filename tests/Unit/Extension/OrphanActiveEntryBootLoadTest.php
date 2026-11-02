<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 오토로드 캐시에 없는 활성 확장 폴더(설치 기록 없는 고아 폴더)를 부팅이 읽어도 죽지 않는지 검증 (#159)
 *
 * ## 무엇을 막는가
 *
 * 부팅의 `loadPlugins()`·`loadModules()` 는 활성 디렉토리의 진입 파일을 모두 `require_once` 한다. 설치된 확장은 진입점이
 * 오토로드 캐시로 PSR-4 를 먼저 등록하지만, 캐시에 없는 폴더(설치가 중간에 끊겨 DB 행만 지워진 사본, 손으로 복사한 사본)는
 * 등록되지 않았다. 진입 클래스가 자기 PSR-4 클래스를 상속하면 부팅이 「Class not found」 로 죽어 모든 artisan 명령·웹 요청이
 * 실패하고, 복구 명령(`plugin:install {id} --force`)조차 실행되지 않았다(수정 전 실측 T10-a: EXIT=1).
 * 캐시에 없는 확장만 그 확장 하나의 PSR-4·소스 classmap 을 등록한 뒤 진입 파일을 읽는다(설치된 확장은 비용 없음).
 *
 * 트러블슈팅 `[case:backend-63]` 의 회귀 그물이다.
 */
class OrphanActiveEntryBootLoadTest extends TestCase
{
    /** 임시 확장 루트 */
    private string $tempRoot;

    /** @var array<string, mixed>|null 테스트 전 기억된 오토로드 상태 */
    private ?array $savedState = null;

    /**
     * 테스트 준비 — 임시 루트를 정하고 기억된 오토로드 상태를 보관합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-159-orphan-'.bin2hex(random_bytes(6));
        $this->savedState = (new ReflectionProperty(ExtensionManager::class, 'registeredAutoloadState'))->getValue();
    }

    /**
     * 테스트 정리 — 임시 루트를 지우고 오토로드 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        (new ReflectionProperty(ExtensionManager::class, 'registeredAutoloadState'))->setValue(null, $this->savedState);

        if (is_dir($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * 확장 종류별 픽스처 정의.
     *
     * @return array<string, array{string, class-string, string, string, string}> 종류, 매니저, 식별자, 부모 네임스페이스, 진입 FQCN
     */
    public static function kinds(): array
    {
        return [
            'plugin' => ['plugins', PluginManager::class, 'zztest-orphan159_plugin', 'Zztest159OrphanPlugin\\Impl', 'Plugins\\Zztest\\Orphan159Plugin\\Plugin'],
            'module' => ['modules', ModuleManager::class, 'zztest-orphan159_module', 'Zztest159OrphanModule\\Impl', 'Modules\\Zztest\\Orphan159Module\\Module'],
        ];
    }

    /**
     * 자기 PSR-4 클래스를 상속하는 활성 디렉토리 확장(오토로드 캐시에 없음)을 만듭니다.
     *
     * @param  string  $kind  확장 종류 디렉터리 (plugins 또는 modules)
     * @param  string  $identifier  확장 식별자
     * @param  string  $parentNamespace  부모 클래스 네임스페이스
     * @param  string  $entryClass  진입 클래스의 FQCN
     * @return void 반환값 없음
     */
    private function writeOrphan(string $kind, string $identifier, string $parentNamespace, string $entryClass): void
    {
        $isModule = $kind === 'modules';
        $dir = $this->tempRoot."/{$kind}/{$identifier}";
        $short = $isModule ? 'Module' : 'Plugin';
        $base = $isModule ? '\\App\\Extension\\AbstractModule' : '\\App\\Extension\\AbstractPlugin';

        File::ensureDirectoryExists($dir.'/src');
        File::put($dir.'/'.($isModule ? 'module.json' : 'plugin.json'), json_encode([
            'identifier' => $identifier,
            'vendor' => 'zztest',
            'name' => ['ko' => '고아 폴더 프로브', 'en' => 'Orphan Probe'],
            'version' => '0.0.1',
        ], JSON_UNESCAPED_UNICODE));
        File::put($dir.'/composer.json', json_encode(['autoload' => ['psr-4' => [$parentNamespace.'\\' => 'src/']]], JSON_UNESCAPED_SLASHES));
        File::put($dir."/src/{$short}.php", "<?php\n\nnamespace {$parentNamespace};\n\nclass {$short} extends {$base}\n{\n}\n");

        $namespace = substr($entryClass, 0, strrpos($entryClass, '\\'));
        File::put($dir.'/'.($isModule ? 'module.php' : 'plugin.php'), "<?php\n\nnamespace {$namespace};\n\nfinal class {$short} extends \\{$parentNamespace}\\{$short}\n{\n}\n");
    }

    /**
     * 오토로드 캐시에 없는 활성 확장도 부팅 로드가 그 확장의 오토로드를 등록해 진입 클래스를 올린다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects negative_cache_bypassed
     *
     * @param  string  $kind  확장 종류 디렉터리 (plugins 또는 modules)
     * @param  class-string  $managerClass  검사할 매니저 클래스
     * @param  string  $identifier  확장 식별자
     * @param  string  $parentNamespace  부모 클래스 네임스페이스
     * @param  string  $entryClass  진입 클래스의 FQCN
     * @return void 반환값 없음
     */
    #[DataProvider('kinds')]
    public function test_boot_load_registers_autoload_for_extension_missing_from_cache(
        string $kind,
        string $managerClass,
        string $identifier,
        string $parentNamespace,
        string $entryClass,
    ): void {
        $this->writeOrphan($kind, $identifier, $parentNamespace, $entryClass);
        ExtensionManager::rememberRegisteredAutoload(['extensions_fingerprint' => [], 'src_classmap' => []]);

        $manager = app($managerClass);
        $ref = new ReflectionClass($manager);
        $ref->getProperty($kind === 'modules' ? 'modulesPath' : 'pluginsPath')->setValue($manager, $this->tempRoot.'/'.$kind);

        // 수정 전에는 여기서 Class not found 로 부팅이 죽었다.
        $kind === 'modules' ? $manager->loadModules() : $manager->loadPlugins();

        $this->assertTrue(class_exists($entryClass, false), '고아 폴더의 진입 클래스를 올리지 못했습니다.');
        $loaded = $kind === 'modules' ? $manager->getModule($identifier) : $manager->getPlugin($identifier);
        $this->assertNotNull($loaded);
    }
}
