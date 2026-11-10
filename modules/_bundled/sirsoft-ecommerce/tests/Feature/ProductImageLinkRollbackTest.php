<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 임시 이미지 연결의 롤백 — 파일도 임시 자리로 (#883 3단계)
 *
 * 상품 저장이 임시 이미지를 상품 경로로 옮긴 뒤 같은 트랜잭션의 뒤 단계가 실패하면, 종전에는 파일만 상품 경로에 남아
 * 이미지 행(임시 경로)과 어긋났다. 이동은 StorageTransaction 이라 롤백되면 사본을 지우고 원본을 둔다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-save-transaction-hooks.yaml
 */
class ProductImageLinkRollbackTest extends ModuleTestCase
{
    /**
     * 트랜잭션이 롤백되면 임시 이미지 파일과 행이 그대로다. 커밋되면 상품 경로로 옮겨진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_image_link_rollback
     *
     * @effects temp_image_file_restored_on_rollback
     */
    public function test_temp_image_link_is_compensated_on_rollback(): void
    {
        Storage::fake('local');
        Storage::fake('modules');
        $storage = $this->serviceStorage(app(ProductImageService::class));
        $product = Product::factory()->create();
        $tempKey = (string) Str::uuid();
        $tempPath = "products/temp/{$tempKey}/a.jpg";
        $storage->put('images', $tempPath, 'jpg');
        $image = ProductImage::create([
            'product_id' => null,
            'temp_key' => $tempKey,
            'original_filename' => 'a.jpg',
            'stored_filename' => 'a.jpg',
            'disk' => $storage->getDisk(),
            'path' => $tempPath,
            'mime_type' => 'image/jpeg',
            'file_size' => 3,
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 1,
        ]);

        try {
            DB::transaction(function () use ($tempKey, $product) {
                app(ProductImageService::class)->linkTempImages($tempKey, $product->id, $product->product_code);

                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
        }

        $image->refresh();
        $this->assertSame($tempPath, $image->path);
        $this->assertTrue($storage->exists('images', $tempPath));
        $this->assertFalse($storage->exists('images', "products/{$product->product_code}/a.jpg"));

        DB::transaction(fn () => app(ProductImageService::class)->linkTempImages($tempKey, $product->id, $product->product_code));

        $this->assertFalse($storage->exists('images', $tempPath));
        $this->assertTrue($storage->exists('images', "products/{$product->product_code}/a.jpg"));
    }

    /**
     * 서비스가 주입받은 저장소를 돌려줍니다 — 모듈이 쓰는 디스크(구성에 따라 local·modules)를 그대로 쓰기 위해.
     *
     * @param  object  $service  저장소를 주입받은 서비스
     * @return \App\Contracts\Extension\StorageInterface 저장소
     */
    private function serviceStorage(object $service): \App\Contracts\Extension\StorageInterface
    {
        return (new \ReflectionProperty($service, 'storage'))->getValue($service);
    }
}
