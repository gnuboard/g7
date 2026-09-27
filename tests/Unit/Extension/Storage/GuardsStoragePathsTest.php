<?php

namespace Tests\Unit\Extension\Storage;

use App\Contracts\Extension\StorageInterface;
use App\Exceptions\UnsafeStoragePathException;
use App\Extension\ModuleManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use App\Extension\TemplateManager;
use App\Services\LayoutService;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\Helpers\MocksExtensions;
use Tests\TestCase;

/**
 * 스토리지 드라이버 경로 가드 테스트 (KVE-2026-2349)
 *
 * 게시판 temp_key 에 `../../../..` 를 넣으면 임시 디렉토리 정리 경로가 모듈 디스크 루트로
 * 축약되어 전 모듈 저장소가 삭제됐다. 세 드라이버 공용 가드(GuardsStoragePaths)가
 * 순회·절대경로·인코딩 조각을 결합 전에 거부하고, 디스크·확장 루트 삭제를 막는지(카테고리 루트 삭제는 인터페이스 계약대로 허용),
 * 그리고 정상 경로·카테고리 전체 삭제(deleteAll)는 그대로 동작하는지 실제 파일로 확인한다.
 *
 * 격리된 임시 디스크(local-safe)를 쓰므로 DB 도 운영 저장소도 건드리지 않는다.
 */
class GuardsStoragePathsTest extends TestCase
{
    use MocksExtensions;

    /**
     * 테스트 전용 디스크 이름
     */
    private const DISK = 'guard-test';

    /**
     * 가드가 거부해야 하는 경로 조각 (사유 코드 => 값)
     *
     * @var array<string, string>
     */
    private const UNSAFE_FRAGMENTS = [
        'dot_segment' => '../../../..',
        'nested_dot_segment' => 'free/temp/../../../..',
        'deep_dot_segment' => 'a/../../../../..',
        'single_dot' => '.',
        'encoded_slash' => '..%2f..',
        'encoded_dot_upper' => '%2E%2E',
        'backslash' => '..\\..',
        'absolute_unix' => '/etc/passwd',
        'absolute_windows' => 'C:/Windows',
        'null_byte' => "a\0b",
        'double_slash' => 'a//b',
        'trailing_slash' => 'dir/',
    ];

    /**
     * 격리 디스크 루트
     */
    private string $root;

    /**
     * 격리 디스크를 준비하고 형제 확장 canary 파일을 심습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/'.self::DISK);
        File::deleteDirectory($this->root);

        Config::set('filesystems.disks.'.self::DISK, [
            'driver' => 'local-safe',
            'allow_relative_path_traversal' => false,
            'root' => $this->root,
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);
        Storage::forgetDisk(self::DISK);

        // 가드가 뚫리면 가장 먼저 사라질 형제 확장의 저장소
        Storage::disk(self::DISK)->put('canary-extension/settings/keep.json', '{}');
    }

    /**
     * 격리 디스크를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::forgetDisk(self::DISK);
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 모듈 드라이버 — 중첩 경로 저장·조회·하위 디렉토리 삭제와 카테고리 전체 삭제는 그대로 동작한다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=module,target=nested_path
     *
     * @effects nested_paths_and_category_delete_still_work
     */
    public function test_module_driver_keeps_nested_paths_working(): void
    {
        $this->assertNestedPathsWork(new ModuleStorageDriver('sirsoft-board', self::DISK), 'sirsoft-board/attachments');
    }

    /**
     * 플러그인 드라이버 — 중첩 경로 저장·조회·하위 디렉토리 삭제와 카테고리 전체 삭제는 그대로 동작한다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=plugin,target=nested_path
     *
     * @effects nested_paths_and_category_delete_still_work
     */
    public function test_plugin_driver_keeps_nested_paths_working(): void
    {
        $this->assertNestedPathsWork(new PluginStorageDriver('sirsoft-pay', self::DISK), 'sirsoft-pay/attachments');
    }

    /**
     * 코어 드라이버 — 중첩 경로 저장·조회·하위 디렉토리 삭제와 카테고리 전체 삭제는 그대로 동작한다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=core,target=nested_path
     *
     * @effects nested_paths_and_category_delete_still_work
     */
    public function test_core_driver_keeps_nested_paths_working(): void
    {
        $this->assertNestedPathsWork(new CoreStorageDriver(self::DISK), 'attachments');
    }

