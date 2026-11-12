<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Services;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;
use Plugins\Sirsoft\Popup\Services\PopupImageService;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 「데이터까지 삭제」의 이미지 폴더 비우기가 행이 기록한 **모든 디스크**를 비우는지 고정한다 (#878).
 *
 * 공개 자산 디스크를 켰다 끈 사이트는 파일이 두 디스크 이상에 흩어진다. 주입 디스크만 비우면 다른 디스크의 파일이
 * 아무도 찾지 못하는 고아로 남는다.
 */
class PopupImagePurgeAllTest extends PluginTestCase
{
    /**
     * 시험 디스크를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('plugins');
        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        PopupImage::flushStorageCache();
    }

    /**
     * 지정 디스크에 파일을 두고 그 디스크를 기록한 행을 만듭니다.
     *
     * @param  string  $disk  파일과 행을 둘 디스크 이름
     * @return PopupImage 저장한 팝업 이미지 행
     */
    private function rowOn(string $disk): PopupImage
    {
        $path = '2026/11/'.Str::random(16).'.jpg';
        if (config("filesystems.disks.{$disk}") !== null) {
            (new PluginStorageDriver('sirsoft-popup', $disk))->put('images', $path, 'bytes');
        }

        return PopupImage::factory()->create(['temp_key' => (string) Str::uuid(), 'disk' => $disk, 'path' => $path]);
    }

    /**
     * 두 디스크에 흩어진 파일을 모두 비우고 true 를 돌려준다.
     *
     * @scenario surface=purge_all
     *
     * @effects popup_purge_all_covers_every_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_purges_every_recorded_disk(): void
    {
        $cdn = $this->rowOn('fake_cdn');
        $local = $this->rowOn('plugins');
        $service = new PopupImageService(app(PopupImageRepositoryInterface::class), new PluginStorageDriver('sirsoft-popup', 'plugins'));

        $this->assertTrue($service->purgeAll());
        $this->assertFalse((new PluginStorageDriver('sirsoft-popup', 'fake_cdn'))->exists('images', $cdn->path));
        $this->assertFalse((new PluginStorageDriver('sirsoft-popup', 'plugins'))->exists('images', $local->path));
    }

    /**
     * 고아 disk 행이 섞여 있어도 예외 없이 나머지 디스크를 비운다(고아는 주입 디스크로 해석돼 한 번만 비운다).
     *
     * @scenario surface=purge_all
     *
     * @effects popup_purge_all_covers_every_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_orphan_disk_rows_do_not_block_purge(): void
    {
        $cdn = $this->rowOn('fake_cdn');
        $this->rowOn('gone_cdn');
        $service = new PopupImageService(app(PopupImageRepositoryInterface::class), new PluginStorageDriver('sirsoft-popup', 'plugins'));

        $this->assertTrue($service->purgeAll());
        $this->assertFalse((new PluginStorageDriver('sirsoft-popup', 'fake_cdn'))->exists('images', $cdn->path));
    }

    /**
     * 한 디스크가 실패해도 나머지는 비우고 false 를 돌려준다.
     *
     * @scenario surface=purge_all
     *
     * @effects popup_purge_all_covers_every_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_one_failing_disk_returns_false_but_others_are_purged(): void
    {
        $cdn = $this->rowOn('fake_cdn');
        $this->rowOn('plugins');
        $failing = new class('sirsoft-popup', 'plugins') extends PluginStorageDriver
        {
            /**
             * plugins 디스크만 비우기 실패를 흉내 냅니다(`withDisk()` 사본은 다른 디스크라 정상).
             *
             * @param  string  $category  카테고리
             * @return bool 비웠으면 true
             */
            public function deleteAll(string $category): bool
            {
                if ($this->getDisk() === 'plugins') {
                    throw new \RuntimeException('disk unavailable');
                }

                return parent::deleteAll($category);
            }
        };
        $service = new PopupImageService(app(PopupImageRepositoryInterface::class), $failing);

        $this->assertFalse($service->purgeAll());
        $this->assertFalse((new PluginStorageDriver('sirsoft-popup', 'fake_cdn'))->exists('images', $cdn->path));
    }
}
