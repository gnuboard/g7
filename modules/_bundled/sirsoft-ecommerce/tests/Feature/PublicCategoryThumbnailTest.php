<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 카테고리 상세 thumbnail_url 방출 테스트 (공개 이슈 #22 부수 — P3-B)
 *
 * module.php seoOpenGraph 의 `category.data.thumbnail_url` 소비처는 있었지만 어떤
 * Resource 도 이 키를 방출하지 않는 死표현식이었다. PublicCategoryDetailResource 가
 * 로드된 images 첫 건의 URL 을 방출해 카테고리 og:image 의 생산자가 되는 계약을
 * 고정한다 (재쿼리 금지 — 로드된 관계에서만 선택).
 *
 * @effects category_detail_emits_thumbnail_url
 */
class PublicCategoryThumbnailTest extends ModuleTestCase
{
    /**
     * @param  array  $attributes  덮어쓸 속성
     * @return Category 생성된 활성 카테고리
     */
    private function createCategory(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'name' => ['ko' => '썸네일 카테고리', 'en' => 'Thumbnail Category'],
            'slug' => 'thumb-cat-'.uniqid(),
            'is_active' => true,
            'depth' => 0,
            'path' => '',
            'sort_order' => 0,
        ], $attributes));
    }

    /**
     * @effects category_detail_emits_thumbnail_url
     */
    #[Test]
    public function detail_emits_first_image_url_as_thumbnail(): void
    {
        $category = $this->createCategory();

        $image = CategoryImage::create([
            'category_id' => $category->id,
            'original_filename' => 'cat.jpg',
            'stored_filename' => 'cat-'.uniqid().'.jpg',
            'disk' => 'modules',
            'path' => 'category/thumb-test/cat.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 11,
            'collection' => 'main',
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/'.$category->slug);

        $response->assertStatus(200);
        $this->assertSame(
            $image->download_url,
            $response->json('data.thumbnail_url'),
            '로드된 images 첫 건의 URL 이 thumbnail_url 로 방출되어야 합니다.'
        );
    }

    /**
     * @effects category_detail_emits_thumbnail_url
     */
    #[Test]
    public function detail_emits_null_thumbnail_without_images(): void
    {
        $category = $this->createCategory();

        $response = $this->getJson('/api/modules/sirsoft-ecommerce/categories/'.$category->slug);

        $response->assertStatus(200);
        $this->assertArrayHasKey('thumbnail_url', $response->json('data'));
        $this->assertNull($response->json('data.thumbnail_url'));
    }
}
