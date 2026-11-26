<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use Plugins\Sirsoft\HeroSection\Enums\BannerMediaType;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Support\PublicBannerPresenter;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 사용자 화면 공개 응답(`hero` API)의 배너 미디어가 `url`(API·썸네일 기준)과 `direct_url`(행 disk 직접 주소)을 함께 싣는지
 * 고정한다. 혼재 운용에서 행마다 정확해야 한다 — 공개 디스크 행은 직접 주소, 기본 디스크 행은 null.
 */
class PublicBannerPresenterDirectUrlTest extends PluginTestCase
{
    /**
     * 직접 URL 을 내는 시험 디스크를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);
        BannerImage::flushStorageCache();
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
     * 저장하지 않은 파일 행을 만듭니다.
     *
     * @param  string  $hash  공개 해시
     * @param  string  $disk  행에 기록할 디스크 이름
     * @param  string  $kind  파일 종류 (image 또는 video)
     * @return BannerImage 저장하지 않은 배너 파일 행
     */
    private function media(string $hash, string $disk, string $kind = 'image'): BannerImage
    {
        $media = new BannerImage;
        $media->forceFill([
            'hash' => $hash, 'kind' => $kind, 'disk' => $disk, 'path' => '2026/11/'.$hash.($kind === 'video' ? '.mp4' : '.jpg'),
            'mime' => $kind === 'video' ? 'video/mp4' : 'image/jpeg', 'width' => $kind === 'video' ? null : 1920, 'height' => $kind === 'video' ? null : 640,
        ]);

        return $media;
    }

    /**
     * 공개 디스크 행은 `direct_url` 이 채워지고 기본 디스크 행은 null, `url` 은 언제나 공개 API 주소다.
     *
     * @scenario surface=direct_url
     *
     * @effects hero_direct_url_follows_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_direct_url_is_per_row(): void
    {
        $banner = new Banner;
        $banner->forceFill(['id' => 9, 'media_type' => BannerMediaType::Video->value, 'title' => ['ko' => '제목'], 'is_active' => true, 'display_period' => 'always']);
        $banner->setRelation('pcImage', $this->media('pcimg', 'fake_cdn'));
        $banner->setRelation('mobileImage', $this->media('mimg', 'plugins'));
        $banner->setRelation('pcVideo', $this->media('pcvid', 'fake_cdn', 'video'));
        $banner->setRelation('mobileVideo', $this->media('mvid', 'plugins', 'video'));

        $public = PublicBannerPresenter::present($banner, 'ko');

        $this->assertSame('https://cdn.test/assets/sirsoft-hero_section/images/2026/11/pcimg.jpg', $public['images']['pc']['direct_url']);
        $this->assertNull($public['images']['mobile']['direct_url']);
        $this->assertSame('https://cdn.test/assets/sirsoft-hero_section/images/2026/11/pcvid.mp4', $public['videos']['pc']['direct_url']);
        $this->assertNull($public['videos']['mobile']['direct_url']);
        $this->assertSame('/api/plugins/sirsoft-hero_section/images/pcimg', $public['images']['pc']['url']);
        $this->assertSame('/api/plugins/sirsoft-hero_section/images/mvid', $public['videos']['mobile']['url']);
    }
}
