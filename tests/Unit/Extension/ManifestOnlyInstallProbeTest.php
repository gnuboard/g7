<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 설치 전 확인용 정보 조회는 미설치 확장의 PHP 진입 파일을 실행하지 않고 manifest(JSON)만 읽는지 검증 (#159)
 *
 * ## 무엇을 막는가
 *
 * 명령줄 설치(설치 마법사 포함)는 설치 직전 의존 확장을 판정하려고 `_bundled` 진입 파일을 PHP 로 실행했다. 진입 클래스가
 * 자기 PSR-4 클래스를 상속하면 그 부모를 이 시점에 못 찾고, Composer 가 그 실패를 `missingClasses` 에 기억해 이어지는
 * 설치 단계가 「Class not found」 로 죽었다. 설치 판정에 필요한 값(이름·버전·의존·연동)은 원래 manifest 가 SSoT 다.
 *
 * ## 무엇으로 판정하는가
 *
 * 「진입 클래스가 선언되지 않았다」 만으로는 판정하지 않는다 — 부모를 못 찾으면 수정 전에도 선언되지 않는다. 대신
 * 앞쪽에 건 감시 오토로더로 **부모 FQCN 이 한 번이라도 오토로드 요청됐는지**와, 코어 로더의 음성 캐시에 남았는지를 본다.
 *
 * 트러블슈팅 `[case:backend-63]` 의 회귀 그물이다.
 */
class ManifestOnlyInstallProbeTest extends TestCase
{
    /** 임시 확장 루트 */
    private string $tempRoot;

    /** @var array<int, string> 감시 오토로더가 본 요청 */
    private array $requested = [];

    /** @var (callable(string): void)|null 감시 오토로더 */
    private $watcher = null;

    /**
     * 테스트 준비 — 임시 확장 루트 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-159-manifest-'.bin2hex(random_bytes(6));
    }

    /**
     * 테스트 정리 — 감시 오토로더를 떼고 임시 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->watcher !== null) {
            spl_autoload_unregister($this->watcher);
            $this->watcher = null;
        }

        if (is_dir($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * 확장 종류별 픽스처 정의.
     *
     * @return array<string, array{string, string, string, string, string}> 종류, 매니저, 식별자, 진입 FQCN, 부모 FQCN
     */
    public static function kinds(): array
    {
        return [
            'plugin' => ['plugins', PluginManager::class, 'zztest-probe159_plugin', 'Plugins\\Zztest\\Probe159Plugin\\Plugin', 'Zztest159ProbePlugin\\Impl\\Plugin'],
            'module' => ['modules', ModuleManager::class, 'zztest-probe159_module', 'Modules\\Zztest\\Probe159Module\\Module', 'Zztest159ProbeModule\\Impl\\Module'],
        ];
    }

    /**
     * 자기 PSR-4 클래스를 상속하는(부모 소스는 없는) `_bundled` 확장을 만듭니다.
     *
     * @param  string  $kind  확장 종류 디렉터리 (plugins 또는 modules)
     * @param  string  $identifier  확장 식별자
     * @param  string  $entryClass  진입 클래스의 FQCN
     * @param  string  $parentClass  진입 클래스가 상속하는 부모 클래스의 FQCN
     * @return void 반환값 없음
     */
    private function writeBundled(string $kind, string $identifier, string $entryClass, string $parentClass): void
    {
        $isModule = $kind === 'modules';
        $dir = $this->tempRoot."/{$kind}/_bundled/{$identifier}";
        File::ensureDirectoryExists($dir);

        File::put($dir.'/'.($isModule ? 'module.json' : 'plugin.json'), json_encode([
            'identifier' => $identifier,
            'vendor' => 'zztest',
            'name' => ['ko' => '매니페스트 프로브', 'en' => 'Manifest Probe'],
            'version' => '1.2.3',
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'dependencies' => ['modules' => [], 'plugins' => []],
        ], JSON_UNESCAPED_UNICODE));

        $namespace = substr($entryClass, 0, strrpos($entryClass, '\\'));
        $short = substr($entryClass, strrpos($entryClass, '\\') + 1);
        File::put($dir.'/'.($isModule ? 'module.php' : 'plugin.php'), "<?php\n\nnamespace {$namespace};\n\nfinal class {$short} extends \\{$parentClass}\n{\n}\n");
    }

    /**
     * 매니저의 확장 루트를 임시 루트로 돌리고 `_bundled` 메타데이터를 읽힙니다.
     *
     * @param  object  $manager  경로를 바꿀 모듈·플러그인 매니저
     * @param  string  $kind  확장 종류 디렉터리 (plugins 또는 modules)
     * @return void 반환값 없음
     */
    private function loadBundledInto(object $manager, string $kind): void
    {
        $ref = new ReflectionClass($manager);
        $isModule = $kind === 'modules';

        $path = $ref->getProperty($isModule ? 'modulesPath' : 'pluginsPath');
        $path->setValue($manager, $this->tempRoot.'/'.$kind);

        $load = $ref->getMethod($isModule ? 'loadBundledModules' : 'loadBundledPlugins');
        $load->invoke($manager);
    }

    /**
     * 부모 FQCN 의 오토로드 요청을 세는 감시 오토로더를 맨 앞에 겁니다.
     *
     * @param  string  $parentClass  부모 FQCN
     * @return void 반환값 없음
     */
    private function watch(string $parentClass): void
    {
        $this->watcher = function (string $class) use ($parentClass): void {
            if ($class === $parentClass) {
                $this->requested[] = $class;
            }
        };
        spl_autoload_register($this->watcher, true, true);
    }

    /**
     * 설치 전 확인 조회는 manifest 값만 돌려주고 진입 파일을 실행하지 않는다.
     *
     * @scenario probe_mode=manifest, loader=core
     *
     * @effects install_probe_reads_manifest_only
     *
     * @param  string  $kind  확장 종류 디렉터리 (plugins 또는 modules)
     * @param  class-string  $managerClass  검사할 매니저 클래스
     * @param  string  $identifier  확장 식별자
     * @param  string  $entryClass  진입 클래스의 FQCN
     * @param  string  $parentClass  진입 클래스가 상속하는 부모 클래스의 FQCN
     * @return void 반환값 없음
     */
    #[DataProvider('kinds')]
    public function test_install_probe_reads_manifest_without_executing_entry_file(
        string $kind,
        string $managerClass,
        string $identifier,
        string $entryClass,
        string $parentClass,
    ): void {
        $this->writeBundled($kind, $identifier, $entryClass, $parentClass);
        $manager = app($managerClass);
        $this->loadBundledInto($manager, $kind);
        $this->watch($parentClass);

        $info = $kind === 'modules'
            ? $manager->getModuleManifestInfo($identifier)
            : $manager->getPluginManifestInfo($identifier);

        $this->assertIsArray($info);
        $this->assertSame($identifier, $info['identifier']);
        $this->assertSame('1.2.3', $info['version']);
        $this->assertNotSame('', $info['name']);
        $this->assertFalse($info['is_installed']);
        $this->assertSame('uninstalled', $info['status']);
        $this->assertArrayHasKey('dependencies', $info);

        $this->assertSame([], $this->requested, '설치 전 확인이 미설치 확장의 진입 파일을 실행해 부모 클래스를 오토로드 요청했습니다.');
        $missing = (new ReflectionProperty(ClassLoader::class, 'missingClasses'))->getValue(require base_path('vendor/autoload.php'));
        $this->assertArrayNotHasKey($parentClass, $missing, '부모 클래스가 Composer 음성 캐시에 남았습니다 — 이어지는 설치가 Class not found 로 죽습니다.');
        $this->assertFalse(class_exists($entryClass, false));
    }

    /**
     * `_bundled`·`_pending` 어디에도 없는 식별자는 null 이다.
     *
     * @scenario probe_mode=manifest, loader=core
     *
     * @effects install_probe_reads_manifest_only
     *
     * @param  string  $kind  확장 종류 디렉터리 (plugins 또는 modules)
     * @param  class-string  $managerClass  검사할 매니저 클래스
     * @return void 반환값 없음
     */
    #[DataProvider('kinds')]
    public function test_unknown_identifier_returns_null(string $kind, string $managerClass): void
    {
        $manager = app($managerClass);
        File::ensureDirectoryExists($this->tempRoot.'/'.$kind.'/_bundled');
        $this->loadBundledInto($manager, $kind);

        $info = $kind === 'modules'
            ? $manager->getModuleManifestInfo('zztest-absent159')
            : $manager->getPluginManifestInfo('zztest-absent159');

        $this->assertNull($info);
    }
}
