<?php

namespace Tests\Unit\Extension;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Extension\ExtensionManager;
use App\Extension\Support\ExtensionFingerprint;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 런타임 오토로드 재등록·자기 복구가 「등록된 첫 로더」 가 아니라 코어 Composer 로더에 등록하는지 검증 (#159)
 *
 * ## 무엇을 막는가
 *
 * Composer 오토로더는 자신을 `ClassLoader::getRegisteredLoaders()` 맨 앞에 넣는다. 운영 부팅은 코어 로더 뒤에 확장 자체
 * vendor 오토로더를 불러오므로, 첫 항목은 마지막에 불린 **확장 vendor 로더**다. 재등록이 그 로더에 PSR-4 를 넣고,
 * 자기 복구가 사라진 클래스의 null classmap 을 그 로더에 넣으면 코어 로더엔 삭제된 클래스 경로가 남는다.
 *
 * 시험 프로세스는 확장 vendor 를 vendorDir 없는 로더로 등록해 이 상황이 생기지 않는다. 그래서 vendorDir 를 가진 임시
 * 로더를 맨 앞에 직접 건다.
 */
class CoreClassLoaderSelectionTest extends TestCase
{
    /** base_path 기준 임시 루트 (상대 경로) */
    private string $relativeRoot;

    /** 맨 앞에 건 임시 로더 */
    private ?ClassLoader $foreignLoader = null;

    /** @var array<string, mixed>|null 테스트 전 기억된 오토로드 상태 */
    private ?array $savedState = null;

    /**
     * 테스트 준비 — 임시 루트와 맨 앞 임시 로더를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->relativeRoot = 'storage/framework/testing/g7-159-loader-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(base_path($this->relativeRoot.'/foreign-vendor'));
        File::ensureDirectoryExists(base_path($this->relativeRoot.'/src'));

        $this->savedState = (new ReflectionProperty(ExtensionManager::class, 'registeredAutoloadState'))->getValue();
        ExtensionFingerprint::forget();

        $this->foreignLoader = new ClassLoader(base_path($this->relativeRoot.'/foreign-vendor'));
        $this->foreignLoader->register(true);
    }

    /**
     * 테스트 정리 — 임시 로더를 떼고 임시 루트·기억 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->foreignLoader?->unregister();
        $this->foreignLoader = null;

        (new ReflectionProperty(ExtensionManager::class, 'registeredAutoloadState'))->setValue(null, $this->savedState);
        ExtensionFingerprint::forget();

        if (is_dir(base_path($this->relativeRoot))) {
            File::deleteDirectory(base_path($this->relativeRoot));
        }

        parent::tearDown();
    }

    /**
     * 코어 Composer 로더.
     *
     * @return ClassLoader 코어 로더
     */
    private function coreLoader(): ClassLoader
    {
        return require base_path('vendor/autoload.php');
    }

    /**
     * classmap·vendor_autoloads 가 빈 임시 오토로드 파일을 씁니다 (실제 autoload-extensions.php 는 쓰지 않는다).
     *
     * @param  array<string, string>  $psr4  네임스페이스 → base_path 상대 경로
     * @return string 절대 경로
     */
    private function writeAutoloadFile(array $psr4): string
    {
        $path = base_path($this->relativeRoot.'/autoload-extensions.php');
        File::put($path, '<?php return '.var_export([
            'psr4' => $psr4,
            'classmap' => [],
            'src_classmap' => [],
            'files' => [],
            'vendor_autoloads' => [],
        ], true).';');

        return $path;
    }

    /**
     * 전제: 임시 로더가 등록된 로더 목록 맨 앞이다.
     *
     * @return void 반환값 없음
     */
    private function assertForeignLoaderIsFirst(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $this->assertSame($this->foreignLoader, $loaders[array_key_first($loaders)], '전제: 임시 로더가 맨 앞이어야 한다');
    }

    /**
     * 런타임 재등록은 맨 앞 확장 vendor 로더가 아니라 코어 로더에 PSR-4 를 넣는다.
     *
     * @scenario probe_mode=php, loader=extension-vendor
     *
     * @effects core_loader_selected
     *
     * @return void 반환값 없음
     */
    public function test_reregister_runtime_autoload_targets_core_loader(): void
    {
        $this->assertForeignLoaderIsFirst();
        $manager = app(ExtensionManager::class);
        $path = $this->writeAutoloadFile(['Zztest159Sel\\' => $this->relativeRoot.'/src']);
        (fn () => $this->autoloadFilePath = $path)->call($manager);

        $manager->reregisterRuntimeAutoload();

        $this->assertArrayHasKey('Zztest159Sel\\', $this->coreLoader()->getPrefixesPsr4(), '코어 로더에 PSR-4 가 등록되지 않았습니다.');
        $this->assertArrayNotHasKey('Zztest159Sel\\', $this->foreignLoader->getPrefixesPsr4(), '확장 vendor 로더에 PSR-4 를 넣었습니다.');
    }

    /**
     * 자기 복구는 사라진 클래스의 null classmap 을 코어 로더에 넣는다.
     *
     * @scenario probe_mode=php, loader=extension-vendor
     *
     * @effects core_loader_selected
     *
     * @return void 반환값 없음
     */
    public function test_heal_stale_autoload_unmaps_vanished_class_on_core_loader(): void
    {
        $this->assertForeignLoaderIsFirst();
        $path = $this->writeAutoloadFile([]);

        // 실제 생성기(DB 설치 목록 × 디스크)를 돌리지 않고, 사라진 클래스가 빠진 새 파일을 쓰는 것으로 대신한다.
        $manager = new class(app(ModuleRepositoryInterface::class), app(PluginRepositoryInterface::class)) extends ExtensionManager
        {
            /**
             * 임시 오토로드 파일을 그대로 둔다 (이미 새 내용으로 써 두었다).
             *
             * @return void 반환값 없음
             */
            public function generateAutoloadFile(): void {}
        };
        (fn () => $this->autoloadFilePath = $path)->call($manager);

        ExtensionManager::rememberRegisteredAutoload([
            'extensions_fingerprint' => ['modules:zztest-absent159' => '1:1:1'],
            'src_classmap' => ['Zztest159Sel\\Gone' => $this->relativeRoot.'/src/Gone.php'],
        ]);

        $this->assertTrue($manager->healStaleAutoload());

        $coreMap = $this->coreLoader()->getClassMap();
        $this->assertArrayHasKey('Zztest159Sel\\Gone', $coreMap, '코어 로더에 사라진 클래스를 덮지 않았습니다 — 삭제된 파일 경로가 남습니다.');
        $this->assertNull($coreMap['Zztest159Sel\\Gone']);
        $this->assertArrayNotHasKey('Zztest159Sel\\Gone', $this->foreignLoader->getClassMap(), '확장 vendor 로더에 null classmap 을 넣었습니다.');
    }
}
