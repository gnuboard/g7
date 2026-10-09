<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 미리보기를 제공하지 않는 형식의 첨부 미리보기 응답 문구 테스트
 *
 * 미리보기는 일반 이미지(JPG·PNG·GIF·WebP)만 화면에 띄운다. SVG·TXT 처럼 미리보기를 주지 않는
 * 형식의 미리보기 주소를 열면 404 와 함께 「첨부파일이 스토리지에 존재하지 않습니다.」가 나갔다
 * (7.1.0 전수 실측 영역 L, O-L4). 파일은 있으므로 그 문구는 사실과 다르다. 상태코드 404 는 그대로
 * 두고 문구만 「미리보기를 제공하지 않는 형식」으로 바꾼다. 이미지인데 파일이 정말 없을 때의 문구는
 * 종전대로 남는다.
 *
 * @scenario-manifest tests/scenarios/preview-not-supported-message.yaml
 */
class PreviewNotSupportedMessageTest extends FeatureTestCase
{
    private const BASE = '/api/modules/sirsoft-page/pages/attachment';

    /**
     * 테스트 정리
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'preview-unsupported-%')->forceDelete();
        parent::tearDown();
    }

    /**
     * 발행 페이지에 주어진 형식의 첨부를 만든다.
     *
     * @param  string  $mime  MIME 형식
     * @param  string  $filename  원본 파일명
     * @return PageAttachment 생성된 첨부
     */
    private function makeAttachment(string $mime, string $filename): PageAttachment
    {
        $page = Page::factory()->published()->create(['slug' => 'preview-unsupported-'.uniqid()]);

        return PageAttachment::factory()->create([
            'page_id' => $page->id,
            'mime_type' => $mime,
            'original_filename' => $filename,
        ]);
    }

    /**
     * SVG·TXT 미리보기는 404 이고, 문구는 저장소 부재가 아니라 미리보기 미지원을 말한다(ko).
     *
     * @return void 반환값 없음
     *
     * @scenario mime=non_previewable,locale=ko
     *
     * @effects non_previewable_type_says_preview_not_supported
     */
    public function test_non_previewable_types_say_preview_not_supported_in_korean(): void
    {
        foreach ([['image/svg+xml', 'vec.svg'], ['text/plain', 'note.txt']] as [$mime, $name]) {
            $attachment = $this->makeAttachment($mime, $name);

            $response = $this->withHeaders(['Accept-Language' => 'ko'])
                ->getJson(self::BASE.'/'.$attachment->hash.'/preview');

            $response->assertStatus(404);
            $this->assertSame('미리보기를 제공하지 않는 파일 형식입니다.', $response->json('message'), $mime);
        }
    }

    /**
     * 같은 문구가 영어로도 나간다.
     *
     * @return void 반환값 없음
     *
     * @scenario mime=non_previewable,locale=en
     *
     * @effects non_previewable_type_says_preview_not_supported
     */
    public function test_non_previewable_type_says_preview_not_supported_in_english(): void
    {
        foreach ([['image/svg+xml', 'vec.svg'], ['text/plain', 'note.txt']] as [$mime, $name]) {
            $attachment = $this->makeAttachment($mime, $name);

            $response = $this->withHeaders(['Accept-Language' => 'en'])
                ->getJson(self::BASE.'/'.$attachment->hash.'/preview');

            $response->assertStatus(404);
            $this->assertSame('Preview is not available for this file type.', $response->json('message'), $mime);
        }
    }

    /**
     * 이미지인데 파일이 저장소에 없으면 종전 문구(저장소 부재)를 그대로 쓴다(ko).
     *
     * @return void 반환값 없음
     *
     * @scenario mime=jpeg_missing_file,locale=ko
     *
     * @effects missing_image_file_keeps_file_not_found_message
     */
    public function test_missing_image_file_keeps_file_not_found_message(): void
    {
        $this->assertMissingFileMessage('ko', '첨부파일이 스토리지에 존재하지 않습니다.');
    }

    /**
     * 같은 경우 영어 문구도 종전대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario mime=jpeg_missing_file,locale=en
     *
     * @effects missing_image_file_keeps_file_not_found_message
     */
    public function test_missing_image_file_keeps_file_not_found_message_in_english(): void
    {
        $this->assertMissingFileMessage('en', 'Attachment file does not exist in storage.');
    }

    /**
     * 파일이 없는 이미지 첨부의 미리보기 응답 문구를 확인한다.
     *
     * @param  string  $locale  요청 언어
     * @param  string  $message  기대 문구
     * @return void 반환값 없음
     */
    private function assertMissingFileMessage(string $locale, string $message): void
    {
        $attachment = $this->makeAttachment('image/jpeg', 'gone.jpg');

        $response = $this->withHeaders(['Accept-Language' => $locale])
            ->getJson(self::BASE.'/'.$attachment->hash.'/preview');

        $response->assertStatus(404);
        $this->assertSame($message, $response->json('message'));
    }
}
