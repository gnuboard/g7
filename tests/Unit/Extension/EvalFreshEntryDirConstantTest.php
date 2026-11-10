<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\ExtensionDoc\DeclarativeSurfaceCollector;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 이미 선언된 진입 클래스를 새 이름으로 eval 재로드할 때 `__DIR__`·`__FILE__` 이 진입 파일 위치를 가리키는지 검증 (#159)
 *
 * ## 무엇을 막는가
 *
 * 활성 사본이 로드된 상태에서 `_bundled` 사본 정보를 읽거나(미설치 상세·업데이트 확인) 확장 문서를 만들 때는 같은
 * 클래스명을 다시 선언할 수 없어 진입 파일 내용을 클래스명만 바꿔 eval 한다. eval 된 코드의 `__DIR__` 은 eval 을 부른
 * 코어 파일의 디렉터리라, 진입 파일이 `__DIR__` 로 자기 파일을 찾으면 엉뚱한 경로를 봤다.
 *
 * ## 예외 (필수)
 *
 * 진입 파일이 `__DIR__.'/vendor/autoload.php'` 를 부르는 확장에서, 활성 사본이 그 vendor 오토로더를 이미 선언했는데
 * `_bundled` 사본 eval 의 `__DIR__` 을 고치면 `_bundled` vendor 의 같은 이름 오토로더를 다시 선언해 치명 오류로 죽는다.
 * 그 경우에는 고치지 않는다(종전 동작). 치명 오류는 프로세스를 끝내므로 별도 프로세스에서 잰다.
 */
class EvalFreshEntryDirConstantTest extends TestCase
{
    /** 임시 루트 */
    private string $tempRoot;

    /**
     * 테스트 준비 — 임시 루트 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-159-eval-'.bin2hex(random_bytes(6));
    }

    /**
     * 테스트 정리 — 임시 루트를 지웁니다.
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
     * eval 재로드 경로 정의.
     *
     * @return array<string, array{string}> 경로 이름
     */
    public static function reloaders(): array
    {
        return [
            'plugin' => ['plugin'],
            'module' => ['module'],
            'docgen' => ['docgen'],
        ];
    }

    /**
     * 진입 파일 하나를 씁니다.
     *
     * @param  string  $dir  확장 디렉터리
     * @param  string  $kind  재로드 경로 이름 (plugin·module·docgen)
     * @param  bool  $requiresOwnVendor  진입 파일이 자체 vendor/autoload.php 를 부르는지
     * @return array{file: string, class: string, namespace: string, short: string} 진입 파일 경로·클래스 정보
     */
    private function writeEntry(string $dir, string $kind, bool $requiresOwnVendor): array
    {
        $isModule = $kind === 'module';
        $short = $isModule ? 'Module' : 'Plugin';
        $namespace = ($isModule ? 'Modules' : 'Plugins').'\\Zztest\\Eval159'.ucfirst($kind);
        $base = $isModule ? '\\App\\Extension\\AbstractModule' : '\\App\\Extension\\AbstractPlugin';
        $file = $dir.DIRECTORY_SEPARATOR.($isModule ? 'module.php' : 'plugin.php');

        $vendorRequire = $requiresOwnVendor
            ? "\$vendorAutoload = __DIR__.'/vendor/autoload.php';\nif (is_file(\$vendorAutoload)) {\n    require_once \$vendorAutoload;\n}\n\n"
            : '';

        File::ensureDirectoryExists($dir);
        File::put($file, <<<PHP
<?php

namespace {$namespace};

{$vendorRequire}class {$short} extends {$base}
{
    public function where(): string
    {
        return __DIR__;
    }

    public function whereFile(): string
    {
        return __FILE__;
    }

    public function literal(): string
    {
        return '__DIR__';
    }
}
PHP);

        return ['file' => $file, 'class' => $namespace.'\\'.$short, 'namespace' => $namespace, 'short' => $short];
    }

    /**
     * 확장 자체 vendor 오토로더 사본을 씁니다.
     *
     * @param  string  $dir  확장 디렉터리
     * @param  string  $suffix  오토로더 클래스 접미어
     * @param  string  $copy  사본 이름
     * @return string autoload.php 절대 경로
     */
    private function writeVendor(string $dir, string $suffix, string $copy): string
    {
        File::ensureDirectoryExists($dir.'/vendor/composer');
        File::put($dir.'/vendor/composer/autoload_real.php', "<?php\n\nclass ComposerAutoloaderInit{$suffix}\n{\n    public static function getLoader(): string\n    {\n        return '{$copy}';\n    }\n}\n");
        File::put($dir.'/vendor/autoload.php', "<?php\n\nrequire_once __DIR__.'/composer/autoload_real.php';\n\nreturn ComposerAutoloaderInit{$suffix}::getLoader();\n");

        return $dir.'/vendor/autoload.php';
    }

    /**
     * 경로별 eval 재로드를 부릅니다.
     *
     * @param  string  $kind  재로드 경로 이름 (plugin·module·docgen)
     * @param  array{file: string, class: string, namespace: string, short: string}  $entry  진입 파일 경로·클래스 정보
     * @param  string  $dir  확장 디렉터리
     * @return object|null 재로드한 확장 인스턴스 (실패하면 null)
     */
    private function evalFresh(string $kind, array $entry, string $dir): ?object
    {
        return match ($kind) {
            'plugin' => (new ReflectionMethod(PluginManager::class, 'evalFreshPlugin'))->invoke(app(PluginManager::class), $entry['file'], $entry['class'], $dir),
            'module' => (new ReflectionMethod(ModuleManager::class, 'evalFreshModule'))->invoke(app(ModuleManager::class), $entry['file'], $entry['class'], $dir),
            'docgen' => (new ReflectionMethod(DeclarativeSurfaceCollector::class, 'evalFreshEntry'))->invoke(new DeclarativeSurfaceCollector, [
                'entryFile' => $entry['file'],
                'entryClassShort' => $entry['short'],
                'namespace' => $entry['namespace'],
                'path' => $dir,
            ]),
        };
    }

    /**
     * 경로 비교용 정규화.
     *
     * @param  string  $path  경로
     * @return string 정규화한 경로
     */
    private function normalize(string $path): string
    {
        return strtolower(str_replace('\\', '/', realpath($path) ?: $path));
    }

    /**
     * eval 재로드 인스턴스의 `__DIR__`·`__FILE__` 은 진입 파일 위치이고, 문자열 리터럴은 그대로다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects eval_reload_keeps_entry_file_location
     *
     * @param  string  $kind  재로드 경로 이름 (plugin·module·docgen)
     * @return void 반환값 없음
     */
    #[DataProvider('reloaders')]
    public function test_eval_reload_resolves_dir_constants_to_entry_file(string $kind): void
    {
        $dir = $this->tempRoot.DIRECTORY_SEPARATOR.$kind;
        $entry = $this->writeEntry($dir, $kind, false);

        $instance = $this->evalFresh($kind, $entry, $dir);

        $this->assertIsObject($instance);
        $this->assertSame($this->normalize($dir), $this->normalize($instance->where()), 'eval 재로드의 __DIR__ 이 진입 파일 디렉터리가 아닙니다.');
        $this->assertSame($this->normalize($entry['file']), $this->normalize($instance->whereFile()), 'eval 재로드의 __FILE__ 이 진입 파일이 아닙니다.');
        $this->assertSame('__DIR__', $instance->literal(), '문자열 리터럴 안의 __DIR__ 은 바꾸지 않는다');
    }

    /**
     * 활성 사본이 vendor 오토로더를 선언한 상태에서 `_bundled` 사본 eval 이 같은 이름 오토로더를 다시 선언하지 않는다.
     *
     * @scenario probe_mode=php, loader=extension-vendor
     *
     * @effects vendor_autoloader_not_redeclared
     *
     * @param  string  $kind  재로드 경로 이름 (plugin·module·docgen)
     * @return void 반환값 없음
     */
    #[DataProvider('reloaders')]
    #[RunInSeparateProcess]
    public function test_eval_reload_does_not_redeclare_vendor_autoloader_of_active_copy(string $kind): void
    {
        $suffix = 'Zztest'.bin2hex(random_bytes(8));
        $activeDir = $this->tempRoot.DIRECTORY_SEPARATOR.'active-'.$kind;
        $bundledDir = $this->tempRoot.DIRECTORY_SEPARATOR.'bundled-'.$kind;
        require_once $this->writeVendor($activeDir, $suffix, 'active');
        $this->writeVendor($bundledDir, $suffix, 'bundled');
        $entry = $this->writeEntry($bundledDir, $kind, true);

        // 예외가 없으면 여기서 _bundled vendor 를 불러 프로세스가 fatal 로 종료된다.
        $instance = $this->evalFresh($kind, $entry, $bundledDir);

        $this->assertIsObject($instance);
        $this->assertSame('active', ('ComposerAutoloaderInit'.$suffix)::getLoader());
        $included = array_map(fn (string $f) => $this->normalize($f), get_included_files());
        $this->assertNotContains($this->normalize($bundledDir.'/vendor/composer/autoload_real.php'), $included);
    }
}
