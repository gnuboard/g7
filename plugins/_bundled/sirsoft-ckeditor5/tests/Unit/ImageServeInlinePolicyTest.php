<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Unit;

use App\Contracts\Extension\StorageInterface;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Services\ImageServeService;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 에디터 이미지 서빙 인라인 정책 회귀 테스트 (KVE-2026-2366 전수 T-B)
 *
 * 에디터 이미지 업로드는 `image`+`mimes:` 로 SVG 를 거부하지만, 공개 서빙(`images/{hash}`)은
 * 저장된 MIME 을 그대로 인라인으로 내보내는 sink 였다. 서빙 계층은 래스터 이미지만 인라인으로 둔다.
 */
class ImageServeInlinePolicyTest extends PluginTestCase
{
    private ImageServeService $service;

    /**
     * 서빙 서비스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Ckeditor5ImageUpload::flushStorageCache();
        $this->service = app(ImageServeService::class);
    }

    /**
     * 저장된 SVG 이미지는 다운로드로 강제되고 nosniff·CSP 가 붙는다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=editor_image
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function stored_svg_is_served_as_download(): void
    {
        $image = $this->makeRow('images/kve-inline/evil.png', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $response = $this->service->serve($image);

        $this->assertNotNull($response);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 래스터 이미지는 인라인을 유지하고 nosniff 가 붙는다 (본문 이미지 표시 회귀 가드).
     *
     * @scenario entry_point=serving, content=real_png, surface=editor_image
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function raster_image_stays_inline(): void
    {
        $image = $this->makeRow('images/kve-inline/real.jpg', 'image/jpeg', 'jpeg-bytes');

        $response = $this->service->serve($image);

        $this->assertNotNull($response);
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * 주입 스토리지에 파일을 두고 업로드 행을 만듭니다.
     *
     * @param  string  $filePath  저장 경로 (첫 세그먼트 = 카테고리)
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return Ckeditor5ImageUpload 업로드 모델
     */
    private function makeRow(string $filePath, string $mime, string $content): Ckeditor5ImageUpload
    {
        [$category, $relativePath] = explode('/', $filePath, 2);
        $this->storage()->put($category, $relativePath, $content);

        return Ckeditor5ImageUpload::create([
            'original_name' => basename($filePath),
            'file_path' => $filePath,
            'storage_disk' => $this->storage()->getDisk(),
            'file_size' => strlen($content),
            'mime_type' => $mime,
            'uploaded_by' => null,
        ]);
    }

    /**
     * 서빙 서비스가 쓰는 플러그인 스토리지 드라이버를 꺼냅니다.
     *
     * @return StorageInterface 플러그인 스토리지
     */
    private function storage(): StorageInterface
    {
        $property = (new \ReflectionClass($this->service))->getProperty('storage');
        $property->setAccessible(true);

        return $property->getValue($this->service);
    }

    /**
     * 테스트 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->storage()->deleteDirectory('images', 'kve-inline');
        Ckeditor5ImageUpload::flushStorageCache();

        parent::tearDown();
    }
}