    /**
     * 모듈 드라이버 — 순회·절대경로·인코딩 조각은 디스크에 닿기 전에 거부되고 형제 저장소는 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=module,target=unsafe_fragment
     *
     * @effects unsafe_fragment_rejected_before_disk, sibling_extension_storage_survives
     */
    public function test_module_driver_rejects_unsafe_fragments(): void
    {
        $this->assertUnsafeFragmentsRejected(new ModuleStorageDriver('sirsoft-board', self::DISK));
    }

    /**
     * 플러그인 드라이버 — 순회·절대경로·인코딩 조각은 디스크에 닿기 전에 거부되고 형제 저장소는 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=plugin,target=unsafe_fragment
     *
     * @effects unsafe_fragment_rejected_before_disk, sibling_extension_storage_survives
     */
    public function test_plugin_driver_rejects_unsafe_fragments(): void
    {
        $this->assertUnsafeFragmentsRejected(new PluginStorageDriver('sirsoft-pay', self::DISK));
    }

    /**
     * 코어 드라이버 — 순회·절대경로·인코딩 조각은 디스크에 닿기 전에 거부되고 형제 저장소는 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=core,target=unsafe_fragment
     *
     * @effects unsafe_fragment_rejected_before_disk, sibling_extension_storage_survives
     */
    public function test_core_driver_rejects_unsafe_fragments(): void
    {
        $this->assertUnsafeFragmentsRejected(new CoreStorageDriver(self::DISK));
    }

    /**
     * 모듈 드라이버 — 빈 디렉토리 인자는 계약대로 그 카테고리만 지우고, 빈 카테고리(확장 루트)는 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=module,target=protected_root
     *
     * @effects protected_root_deletion_refused_and_logged, category_root_deletion_follows_interface_contract, sibling_extension_storage_survives
     */
    public function test_module_driver_refuses_protected_root_deletion(): void
    {
        $this->assertProtectedRootsRefused(new ModuleStorageDriver('sirsoft-board', self::DISK), 'sirsoft-board');
    }

    /**
     * 플러그인 드라이버 — 빈 디렉토리 인자는 계약대로 그 카테고리만 지우고, 빈 카테고리(확장 루트)는 거부된다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=plugin,target=protected_root
     *
     * @effects protected_root_deletion_refused_and_logged, category_root_deletion_follows_interface_contract, sibling_extension_storage_survives
     */
    public function test_plugin_driver_refuses_protected_root_deletion(): void
    {
        $this->assertProtectedRootsRefused(new PluginStorageDriver('sirsoft-pay', self::DISK), 'sirsoft-pay');
    }

    /**
     * 코어 드라이버 — 빈 디렉토리 인자는 계약대로 그 카테고리만 지우고, 디스크 루트(빈 카테고리)
     * 삭제는 거부되고 보안 로그가 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=core,target=protected_root
     *
     * @effects protected_root_deletion_refused_and_logged, category_root_deletion_follows_interface_contract, sibling_extension_storage_survives
     */
    public function test_core_driver_refuses_protected_root_deletion(): void
    {
        $driver = new CoreStorageDriver(self::DISK);
        $driver->put('attachments', 'a.txt', 'x');
        $driver->put('settings', 'keep.json', '{}');
        Log::spy();

        foreach ([fn () => $driver->deleteDirectory('', ''), fn () => $driver->deleteAll('')] as $call) {
            $this->assertRejected($call, 'destructive_on_root');
        }
        Log::shouldHaveReceived('warning')->with('스토리지 루트를 겨냥한 삭제 작업을 차단했습니다', Mockery::type('array'))->twice();
        $this->assertTrue(Storage::disk(self::DISK)->exists('attachments/a.txt'));

        // 인터페이스 계약: 빈 디렉토리 인자는 그 카테고리 루트를 지운다 — 다른 카테고리·다른 확장은 남는다
        $this->assertTrue($driver->deleteDirectory('attachments', ''));
        $this->assertFalse(Storage::disk(self::DISK)->exists('attachments'));
        $this->assertTrue(Storage::disk(self::DISK)->exists('settings/keep.json'));
        $this->assertTrue(Storage::disk(self::DISK)->exists('canary-extension/settings/keep.json'));
    }

    /**
     * 공개 URL 해석은 가드가 거부한 경로에서 예외 대신 null 을 돌려준다 (목록 직렬화 보호).
     *
     * @return void 반환값 없음
     *
     * @effects unsafe_url_resolves_to_null
     */
    public function test_url_returns_null_for_rejected_path(): void
    {
        $driver = new ModuleStorageDriver('sirsoft-board', self::DISK);

        $this->assertNull($driver->url('images', '../../x.png'));
        $this->assertNull($driver->url('images', 'a//b.png'));
    }

