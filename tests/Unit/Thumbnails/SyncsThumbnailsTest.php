<?php

namespace Tests\Unit\Thumbnails;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Extension\Storage\CoreStorageDriver;
use App\Extension\Storage\ModuleStorageDriver;
use App\Extension\Storage\PluginStorageDriver;
use App\Thumbnails\ThumbnailPurgeBuffer;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 저장소 계층 썸네일 무효화 테스트 (세 드라이버 × put·delete·deleteDirectory·deleteAll)
 *
 * 원본을 덮어쓰거나 지우면 같은 접두의 파생본이 모든 썸네일 디스크에서 지워지고(D14·D43), 퍼지 훅
 * `core.thumbnail.after_purge` 가 묶음으로 발화한다(D30). 건너뛰기는 디스크 이름이 아니라 `thumbnails/` 경로로
 * 판정한다 — 공용 디스크(public)를 썸네일 디스크로 골라도 그 디스크 원본의 무효화는 살아 있어야 한다.
 */
class SyncsThumbnailsTest extends TestCase
{
    /** @var list<list<array<string, mixed>>> 퍼지 훅이 받은 묶음 */
    private array $purges = [];

    /**
     * 디스크를 가짜로 바꾸고 썸네일 디스크·퍼지 훅을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['attachments', 'modules', 'plugins', 'thumbnails', 'public'] as $disk) {
            Storage::fake($disk);
        }

        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.disk_public', 'thumbnails');
        Config::set('core.thumbnail.disk_gated', 'thumbnails');

        $this->purges = [];
        HookManager::clearAction('core.thumbnail.after_purge');
        HookManager::addAction('core.thumbnail.after_purge', function (array $items) {
            $this->purges[] = $items;
        });
    }

    /**
     * 퍼지 훅을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction('core.thumbnail.after_purge');

        parent::tearDown();
    }

    /**
     * 드라이버 표 — 드라이버, 원본 디스크, 디스크 위 전체 경로 접두(카테고리 앞).
     *
     * @return array<string, array{0:string}> 드라이버 종류
     */
    public static function driverProvider(): array
    {
        return [
            'core' => ['core'],
            'module' => ['module'],
            'plugin' => ['plugin'],
        ];
    }

    /**
     * 드라이버와 원본 디스크·전체 경로 접두를 만듭니다.
     *
     * @param  string  $kind  드라이버 종류 (core, module, plugin)
     * @return array{0:StorageInterface,1:string,2:string} [드라이버, 디스크, 전체 경로 접두]
     */
    private function driver(string $kind): array
    {
        return match ($kind) {
            'core' => [new CoreStorageDriver('attachments'), 'attachments', ''],
            'module' => [new ModuleStorageDriver('g7test-thumbmod', 'modules'), 'modules', 'g7test-thumbmod/'],
            'plugin' => [new PluginStorageDriver('g7test-thumbplug', 'plugins'), 'plugins', 'g7test-thumbplug/'],
        };
    }

    /**
     * 원본 하나의 파생본을 썸네일 디스크에 심습니다.
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $fullPath  원본 전체 경로
     * @param  string  $thumbnailDisk  썸네일 디스크
     * @return string 심은 파생본 키
     */
    private function plant(string $sourceDisk, string $fullPath, string $thumbnailDisk = 'thumbnails'): string
    {
        $key = ThumbnailStore::ROOT."/{$sourceDisk}/{$fullPath}/sm_0123abcd.jpg";
        Storage::disk($thumbnailDisk)->put($key, 'thumb');

        return $key;
    }

    /**
     * 덮어쓰기는 파생본을 지우고, 새 파일 쓰기는 지우지 않는다(이미 있을 때만).
     *
     * @effects derivatives_purged_on_overwrite, purge_hook_fired_with_payload
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_put_purges_only_when_overwriting(string $kind): void
    {
        [$driver, $disk, $prefix] = $this->driver($kind);

        // 새 파일 — 쓰기 전 판정은 원본이 없어 지우지 않지만, 같은 경로에 남은 옛 파생본은 새 원본과 다른 그림이라
        // 쓴 뒤 재확인이 지운다(쓰는 사이 옛 원본으로 다시 만든 파생본도 같은 자리에서 걷힌다)
        $stale = $this->plant($disk, $prefix.'images/2026/11/new.jpg');
        $this->assertTrue($driver->put('images', '2026/11/new.jpg', 'v1'));
        Storage::disk('thumbnails')->assertMissing($stale);
        $this->purges = [];

        // 덮어쓰기
        $thumb = $this->plant($disk, $prefix.'images/2026/11/new.jpg');
        $other = $this->plant($disk, $prefix.'images/2026/11/other.jpg');
        $this->assertTrue($driver->put('images', '2026/11/new.jpg', 'v2'));

        Storage::disk('thumbnails')->assertMissing($thumb);
        Storage::disk('thumbnails')->assertExists($other);
        $this->assertCount(1, $this->purges);
        $this->assertSame([
            'source_disk' => $disk,
            'prefix' => $prefix.'images/2026/11/new.jpg',
            'thumbnail_disk' => 'thumbnails',
            'public_url_prefix' => null,
            'original_public_url' => null,
            'original_is_directory' => false,
        ], $this->purges[0][0]);
    }

    /**
     * 래스터가 아닌 파일은 덮어써도 지우지 않는다.
     *
     * @effects non_raster_never_purges
     *
     * @return void 반환값 없음
     */
    public function test_non_raster_overwrite_does_not_purge(): void
    {
        [$driver, $disk] = $this->driver('core');
        $driver->put('docs', 'a.pdf', 'v1');
        $thumb = $this->plant($disk, 'docs/a.pdf');

        $driver->put('docs', 'a.pdf', 'v2');
        $driver->delete('docs', 'a.pdf');

        Storage::disk('thumbnails')->assertExists($thumb);
        $this->assertSame([], $this->purges);
    }

