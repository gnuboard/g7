<?php

namespace Tests\Unit\Extension;

use App\Extension\ExtensionManager;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 설치 직전 소스 classmap 등록이 같은 프로세스의 앞선 실패 조회가 남긴 Composer 음성 캐시를 우회하는지 검증 (#159)
 *
 * ## 무엇을 막는가
 *
 * Composer `ClassLoader::findFile()` 은 한 번 못 찾은 클래스를 `missingClasses` 에 기억하고, 그 뒤로는 PSR-4 를
 * 다시 보지 않는다. 같은 프로세스에서 미설치 확장의 진입 파일을 먼저 읽어(설치 전 의존 확인) 그 확장이 상속하는
 * 자기 소스 클래스를 못 찾은 적이 있으면, 설치 단계가 `addPsr4()` 로 경로를 등록해도 「Class not found」 로 죽었다.
 * Composer 는 classMap 을 missingClasses 보다 먼저 보므로, 설치 직전에 그 확장의 소스 classmap 을 등록하면 풀린다.
 *
 * ## 왜 base_path 아래 임시 루트인가
 *
 * 실제 번들 확장은 테스트 부트스트랩이 PSR-4 를 미리 등록해 두어 음성 캐시가 생기지 않는다. 처음 보는 네임스페이스를
 * 가진 임시 확장으로 「못 찾음 → PSR-4 추가 → 여전히 못 찾음」 을 먼저 재현한 뒤 등록 경로를 부른다.
 *
 * 트러블슈팅 `[case:backend-63]` 의 회귀 그물이다.
 */
class InstallClassmapBypassesNegativeCacheTest extends TestCase
{
    /** 임시 확장 디렉터리 (절대 경로) */
    private string $extensionDir;

    /**
     * 테스트 준비 — 임시 확장 디렉터리 경로를 정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionDir = base_path('storage/framework/testing/g7-159-'.bin2hex(random_bytes(6)));
    }

    /**
     * 테스트 정리 — 임시 확장 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_dir($this->extensionDir)) {
            File::deleteDirectory($this->extensionDir);
        }

        parent::tearDown();
    }

    /**
     * 임시 확장 파일을 씁니다.
     *
     * @param  array<string, string>  $psr4  네임스페이스 → 확장 디렉터리 기준 경로
     * @param  array<string, string>  $files  확장 디렉터리 기준 경로 → PHP 소스
     * @return void 반환값 없음
     */
    private function writeExtension(array $psr4, array $files): void
    {
        File::ensureDirectoryExists($this->extensionDir);
        File::put($this->extensionDir.'/composer.json', json_encode(['autoload' => ['psr-4' => $psr4]], JSON_UNESCAPED_SLASHES));

        foreach ($files as $relative => $source) {
            File::ensureDirectoryExists(dirname($this->extensionDir.'/'.$relative));
            File::put($this->extensionDir.'/'.$relative, $source);
        }
    }

    /**
     * 코어 Composer 로더 (base_path('vendor') 의 싱글턴).
     *
     * @return ClassLoader 코어 로더
     */
    private function coreLoader(): ClassLoader
    {
        return require base_path('vendor/autoload.php');
    }

    /**
     * 코어 로더가 클래스를 「없음」 으로 기억하고 있는지 봅니다.
     *
     * @param  string  $class  확인할 클래스의 FQCN
     * @return bool 음성 캐시에 있으면 true
     */
    private function isNegativelyCached(string $class): bool
    {
        $missing = (new ReflectionProperty(ClassLoader::class, 'missingClasses'))->getValue($this->coreLoader());

        return isset($missing[$class]);
    }

    /**
     * 앞선 실패 조회로 음성 캐시가 생긴 클래스도 소스 classmap 등록 뒤에는 로드된다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects negative_cache_bypassed
     *
     * @return void 반환값 없음
     */
    public function test_source_classmap_registration_bypasses_negative_cache(): void
    {
        $this->writeExtension(['Zztest159\\Impl\\' => 'src/'], [
            'src/Base.php' => "<?php\n\nnamespace Zztest159\\Impl;\n\nclass Base\n{\n}\n",
        ]);
        $class = 'Zztest159\\Impl\\Base';

        // 설치 전 의존 확인이 진입 파일을 읽다 부모 클래스를 못 찾은 상태를 재현한다.
        $this->assertFalse(class_exists($class));
        $this->assertTrue($this->isNegativelyCached($class), '전제: Composer 가 못 찾은 클래스를 기억해야 한다');

        // 종전 설치 흐름(addPsr4 만)으로는 풀리지 않는다 — 이 테스트가 재현하는 결함의 핵심.
        $this->coreLoader()->addPsr4('Zztest159\\Impl\\', $this->extensionDir.'/src');
        $this->assertFalse(class_exists($class), '전제: addPsr4 만으로는 음성 캐시가 풀리지 않는다');

        ExtensionManager::registerExtensionClassmapAt($this->extensionDir, $this->coreLoader());

        $this->assertTrue(class_exists($class), '설치 직전 소스 classmap 등록이 음성 캐시를 우회하지 못했습니다.');
    }

    /**
     * PSR-4 가 확장 루트(./)를 가리켜도 확장 자체 vendor 아래 파일은 classmap 에 싣지 않는다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects negative_cache_bypassed
     *
     * @return void 반환값 없음
     */
    public function test_source_classmap_skips_vendor_and_node_modules(): void
    {
        $this->writeExtension(['Zztest159Root\\' => './'], [
            'Entry.php' => "<?php\n\nnamespace Zztest159Root;\n\nclass Entry\n{\n}\n",
            'src/Service.php' => "<?php\n\nnamespace Zztest159Root\\Src;\n\nclass Service\n{\n}\n",
            'vendor/pkg/Shadow.php' => "<?php\n\nnamespace Zztest159Root\\Vendor;\n\nclass Shadow\n{\n}\n",
            'node_modules/pkg/Shadow.php' => "<?php\n\nnamespace Zztest159Root\\NodeModules;\n\nclass Shadow\n{\n}\n",
        ]);

        $loader = new ClassLoader;
        ExtensionManager::registerExtensionClassmapAt($this->extensionDir, $loader);
        $classMap = $loader->getClassMap();

        $this->assertArrayHasKey('Zztest159Root\\Entry', $classMap);
        $this->assertArrayHasKey('Zztest159Root\\Src\\Service', $classMap);
        $this->assertSame(realpath($this->extensionDir.'/src/Service.php'), realpath($classMap['Zztest159Root\\Src\\Service']), '절대 경로로 등록해야 한다');
        $this->assertArrayNotHasKey('Zztest159Root\\Vendor\\Shadow', $classMap, 'vendor 하위는 스캔하지 않는다');
        $this->assertArrayNotHasKey('Zztest159Root\\NodeModules\\Shadow', $classMap, 'node_modules 하위는 스캔하지 않는다');
    }

    /**
     * 설치·활성화·시더 경로가 부르는 등록 함수가 PSR-4 등록 뒤에 소스 classmap 등록을 한다.
     *
     * @scenario probe_mode=php, loader=core
     *
     * @effects negative_cache_bypassed
     *
     * @return void 반환값 없음
     */
    public function test_register_autoload_paths_registers_source_classmap_after_psr4(): void
    {
        $ref = new \ReflectionMethod(ExtensionManager::class, 'registerExtensionAutoloadPaths');
        $lines = file($ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));

        $psr4Pos = strpos($body, '->addPsr4(');
        $classmapPos = strpos($body, 'registerExtensionClassmapAt(');

        $this->assertNotFalse($psr4Pos);
        $this->assertNotFalse($classmapPos, 'registerExtensionAutoloadPaths 가 소스 classmap 을 등록하지 않습니다');
        $this->assertGreaterThan($psr4Pos, $classmapPos);
    }
}
