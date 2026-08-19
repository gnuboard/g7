<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use App\Extension\Storage\ModuleStorageDriver;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 카테고리 삭제 시 이미지 물리 파일까지 삭제되는지 검증 (회귀)
 *
 * 종전에는 `$category->images()->delete()` 로 DB 행만 지워 파일이 디스크에 영구 잔존했다.
 * 같은 모듈의 상품 삭제는 파일까지 지우므로 두 경로가 비대칭이었다.
 *
 * @see ProductService::delete() 대칭 선례
 */
class CategoryDeleteImageFileTest extends ModuleTestCase
{
    /**
     * 지정 disk 에 파일과 카테고리 이미지 행을 함께 생성합니다.
     *
     * @param  int  $categoryId  카테고리 ID
     * @param  string  $disk  행 disk
     * @param  string  $path  이미지 경로 (images/ 하위 상대 경로)
     * @return CategoryImage 생성된 행
     */
    private function makeImage(int $categoryId, string $disk, string $path): CategoryImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', $disk))->put('images', $path, 'image-bytes');

        return CategoryImage::create([
            'category_id' => $categoryId,
            'original_filename' => 'category.jpg',
            'stored_filename' => basename($path),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'collection' => 'main',
            'sort_order' => 0,
        ]);
    }

    /**
     * @effects category_delete_removes_image_files, category_delete_removes_image_rows
     */
    #[Test]
    public function deleting_a_category_removes_its_image_files_and_rows(): void
    {
        $category = Category::create([
            'name' => ['ko' => '삭제 대상 카테고리', 'en' => 'Category To Delete'],
            'slug' => 'category-delete-image-file-test',
            'is_active' => true,
            'depth' => 0,
            'path' => '',
            'sort_order' => 0,
        ]);

        $image = $this->makeImage($category->id, 'modules', 'category/delete-test/main.jpg');

        $storage = new ModuleStorageDriver('sirsoft-ecommerce', 'modules');
        $this->assertTrue(
            $storage->exists('images', $image->path),
            '사전 조건: 삭제 전에는 이미지 파일이 존재해야 한다.'
        );

        app(CategoryService::class)->deleteCategory($category->id);

        $this->assertFalse(
            $storage->exists('images', $image->path),
            '카테고리를 삭제하면 이미지 물리 파일도 함께 삭제되어야 한다.'
        );

        $this->assertDatabaseMissing('ecommerce_category_images', ['id' => $image->id]);
        $this->assertDatabaseMissing('ecommerce_categories', ['id' => $category->id]);
    }

    /**
     * 행마다 disk 가 다른 혼재 상태에서도 각 행의 실제 저장 위치를 향해 삭제해야 한다.
     *
     * @effects category_delete_removes_image_files
     */
    #[Test]
    public function deleting_a_category_removes_image_files_across_mixed_disks(): void
    {
        config()->set('filesystems.disks.fake_cdn', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/fake_cdn'),
            'url' => 'https://cdn.test/assets',
        ]);

        $category = Category::create([
            'name' => ['ko' => '혼재 디스크 카테고리', 'en' => 'Mixed Disk Category'],
            'slug' => 'category-delete-mixed-disk-test',
            'is_active' => true,
            'depth' => 0,
            'path' => '',
            'sort_order' => 0,
        ]);

        $local = $this->makeImage($category->id, 'modules', 'category/delete-mixed/local.jpg');
        $cdn = $this->makeImage($category->id, 'fake_cdn', 'category/delete-mixed/cdn.jpg');

        app(CategoryService::class)->deleteCategory($category->id);

        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->exists('images', $local->path),
            '기본 디스크 행의 파일이 삭제되어야 한다.'
        );
        $this->assertFalse(
            (new ModuleStorageDriver('sirsoft-ecommerce', 'fake_cdn'))->exists('images', $cdn->path),
            '다른 디스크 행의 파일도 그 행의 disk 를 향해 삭제되어야 한다.'
        );
    }
}
