<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\TestCase;

/**
 * 번들·대기 확장의 진입 파일은 있는데 클래스를 찾을 수 없으면 경고를 남긴다 (7.1.3).
 *
 * 예전에는 `tryLoadModuleInstance()`·`tryLoadPluginInstance()` 가 `require` 뒤 `class_exists` 가 거짓이면
 * 로그 없이 null 을 돌려줬다 — 네임스페이스를 잘못 쓴 번들 확장이 관리자 확장 목록에서 이유 없이 빠졌다.
 * 예외 갈래(catch)는 이미 warning 이었고, 이 시험은 「클래스 없음」 갈래를 잠근다.
 *
 * @scenario-manifest tests/scenarios/bundled-meta-class-missing.yaml
 */
class BundledMetaClassMissingWarningTest extends TestCase
{
    /** 임시 확장 루트 (modules/ 와 plugins/ 를 담는다) */
    private string $tempRoot;

    /**
     * 테스트 준비 — 임시 확장 루트 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-bundled-meta-missing-'.bin2hex(random_bytes(6));
    }

    /**
     * 테스트 정리 — 임시 확장 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_dir($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * 네임스페이스가 틀린 진입 파일을 `_bundled` 아래에 만든다.
     *
     * @param  string  $kind  `modules` 또는 `plugins`
     * @param  string  $identifier  확장 디렉토리명 (vendor-name)
     * @return void 반환값 없음
     */
    private function writeTypoFixture(string $kind, string $identifier): void
    {
        $isModule = $kind === 'modules';
        $file = $isModule ? 'module.php' : 'plugin.php';
        $class = $isModule ? 'Module' : 'Plugin';
        $base = $isModule ? 'App\Extension\AbstractModule' : 'App\Extension\AbstractPlugin';
        // 디렉토리명이 기대하는 네임스페이스와 다른 이름을 일부러 쓴다(오타 재현).
        // 조각이 숫자로 시작하면 구문 오류(catch 갈래)가 되므로 글자로 시작시킨다.
        $typoNamespace = 'G7typo\\N'.bin2hex(random_bytes(4));

        $path = $this->tempRoot."/{$kind}/_bundled/{$identifier}/{$file}";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, <<<PHP
<?php

namespace {$typoNamespace};

class {$class} extends \\{$base}
{
}
PHP);
    }

    /**
     * 매니저의 확장 루트를 임시 픽스처로 바꾼다.
     *
     * @param  object  $manager  ModuleManager / PluginManager 인스턴스
     * @param  string  $property  경로 프로퍼티명
     * @param  string  $path  새 경로
     * @return void 반환값 없음
     */
    private function repointRoot(object $manager, string $property, string $path): void
    {
        $prop = (new ReflectionClass($manager))->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($manager, $path);
    }

    /**
     * 모듈 번들 진입 파일의 클래스를 찾을 수 없으면 null 과 함께 경고를 남긴다.
     *
     * @scenario ext_type=module
     *
     * @effects bundled_entry_class_missing_logs_warning
     *
     * @return void 반환값 없음
     */
    public function test_module_bundled_entry_class_missing_logs_warning(): void
    {
        $this->writeTypoFixture('modules', 'g7typo-module_fixture');

        $manager = app(ModuleManager::class);
        $this->repointRoot($manager, 'modulesPath', $this->tempRoot.'/modules');

        Log::spy();

        $method = (new ReflectionClass($manager))->getMethod('tryLoadModuleInstance');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($manager, 'g7typo-module_fixture', false));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['module'] ?? null) === 'g7typo-module_fixture'
                && str_ends_with((string) ($context['expected_class'] ?? ''), '\\Module'))
            ->once();
    }

    /**
     * 플러그인 번들 진입 파일의 클래스를 찾을 수 없으면 null 과 함께 경고를 남긴다.
     *
     * @scenario ext_type=plugin
     *
     * @effects bundled_entry_class_missing_logs_warning
     *
     * @return void 반환값 없음
     */
    public function test_plugin_bundled_entry_class_missing_logs_warning(): void
    {
        $this->writeTypoFixture('plugins', 'g7typo-plugin_fixture');

        $manager = app(PluginManager::class);
        $this->repointRoot($manager, 'pluginsPath', $this->tempRoot.'/plugins');

        Log::spy();

        $method = (new ReflectionClass($manager))->getMethod('tryLoadPluginInstance');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($manager, 'g7typo-plugin_fixture', false));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => ($context['plugin'] ?? null) === 'g7typo-plugin_fixture'
                && str_ends_with((string) ($context['expected_class'] ?? ''), '\\Plugin'))
            ->once();
    }
}
