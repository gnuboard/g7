<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 공개 자산 디스크에는 「공개 디스크로 내보낼 확장자」 의 에디터 이미지만 둔다.
 *
 * 저장 확장자가 목록 밖이면 플러그인 기본 디스크에 저장하고 서버를 거치는 주소로 내려보낸다.
 * 직접 주소는 경로 확장자가 목록 안일 때만 발급한다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-ckeditor5/tests/scenarios/image-public-disk-extension-gate.yaml
 */
class ImagePublicDiskExtensionGateTest extends PluginTestCase
{
    /**
     * 가짜 CDN 디스크를 등록합니다.
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
        Ckeditor5ImageUpload::flushStorageCache();
    }

    /**
     * 드라이버 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Ckeditor5ImageUpload::flushStorageCache();

        parent::tearDown();
    }

    /**
     * 공개 자산 디스크(fake_cdn)를 주입받은 업로드 서비스를 만듭니다.
     *
     * @return ImageUploadService 서비스
     */
    private function serviceOnPublicDisk(): ImageUploadService
    {
        return new ImageUploadService(
            app(ImageUploadRepositoryInterface::class),
            new PluginStorageDriver('sirsoft-ckeditor5', 'fake_cdn')
        );
    }

    /**
     * 목록 밖 확장자의 이미지는 공개 디스크가 아니라 플러그인 기본 디스크에 저장되고 직접 주소를 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=editor__not_exportable
     *
     * @effects non_exportable_image_stays_on_default_disk_with_api_url
     */
    #[Test]
    public function non_exportable_image_is_stored_on_the_default_disk(): void
    {
        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg']);

        $record = $this->serviceOnPublicDisk()->upload(UploadedFile::fake()->image('a.png', 20, 20), null);

        $this->assertNotSame('fake_cdn', $record->storage_disk);
        $this->assertStringStartsWith('/api/plugins/sirsoft-ckeditor5/images/', $record->download_url);

        [$category, $relative] = explode('/', $record->file_path, 2);
        $this->assertFalse((new PluginStorageDriver('sirsoft-ckeditor5', 'fake_cdn'))->exists($category, $relative));
        $this->assertTrue((new PluginStorageDriver('sirsoft-ckeditor5', $record->storage_disk))->exists($category, $relative));
    }

    /**
     * 목록 안 확장자의 이미지는 그대로 공개 디스크에 저장되고 직접 주소를 받는다(회귀 없음).
     *
     * @return void 반환값 없음
     *
     * @scenario case=editor__exportable
     *
     * @effects exportable_image_keeps_direct_url
     */
    #[Test]
    public function exportable_image_still_goes_to_the_public_disk(): void
    {
        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);

        $record = $this->serviceOnPublicDisk()->upload(UploadedFile::fake()->image('a.png', 20, 20), null);

        $this->assertSame('fake_cdn', $record->storage_disk);
        $this->assertStringStartsWith('https://cdn.test/assets', $record->download_url);
    }

    /**
     * 공개 디스크에 이미 놓인 목록 밖 확장자 행은 직접 주소 대신 서버를 거치는 주소를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=editor__legacy_row_not_exportable
     *
     * @effects non_exportable_image_stays_on_default_disk_with_api_url
     */
    #[Test]
    public function existing_non_exportable_row_gets_the_api_url(): void
    {
        $row = new Ckeditor5ImageUpload([
            'original_name' => 'legacy.html',
            'file_path' => 'images/2026/01/01/legacy.html',
            'storage_disk' => 'fake_cdn',
        ]);
        $row->hash = 'aaaabbbbcccc';

        $this->assertSame('/api/plugins/sirsoft-ckeditor5/images/aaaabbbbcccc', $row->download_url);
    }
}
