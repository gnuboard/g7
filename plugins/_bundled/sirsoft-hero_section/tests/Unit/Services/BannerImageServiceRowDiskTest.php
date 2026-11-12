<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Services;

use App\Extension\Storage\PluginStorageDriver;
use App\Services\PluginSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Plugin;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\BannerImageService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 파일의 읽기·서빙·썸네일 원본·삭제가 **행에 기록된 disk** 를 따르는지 고정한다 (#878).
 *
 * 서비스에 주입되는 스토리지는 「지금 업로드가 갈 디스크」(공개 자산 디스크 설정 반영)다. 운영자가 디스크를 바꾸면
 * 옛 행과 새 행이 서로 다른 디스크에 섞이므로(혼재 운용), 이미 올린 파일은 주입 디스크가 아니라 행 disk 에서 찾아야 한다.
 * 행 disk 에 파일이 없으면 주입 디스크로 몰래 물러나지 않는다 — 고아 disk(설정에서 사라짐)만 주입 디스크로 폴백해 404 로
 * 떨어진다(예외로 500 이 되지 않게).
 */
class BannerImageServiceRowDiskTest extends PluginTestCase
{
    /**
     * 주입 디스크(plugins) 기준 서비스
     */
    private BannerImageService $service;

    /**
     * 시험 디스크를 등록하고 기본(plugins) 디스크를 주입한 서비스를 만듭니다.
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
        BannerImage::flushStorageCache();

        $this->service = new BannerImageService(
            app(BannerImageRepositoryInterface::class),
            new PluginStorageDriver('sirsoft-hero_section', 'plugins'),
        );
    }

    /**
     * 행 disk 드라이버 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        BannerImage::flushStorageCache();

        parent::tearDown();
    }

    /**
     * 지정 disk 에 파일을 두고 그 disk 를 기록한 행을 만듭니다.
     *
     * @param  string  $disk  파일·행 disk
     * @param  string  $kind  종류 (image 또는 video)
     * @param  bool  $putFile  파일을 둘지
     * @return BannerImage 저장한 행
     */
    private function rowOn(string $disk, string $kind = 'image', bool $putFile = true): BannerImage
    {
        $video = $kind === 'video';
        $path = '2026/11/'.Str::random(16).($video ? '.mp4' : '.jpg');
        if ($putFile) {
            (new PluginStorageDriver('sirsoft-hero_section', $disk))->put('images', $path, $video ? 'video-bytes' : 'image-bytes');
        }

        return BannerImage::factory()->create([
            'temp_key' => (string) Str::uuid(),
            'kind' => $kind,
            'disk' => $disk,
            'path' => $path,
            'mime' => $video ? 'video/mp4' : 'image/jpeg',
            'width' => $video ? null : 1920,
            'height' => $video ? null : 640,
        ]);
    }

    /**
     * 플러그인 개별 공개 자산 디스크 설정을 고정합니다 — 고정하지 않으면 개발 머신의 실제 저장값을 읽는다.
     *
     * @param  string  $override  플러그인 설정 public_asset_disk 값 ('' = 코어 따름)
     * @return void 반환값 없음
     */
    private function fixPluginOverride(string $override): void
    {
        $stub = new class($override) extends PluginSettingsService
        {
            /**
             * 고정 값을 받습니다.
             *
             * @param  string  $override  고정할 값
             */
            public function __construct(private string $override) {}

            /**
             * 설정 조회 — public_asset_disk 만 고정 값.
             *
             * @param  string  $identifier  확장 식별자
             * @param  string|null  $key  키
             * @param  mixed  $default  기본값
             * @return mixed 값
             */
            public function get(string $identifier, ?string $key = null, mixed $default = null): mixed
            {
                return $key === 'public_asset_disk' ? $this->override : $default;
            }
        };

        $this->app->instance(PluginSettingsService::class, $stub);
    }

    /**
     * 혼재 운용 — 주입 디스크와 다른 disk 의 행도, 같은 disk 의 행도 각자 디스크에서 스트림으로 서빙된다.
     *
     * @scenario surface=serve
     *
     * @effects hero_serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_serving_reads_each_row_from_its_recorded_disk(): void
    {
        $cdn = $this->rowOn('fake_cdn');
        $local = $this->rowOn('plugins');

        $this->assertTrue($this->service->originalExists($cdn));
        $this->assertTrue($this->service->originalExists($local));
        $this->assertNotNull($this->service->publicServing($cdn)['response'] ?? null);
        $this->assertNotNull($this->service->publicServing($local)['response'] ?? null);
    }

    /**
     * 행 disk 에 파일이 없고 주입 디스크에만 있으면 null — 다른 디스크의 같은 경로로 몰래 물러나지 않는다.
     *
     * @scenario surface=serve
     *
     * @effects hero_serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_missing_on_row_disk_is_not_silently_served_from_injected_disk(): void
    {
        $row = $this->rowOn('fake_cdn', putFile: false);
        (new PluginStorageDriver('sirsoft-hero_section', 'plugins'))->put('images', $row->path, 'image-bytes');

        $this->assertFalse($this->service->originalExists($row));
        $this->assertNull($this->service->publicServing($row));
    }

    /**
     * 고아 disk(설정에서 사라짐) 행은 예외 없이 주입 디스크로 폴백해 없음(null) 으로 떨어진다.
     *
     * @scenario surface=serve
     *
     * @effects hero_orphan_disk_falls_back_to_streaming
     *
     * @return void 반환값 없음
     */
    public function test_orphan_row_disk_falls_back_without_exception(): void
    {
        $row = $this->rowOn('fake_cdn');
        $row->forceFill(['disk' => 'gone_cdn'])->save();

        $this->assertFalse($this->service->originalExists($row));
        $this->assertNull($this->service->publicServing($row));
        $this->assertTrue($this->service->deleteImage($row), '고아 disk 행 삭제는 파일 없음으로 보고 행을 지운다');
    }

    /**
     * 썸네일 원본 정보가 행 disk 를 가리킨다 — 파생본 키가 원본 디스크와 어긋나지 않게.
     *
     * @scenario surface=thumbnail_source
     *
     * @effects hero_serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_thumbnail_source_points_at_row_disk(): void
    {
        $source = $this->service->thumbnailSource($this->rowOn('fake_cdn'));

        $this->assertNotNull($source);
        $this->assertSame('fake_cdn', $source->toArray()['disk']);
    }

    /**
     * 삭제는 행 disk 의 파일만 지운다 — 주입 디스크의 같은 경로 파일은 남는다.
     *
     * @scenario surface=delete
     *
     * @effects hero_serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_delete_removes_file_on_row_disk_only(): void
    {
        $row = $this->rowOn('fake_cdn');
        $injected = new PluginStorageDriver('sirsoft-hero_section', 'plugins');
        $injected->put('images', $row->path, 'decoy');
        $cdn = new PluginStorageDriver('sirsoft-hero_section', 'fake_cdn');

        $this->assertTrue($this->service->deleteImage($row));
        $this->assertFalse($cdn->exists('images', $row->path), '행 disk 파일이 지워져야 한다');
        $this->assertTrue($injected->exists('images', $row->path), '주입 디스크의 같은 경로 파일은 남아야 한다');
        $this->assertNull(BannerImage::query()->find($row->id));
    }

    /**
     * 로컬 드라이버의 직접 URL 디스크에 있는 영상은 절대 경로 파일 응답(범위 요청 지원)으로 나간다.
     *
     * @scenario surface=serve
     *
     * @effects hero_serve_and_delete_follow_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_local_cdn_video_row_is_served_as_absolute_file(): void
    {
        $row = $this->rowOn('fake_cdn', 'video');
        $serving = $this->service->publicServing($row);

        $this->assertIsString($serving['file'] ?? null);
        $this->assertFileExists($serving['file']);
        $this->assertStringContainsString('fake_cdn', str_replace('\\', '/', $serving['file']));
    }

    /**
     * 업로드는 주입(카테고리) 디스크를 행 disk 로 기록한다 — 공개 자산 디스크를 켜면 그 디스크, 아니면 plugins.
     *
     * @scenario surface=serve
     *
     * @effects hero_upload_records_public_asset_disk
     *
     * @return void 반환값 없음
     */
    public function test_upload_records_category_disk_on_row(): void
    {
        $this->fixPluginOverride('');

        Config::set('core.storage.public_asset_disk', 'fake_cdn');
        $cdnService = new BannerImageService(app(BannerImageRepositoryInterface::class), (new Plugin)->getStorageFor('images'));
        $cdnRow = $cdnService->upload(UploadedFile::fake()->image('cdn.jpg', 40, 40), 'pc', (string) Str::uuid(), null);
        $this->assertSame('fake_cdn', $cdnRow->disk);
        $this->assertNotNull($cdnRow->directUrl());

        Config::set('core.storage.public_asset_disk', '');
        $localService = new BannerImageService(app(BannerImageRepositoryInterface::class), (new Plugin)->getStorageFor('images'));
        $localRow = $localService->upload(UploadedFile::fake()->image('local.jpg', 40, 40), 'pc', (string) Str::uuid(), null);
        $this->assertSame('plugins', $localRow->disk);
        $this->assertNull($localRow->directUrl());

        (new PluginStorageDriver('sirsoft-hero_section', 'fake_cdn'))->delete('images', $cdnRow->path);
    }
}
