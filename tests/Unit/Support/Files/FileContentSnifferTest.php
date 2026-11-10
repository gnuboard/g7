<?php

namespace Tests\Unit\Support\Files;

use App\Support\Files\FileContentSniffer;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 업로드 파일 내용 판정 입구 — 본문 미디어(내용 MIME)와 첨부(내용 추론 확장자)가 함께 쓴다 (#887, G11)
 *
 * 본문 미디어는 finfo 내용 MIME 이 1순위다. 첨부는 7.1.0 `mimes:` 호환 판정(`guessExtension()`)을 그대로 쓴다 — 실제 업로드는
 * 두 값의 출처가 같고(finfo), 내용을 보고하지 않는 가짜 파일에서만 다르다. 첨부 판정 결과가 바뀌지 않는다는 것을 잠근다.
 *
 * @scenario-manifest tests/scenarios/content-media-core-skeleton.yaml
 */
class FileContentSnifferTest extends TestCase
{
    /**
     * 실제 이미지는 내용 MIME 과 내용 추론 확장자가 함께 이미지다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sniffer, extension=module
     *
     * @effects real_content_mime, extension_same_as_guess
     */
    public function test_real_image_mime_and_extension(): void
    {
        // 가짜 이미지 생성기는 소문자 확장자로만 형식을 고른다(대문자면 jpeg 로 만든다).
        $file = UploadedFile::fake()->image('photo.png', 4, 4);

        $this->assertSame('image/png', FileContentSniffer::mimeType($file));
        $this->assertSame($file->guessExtension(), FileContentSniffer::extension($file));
        $this->assertSame('png', FileContentSniffer::extension($file));
    }

    /**
     * 이름만 png 인 SVG 는 내용 MIME 이 SVG 이고, 첨부 판정의 추론 확장자도 svg 다(위장 차단).
     *
     * @return void 반환값 없음
     *
     * @scenario case=sniffer, extension=module
     *
     * @effects spoofed_name_detected
     */
    public function test_renamed_svg_is_detected_by_content(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sn');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $file = new UploadedFile($path, 'evil.png', 'image/png', null, true);

        $this->assertStringContainsString('svg', (string) FileContentSniffer::mimeType($file));
        $this->assertSame('svg', FileContentSniffer::extension($file));
    }

    /**
     * 내용을 보고하지 않는 가짜 파일(이름으로 MIME 을 정함)에서 본문 미디어 판정은 내용(finfo)을, 첨부 판정은 종전
     * `guessExtension()` 을 따른다 — 첨부 판정 결과가 7.1.0 과 같다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sniffer, extension=module
     *
     * @effects extension_same_as_guess, content_mime_ignores_name
     */
    public function test_name_reported_fake_keeps_attachment_judgment(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 1);

        $this->assertSame($file->guessExtension(), FileContentSniffer::extension($file));
        $this->assertNotSame('video/mp4', FileContentSniffer::mimeType($file), '이름이 아니라 내용(0 바이트 채움)으로 판정한다');
    }
}