    /**
     * local-safe 디스크는 Flysystem 에 닿은 `..` 를 PathTraversalDetected 로 거부하고,
     * 표준 local 디스크는 기존 정규화를 유지한다.
     *
     * @return void 반환값 없음
     *
     * @effects local_safe_disk_rejects_relative_traversal
     */
    public function test_local_safe_disk_rejects_relative_traversal(): void
    {
        try {
            Storage::disk(self::DISK)->exists('canary-extension/../canary-extension/settings/keep.json');
            $this->fail('local-safe 디스크가 상대경로 순회를 허용했습니다.');
        } catch (PathTraversalDetected) {
            $this->addToAssertionCount(1);
        }

        // 확장 저장소 디스크가 local-safe 로 결선되어 있다
        foreach (['modules', 'plugins', 'attachments', 'public'] as $disk) {
            $this->assertSame('local-safe', config("filesystems.disks.{$disk}.driver"), $disk);
        }

        // 표준 local 드라이버는 그대로 (영향 범위 최소화)
        $plain = Storage::build(['driver' => 'local', 'root' => $this->root]);
        $this->assertTrue($plain->exists('canary-extension/../canary-extension/settings/keep.json'));
    }

    /**
     * 운영 설정 그대로 만든 확장 저장소 디스크 4종이 드라이버 가드를 거치지 않은 순회 조각도 거부한다.
     *
     * 다른 테스트 대부분이 `Storage::fake()` 를 써서 local-safe 드라이버를 거치지 않는다 — fake 는 표준 local
     * 드라이버로 디스크를 만든다. 그래서 운영 디스크 설정(`config/filesystems.php`)에서 디스크를 직접 만들어
     * 루트만 격리 폴더로 바꾸고, 드라이버 경로 가드를 우회한 호출(디스크 직접 삭제)도 막히는지 확인한다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=disk,target=unsafe_fragment
     *
     * @effects production_disks_reject_traversal_without_driver_guard, sibling_extension_storage_survives
     */
    public function test_production_extension_disks_reject_traversal_without_driver_guard(): void
    {
        $this->assertProductionDisksRefuse(['a/../victim', 'victim/sub/../../victim']);
    }

    /**
     * 운영 설정 그대로 만든 확장 저장소 디스크 4종이 디스크 루트·그 위를 가리키는 삭제를 거부한다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=disk,target=protected_root
     *
     * @effects production_disks_reject_traversal_without_driver_guard, protected_root_deletion_refused_and_logged
     */
    public function test_production_extension_disks_refuse_root_deletion_without_driver_guard(): void
    {
        $this->assertProductionDisksRefuse(['victim/..', 'a/b/../../..']);
    }

    /**
     * local-safe 디스크가 Laravel 기본 local 드라이버의 read-only·prefix 옵션을 조용히 무시하지 않는다.
     *
     * 래퍼 패키지가 설치돼 있으면 옵션이 적용되고(읽기 전용은 쓰기 거부, prefix 는 하위 폴더), 없으면
     * 설정 오류로 알린다.
     *
     * @return void 반환값 없음
     *
     * @scenario driver=disk,target=nested_path
     *
     * @effects local_safe_options_applied_or_rejected_loudly
     */
    public function test_local_safe_disk_honours_read_only_and_prefix_options(): void
    {
        $base = ['driver' => 'local-safe', 'root' => $this->root.'/options', 'allow_relative_path_traversal' => false];

        if (class_exists(ReadOnlyFilesystemAdapter::class)) {
            $readOnly = Storage::build($base + ['read-only' => true, 'throw' => true]);
            $this->expectExceptionOnWrite(fn () => $readOnly->put('x.txt', 'x'));
        } else {
            $this->assertOptionRejected($base + ['read-only' => true], 'league/flysystem-read-only');
        }

        if (class_exists(PathPrefixedAdapter::class)) {
            Storage::build($base + ['prefix' => 'scoped'])->put('y.txt', 'y');
            $this->assertFileExists($this->root.'/options/scoped/y.txt');
        } else {
            $this->assertOptionRejected($base + ['prefix' => 'scoped'], 'league/flysystem-path-prefixing');
        }

        // 옵션이 없으면 그대로 동작한다
        Storage::build($base)->put('z.txt', 'z');
        $this->assertFileExists($this->root.'/options/z.txt');
    }

