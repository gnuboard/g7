<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * 런타임 오토로드 재등록이 다른 사본에서 이미 선언된 진입 클래스를 재선언하지 않는지 검증
 *
 * ## 무엇을 막는가
 *
 * ZIP·GitHub 으로 모듈·플러그인을 설치하면 서비스가 먼저 "이미 설치됐는가" 를 확인하려고
 * `getModuleInfo()` / `getPluginInfo()` 를 부른다. 활성 디렉토리에 아직 없으니 `_bundled`
 * 메타데이터 폴백으로 가고, 그 안의 `tryLoadModuleInstance()` 가 **번들 사본**의
 * `module.php` 로 진짜 클래스명을 선언한다. 설치가 끝나면 `updateComposerAutoload()` →
 * `reregisterRuntimeAutoload()` 가 오토로드 파일의 classmap(활성 사본 `module.php`)을
 * `require_once` 하는데, 경로가 달라 중복 제거가 통하지 않는다.
 *
 * ```text
 * Cannot declare class Modules\Gnuboard7\HelloModule\Module, because the name is already in use
 * ```
 *
 * 설치는 이미 끝났는데 응답이 500 이 되고, 프로세스가 종료돼 `_pending` 스테이징도 남는다.
 * `_bundled` 에도 있는 식별자를 ZIP 으로 올린 경우에만 난다.
 *
 * ## 왜 픽스처를 base_path 아래에 두는가
 *
 * 오토로드 파일의 classmap 은 `base_path()` 기준 상대 경로다. 실제 확장으로 짜면 테스트
 * 부트스트랩이 이미 같은 경로를 로드해 둬 `require_once` 가 아무 일도 하지 않으므로 가드를
 * 지워도 통과한다. 두 사본을 갖는 임시 확장을 만들어 위험한 조합을 직접 재현한다.
 */
class RuntimeAutoloadEntryRedeclareTest extends TestCase
{
    /** base_path 기준 임시 루트 (상대 경로) */
    private string $relativeRoot;

    /**
     * 테스트 준비 — 임시 확장 루트 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->relativeRoot = 'storage/framework/testing/g7-entry-redeclare-'.bin2hex(random_bytes(6));
    }

    /**
     * 테스트 정리 — 임시 확장 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $root = base_path($this->relativeRoot);
        if (is_dir($root)) {
            File::deleteDirectory($root);
        }

        parent::tearDown();
    }

    /**
     * 진입 파일 사본 하나를 만든다.
     *
     * 사본마다 `origin()` 반환값이 달라 어느 쪽이 선언됐는지 구분된다.
     *
     * @param  string  $kind  `modules` 또는 `plugins`
     * @param  string  $copy  `active` 또는 `bundled`
     * @param  string  $identifier  확장 디렉토리명 (vendor-name)
     * @param  string  $namespace  네임스페이스 조각 (Vendor\Name)
     * @return string base_path 기준 상대 경로
     */
    private function writeEntry(string $kind, string $copy, string $identifier, string $namespace): string
    {
        $isModule = $kind === 'modules';
        $rootNs = $isModule ? 'Modules' : 'Plugins';
        $base = $isModule ? 'App\Extension\AbstractModule' : 'App\Extension\AbstractPlugin';
        $file = $isModule ? 'module.php' : 'plugin.php';
        $class = $isModule ? 'Module' : 'Plugin';

        $relative = $copy === 'active'
            ? "{$this->relativeRoot}/{$kind}/{$identifier}/{$file}"
            : "{$this->relativeRoot}/{$kind}/_bundled/{$identifier}/{$file}";

        File::ensureDirectoryExists(dirname(base_path($relative)));
        File::put(base_path($relative), <<<PHP
<?php

namespace {$rootNs}\\{$namespace};

class {$class} extends \\{$base}
{
    public function origin(): string
    {
        return '{$copy}';
    }
}
PHP);

        return $relative;
    }

    /**
     * 주어진 classmap 만 담은 오토로드 파일로 런타임 재등록을 수행한다.
     *
     * @param  array<int, string>  $classmap  base_path 기준 진입 파일 경로 목록
     * @return void 반환값 없음
     */
    private function reregisterWith(array $classmap): void
    {
        $autoloadFile = base_path($this->relativeRoot.'/autoload-extensions.php');
        File::ensureDirectoryExists(dirname($autoloadFile));
        File::put($autoloadFile, '<?php return '.var_export([
            'psr4' => [],
            'classmap' => $classmap,
            'src_classmap' => [],
            'files' => [],
            'vendor_autoloads' => [],
        ], true).';');

        $manager = app(ExtensionManager::class);
        $prop = (new ReflectionClass($manager))->getProperty('autoloadFilePath');
        $prop->setAccessible(true);
        $prop->setValue($manager, $autoloadFile);

        // 가드가 없으면 여기서 프로세스가 fatal 로 종료된다 (Error 라 catch 로 못 막는다).
        $manager->reregisterRuntimeAutoload();
    }

