<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\NotModifiedResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 첨부 다운로드 조건부 요청 계약 테스트 (사용자·관리자 두 통로)
 *
 * 미리보기 통로(`AttachmentPreviewCacheContractTest`)는 코어 형제와 같은 캐시 계약을 갖는데
 * 다운로드 두 통로는 `download()` 의 스트림을 **그대로** 돌려줘 검증자가 하나도 없었다 —
 * 같은 첨부가 미리보기로는 304 로 재검증되고 내려받기로는 매번 전문을 받아 갔다. 오류도
 * 로그도 없이 대역폭으로만 나타나고, 링크가 되는 서버의 정적 서빙은 두 통로를 가리지
 * 않으므로 링크 유무로 동작이 갈리는 상태였다 (14차 G3 라운드).
 *
 * @scenario serving_type=download, contract_axis=conditional_request
 */
class AttachmentDownloadCacheContractTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'download-cache';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '다운로드 캐시 계약 테스트', 'en' => 'Download Cache Contract'],
            'is_active' => true,
            'use_file_upload' => true,
            // 비이미지(문서) 축을 재기 위해 텍스트 확장자를 허용한다 — 팩토리 기본 목록에는 없다.
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'txt'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload', 'attachments.download']);
    }

    /**
     * 텍스트 첨부를 업로드하고 그 행을 돌려줍니다.
     *
     * @return object 업로드된 첨부 행
     */
    private function uploadTextAttachment(): object
    {
        $postId = $this->createTestPost([
            'title' => '다운로드 캐시 계약',
            'status' => 'published',
        ]);

        $file = UploadedFile::fake()->createWithContent('notes.txt', str_repeat('plain text fixture ', 16));

        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => $file, 'post_id' => $postId]
        );

        $response->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function userDownloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}";
    }

    private function adminDownloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/attachments/download/{$hash}";
    }

    /**
     * 304 가 본문 없는 전용 응답이고 200 의 캐시 축을 그대로 싣는지 단언합니다.
     */
    private function assertNotModifiedMirrors200(TestResponse $full, TestResponse $notModified): void
    {
        $notModified->assertStatus(304);
        $this->assertInstanceOf(NotModifiedResponse::class, $notModified->baseResponse);
        $this->assertSame('', (string) $notModified->baseResponse->getContent(), '304 에 본문이 실렸다');

        foreach (['ETag', 'Last-Modified', 'Cache-Control', 'Expires', 'Vary'] as $header) {
            $this->assertNotNull($full->headers->get($header), "200 이 {$header} 를 싣지 않는다");
            $this->assertSame(
                $full->headers->get($header),
                $notModified->headers->get($header),
                "304 가 200 과 다른 {$header} 를 광고한다 (RFC 9110 §15.4.5)"
            );
        }
    }

    /**
     * 사용자 다운로드는 행 메타 기준 검증자를 싣고 다운로드 방식(attachment)을 유지한다.
     */
    public function test_user_download_carries_validators_and_keeps_attachment_disposition(): void
    {
        $row = $this->uploadTextAttachment();

        $response = $this->get($this->userDownloadUrl($row->hash));

        $response->assertStatus(200);
        $this->assertStringStartsWith(
            'attachment;',
            (string) $response->headers->get('Content-Disposition'),
            '다운로드 통로는 attachment 방식이어야 한다 — 캐시 계약을 입히며 inline 이 되면 파일이 화면에 열린다'
        );
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $response->headers->get('ETag'));
        $this->assertSame(
            gmdate('D, d M Y H:i:s', strtotime($row->updated_at)).' GMT',
            $response->headers->get('Last-Modified'),
            '다운로드 응답은 행 메타(updated_at) 기준 Last-Modified 를 내보내야 한다'
        );
        $this->assertNotNull($response->headers->get('Expires'));
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @effects download_answers_conditional_requests_with_304_carrying_the_cache_headers_of_200
     */
    public function test_user_download_answers_if_none_match_with_304(): void
    {
        $row = $this->uploadTextAttachment();

        $full = $this->get($this->userDownloadUrl($row->hash));
        $full->assertStatus(200);

        $notModified = $this->get($this->userDownloadUrl($row->hash), ['If-None-Match' => (string) $full->headers->get('ETag')]);

        $this->assertNotModifiedMirrors200($full, $notModified);
    }

    /**
     * @effects download_answers_conditional_requests_with_304_carrying_the_cache_headers_of_200
     */
    public function test_user_download_answers_if_modified_since_with_304(): void
    {
        $row = $this->uploadTextAttachment();

        $full = $this->get($this->userDownloadUrl($row->hash));
        $full->assertStatus(200);

        $notModified = $this->get($this->userDownloadUrl($row->hash), ['If-Modified-Since' => (string) $full->headers->get('Last-Modified')]);

        $this->assertNotModifiedMirrors200($full, $notModified);
    }

    public function test_user_download_returns_full_200_for_a_non_matching_validator(): void
    {
        $row = $this->uploadTextAttachment();

        $stale = $this->get($this->userDownloadUrl($row->hash), ['If-None-Match' => '"not-the-current-tag"']);

        $stale->assertStatus(200);
        $this->assertStringStartsWith('attachment;', (string) $stale->headers->get('Content-Disposition'));
    }

    /**
     * 사용자 업로드물 통로는 압축 제외를 **선언**한다 — 텍스트 첨부는 압축 대상 유형이라 선언이
     * 없으면 미들웨어가 응답 클래스를 보고 건너뛰는 우연에만 기대게 된다.
     */
    public function test_user_download_declares_compression_skip(): void
    {
        $row = $this->uploadTextAttachment();

        $this->get($this->userDownloadUrl($row->hash), ['Accept-Encoding' => 'gzip'])->assertStatus(200);

        $this->assertTrue($this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE));
    }

    /**
     * 관리자 다운로드 통로도 같은 계약을 갖는다 — 한쪽만 고치면 그 비대칭이 다음 사각이 된다.
     *
     * @effects download_answers_conditional_requests_with_304_carrying_the_cache_headers_of_200
     */
    public function test_admin_download_shares_the_same_conditional_contract(): void
    {
        $row = $this->uploadTextAttachment();

        $admin = $this->createAdminUser([
            "sirsoft-board.{$this->board->slug}.admin.attachments.download",
        ]);

        $full = $this->actingAs($admin)->get($this->adminDownloadUrl($row->hash));
        $full->assertStatus(200);
        $this->assertStringStartsWith('attachment;', (string) $full->headers->get('Content-Disposition'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $full->headers->get('ETag'));

        $notModified = $this->actingAs($admin)->get(
            $this->adminDownloadUrl($row->hash),
            ['If-None-Match' => (string) $full->headers->get('ETag')]
        );

        $this->assertNotModifiedMirrors200($full, $notModified);
    }
}
