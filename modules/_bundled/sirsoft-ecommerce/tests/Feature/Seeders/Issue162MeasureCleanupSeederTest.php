<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Seeders;

use App\Extension\ModuleManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\Issue162MeasureCleanupSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\Issue162MeasureSeeder;
use Modules\Sirsoft\Ecommerce\Enums\ContentMediaOwnerType;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 실측 정리 시더가 표지 상품의 본문 미디어(행·파일)까지 지우는지 검증합니다.
 *
 * 본문 미디어는 소유자 외래키가 없어 상품을 지워도 남는다. 종전 시더는 상품·주문만 지워 행과 파일이 함께 남았다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/measure-cleanup-seeder.yaml
 */
class Issue162MeasureCleanupSeederTest extends ModuleTestCase
{
    /**
     * 저장소를 가짜로 바꾸고 실측 시더 가드를 엽니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('modules');
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');
    }

    /**
     * 실측 시더 가드를 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');

        parent::tearDown();
    }

    /**
     * 표지 상품을 지우면서 그 상품의 본문 미디어 행과 서비스 저장소 파일도 지운다. 다른 상품의 본문 미디어는 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cleanup
     *
     * @effects marker_product_content_media_deleted
     */
    public function test_cleanup_deletes_marker_product_content_media(): void
    {
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.products.create']));
        $marker = Product::factory()->create(['product_code' => Issue162MeasureSeeder::PRODUCT_CODE]);
        $other = Product::factory()->create();

        $service = app(ContentMediaService::class);
        $mine = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('a.png'), (string) Str::uuid());
        $mine->update(['attachmentable_id' => $marker->id]);
        $kept = $service->upload(ContentMediaOwnerType::Product, UploadedFile::fake()->image('b.png'), (string) Str::uuid());
        $kept->update(['attachmentable_id' => $other->id]);

        $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorage();
        $this->assertTrue($storage->exists(ContentMediaService::CATEGORY, $mine->path), '전제: 서비스가 올린 파일이 모듈 저장소에 있다');

        $this->seed(Issue162MeasureCleanupSeeder::class);

        $this->assertNull(Product::withTrashed()->find($marker->id));
        $this->assertNull(ContentMedia::withTrashed()->find($mine->id), '표지 상품의 본문 미디어 행을 지운다');
        $this->assertFalse($storage->exists(ContentMediaService::CATEGORY, $mine->path), '표지 상품의 본문 미디어 파일을 지운다');
        $this->assertNotNull(ContentMedia::query()->find($kept->id), '다른 상품의 본문 미디어는 남긴다');
        $this->assertTrue($storage->exists(ContentMediaService::CATEGORY, $kept->path));
    }
}
