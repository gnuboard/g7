<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\ExtensionRemoteStorageCleanup;
use App\Extension\Support\LifecycleWarnings;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 「데이터까지 삭제」 때 원격 디스크의 확장 폴더 정리 테스트
 *
 * 매니저의 저장소 삭제는 기본 디스크(`modules`·`plugins`)의 로컬 폴더만 지웠다. 비공개 첨부 저장소·공개 자산 디스크가 기본과
 * 다르면 그 디스크의 `{identifier}/` 아래 객체도 지워야 하고, 실패는 제거를 막지 않고 수명주기 경고 1건으로 남아야 한다.
 * 시험용 원격 디스크 두 개는 local 드라이버로 `filesystems.disks` 에 정의한다(DB·DDL 을 쓰지 않는다).
 */
class ExtensionStorageRemoteCleanupTest extends TestCase
{
    /** 시험 확장 식별자 (공식 확장과 겹치지 않는 가짜 이름) */
    private const IDENTIFIER = 'g7test-rmclean';

    /** 남아야 하는 다른 확장 식별자 */
    private const OTHER = 'g7test-rmkeep';

    /** 시험용 비공개 첨부 저장소 디스크 */
    private const PRIVATE_DISK = 'g7test-remote-private';

    /** 시험용 공개 자산 디스크 */
    private const PUBLIC_DISK = 'g7test-remote-public';

    /**
     * 시험용 원격 디스크 두 개를 정의하고 비공개·공개 자산 디스크로 고릅니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::PRIVATE_DISK, self::PUBLIC_DISK] as $disk) {
            $root = $this->diskRoot($disk);
            (new Filesystem)->deleteDirectory($root);
            Config::set("filesystems.disks.{$disk}", ['driver' => 'local', 'root' => $root, 'throw' => false]);
            Storage::forgetDisk($disk);
        }

        // 카테고리 삭제가 부르는 썸네일 동기화가 실제 썸네일 디스크를 건드리지 않게 한다
        Storage::fake('thumbnails');
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');

        Config::set('core.storage.private_attachment_disk', self::PRIVATE_DISK);
        Config::set('core.storage.public_asset_disk', self::PUBLIC_DISK);
    }

    /**
     * 시험 디스크 폴더를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([self::PRIVATE_DISK, self::PUBLIC_DISK] as $disk) {
            Storage::forgetDisk($disk);
            (new Filesystem)->deleteDirectory($this->diskRoot($disk));
        }

        parent::tearDown();
    }

    /**
     * 확장 종류 표 — 종류, 기본 디스크
     *
     * @return array<string, array{0: string, 1: string}> 표
     */
    public static function typeProvider(): array
    {
        return [
            'module' => ['module', 'modules'],
            'plugin' => ['plugin', 'plugins'],
        ];
    }

    /**
     * 비공개·공개 자산 디스크가 기본과 다르면 두 디스크의 확장 폴더 객체가 0 이 되고 다른 확장 폴더는 남는다.
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     *
     * @scenario case=extension_delete_data
     *
     * @effects extension_delete_data_purges_remote_private_and_public_disks
     */
    #[DataProvider('typeProvider')]
    public function test_remote_private_and_public_disks_are_purged(string $type): void
    {
        $extension = $this->makeExtension($type);
        $this->seedFiles(self::PRIVATE_DISK, ['attachments/2026/11/a.pdf', 'attachments/b.txt']);
        $this->seedFiles(self::PUBLIC_DISK, ['images/c.png', 'images/sub/d.jpg']);

        $disks = ExtensionRemoteStorageCleanup::resolveDisks($extension, $type);
        $this->assertSame([self::PRIVATE_DISK, self::PUBLIC_DISK], $disks);

        ExtensionRemoteStorageCleanup::purge($extension, $type, $disks);

        foreach ([self::PRIVATE_DISK, self::PUBLIC_DISK] as $disk) {
            $this->assertSame([], Storage::disk($disk)->allFiles(self::IDENTIFIER), $disk);
            $this->assertFalse(Storage::disk($disk)->exists(self::IDENTIFIER), $disk.' 빈 확장 폴더');
            Storage::disk($disk)->assertExists(self::OTHER.'/attachments/keep.txt');
        }
        $this->assertSame([], LifecycleWarnings::current()->all());
    }