    /**
     * 설정 디렉토리 정리는 확장 루트가 아니라 settings 카테고리만 지운다.
     *
     * 종전에는 `deleteDirectory('', '')` 로 확장 저장소 전체를 지웠는데, 가드가 확장 루트
     * 파괴를 거부하므로 카테고리 전체 삭제 API 로만 정리한다.
     *
     * @return void 반환값 없음
     *
     * @effects settings_cleanup_limited_to_settings_category
     */
    public function test_settings_directory_cleanup_deletes_only_settings_category(): void
    {
        $driver = new ModuleStorageDriver('sirsoft-board', self::DISK);
        $driver->put('settings', 'setting.json', '{}');
        $driver->put('attachments', 'free/2026/01/01/a.png', 'x');

        $module = $this->createMockModule(['identifier' => 'sirsoft-board', 'withStorage' => false]);
        $module->shouldReceive('getStorage')->andReturn($driver);

        $manager = Mockery::mock(ModuleManager::class);
        $manager->shouldReceive('getModule')->with('sirsoft-board')->andReturn($module);

        $service = new ModuleSettingsService(
            $manager,
            Mockery::mock(TemplateManager::class),
            Mockery::mock(LayoutService::class)
        );

        $this->assertTrue($service->deleteSettingsDirectory('sirsoft-board'));
        $this->assertFalse($driver->exists('settings', 'setting.json'));
        $this->assertTrue($driver->exists('attachments', 'free/2026/01/01/a.png'));
    }

    /**
     * 운영 디스크 설정으로 만든 디스크 4종이 주어진 경로의 삭제를 PathTraversalDetected 로 거부하고
     * 옆 폴더를 보존하는지 확인합니다.
     *
     * @param  array<int, string>  $paths  삭제를 시도할 경로
     * @return void 반환값 없음
     */
    private function assertProductionDisksRefuse(array $paths): void
    {
        foreach (['modules', 'plugins', 'attachments', 'public'] as $disk) {
            $filesystem = Storage::build(array_merge(config("filesystems.disks.{$disk}"), ['root' => $this->root.'/'.$disk]));
            $filesystem->put('victim/keep.txt', 'x');

            foreach ($paths as $path) {
                try {
                    $filesystem->deleteDirectory($path);
                    $this->fail("{$disk} 디스크가 {$path} 삭제를 허용했습니다.");
                } catch (PathTraversalDetected) {
                    $this->addToAssertionCount(1);
                }
            }

            $this->assertTrue($filesystem->exists('victim/keep.txt'), $disk);
        }
    }

    /**
     * 옵션에 필요한 래퍼 패키지가 없을 때 디스크 생성이 설정 오류로 실패하는지 확인합니다.
     *
     * @param  array<string, mixed>  $config  디스크 설정
     * @param  string  $package  안내에 나와야 할 패키지 이름
     * @return void 반환값 없음
     */
    private function assertOptionRejected(array $config, string $package): void
    {
        try {
            Storage::build($config);
            $this->fail("{$package} 없이 옵션이 조용히 무시됐습니다.");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($package, $e->getMessage());
        }
    }

