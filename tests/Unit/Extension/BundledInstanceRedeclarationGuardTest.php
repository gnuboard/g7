<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use Modules\G7guard\ModuleFixture\Module;
use Plugins\G7guard\PluginFixture\Plugin;
use ReflectionClass;
use Tests\TestCase;

/**
 * `_bundled` 인스턴스 조회가 이미 로드된 클래스를 재선언하지 않는지 검증
 *
 * ## 무엇을 막는가
 *
 * `tryLoadModuleInstance()` / `tryLoadPluginInstance()` 는 상세 정보 조회를 위해
 * `_bundled` 의 `module.php` / `plugin.php` 를 읽는다. 그런데 같은 확장의 **활성 디렉토리
 * 사본**은 부팅 중 이미 로드돼 있을 수 있다. 두 파일은 경로가 달라 `require_once` 의
 * 중복 제거가 통하지 않으므로, 그대로 require 하면 같은 FQN 을 두 번 선언하게 된다.
 *
 * ```text
 * Cannot declare class Modules\Foo\Bar\Module, because the name is already in use
 * ```
 *
 * 특히 위험한 이유는 이것이 **PHP Error 라서 `catch (\Exception)` 에 걸리지 않는다**는
 * 점이다. 두 메서드 모두 try/catch 로 감싸여 있어 안전해 보이지만 실제로는 프로세스가
 * 그 자리에서 종료된다 — 테스트 스위트라면 요약 줄도 없이 중단된다.
 *
 * ## 왜 픽스처를 따로 만드는가
 *
 * 테스트 프로세스에서는 부트스트랩이 **`_bundled` 쪽**을 먼저 로드하므로, 실제 번들 확장으로
 * 이 메서드를 호출하면 같은 경로를 다시 require 하는 것이라 중복 제거가 통해 재선언이
 * 일어나지 않는다. 즉 실제 확장으로 짠 테스트는 가드를 지워도 통과한다(처음에 그렇게 짰다가
 * red 증명에서 걸렸다). 위험한 조합은 "**활성** 사본이 로드된 상태에서 **_bundled** 를
 * require" 이므로, 두 사본을 갖는 임시 모듈 루트를 만들어 그 조합을 직접 재현한다.
 */
class BundledInstanceRedeclarationGuardTest extends TestCase
{
    /** 임시 확장 루트 (modules/ 와 plugins/ 를 담는다) */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-redeclare-guard-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * 활성/번들 두 사본을 갖는 확장 픽스처를 만든다.
     *
     * 두 사본은 같은 FQN 을 쓰되 `origin()` 반환값이 달라, 어느 쪽을 읽었는지 구분된다.
     *
     * @param  string  $kind  `modules` 또는 `plugins`
     * @param  string  $identifier  확장 디렉토리명 (vendor-name)
     * @param  string  $namespace  네임스페이스 조각 (Vendor\Name)
     * @return string 활성 사본 파일 경로
     */
    private function writeFixture(string $kind, string $identifier, string $namespace): string
    {
        $isModule = $kind === 'modules';
        $rootNs = $isModule ? 'Modules' : 'Plugins';
        $base = $isModule ? 'App\Extension\AbstractModule' : 'App\Extension\AbstractPlugin';
        $file = $isModule ? 'module.php' : 'plugin.php';
        $class = $isModule ? 'Module' : 'Plugin';

        $activePath = $this->tempRoot."/{$kind}/{$identifier}/{$file}";
        $bundledPath = $this->tempRoot."/{$kind}/_bundled/{$identifier}/{$file}";

        foreach (['active' => $activePath, 'bundled' => $bundledPath] as $origin => $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, <<<PHP
<?php

namespace {$rootNs}\\{$namespace};

class {$class} extends \\{$base}
{
    public function origin(): string
    {
        return '{$origin}';
    }
}
PHP);
        }

        return $activePath;
    }

    /**
     * 매니저의 확장 루트를 임시 픽스처로 바꾼다.
     *
     * @param  object  $manager  ModuleManager / PluginManager 인스턴스
     * @param  string  $property  경로 프로퍼티명
     * @param  string  $path  새 경로
     */
    private function repointRoot(object $manager, string $property, string $path): void
    {
        $ref = new ReflectionClass($manager);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($manager, $path);
    }

    public function test_module_bundled_instance_does_not_redeclare_active_copy(): void
    {
        $activePath = $this->writeFixture('modules', 'g7guard-module_fixture', 'G7guard\\ModuleFixture');

        // 부팅이 활성 사본을 로드한 상태를 재현한다.
        require_once $activePath;
        $this->assertTrue(class_exists(Module::class, false));

        $manager = app(ModuleManager::class);
        $this->repointRoot($manager, 'modulesPath', $this->tempRoot.'/modules');

        $method = (new ReflectionClass($manager))->getMethod('tryLoadModuleInstance');
        $method->setAccessible(true);

        // 가드가 없으면 여기서 프로세스가 fatal 로 종료된다 (Error 라 catch 로 못 막는다).
        $module = $method->invoke($manager, 'g7guard-module_fixture', false);

        $this->assertInstanceOf(ModuleInterface::class, $module, '번들 인스턴스를 얻지 못했습니다.');
        $this->assertSame(
            'bundled',
            $module->origin(),
            '활성 사본을 그대로 돌려줬습니다 — 번들 쪽 메타데이터를 읽어야 합니다.',
        );
    }

    public function test_plugin_bundled_instance_does_not_redeclare_active_copy(): void
    {
        $activePath = $this->writeFixture('plugins', 'g7guard-plugin_fixture', 'G7guard\\PluginFixture');

        require_once $activePath;
        $this->assertTrue(class_exists(Plugin::class, false));

        $manager = app(PluginManager::class);
        $this->repointRoot($manager, 'pluginsPath', $this->tempRoot.'/plugins');

        $method = (new ReflectionClass($manager))->getMethod('tryLoadPluginInstance');
        $method->setAccessible(true);

        $plugin = $method->invoke($manager, 'g7guard-plugin_fixture', false);

        $this->assertInstanceOf(PluginInterface::class, $plugin, '번들 인스턴스를 얻지 못했습니다.');
        $this->assertSame(
            'bundled',
            $plugin->origin(),
            '활성 사본을 그대로 돌려줬습니다 — 번들 쪽 메타데이터를 읽어야 합니다.',
        );
    }
}
