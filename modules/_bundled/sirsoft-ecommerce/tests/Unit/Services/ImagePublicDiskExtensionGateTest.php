<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Storage\ModuleStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 자산 디스크에는 「공개 디스크로 내보낼 확장자」 의 이미지만 둔다.
 *
 * 이미지 서비스는 공개 자산 디스크를 카테고리 단위로 주입받는다. 저장 확장자가 목록 밖이면 모듈 기본
 * 디스크에 저장하고 서버를 거치는 주소로 내려보낸다. 직접 주소는 경로 확장자가 목록 안일 때만 발급한다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/image-public-disk-extension-gate.yaml
 */
class ImagePublicDiskExtensionGateTest extends ModuleTestCase
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
        AssetStorage::flush();
    }

    /**
     * 드라이버 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetStorage::flush();

        parent::tearDown();
    }

    /**
     * 공개 자산 디스크(fake_cdn)를 주입받은 상품 이미지 서비스를 만듭니다.
     *
     * @return ProductImageService 서비스
     */
    private function serviceOnPublicDisk(): ProductImageService
    {
        $this->app->when(ProductImageService::class)
            ->needs(StorageInterface::class)
            ->give(fn () => new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'));

        return $this->app->make(ProductImageService::class);
    }

    /**
     * 목록 밖 확장자의 이미지는 공개 디스크가 아니라 모듈 기본 디스크에 저장되고 직접 주소를 받지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=product__not_exportable
     *
     * @effects non_exportable_image_stays_on_default_disk_with_api_url
     */
    #[Test]
    public function non_exportable_image_is_stored_on_the_default_disk(): void
    {
        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg']);
        $service = $this->serviceOnPublicDisk();

        $image = $service->upload(UploadedFile::fake()->image('a.png', 20, 20), null, 'main', 'g7-ext-gate');

        $this->assertNotSame('fake_cdn', $image->disk);
        $this->assertFalse((new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'))->exists('images', $image->path));
        $this->assertTrue(AssetStorage::forDisk($image->disk)->exists('images', $image->path));
        $this->assertStringStartsWith('/api/modules/sirsoft-ecommerce/product-image/', $image->download_url);

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * 목록 안 확장자의 이미지는 그대로 공개 디스크에 저장되고 직접 주소를 받는다(회귀 없음).
     *
     * @return void 반환값 없음
     *
     * @scenario case=product__exportable
     *
     * @effects exportable_image_keeps_direct_url
     */
    #[Test]
    public function exportable_image_still_goes_to_the_public_disk(): void
    {
        Config::set('core.storage.public_asset_extensions', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
        $service = $this->serviceOnPublicDisk();

        $image = $service->upload(UploadedFile::fake()->image('a.png', 20, 20), null, 'main', 'g7-ext-gate');

        $this->assertSame('fake_cdn', $image->disk);
        $this->assertStringStartsWith('https://cdn.test/assets', $image->download_url);

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * 공개 디스크에 이미 놓인 목록 밖 확장자 행은 직접 주소 대신 서버를 거치는 주소를 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=product__legacy_row_not_exportable
     *
     * @effects non_exportable_image_stays_on_default_disk_with_api_url
     */
    #[Test]
    public function existing_non_exportable_row_gets_the_api_url(): void
    {
        $image = new ProductImage([
            'hash' => 'aaaabbbbcccc',
            'disk' => 'fake_cdn',
            'path' => 'products/P001/legacy.html',
        ]);

        $this->assertSame('/api/modules/sirsoft-ecommerce/product-image/aaaabbbbcccc', $image->download_url);

        $ok = new ProductImage(['hash' => 'ddddeeeeffff', 'disk' => 'fake_cdn', 'path' => 'products/P001/a.jpg']);
        $this->assertStringStartsWith('https://cdn.test/assets', $ok->download_url);
    }
}
