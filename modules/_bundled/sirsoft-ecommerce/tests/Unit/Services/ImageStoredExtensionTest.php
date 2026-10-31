<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Http\UploadedFile;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 저장 경로 확장자 정규화 테스트 (P21)
 *
 * 검증 룰(`image`/`mimes`)의 통과 판정은 내용 기반이라, 내용이 정상 이미지이고 파일명만
 * `.html`·`.js` 인 업로드는 검증을 통과한다 (`ImageUploadExtensionGuardTest` 가 그 사실을 고정).
 * 그 값이 저장 경로에 그대로 쓰이면 그 디스크를 서빙하는 웹서버가 **확장자로** 처리하므로,
 * 저장 지점은 클라이언트 값이 아니라 **검증된 내용**에서 확장자를 정한다.
 */
class ImageStoredExtensionTest extends ModuleTestCase
{
    /**
     * 내용은 정상 PNG 인데 클라이언트 파일명만 다른 업로드를 만듭니다.
     */
    private function disguisedPng(string $clientName): UploadedFile
    {
        $png = UploadedFile::fake()->image('real.png', 20, 20);
        $path = tempnam(sys_get_temp_dir(), 'g7ext');
        copy($png->getRealPath(), $path);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * 클레임 첨부 — 위장 확장자는 저장 경로에 실리지 않고 내용 기준 확장자가 쓰인다.
     */
    #[Test]
    public function claim_attachment_path_uses_the_content_extension(): void
    {
        $order = Order::factory()->create();
        $service = app(ClaimAttachmentService::class);

        $image = $service->uploadPending($this->disguisedPng('evil.html'), $order);

        $this->assertStringEndsWith(
            '.png',
            $image->path,
            '저장 경로가 클라이언트 확장자를 그대로 쓰면 공개 디스크에서 동일 오리진 실행 파일이 된다'
        );
        $this->assertStringNotContainsString('.html', $image->path);

        $this->assertTrue($service->delete($image));
    }

    /**
     * 클레임 첨부 — 정상 업로드의 확장자는 보존된다 (대조군).
     */
    #[Test]
    public function claim_attachment_path_keeps_a_legitimate_extension(): void
    {
        $order = Order::factory()->create();
        $service = app(ClaimAttachmentService::class);

        $image = $service->uploadPending(UploadedFile::fake()->image('ok.png', 20, 20), $order);

        $this->assertStringEndsWith('.png', $image->path);

        $this->assertTrue($service->delete($image));
    }

    /**
     * 원본 파일명은 표시용으로 그대로 보존된다 — 정규화 대상은 **저장 경로**뿐이다.
     */
    #[Test]
    public function original_name_is_preserved_for_display(): void
    {
        $order = Order::factory()->create();
        $service = app(ClaimAttachmentService::class);

        $image = $service->uploadPending($this->disguisedPng('evil.html'), $order);

        $this->assertSame('evil.html', $image->original_name);

        $this->assertTrue($service->delete($image));
    }

    /**
     * 상품 이미지 — 공개 디스크로 나가는 경로도 내용 기준 확장자를 쓴다.
     *
     * 공개 자산 카테고리(`images`)는 운영자가 CDN·S3 로 배선할 수 있고 그 디스크는 서버
     * 권한 검사를 거치지 않고 직접 URL 로 서빙된다 — 확장자 혼동의 대가가 가장 큰 자리가
     * 클레임 첨부가 아니라 이 셋이다.
     */
    #[Test]
    public function product_image_path_uses_the_content_extension(): void
    {
        $service = app(ProductImageService::class);

        $image = $service->upload($this->disguisedPng('evil.php'), null, 'main', 'g7-ext-probe');

        $this->assertStringEndsWith('.png', $image->path);
        $this->assertStringEndsWith('.png', $image->stored_filename);
        $this->assertStringNotContainsString('.php', $image->path);
        $this->assertSame('evil.php', $image->original_filename);

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * 카테고리 이미지 — 동일.
     */
    #[Test]
    public function category_image_path_uses_the_content_extension(): void
    {
        $service = app(CategoryImageService::class);

        $image = $service->upload($this->disguisedPng('evil.svg'), null, 'main', 'g7-ext-probe');

        $this->assertStringEndsWith('.png', $image->path);
        $this->assertStringEndsWith('.png', $image->stored_filename);
        $this->assertStringNotContainsString('.svg', $image->path);
        $this->assertSame('evil.svg', $image->original_filename);

        $this->assertTrue($service->delete($image->id));
    }

    /**
     * 리뷰 이미지 — 동일.
     */
    #[Test]
    public function review_image_path_uses_the_content_extension(): void
    {
        $service = app(ProductReviewImageService::class);
        $review = ProductReview::factory()->create();

        $image = $service->upload($this->disguisedPng('evil.html'), $review);

        $this->assertStringEndsWith('.png', $image->path);
        $this->assertStringEndsWith('.png', $image->stored_filename);
        $this->assertStringNotContainsString('.html', $image->path);
        $this->assertSame('evil.html', $image->original_filename);

        $this->assertTrue($service->delete($image));
    }
}
