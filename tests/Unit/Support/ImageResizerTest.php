<?php

namespace Tests\Unit\Support;

use App\Services\AttachmentService;
use App\Support\ImageResizer;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 업로드 이미지 리사이즈 테스트
 *
 * 환경설정 > 업로드의 최대 가로/세로 크기와 품질은 저장·검증만 되고 실제로 이미지를 줄이는
 * 코드가 없었습니다. 관리자는 값을 바꾸면 적용된다고 인지하지만 원본이 그대로 저장됐습니다.
 */
class ImageResizerTest extends TestCase
{
    /**
     * 지정 크기의 임시 PNG 를 만듭니다.
     *
     * @param  int  $width  가로 픽셀
     * @param  int  $height  세로 픽셀
     * @return string 생성된 파일 경로
     */
    private function makeImage(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'g7img').'.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * 파일의 가로·세로를 반환합니다.
     *
     * @param  string  $path  이미지 경로
     * @return array{0:int,1:int} [가로, 세로]
     */
    private function dimensions(string $path): array
    {
        $size = getimagesize($path);

        return [(int) $size[0], (int) $size[1]];
    }

    #[Test]
    public function it_shrinks_an_image_that_exceeds_the_configured_bounds(): void
    {
        config(['attachment.image_max_width' => 800, 'attachment.image_max_height' => 600, 'attachment.image_quality' => 80]);

        $path = $this->makeImage(1600, 1200);

        $this->assertTrue(app(ImageResizer::class)->resizeInPlace($path, 'image/png'));

        [$width, $height] = $this->dimensions($path);

        $this->assertSame(800, $width);
        // 비율 유지 — 1600x1200 을 800 폭에 맞추면 600
        $this->assertSame(600, $height);

        @unlink($path);
    }

    #[Test]
    public function it_keeps_aspect_ratio_when_only_one_side_exceeds(): void
    {
        config(['attachment.image_max_width' => 1000, 'attachment.image_max_height' => 1000, 'attachment.image_quality' => 80]);

        $path = $this->makeImage(2000, 500);

        $this->assertTrue(app(ImageResizer::class)->resizeInPlace($path, 'image/png'));

        [$width, $height] = $this->dimensions($path);

        $this->assertSame(1000, $width);
        $this->assertSame(250, $height);

        @unlink($path);
    }

    #[Test]
    public function it_leaves_images_within_bounds_untouched(): void
    {
        config(['attachment.image_max_width' => 1000, 'attachment.image_max_height' => 1000]);

        $path = $this->makeImage(400, 300);
        $before = md5_file($path);

        $this->assertFalse(
            app(ImageResizer::class)->resizeInPlace($path, 'image/png'),
            '한계 이내 이미지는 다시 인코딩하지 않아야 합니다 — 불필요한 화질 손실을 만듭니다.'
        );
        $this->assertSame($before, md5_file($path));

        @unlink($path);
    }

    #[Test]
    public function it_does_nothing_when_no_limit_is_configured(): void
    {
        config(['attachment.image_max_width' => null, 'attachment.image_max_height' => null]);

        $path = $this->makeImage(4000, 3000);
        $before = md5_file($path);

        $this->assertFalse(app(ImageResizer::class)->resizeInPlace($path, 'image/png'));
        $this->assertSame($before, md5_file($path));

        @unlink($path);
    }

    #[Test]
    public function it_ignores_non_image_files(): void
    {
        config(['attachment.image_max_width' => 100, 'attachment.image_max_height' => 100]);

        $path = tempnam(sys_get_temp_dir(), 'g7doc').'.txt';
        file_put_contents($path, str_repeat('a', 500));
        $before = md5_file($path);

        $this->assertFalse(app(ImageResizer::class)->resizeInPlace($path, 'text/plain'));
        $this->assertSame($before, md5_file($path));

        @unlink($path);
    }

    #[Test]
    public function upload_applies_the_configured_bounds(): void
    {
        config(['attachment.image_max_width' => 640, 'attachment.image_max_height' => 640, 'attachment.image_quality' => 75]);

        $path = $this->makeImage(1280, 960);
        $upload = new UploadedFile($path, 'large.png', 'image/png', null, true);

        $attachment = app(AttachmentService::class)->upload($upload);

        $this->assertSame(640, $attachment->meta['width'] ?? null, '저장된 첨부의 가로가 설정 상한을 넘습니다 — 리사이즈가 업로드 경로에 적용되지 않았습니다.');
        $this->assertSame(480, $attachment->meta['height'] ?? null);

        @unlink($path);
    }
}
