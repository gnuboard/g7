<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Listeners\PluginLifecycleListener;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Plugin;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 플러그인 제거 — 이미지 폴더는 데이터까지 지운 제거에서만, 코어의 제거 가드를 모두 지난 뒤에 비운다.
 *
 * `core.plugins.before_uninstall` 은 가드보다 먼저 발화하므로 거기서 지우면 제거가 막혀도 파일만 사라진다(전 배너 깨짐).
 * 코어는 데이터 삭제 때 마이그레이션을 먼저 되돌린 뒤 `uninstall()` 을 부르므로, 테이블이 없으면 데이터까지 지운 제거다.
 * 그때는 행을 읽을 수 없으므로 제거 시작 때 리스너가 행 디스크 목록만 읽어 두고, `uninstall()` 이 그 디스크까지 비운다.
 */
class PluginUninstallTest extends PluginTestCase
{
    /**
     * 앞 시험이 남긴 읽어 둔 디스크 목록을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        BannerImageService::takeDisksForUninstall();
    }

    /**
     * 플러그인 인스턴스를 만듭니다.
     *
     * @return Plugin 플러그인
     */
    private function plugin(): Plugin
    {
        return new Plugin(['identifier' => 'sirsoft-hero_section', 'vendor' => 'sirsoft', 'version' => '1.0.0']);
    }

    /**
     * 데이터까지 지운 제거(테이블이 사라짐)면 이미지 폴더를 비운다.
     *
     * @scenario surface=uninstall_purges_images_after_guards
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_with_data_removed_empties_image_folder(): void
    {
        $this->fakeImageDisks();
        $plugin = $this->plugin();
        $plugin->getStorage()->put('images', 'a/b.png', 'x');
        Schema::shouldReceive('hasTable')->with('hero_section_banners')->andReturn(false);

        $this->assertTrue($plugin->uninstall());
        $this->assertFalse($plugin->getStorage()->exists('images', 'a/b.png'));
    }

    /**
     * 드라이버가 예외 없이 false 로 실패를 돌려줘도(디렉터리 삭제 실패) 남은 파일을 로그로 알리고, 끝난 제거는 막지 않는다.
     *
     * @scenario surface=uninstall_purges_images_after_guards
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_logs_when_image_folder_is_not_deleted(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->once())->method('deleteAll')->with('images')->willReturn(false);
        $plugin = new class(['identifier' => 'sirsoft-hero_section', 'vendor' => 'sirsoft', 'version' => '1.0.0']) extends Plugin
        {
            public StorageInterface $driver;

            /**
             * 시험용 드라이버를 돌려줍니다(지금 업로드 디스크).
             *
             * @param  string  $category  카테고리
             * @return StorageInterface 스토리지 드라이버
             */
            public function getStorageFor(string $category): StorageInterface
            {
                return $this->driver;
            }
        };
        $plugin->driver = $storage;
        Schema::shouldReceive('hasTable')->with('hero_section_banners')->andReturn(false);
        Log::spy();

        $this->assertTrue($plugin->uninstall());
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 데이터를 남기는 제거(테이블이 그대로)면 이미지를 건드리지 않는다 — 다시 설치하면 배너가 그대로 보여야 한다.
     *
     * @scenario surface=uninstall_purges_images_after_guards
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_keeping_data_leaves_images(): void
    {
        $this->fakeImageDisks();
        $plugin = $this->plugin();
        $plugin->getStorage()->put('images', 'a/b.png', 'x');

        $this->assertTrue($plugin->uninstall());
        $this->assertTrue($plugin->getStorage()->exists('images', 'a/b.png'));
    }

    /**
     * 제거 시작 때 리스너는 행 디스크 목록만 읽고 파일은 지우지 않는다 — 가드에서 막혀도 배너 그림이 남는다.
     * 가드를 지난 `uninstall()` 은 읽어 둔 모든 디스크(공개 자산 디스크를 켰다 끈 사이트의 다른 디스크 포함)를 비운다.
     *
     * @scenario surface=uninstall_purges_images_after_guards
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_empties_every_disk_remembered_before_tables_are_dropped(): void
    {
        Storage::fake('plugins');
        Config::set('filesystems.disks.fake_cdn', ['driver' => 'local', 'root' => storage_path('framework/testing/disks/fake_cdn')]);
        BannerImage::flushStorageCache();
        $cdn = $this->rowOn('fake_cdn');
        $local = $this->rowOn('plugins');

        app(PluginLifecycleListener::class)->beforeUninstall('sirsoft-hero_section', true);
        $this->assertTrue((new PluginStorageDriver('sirsoft-hero_section', 'fake_cdn'))->exists('images', $cdn->path));
        $this->assertTrue((new PluginStorageDriver('sirsoft-hero_section', 'plugins'))->exists('images', $local->path));

        Schema::shouldReceive('hasTable')->with('hero_section_banners')->andReturn(false);
        $this->assertTrue($this->plugin()->uninstall());
        $this->assertFalse((new PluginStorageDriver('sirsoft-hero_section', 'fake_cdn'))->exists('images', $cdn->path));
        $this->assertFalse((new PluginStorageDriver('sirsoft-hero_section', 'plugins'))->exists('images', $local->path));
        $this->assertSame([], BannerImageService::takeDisksForUninstall());
    }

    /**
     * 데이터를 남기는 제거는 디스크 목록도 읽지 않는다.
     *
     * @scenario surface=uninstall_purges_images_after_guards
     *
     * @return void 반환값 없음
     */
    public function test_keeping_data_does_not_remember_disks(): void
    {
        Storage::fake('plugins');
        BannerImage::flushStorageCache();
        $this->rowOn('plugins');

        app(PluginLifecycleListener::class)->beforeUninstall('sirsoft-hero_section', false);

        $this->assertSame([], BannerImageService::takeDisksForUninstall());
    }

    /**
     * 지정 디스크에 파일을 두고 그 디스크를 기록한 행을 만듭니다.
     *
     * @param  string  $disk  파일과 행을 둘 디스크 이름
     * @return BannerImage 저장한 배너 파일 행
     */
    private function rowOn(string $disk): BannerImage
    {
        $path = '2026/11/'.Str::random(16).'.jpg';
        (new PluginStorageDriver('sirsoft-hero_section', $disk))->put('images', $path, 'bytes');

        return BannerImage::factory()->create(['temp_key' => (string) Str::uuid(), 'disk' => $disk, 'path' => $path]);
    }
}
