<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\Storage\ModuleStorageDriver;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * 이커머스 이미지 서빙 인라인 정책 회귀 테스트 (KVE-2026-2366 전수 T-B)
 *
 * 이미지 업로드는 `image` 규칙으로 SVG 를 거부하지만, 서빙 계층은 저장된 MIME 과 무관하게
 * 래스터 이미지만 인라인으로 내보내야 한다(심층방어). 상품·리뷰 이미지 서비스도 같은
 * InlineMediaPolicy::harden 을 거친다 — 카테고리·상품·리뷰 서빙 지점을 각각 잠근다.
 */
class ImageServingInlinePolicyTest extends ModuleTestCase
{
    private CategoryImageService $service;

    /**
     * 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CategoryImageService::class);
    }

    /**
     * 저장된 SVG 이미지는 다운로드로 강제되고 nosniff·CSP 가 붙는다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=ecommerce_image
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function stored_svg_is_served_as_download(): void
    {
        $row = $this->makeRow('category/kve-inline/evil.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $response = $this->service->download($row->hash);

        $this->assertNotNull($response);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 래스터 이미지는 인라인을 유지하고 nosniff 가 붙는다 (상품 화면 이미지 회귀 가드).
     *
     * @scenario entry_point=serving, content=real_png, surface=ecommerce_image
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function raster_image_stays_inline(): void
    {
        $row = $this->makeRow('category/kve-inline/real.jpg', 'image/jpeg', 'jpeg-bytes');

        $response = $this->service->download($row->hash);

        $this->assertNotNull($response);
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * 상품 이미지 서빙도 SVG 는 다운로드+nosniff+CSP, 래스터는 인라인+nosniff 다.
     *
     * 카테고리와 별개의 서비스 메서드라 그 서빙 지점의 harden 호출을 따로 잠근다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=ecommerce_image
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp, raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function product_image_serving_applies_inline_policy(): void
    {
        $service = app(ProductImageService::class);
        $svg = $this->makeProductRow('product/kve-inline/evil.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $png = $this->makeProductRow('product/kve-inline/real.png', 'image/png', 'png-bytes');

        $this->assertNonInlineHardened($service->download($svg->hash));
        $this->assertInlineHardened($service->download($png->hash));
    }

    /**
     * 리뷰 이미지 서빙도 SVG 는 다운로드+nosniff+CSP, 래스터는 인라인+nosniff 다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=ecommerce_image
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp, raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function review_image_serving_applies_inline_policy(): void
    {
        Auth::login($this->createUser());
        $product = Product::factory()->onSale()->create();
        $orderOption = OrderOption::factory()->create(['product_id' => $product->id]);
        $review = ProductReview::factory()->create([
            'product_id' => $product->id,
            'order_option_id' => $orderOption->id,
            'user_id' => Auth::id(),
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        $service = app(ProductReviewImageService::class);
        $svg = $this->makeReviewRow($review->id, 'review/kve-inline/evil.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $png = $this->makeReviewRow($review->id, 'review/kve-inline/real.png', 'image/png', 'png-bytes');

        $this->assertNonInlineHardened($service->download($svg->hash));
        $this->assertInlineHardened($service->download($png->hash));
    }

    /**
     * 비래스터 서빙 응답이 다운로드 강제·nosniff·CSP 를 갖는지 단언합니다.
     *
     * @param  Response|null  $response  서빙 응답
     * @return void 반환값 없음
     */
    private function assertNonInlineHardened(?Response $response): void
    {
        $this->assertNotNull($response);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 래스터 서빙 응답이 인라인을 유지하고 nosniff 를 갖는지 단언합니다.
     *
     * @param  Response|null  $response  서빙 응답
     * @return void 반환값 없음
     */
    private function assertInlineHardened(?Response $response): void
    {
        $this->assertNotNull($response);
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * 모듈 스토리지에 파일을 두고 상품 이미지 행을 만듭니다.
     *
     * @param  string  $path  저장 경로
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return ProductImage 이미지 모델
     */
    private function makeProductRow(string $path, string $mime, string $content): ProductImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->put('images', $path, $content);

        return ProductImage::create([
            'temp_key' => 'kve-inline-policy',
            'original_filename' => basename($path),
            'stored_filename' => basename($path),
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => $mime,
            'file_size' => strlen($content),
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * 모듈 스토리지에 파일을 두고 리뷰 이미지 행을 만듭니다.
     *
     * @param  int  $reviewId  리뷰 ID
     * @param  string  $path  저장 경로
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return ProductReviewImage 이미지 모델
     */
    private function makeReviewRow(int $reviewId, string $path, string $mime, string $content): ProductReviewImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->put('images', $path, $content);

        return ProductReviewImage::create([
            'review_id' => $reviewId,
            'original_filename' => basename($path),
            'stored_filename' => basename($path),
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => $mime,
            'file_size' => strlen($content),
            'collection' => 'main',
            'is_thumbnail' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * 모듈 스토리지에 파일을 두고 카테고리 이미지 행을 만듭니다.
     *
     * @param  string  $path  저장 경로
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return CategoryImage 이미지 모델
     */
    private function makeRow(string $path, string $mime, string $content): CategoryImage
    {
        (new ModuleStorageDriver('sirsoft-ecommerce', 'modules'))->put('images', $path, $content);

        return CategoryImage::create([
            'temp_key' => 'kve-inline-policy',
            'original_filename' => basename($path),
            'stored_filename' => basename($path),
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => $mime,
            'file_size' => strlen($content),
            'collection' => 'main',
            'sort_order' => 0,
        ]);
    }

    /**
     * 테스트 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $storage = new ModuleStorageDriver('sirsoft-ecommerce', 'modules');
        foreach (['category/kve-inline', 'product/kve-inline', 'review/kve-inline'] as $dir) {
            $storage->deleteDirectory('images', $dir);
        }

        parent::tearDown();
    }
}
