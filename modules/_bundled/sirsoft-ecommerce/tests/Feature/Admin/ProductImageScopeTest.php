<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 대표 이미지 설정의 상위 상품 스코프 검증 테스트
 *
 * PATCH /api/modules/sirsoft-ecommerce/admin/products/{productId}/images/{imageId}/thumbnail
 *
 * 검증 목적:
 * - 상품 A 의 경로로 상품 B 의 이미지를 대표로 지정할 수 없다
 * - 상품 B 의 대표 이미지 상태가 오염되지 않는다
 */
class ProductImageScopeTest extends ModuleTestCase
{
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.update']);
    }

    /**
     * 다른 상품의 이미지를 대표로 지정할 수 없다
     *
     * @scenario resource=product_image, parent_scope=mismatched, actor=admin
     *
     * @effects cross_scope_write_rejected, target_resource_unchanged_on_rejection, sibling_resource_state_unchanged
     */
    public function test_cannot_set_thumbnail_of_other_product(): void
    {
        $productA = $this->makeProduct();
        $productB = $this->makeProduct();

        $imageOfA = $this->makeImage($productA, isThumbnail: true);
        $imageOfB = $this->makeImage($productB, isThumbnail: true);
        $otherImageOfB = $this->makeImage($productB, isThumbnail: false);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url($productA->id, $otherImageOfB->id))
            ->assertStatus(400);

        $this->assertTrue(
            (bool) $imageOfB->fresh()->is_thumbnail,
            '상품 B 의 기존 대표 이미지가 유지되어야 합니다.'
        );
        $this->assertFalse(
            (bool) $otherImageOfB->fresh()->is_thumbnail,
            '상품 B 의 다른 이미지가 대표로 바뀌면 안 됩니다.'
        );
        $this->assertTrue(
            (bool) $imageOfA->fresh()->is_thumbnail,
            '상품 A 의 대표 이미지도 해제되면 안 됩니다.'
        );
    }

    /**
     * 자기 상품의 이미지는 대표로 지정된다 (회귀 방지)
     *
     * @scenario resource=product_image, parent_scope=matching, actor=admin
     *
     * @effects matching_scope_still_succeeds
     */
    public function test_can_set_thumbnail_of_own_product(): void
    {
        $product = $this->makeProduct();

        $current = $this->makeImage($product, isThumbnail: true);
        $target = $this->makeImage($product, isThumbnail: false);

        $this->actingAs($this->adminUser)
            ->patchJson($this->url($product->id, $target->id))
            ->assertStatus(200);

        $this->assertTrue((bool) $target->fresh()->is_thumbnail, '대상 이미지가 대표가 되어야 합니다.');
        $this->assertFalse((bool) $current->fresh()->is_thumbnail, '기존 대표는 해제되어야 합니다.');
    }

    /**
     * 테스트용 상품을 생성합니다.
     *
     * @return Product 생성된 상품
     */
    private function makeProduct(): Product
    {
        return ProductFactory::new()->create();
    }

    /**
     * 테스트용 상품 이미지를 생성합니다.
     *
     * @param  Product  $product  소속 상품
     * @param  bool  $isThumbnail  대표 이미지 여부
     * @return ProductImage 생성된 이미지
     */
    private function makeImage(Product $product, bool $isThumbnail): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'original_filename' => 'test.jpg',
            'stored_filename' => 'test-'.uniqid().'.jpg',
            'disk' => 'public',
            'path' => 'ecommerce/products/test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'is_thumbnail' => $isThumbnail,
            'sort_order' => 0,
        ]);
    }

    /**
     * 대표 이미지 설정 엔드포인트 URL 을 생성합니다.
     *
     * @param  int  $productId  상품 ID
     * @param  int  $imageId  이미지 ID
     * @return string 엔드포인트 URL
     */
    private function url(int $productId, int $imageId): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/products/{$productId}/images/{$imageId}/thumbnail";
    }
}
