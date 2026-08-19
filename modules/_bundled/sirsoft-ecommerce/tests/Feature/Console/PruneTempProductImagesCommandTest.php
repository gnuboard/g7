<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use App\Extension\Storage\ModuleStorageDriver;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 미연결 임시 상품 이미지 정리 커맨드 테스트
 *
 * `temp_key` 가 남고 `product_id` 가 비어 있는 행만 대상이어야 합니다 — 연결된 이미지가
 * 섞이면 판매 중인 상품의 이미지가 사라집니다.
 */
class PruneTempProductImagesCommandTest extends ModuleTestCase
{
    /**
     * 임시 상품 이미지 파일과 기록을 함께 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return ProductImage 생성된 이미지
     */
    private function makeImage(array $attributes = []): ProductImage
    {
        $filename = uniqid('temp-product-').'.png';
        $path = $attributes['path'] ?? "products/temp/tempkey/{$filename}";

        (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->put('images', $path, 'bytes');

        return ProductImage::create(array_merge([
            'product_id' => null,
            'temp_key' => 'tempkey',
            'original_filename' => $filename,
            'stored_filename' => $filename,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'file_size' => 5,
            'collection' => 'main',
            'sort_order' => 1,
        ], $attributes));
    }

    /**
     * @scenario age=past_retention, image_state=temp_unlinked
     *
     * @effects ecommerce_temp_prune_deletes_file_and_record
     */
    #[Test]
    public function stale_temp_images_lose_both_file_and_record(): void
    {
        $image = $this->makeImage();
        $image->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();

        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->exists('images', $image->path)
        );
        $this->assertDatabaseMissing('ecommerce_product_images', ['id' => $image->id]);
    }

    /**
     * @scenario age=within_retention, image_state=temp_unlinked
     *
     * @effects ecommerce_temp_prune_keeps_within_retention
     */
    #[Test]
    public function temp_image_within_retention_is_kept(): void
    {
        $image = $this->makeImage();

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_product_images', ['id' => $image->id]);
    }

    /**
     * @scenario age=past_retention, image_state=linked_to_product
     *
     * @effects ecommerce_temp_prune_skips_linked
     */
    #[Test]
    public function image_linked_to_a_product_is_never_pruned(): void
    {
        $product = Product::factory()->create();

        $image = $this->makeImage([
            'product_id' => $product->id,
            'temp_key' => null,
            'path' => "products/{$product->product_code}/".uniqid('linked-').'.png',
        ]);
        $image->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_product_images', ['id' => $image->id]);
    }

    /**
     * @effects ecommerce_temp_prune_dry_run
     */
    #[Test]
    public function dry_run_reports_targets_without_deleting(): void
    {
        $image = $this->makeImage();
        $image->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images --dry-run')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_product_images', ['id' => $image->id]);
    }

    /**
     * @effects ecommerce_temp_prune_days_guard
     */
    #[Test]
    public function retention_below_one_day_performs_no_cleanup(): void
    {
        $image = $this->makeImage();
        $image->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images --days=0')->assertSuccessful();

        $this->assertDatabaseHas('ecommerce_product_images', ['id' => $image->id]);
    }

    /**
     * 파일을 비운 temp_key 디렉토리는 남기지 않는다 (회귀)
     *
     * 파일만 지우고 디렉토리를 남기면 상품 등록 폼 세션마다 빈 디렉토리가 쌓여, 정리를
     * 돌려도 저장소 흔적은 계속 늘어난다.
     *
     * @scenario age=past_retention, image_state=temp_unlinked
     *
     * @effects ecommerce_temp_prune_removes_empty_directory
     */
    #[Test]
    public function emptied_temp_directory_is_removed(): void
    {
        $image = $this->makeImage(['path' => 'products/temp/tempkey-empty-dir/'.uniqid('t-').'.png']);
        $image->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();

        $storage = new ModuleStorageDriver('sirsoft-ecommerce', 'modules');

        $this->assertSame(
            [],
            $storage->files('images', 'products/temp/tempkey-empty-dir'),
            '디렉토리 안의 파일은 모두 삭제되어야 한다.'
        );
        $this->assertDirectoryDoesNotExist(
            rtrim($storage->getBasePath('images'), '/\\').'/products/temp/tempkey-empty-dir',
            '비워진 temp 디렉토리는 남기지 않는다.'
        );
    }

    /**
     * 같은 temp_key 에 처리되지 않은 파일이 남아 있으면 디렉토리를 지우지 않는다.
     *
     * @scenario age=past_retention, image_state=temp_unlinked
     *
     * @effects ecommerce_temp_prune_keeps_directory_with_remaining_files
     */
    #[Test]
    public function temp_directory_with_remaining_files_is_kept(): void
    {
        $directory = 'products/temp/tempkey-partial';

        $processed = $this->makeImage(['path' => $directory.'/'.uniqid('done-').'.png']);
        $processed->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        // 같은 디렉토리에 이번 회차 대상이 아닌 파일이 남아 있는 상태
        $storage = new ModuleStorageDriver('sirsoft-ecommerce', 'modules');
        $storage->put('images', $directory.'/keep.png', 'bytes');

        $this->artisan('sirsoft-ecommerce:prune-temp-product-images')->assertSuccessful();

        $this->assertTrue($storage->exists('images', $directory.'/keep.png'));
    }
}
