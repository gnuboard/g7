<?php

namespace Tests\Feature\Attachment;

use App\Extension\HookManager;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 코어 첨부 업로드의 원래 파일 이름 저장 테스트.
 *
 * 브라우저가 파일 이름의 큰따옴표를 `%22` 로 바꿔 보내면 그 값이 그대로 저장돼 목록과 내려받은 파일 이름에
 * `%22` 가 남았다. 업로드는 원래 이름으로 저장하고, 내려받기 `filename*` 에도 원래 이름이 실리는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/upload-original-filename.yaml
 */
class AttachmentQuotedFilenameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 훅을 비우고 첨부 디스크를 가짜로 바꾼다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HookManager::resetAll();
        config(['attachment.disk' => 'attachments']);
        Storage::fake('attachments');
    }

    /**
     * 따옴표 이름 업로드는 원래 이름으로 저장되고 내려받기 `filename*` 에 원래 이름이 실린다.
     *
     * @scenario input=quote
     *
     * @effects core_attachment_stores_restored_name, core_attachment_download_declares_restored_name
     *
     * @return void 반환값 없음
     */
    public function test_quoted_upload_name_is_stored_and_downloaded_as_the_original(): void
    {
        $file = UploadedFile::fake()->createWithContent('견적 %22최종%22 \'사본\'.pdf', '%PDF-1.4 fixture');

        $attachment = app(AttachmentService::class)->upload($file);

        $this->assertSame('견적 "최종" \'사본\'.pdf', $attachment->original_filename);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id, 'original_filename' => '견적 "최종" \'사본\'.pdf']);

        $response = $this->get('/api/attachment/'.$attachment->hash);

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode('견적 "최종" \'사본\'.pdf'), $disposition);
        $this->assertStringNotContainsString('%2522', $disposition, '내려받은 이름에 브라우저 인코딩(%22)이 남았다');
    }

    /**
     * 되돌린 줄바꿈은 이름에 남지만 내려받기 머리글에는 퍼센트 인코딩으로만 실린다 (머리글 주입 없음).
     *
     * @scenario input=crlf_upper
     *
     * @effects core_attachment_stores_restored_name, core_attachment_download_header_has_no_raw_line_break
     *
     * @return void 반환값 없음
     */
    public function test_restored_line_break_never_reaches_the_header_raw(): void
    {
        $file = UploadedFile::fake()->createWithContent('a%0D%0AX-Injected: 1.pdf', '%PDF-1.4 fixture');

        $attachment = app(AttachmentService::class)->upload($file);

        $this->assertSame("a\r\nX-Injected: 1.pdf", $attachment->original_filename);

        $response = $this->get('/api/attachment/'.$attachment->hash);

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $disposition);
        $this->assertNull($response->headers->get('X-Injected'));
        $this->assertStringContainsString("filename*=utf-8''a%0D%0AX-Injected%3A%201.pdf", $disposition);
    }
}
