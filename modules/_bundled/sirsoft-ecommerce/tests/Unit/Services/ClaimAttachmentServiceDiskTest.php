<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\Storage\ModuleStorageDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Support\AssetStorage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 첨부의 저장 디스크 배선 테스트 (P2)
 *
 * 클레임 첨부는 신청자 본인과 운영자만 볼 수 있는 **권한 게이트 대상**이다. 그래서 상품·리뷰
 * 이미지와 달리 공개 자산 디스크(CDN)로 내려가서는 안 된다 — 직접 URL 이 서버 서빙 경로의
 * 권한 검사를 통째로 우회하기 때문이다(`storage-driver.md` "보안 전제").
 *
 * `ClaimAttachmentServiceTest` 는 StorageInterface 를 Mock 으로 대체하므로 ServiceProvider 의
 * 카테고리 배선을 볼 수 없다. 그 배선 회귀는 이 파일이 잡는다.
 */
class ClaimAttachmentServiceDiskTest extends ModuleTestCase
{
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

    protected function tearDown(): void
    {
        AssetStorage::flush();

        parent::tearDown();
    }

    /**
     * 공개 자산 디스크가 켜져 있어도 클레임 첨부는 그 디스크로 가지 않는다.
     */
    #[Test]
    public function upload_never_lands_on_the_public_asset_disk(): void
    {
        Config::set('core.storage.public_asset_disk', 'fake_cdn');

        $order = Order::factory()->create();
        $service = app(ClaimAttachmentService::class);

        $image = $service->uploadPending(
            UploadedFile::fake()->image('claim-cdn.jpg', 40, 40),
            $order
        );

        $this->assertNotSame(
            'fake_cdn',
            $image->disk,
            '권한 게이트가 걸린 클레임 첨부가 공개 자산 디스크에 저장되면 직접 URL 로 게이트가 우회된다'
        );
        $this->assertSame('modules', $image->disk);

        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'))->exists('images', $image->path),
            '파일 실체가 공개 디스크에 남아 있으면 행 disk 값과 무관하게 노출된다'
        );

        $this->assertTrue($service->delete($image));
    }

    /**
     * 공개 자산 디스크 미설정 시에도 같은 기본 디스크를 쓴다 (대조군 — 위 단언이 공허하지 않음을 고정).
     */
    #[Test]
    public function upload_uses_the_base_disk_when_public_asset_disk_is_unset(): void
    {
        Config::set('core.storage.public_asset_disk', '');

        $order = Order::factory()->create();
        $service = app(ClaimAttachmentService::class);

        $image = $service->uploadPending(
            UploadedFile::fake()->image('claim-local.jpg', 40, 40),
            $order
        );

        $this->assertSame('modules', $image->disk);

        $this->assertTrue($service->delete($image));
    }
}
