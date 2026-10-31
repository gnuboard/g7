<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Support\AssetUrl;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 에디터 업로드 이미지의 서빙 폴백 URL 이 마운트 접두사를 갖는지 고정한다.
 *
 * `download_url` 은 `resolveDirectUrl() ?? '/api/plugins/…'` 이다. 뒤쪽 폴백은
 * `url` 미설정 디스크·훅 차단에서 발화하는데, 손조립이라 마운트 접두사가 없었다.
 *
 * 이 통로는 다른 이미지 축보다 회복이 어렵다 — 그 값이 본문 HTML 에 굳어 DB 에
 * 저장되므로, 나중에 조립 지점을 고쳐도 **이미 쓰인 글은 계속 깨진 주소를 갖는다.**
 *
 * @scenario base_path=subpath, url_kind=ckeditor5_image_fallback
 */
class ImageUrlBasePrefixTest extends PluginTestCase
{
    /**
     * 테스트마다 강제한 마운트 접두사를 풀어 다음 테스트에 새지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 디스크 URL 이 없어 서빙 라우트로 폴백할 때 그 주소 앞에 마운트 접두사가 붙는지 확인한다.
     *
     * @scenario base_path=subpath, url_kind=ckeditor5_image_fallback
     * @effects ckeditor5_image_fallback_urls_carry_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function image_fallback_url_carries_the_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $image = new Ckeditor5ImageUpload;
        $image->hash = 'h1';
        $image->storage_disk = '';
        $image->file_path = '';

        $this->assertSame(
            '/g7/api/plugins/sirsoft-ckeditor5/images/h1',
            $image->download_url
        );
    }

    /**
     * 접두사가 없는 배치에서는 종전 문자열 그대로다 (회귀 0).
     *
     * @scenario base_path=root, url_kind=ckeditor5_image_fallback
     * @effects ckeditor5_image_fallback_urls_carry_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function image_fallback_url_is_unchanged_without_a_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('');

        $image = new Ckeditor5ImageUpload;
        $image->hash = 'h1';
        $image->storage_disk = '';
        $image->file_path = '';

        $this->assertSame(
            '/api/plugins/sirsoft-ckeditor5/images/h1',
            $image->download_url
        );
    }
}