    /**
     * 현재 프로세스가 포함한 파일인지 본다 (경로 구분자 차이 무시).
     *
     * @param  string  $relative  base_path 기준 상대 경로
     * @return bool 포함 여부
     */
    private function wasIncluded(string $relative): bool
    {
        $target = str_replace('\\', '/', base_path($relative));

        foreach (get_included_files() as $included) {
            if (strcasecmp(str_replace('\\', '/', $included), $target) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * ZIP 설치 전 조회가 번들 사본을 선언한 뒤 재등록이 활성 사본 모듈을 재선언하지 않는다.
     *
     * @scenario ext_type=module, load_order=bundled_preview_then_runtime_autoload
     *
     * @effects runtime_autoload_skips_entry_declared_from_other_copy
     *
     * @return void 반환값 없음
     */
    public function test_module_runtime_autoload_does_not_redeclare_bundled_preview_class(): void
    {
        $bundled = $this->writeEntry('modules', 'bundled', 'g7guard-autoload_module', 'G7guard\\AutoloadModule');
        $active = $this->writeEntry('modules', 'active', 'g7guard-autoload_module', 'G7guard\\AutoloadModule');

        // 설치 전 중복 확인(tryLoadModuleInstance)이 번들 사본으로 선언한 상태를 재현한다.
        require_once base_path($bundled);
        $class = 'Modules\\G7guard\\AutoloadModule\\Module';
        $this->assertTrue(class_exists($class, false));

        $this->reregisterWith([$active]);

        $this->assertFalse($this->wasIncluded($active), '이미 선언된 진입 클래스의 활성 사본을 다시 읽었습니다.');
        $this->assertSame('bundled', (new $class)->origin());
    }

    /**
     * ZIP 설치 전 조회가 번들 사본을 선언한 뒤 재등록이 활성 사본 플러그인을 재선언하지 않는다.
     *
     * @scenario ext_type=plugin, load_order=bundled_preview_then_runtime_autoload
     *
     * @effects runtime_autoload_skips_entry_declared_from_other_copy
     *
     * @return void 반환값 없음
     */
    public function test_plugin_runtime_autoload_does_not_redeclare_bundled_preview_class(): void
    {
        $bundled = $this->writeEntry('plugins', 'bundled', 'g7guard-autoload_plugin', 'G7guard\\AutoloadPlugin');
        $active = $this->writeEntry('plugins', 'active', 'g7guard-autoload_plugin', 'G7guard\\AutoloadPlugin');

        require_once base_path($bundled);
        $class = 'Plugins\\G7guard\\AutoloadPlugin\\Plugin';
        $this->assertTrue(class_exists($class, false));

        $this->reregisterWith([$active]);

        $this->assertFalse($this->wasIncluded($active), '이미 선언된 진입 클래스의 활성 사본을 다시 읽었습니다.');
        $this->assertSame('bundled', (new $class)->origin());
    }

    /**
     * 아직 선언되지 않은 진입 클래스는 재등록이 그대로 읽어 들인다 (가드가 전부를 건너뛰지 않는다).
     *
     * @scenario ext_type=module, load_order=runtime_autoload_fresh_entry
     *
     * @effects runtime_autoload_still_loads_undeclared_entry
     *
     * @return void 반환값 없음
     */
    public function test_module_runtime_autoload_loads_undeclared_entry(): void
    {
        $active = $this->writeEntry('modules', 'active', 'g7guard-autoload_fresh_module', 'G7guard\\AutoloadFreshModule');
        $class = 'Modules\\G7guard\\AutoloadFreshModule\\Module';
        $this->assertFalse(class_exists($class, false));

        $this->reregisterWith([$active]);

        $this->assertTrue($this->wasIncluded($active));
        $this->assertSame('active', (new $class)->origin());
    }

    /**
     * 아직 선언되지 않은 플러그인 진입 클래스도 재등록이 그대로 읽어 들인다.
     *
     * @scenario ext_type=plugin, load_order=runtime_autoload_fresh_entry
     *
     * @effects runtime_autoload_still_loads_undeclared_entry
     *
     * @return void 반환값 없음
     */
    public function test_plugin_runtime_autoload_loads_undeclared_entry(): void
    {
        $active = $this->writeEntry('plugins', 'active', 'g7guard-autoload_fresh_plugin', 'G7guard\\AutoloadFreshPlugin');
        $class = 'Plugins\\G7guard\\AutoloadFreshPlugin\\Plugin';
        $this->assertFalse(class_exists($class, false));

        $this->reregisterWith([$active]);

        $this->assertTrue($this->wasIncluded($active));
        $this->assertSame('active', (new $class)->origin());
    }
}