    /**
     * 원본 삭제는 그 원본의 파생본만 지운다.
     *
     * @effects derivatives_purged_on_delete, purge_hook_fired_with_payload
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_delete_purges_the_source_derivatives(string $kind): void
    {
        [$driver, $disk, $prefix] = $this->driver($kind);
        $driver->put('images', 'a.png', 'x');
        $driver->put('images', 'b.png', 'x');
        $a = $this->plant($disk, $prefix.'images/a.png');
        $b = $this->plant($disk, $prefix.'images/b.png');

        $this->assertTrue($driver->delete('images', 'a.png'));

        Storage::disk('thumbnails')->assertMissing($a);
        Storage::disk('thumbnails')->assertExists($b);
        $this->assertCount(1, $this->purges);
        $this->assertSame($prefix.'images/a.png', $this->purges[0][0]['prefix']);
    }

    /**
     * 디렉터리 삭제는 그 아래 파생본 전부를(확장자 무관), deleteAll 은 카테고리 전부를 지운다.
     *
     * @effects derivatives_purged_on_directory_delete, purge_hook_fired_with_payload
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_directory_delete_and_delete_all_purge_the_prefix(string $kind): void
    {
        [$driver, $disk, $prefix] = $this->driver($kind);
        $driver->put('images', 'post-1/a.jpg', 'x');
        $driver->put('images', 'post-2/b.jpg', 'x');
        $driver->put('files', 'c.jpg', 'x');
        $inDir = $this->plant($disk, $prefix.'images/post-1/a.jpg');
        $sibling = $this->plant($disk, $prefix.'images/post-2/b.jpg');
        $otherCategory = $this->plant($disk, $prefix.'files/c.jpg');

        $this->assertTrue($driver->deleteDirectory('images', 'post-1'));
        Storage::disk('thumbnails')->assertMissing($inDir);
        Storage::disk('thumbnails')->assertExists($sibling);
        $this->assertSame($prefix.'images/post-1', $this->purges[0][0]['prefix']);

        $this->assertTrue($driver->deleteAll('images'));
        Storage::disk('thumbnails')->assertMissing($sibling);
        Storage::disk('thumbnails')->assertExists($otherCategory);
        $this->assertSame($prefix.'images', $this->purges[1][0]['prefix']);
        $this->assertCount(2, $this->purges);
    }

    /**
     * 파생본 자신(`thumbnails/` 경로)을 쓰거나 지울 때는 아무 것도 하지 않는다 — 재귀 무효화·자기 퍼지 없음.
     *
     * 모듈·플러그인 드라이버는 `{식별자}/{카테고리}` 를 앞에 붙이므로 식별자가 `thumbnails` 인 경우로 같은 판정을 잰다.
     *
     * @effects thumbnail_paths_are_skipped
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_thumbnail_paths_are_skipped(string $kind): void
    {
        [$thumbnails, $category, $path, $directory] = match ($kind) {
            'core' => [new CoreStorageDriver('thumbnails'), '', 'thumbnails/attachments/2026/11/05/a.jpg/sm_0123abcd.jpg', ['thumbnails', 'attachments']],
            'module' => [new ModuleStorageDriver('thumbnails', 'thumbnails'), 'attachments', '2026/11/05/a.jpg/sm_0123abcd.jpg', ['attachments', '2026']],
            'plugin' => [new PluginStorageDriver('thumbnails', 'thumbnails'), 'attachments', '2026/11/05/a.jpg/sm_0123abcd.jpg', ['attachments', '2026']],
        };
        $nested = $this->plant('thumbnails', 'thumbnails/attachments/2026/11/05/a.jpg/sm_0123abcd.jpg');

        $thumbnails->put($category, $path, 'v1');
        $thumbnails->put($category, $path, 'v2');
        $thumbnails->delete($category, $path);
        $thumbnails->deleteDirectory(...$directory);

        Storage::disk('thumbnails')->assertExists($nested);
        $this->assertSame([], $this->purges);
    }

    /**
     * 공개 썸네일 디스크로 공용 디스크(public)를 골라도 그 디스크 원본의 무효화는 동작한다 —
     * 건너뛰기가 디스크 이름이 아니라 경로 판정이어서다. 원본이 공개 디스크면 원본 공개 URL 도 퍼지에 싣는다.
     *
     * @effects public_disk_sources_still_purged, purge_hook_fired_with_payload
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_public_disk_chosen_as_thumbnail_disk_keeps_source_invalidation(string $kind): void
    {
        Config::set('core.thumbnail.disk_public', 'public');
        [$public, $prefix] = match ($kind) {
            'core' => [new CoreStorageDriver('public'), ''],
            'module' => [new ModuleStorageDriver('g7test-thumbmod', 'public'), 'g7test-thumbmod/'],
            'plugin' => [new PluginStorageDriver('g7test-thumbplug', 'public'), 'g7test-thumbplug/'],
        };
        $fullPath = $prefix.'template-layout-attachments/basic/a.png';

        $public->put('template-layout-attachments', 'basic/a.png', 'v1');
        $onPublic = $this->plant('public', $fullPath, 'public');
        $onPrivate = $this->plant('public', $fullPath, 'thumbnails');

        $public->put('template-layout-attachments', 'basic/a.png', 'v2');

        Storage::disk('public')->assertMissing($onPublic);
        Storage::disk('thumbnails')->assertMissing($onPrivate);
        Storage::disk('public')->assertExists($fullPath);

        $this->assertCount(2, $this->purges, '썸네일 디스크 둘 다에서 지웠다');
        $byDisk = array_column(array_merge(...$this->purges), null, 'thumbnail_disk');
        $this->assertNotNull($byDisk['public']['public_url_prefix'], '공개 디스크 파생본은 CDN 퍼지 경로를 싣는다');
        $this->assertStringEndsWith('/thumbnails/public/'.$fullPath, (string) $byDisk['public']['public_url_prefix']);
        $this->assertNull($byDisk['thumbnails']['public_url_prefix']);
        $this->assertStringEndsWith('/'.$fullPath, (string) $byDisk['public']['original_public_url']);
    }

    /**
     * 묶음 안에서 여러 원본을 지우면 퍼지 훅은 끝에 한 번, 모든 접두를 담아 발화한다.
     *
     * @effects purge_batched_once
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_many_deletes_inside_a_batch_fire_the_hook_once(string $kind): void
    {
        [$driver, $disk, $prefix] = $this->driver($kind);

        foreach (['a', 'b', 'c'] as $name) {
            $driver->put('attachments', "{$name}.webp", 'x');
            $this->plant($disk, $prefix."attachments/{$name}.webp");
        }

        app(ThumbnailPurgeBuffer::class)->batch(function () use ($driver) {
            foreach (['a', 'b', 'c'] as $name) {
                $driver->delete('attachments', "{$name}.webp");
            }

            $this->assertSame([], $this->purges, '묶음 안에서는 발화하지 않는다');
        });

        $this->assertCount(1, $this->purges);
        $this->assertSame(
            [$prefix.'attachments/a.webp', $prefix.'attachments/b.webp', $prefix.'attachments/c.webp'],
            array_column($this->purges[0], 'prefix')
        );
    }

    /**
     * 파생본이 없는 원본을 지우면 퍼지 훅을 부르지 않는다(빈 무효화 요청 없음).
     *
     * @effects purge_hook_fired_with_payload
     *
     * @return void 반환값 없음
     */
    public function test_no_derivatives_means_no_purge(): void
    {
        [$driver] = $this->driver('plugin');
        $driver->put('images', 'lonely.gif', 'x');
        $driver->delete('images', 'lonely.gif');

        $this->assertSame([], $this->purges);
    }

    /**
     * 썸네일 원본 키 — 드라이버가 디스크 위에 실제로 쓴 전체 경로를 내준다(파생본 키·무효화가 같은 값을 써야 한다).
     *
     * @effects source_key_matches_driver_path
     *
     * @param  string  $kind  드라이버 종류
     * @return void 반환값 없음
     */
    #[DataProvider('driverProvider')]
    public function test_thumbnail_source_key_and_temp_copy(string $kind): void
    {
        [$driver, $disk, $prefix] = $this->driver($kind);
        $driver->put('images', 'k.png', 'bytes');

        $this->assertSame(['disk' => $disk, 'path' => $prefix.'images/k.png'], $driver->thumbnailSourceKey('images', 'k.png'));

        $temp = $driver->copyToLocalTemp('images', 'k.png');
        $this->assertIsString($temp);
        $this->assertSame('bytes', file_get_contents($temp));
        @unlink($temp);

        $this->assertNull($driver->copyToLocalTemp('images', 'missing.png'));
    }
}