    /**
     * 비공개·공개 자산 디스크가 같으면 한 번만, 기본 디스크와 같으면 원격 정리 대상이 아니다.
     *
     * @param  string  $type  확장 종류
     * @param  string  $baseDisk  기본 디스크
     * @return void 반환값 없음
     *
     * @scenario case=extension_delete_data
     *
     * @effects extension_delete_data_purges_shared_disk_once_and_skips_base
     */
    #[DataProvider('typeProvider')]
    public function test_same_disk_is_purged_once_and_base_disk_is_skipped(string $type, string $baseDisk): void
    {
        $extension = $this->makeExtension($type);

        Config::set('core.storage.public_asset_disk', self::PRIVATE_DISK);
        $this->assertSame([self::PRIVATE_DISK], ExtensionRemoteStorageCleanup::resolveDisks($extension, $type));

        // 같은 디스크를 두 번 지우지 않는다 — 디스크 호출이 한 벌만 일어난다
        $spy = Mockery::mock(FilesystemContract::class);
        $spy->shouldReceive('directories')->with(self::IDENTIFIER)->twice()->andReturn([]);
        $spy->shouldReceive('allFiles')->with(self::IDENTIFIER)->once()->andReturn([]);
        $spy->shouldReceive('deleteDirectory')->with(self::IDENTIFIER)->once()->andReturn(true);
        Storage::set(self::PRIVATE_DISK, $spy);
        ExtensionRemoteStorageCleanup::purge($extension, $type, ExtensionRemoteStorageCleanup::resolveDisks($extension, $type));

        Config::set('core.storage.private_attachment_disk', $baseDisk);
        Config::set('core.storage.public_asset_disk', $baseDisk);
        $this->assertSame([], ExtensionRemoteStorageCleanup::resolveDisks($extension, $type));

        // 「기본」·미정의(고아) 디스크도 대상이 아니다
        Config::set('core.storage.private_attachment_disk', 'default');
        Config::set('core.storage.public_asset_disk', 'g7test-undefined-disk');
        $this->assertSame([], ExtensionRemoteStorageCleanup::resolveDisks($extension, $type));
    }

    /**
     * 원격 삭제가 예외를 던져도 다음 디스크 정리는 끝나고 수명주기 경고가 1건 남는다.
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     *
     * @scenario case=extension_delete_data
     *
     * @effects extension_delete_data_remote_failure_warns_once_and_continues
     */
    #[DataProvider('typeProvider')]
    public function test_remote_failure_leaves_one_warning_and_continues(string $type): void
    {
        $extension = $this->makeExtension($type);
        $this->seedFiles(self::PUBLIC_DISK, ['images/c.png']);

        $broken = Mockery::mock(FilesystemContract::class);
        $broken->shouldReceive('directories')->andThrow(new \RuntimeException('remote unavailable'));
        Storage::set(self::PRIVATE_DISK, $broken);

        ExtensionRemoteStorageCleanup::purge($extension, $type, ExtensionRemoteStorageCleanup::resolveDisks($extension, $type));

        $this->assertSame([], Storage::disk(self::PUBLIC_DISK)->allFiles(self::IDENTIFIER));
        $warnings = LifecycleWarnings::current()->all();
        $this->assertCount(1, $warnings);
        $this->assertSame('cleanup', $warnings[0]['step']);
        $this->assertSame(self::IDENTIFIER, $warnings[0]['target']);
        $this->assertStringContainsString('remote unavailable', $warnings[0]['message']);
    }

    /**
     * 추상 기반 클래스가 아닌 최소 인스턴스(비활성·고아 확장)도 코어 선택값으로 판정해 지운다.
     *
     * @param  string  $type  확장 종류
     * @return void 반환값 없음
     *
     * @scenario case=extension_delete_data
     *
     * @effects extension_delete_data_minimal_instance_uses_core_selection
     */
    #[DataProvider('typeProvider')]
    public function test_minimal_extension_instance_uses_core_selection(string $type): void
    {
        $extension = Mockery::mock($type === 'module' ? ModuleInterface::class : PluginInterface::class);
        $extension->shouldReceive('getIdentifier')->andReturn(self::IDENTIFIER);
        $this->seedFiles(self::PRIVATE_DISK, ['attachments/a.pdf']);
        $this->seedFiles(self::PUBLIC_DISK, ['images/c.png']);

        $disks = ExtensionRemoteStorageCleanup::resolveDisks($extension, $type);
        $this->assertSame([self::PRIVATE_DISK, self::PUBLIC_DISK], $disks);

        ExtensionRemoteStorageCleanup::purge($extension, $type, $disks);

        $this->assertSame([], Storage::disk(self::PRIVATE_DISK)->allFiles(self::IDENTIFIER));
        $this->assertSame([], Storage::disk(self::PUBLIC_DISK)->allFiles(self::IDENTIFIER));
        $this->assertSame([], LifecycleWarnings::current()->all());
    }

