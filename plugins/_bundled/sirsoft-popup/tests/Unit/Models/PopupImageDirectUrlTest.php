<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Models;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Config;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 팝업 이미지의 직접 주소(`directUrl()`)가 행 disk 를 따르는지 고정한다 (#878).
 *
 * 공개 자산 디스크(public·CDN)에 올린 행은 원본 한 장을 PHP 를 거치지 않는 직접 주소로 내고, 기본(플러그인) 디스크
 * 행·고아 디스크 행·비어 있는 행은 null 이라 화면이 공개 API 주소로 받는다. 직접 주소는 설정이 아니라 **행 disk** 로
 * 정한다 — 공개 자산 디스크를 켰다 꺼도 그 디스크에 남은 행은 계속 직접 주소를 낸다(제품 결정, 확장 선례).
 */
class PopupImageDirectUrlTest extends PluginTestCase
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
        PopupImage::flushStorageCache();
    }

    /**
     * 행 disk 드라이버 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        PopupImage::flushStorageCache();

        parent::tearDown();
    }

    /**
     * 저장하지 않은 파일 행을 만듭니다.
     *
     * @param  string|null  $disk  행 disk
     * @param  string|null  $path  행 경로
     * @return PopupImage 이미지 행
     */
    private function row(?string $disk, ?string $path): PopupImage
    {
        $image = new PopupImage;
        $image->forceFill(['hash' => 'h'.md5((string) $path), 'disk' => $disk, 'path' => $path, 'mime' => 'image/jpeg']);

        return $image;
    }

    /**
     * 기본 플러그인 디스크 행은 직접 주소가 없고 원본 주소는 공개 API 다.
     *
     * @scenario surface=direct_url
     *
     * @effects popup_direct_url_null_for_streaming_disk
     *
     * @return void 반환값 없음
     */
    public function test_plugins_disk_row_has_no_direct_url(): void
    {
        $image = $this->row('plugins', '2026/11/a.jpg');

        $this->assertNull($image->directUrl());
        $this->assertSame($image->publicUrl(), $image->mediaUrl());
    }

    /**
     * 직접 URL 디스크 행은 그 디스크의 절대 주소를 낸다.
     *
     * @scenario surface=direct_url
     *
     * @effects popup_direct_url_follows_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_cdn_disk_row_returns_absolute_direct_url(): void
    {
        $image = $this->row('fake_cdn', '2026/11/abc.jpg');

        $this->assertSame('https://cdn.test/assets/sirsoft-popup/images/2026/11/abc.jpg', $image->directUrl());
        $this->assertSame($image->directUrl(), $image->mediaUrl());
    }

    /**
     * 설정에서 사라진 disk(그 디스크를 주던 확장 비활성화)·비어 있는 disk·path 는 예외 없이 null 이다.
     *
     * @scenario surface=direct_url
     *
     * @effects popup_orphan_disk_falls_back_to_streaming
     *
     * @return void 반환값 없음
     */
    public function test_orphan_or_empty_rows_have_no_direct_url(): void
    {
        $this->assertNull($this->row('gone_cdn', '2026/11/a.jpg')->directUrl());
        $this->assertNull($this->row(null, '2026/11/a.jpg')->directUrl());
        $this->assertNull($this->row('', '2026/11/a.jpg')->directUrl());
        $this->assertNull($this->row('fake_cdn', '')->directUrl());
        $this->assertNull($this->row('fake_cdn', null)->directUrl());
    }

    /**
     * `core.storage.filter_url` 훅이 빈 값을 돌려주면(직접 URL 차단) null 이다.
     *
     * @scenario surface=direct_url
     *
     * @effects popup_direct_url_follows_row_disk
     *
     * @return void 반환값 없음
     */
    public function test_filter_url_hook_can_block_direct_url(): void
    {
        $callback = fn ($url) => '';
        HookManager::addFilter('core.storage.filter_url', $callback, 5);

        try {
            $this->assertNull($this->row('fake_cdn', '2026/11/blocked.jpg')->directUrl());
        } finally {
            HookManager::removeFilter('core.storage.filter_url', $callback);
        }
    }
}
