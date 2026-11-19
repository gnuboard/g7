<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 첨부의 원래 파일 이름 저장 테스트.
 *
 * 브라우저는 파일 이름의 큰따옴표를 `%22` 로 바꿔 보낸다. 그 값이 그대로 저장돼 페이지 수정 화면의 첨부 목록과
 * 내려받은 파일 이름이 `견적서 %22최종%22.pdf` 가 됐다. 관리자 업로드 응답과 저장 이름이 원래 이름이고, 공개
 * 내려받기 `filename*` 에도 원래 이름이 실리는지 실제 HTTP 왕복(가짜 디스크)으로 고정한다.
 */
class PageAttachmentOriginalFilenameTest extends FeatureTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-page/admin/attachments';

    /** 브라우저가 보내는 이름 (큰따옴표가 `%22` 로 바뀐 상태). */
    private const SENT_NAME = '견적서 %22최종%22 \'사본\'.pdf';

    /** 사용자가 고른 원래 이름. */
    private const ORIGINAL_NAME = '견적서 "최종" \'사본\'.pdf';

    protected User $adminUser;

    /**
     * 모듈 디스크를 가짜로 바꾸고 페이지 관리자와 PDF 를 허용하는 첨부 설정을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
        ]);

        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 5,
            'max_size_mb' => 10,
            'allowed_types' => ['application/pdf'],
        ]]);
    }

    /**
     * 이 테스트가 만든 페이지를 강제 삭제한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'orig-name-%')->forceDelete();
        parent::tearDown();
    }

    /**
     * 따옴표 이름 업로드는 응답과 저장 행 모두 원래 이름이고, 공개 내려받기 이름도 원래 이름이다.
     *
     * @scenario download_route=public
     *
     * @effects page_upload_response_carries_original_name, page_attachment_stores_original_name, page_download_declares_original_name
     *
     * @return void 반환값 없음
     */
    public function test_quoted_name_is_stored_and_downloaded_as_the_original(): void
    {
        $page = Page::factory()->state([
            'slug' => 'orig-name-'.uniqid(),
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ])->published()->create();

        $file = UploadedFile::fake()->createWithContent(self::SENT_NAME, "%PDF-1.4\n%fixture\n");

        $response = $this->actingAs($this->adminUser)->postJson(self::ENDPOINT, [
            'file' => $file,
            'page_id' => $page->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(self::ORIGINAL_NAME, $response->json('data.data.original_filename'));

        $attachment = PageAttachment::where('page_id', $page->id)->latest('id')->first();
        $this->assertNotNull($attachment);
        $this->assertSame(self::ORIGINAL_NAME, $attachment->original_filename);

        $download = $this->get('/api/modules/sirsoft-page/pages/attachment/'.$attachment->hash);

        $download->assertOk();
        $disposition = (string) $download->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode(self::ORIGINAL_NAME), $disposition);
        $this->assertStringNotContainsString('%2522', $disposition, '내려받은 이름에 브라우저 인코딩(%22)이 남았다');
    }
}