    /**
     * 매니저의 「데이터까지 삭제」 경로가 로컬 폴더를 지우기 전에 디스크를 판정하고, 로컬 삭제 직전에 원격 정리를 부른다.
     *
     * 전체 제거는 오토로드 갱신·라우트 재굽기까지 돌아 단위 시험으로 부르지 않는다 — 호출 순서를 잠근다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=extension_delete_data
     *
     * @effects extension_delete_data_path_runs_remote_cleanup_before_local_delete
     */
    public function test_managers_wire_remote_cleanup_into_delete_data_path(): void
    {
        $module = $this->methodSource(ModuleManager::class, 'uninstallModule');
        $this->assertOrdered($module, [
            'if ($deleteData) {',
            "ExtensionRemoteStorageCleanup::resolveDisks(\$module, 'module')",
            '$this->cleanupDynamicModuleData($module);',
            "ExtensionRemoteStorageCleanup::purge(\$module, 'module', \$remoteDisks)",
            '$this->deleteModuleStorage($module);',
        ]);

        $plugin = $this->methodSource(PluginManager::class, 'uninstallPlugin');
        $this->assertOrdered($plugin, [
            "ExtensionRemoteStorageCleanup::resolveDisks(\$plugin, 'plugin')",
            '$this->cleanupDynamicPluginData($plugin);',
            'DB::beginTransaction();',
            "ExtensionRemoteStorageCleanup::purge(\$plugin, 'plugin', \$remoteDisks)",
            '$this->deletePluginSettingsDirectory($plugin);',
        ]);
    }

    /**
     * 시험 확장 인스턴스를 만듭니다 (매니페스트 없음, 가상 경로의 basename 이 식별자).
     *
     * @param  string  $type  확장 종류
     * @return AbstractModule|AbstractPlugin 확장 인스턴스
     */
    private function makeExtension(string $type): AbstractModule|AbstractPlugin
    {
        $path = '/virtual/'.self::IDENTIFIER;

        if ($type === 'module') {
            return new class($path) extends AbstractModule
            {
                /**
                 * 가상 확장을 만듭니다.
                 *
                 * @param  string  $fakePath  가상 모듈 경로
                 */
                public function __construct(private string $fakePath) {}

                /**
                 * 빈 매니페스트를 돌려줍니다.
                 *
                 * @return array<string, mixed> 빈 매니페스트
                 */
                protected function loadManifest(): array
                {
                    return [];
                }

                /**
                 * 가상 모듈 경로를 돌려줍니다.
                 *
                 * @return string 가상 모듈 경로
                 */
                protected function getModulePath(): string
                {
                    return $this->fakePath;
                }
            };
        }

        return new class($path) extends AbstractPlugin
        {
            /**
             * 가상 확장을 만듭니다.
             *
             * @param  string  $fakePath  가상 플러그인 경로
             */
            public function __construct(private string $fakePath) {}

            /**
             * 빈 매니페스트를 돌려줍니다.
             *
             * @return array<string, mixed> 빈 매니페스트
             */
            protected function loadManifest(): array
            {
                return [];
            }

            /**
             * 가상 플러그인 경로를 돌려줍니다.
             *
             * @return string 가상 플러그인 경로
             */
            protected function getPluginPath(): string
            {
                return $this->fakePath;
            }
        };
    }

    /**
     * 디스크에 시험 확장 파일과 남아야 할 다른 확장 파일을 씁니다.
     *
     * @param  string  $disk  디스크
     * @param  array<int, string>  $paths  확장 폴더 아래 상대 경로
     * @return void 반환값 없음
     */
    private function seedFiles(string $disk, array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk($disk)->put(self::IDENTIFIER.'/'.$path, 'x');
        }
        Storage::disk($disk)->put(self::OTHER.'/attachments/keep.txt', 'keep');
    }

    /**
     * 시험 디스크의 루트 경로를 반환합니다.
     *
     * @param  string  $disk  디스크
     * @return string 루트 경로
     */
    private function diskRoot(string $disk): string
    {
        return storage_path('framework/testing/disks/'.$disk);
    }

    /**
     * 메서드 소스를 반환합니다.
     *
     * @param  class-string  $class  클래스
     * @param  string  $method  메서드
     * @return string 소스
     */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    /**
     * 조각들이 이 순서로 나오는지 확인합니다.
     *
     * @param  string  $source  소스
     * @param  array<int, string>  $needles  조각
     * @return void 반환값 없음
     */
    private function assertOrdered(string $source, array $needles): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = strpos($source, $needle, $offset);
            $this->assertNotFalse($position, "순서대로 찾지 못함: {$needle}");
            $offset = $position + strlen($needle);
        }
    }
}