    /**
     * 쓰기가 예외로 거부되는지 확인합니다.
     *
     * @param  callable  $write  쓰기 호출
     * @return void 반환값 없음
     */
    private function expectExceptionOnWrite(callable $write): void
    {
        try {
            $write();
            $this->fail('읽기 전용 디스크에 쓰기가 허용됐습니다.');
        } catch (UnableToWriteFile) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * 정상 경로가 가드에 걸리지 않는지 확인합니다.
     *
     * @param  StorageInterface  $driver  대상 드라이버
     * @param  string  $categoryRoot  디스크 기준 카테고리 루트 경로
     * @return void 반환값 없음
     */
    private function assertNestedPathsWork(StorageInterface $driver, string $categoryRoot): void
    {
        $this->assertTrue($driver->put('attachments', 'free/temp/5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6/file.name.png', 'x'));
        $this->assertSame('x', $driver->get('attachments', 'free/temp/5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6/file.name.png'));
        $this->assertCount(1, $driver->files('attachments', 'free/temp/5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6'));

        $this->assertTrue($driver->deleteDirectory('attachments', 'free/temp/5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6'));
        $this->assertFalse($driver->exists('attachments', 'free/temp/5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6/file.name.png'));

        // 카테고리 전체 삭제는 deleteAll 로 허용된다
        $driver->put('attachments', 'free/2026/01/01/b.png', 'y');
        $this->assertTrue($driver->deleteAll('attachments'));
        $this->assertFalse(Storage::disk(self::DISK)->exists($categoryRoot));
        $this->assertTrue(Storage::disk(self::DISK)->exists('canary-extension/settings/keep.json'));
    }

    /**
     * 위험 조각이 모든 연산에서 거부되는지 확인합니다.
     *
     * @param  StorageInterface  $driver  대상 드라이버
     * @return void 반환값 없음
     */
    private function assertUnsafeFragmentsRejected(StorageInterface $driver): void
    {
        foreach (self::UNSAFE_FRAGMENTS as $label => $fragment) {
            $this->assertRejected(fn () => $driver->put('attachments', $fragment, 'x'), null, $label);
            $this->assertRejected(fn () => $driver->exists('attachments', $fragment), null, $label);
            $this->assertRejected(fn () => $driver->delete('attachments', $fragment), null, $label);
            $this->assertRejected(fn () => $driver->deleteDirectory('attachments', $fragment), null, $label);
        }

        // 카테고리 조각도 같은 검사를 받는다
        $this->assertRejected(fn () => $driver->deleteAll('../canary-extension'), 'dot_segment');

        $this->assertTrue(Storage::disk(self::DISK)->exists('canary-extension/settings/keep.json'));
    }

    /**
     * 모듈·플러그인 드라이버의 루트 삭제 계약을 확인합니다.
     *
     * 빈 디렉토리 인자는 인터페이스 계약대로 그 카테고리 루트만 지운다. 빈 카테고리(= 확장 루트)는
     * 결합 전에 거부되고 보안 로그에 드라이버 밖 호출 지점이 남는다.
     *
     * @param  StorageInterface  $driver  대상 드라이버
     * @param  string  $identifier  확장 식별자
     * @return void 반환값 없음
     */
    private function assertProtectedRootsRefused(StorageInterface $driver, string $identifier): void
    {
        $driver->put('attachments', 'a.txt', 'x');
        $driver->put('settings', 'keep.json', '{}');
        Log::spy();

        $this->assertRejected(fn () => $driver->deleteDirectory('', ''), 'empty_category');
        $this->assertRejected(fn () => $driver->deleteAll(''), 'empty_category');
        Log::shouldHaveReceived('warning')->with('안전하지 않은 스토리지 경로를 거부했습니다', Mockery::on(
            fn (array $context) => $context['identifier'] === $identifier
                // 보안 로그는 드라이버 밖의 호출 지점을 남긴다 (어느 코드가 위험 경로를 만들었는지 추적)
                && str_starts_with((string) $context['caller'], self::class.'::')
        ))->twice();
        $this->assertTrue($driver->exists('attachments', 'a.txt'));

        // 인터페이스 계약: 빈 디렉토리 인자(생략 포함)는 그 카테고리 루트를 지운다 — 다른 카테고리·다른 확장은 남는다
        $this->assertTrue($driver->deleteDirectory('attachments', ''));
        $this->assertFalse($driver->exists('attachments', 'a.txt'));
        $this->assertFalse(Storage::disk(self::DISK)->exists("{$identifier}/attachments"));
        $driver->put('attachments', 'b.txt', 'y');
        $this->assertTrue($driver->deleteDirectory('attachments'));
        $this->assertFalse($driver->exists('attachments', 'b.txt'));
        $this->assertTrue($driver->exists('settings', 'keep.json'));
        $this->assertTrue(Storage::disk(self::DISK)->exists('canary-extension/settings/keep.json'));
    }

    /**
     * 호출이 UnsafeStoragePathException 으로 거부되는지 확인합니다.
     *
     * @param  callable  $call  실행할 호출
     * @param  string|null  $reason  기대 사유 코드 (null 이면 사유 무관)
     * @param  string  $label  실패 메시지용 라벨
     * @return void 반환값 없음
     */
    private function assertRejected(callable $call, ?string $reason, string $label = ''): void
    {
        try {
            $call();
            $this->fail("거부되어야 할 경로가 통과했습니다: {$label}");
        } catch (UnsafeStoragePathException $e) {
            if ($reason !== null) {
                $this->assertSame($reason, $e->getReason(), $label);
            } else {
                $this->addToAssertionCount(1);
            }
        }
    }
}
