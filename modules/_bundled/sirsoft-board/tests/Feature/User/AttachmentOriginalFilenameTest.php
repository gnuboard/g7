<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 첨부의 원래 파일 이름 저장 테스트.
 *
 * 브라우저는 파일 이름의 큰따옴표를 `%22` 로 바꿔 보낸다. 그 값이 그대로 저장돼 첨부 목록과 내려받은 파일
 * 이름이 `보고서 %22최종%22.txt` 가 됐다. 업로드 응답과 저장 이름이 원래 이름이고, 회원과 관리자 내려받기
 * `filename*` 에도 원래 이름이 실리는지 실제 HTTP 왕복으로 고정한다.
 */
class AttachmentOriginalFilenameTest extends BoardTestCase
{
    /** 브라우저가 보내는 이름 (큰따옴표가 `%22` 로 바뀐 상태). */
    private const SENT_NAME = '보고서 %22최종%22 \'사본\'.txt';

    /** 사용자가 고른 원래 이름. */
    private const ORIGINAL_NAME = '보고서 "최종" \'사본\'.txt';

    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려준다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'original-filename';
    }

    /**
     * 첨부 업로드를 켜고 텍스트 확장자까지 허용한 게시판 속성을 돌려준다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 생성 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '원래 파일 이름 테스트', 'en' => 'Original Filename'],
            'is_active' => true,
            'use_file_upload' => true,
            'allowed_extensions' => ['jpg', 'png', 'pdf', 'txt'],
        ];
    }

    /**
     * 첨부 디스크를 가짜로 바꾸고 비회원에게 읽기, 쓰기, 첨부 업로드와 다운로드 권한을 준다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('modules');
        Storage::fake('public');

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload', 'attachments.download']);
    }

    /**
     * 브라우저가 보낸 이름으로 텍스트 첨부를 올리고 응답과 저장 행을 돌려준다.
     *
     * @return array{0: TestResponse, 1: object} 업로드 응답과 첨부 행
     */
    private function uploadQuotedAttachment(): array
    {
        // 기존 글에 붙이는 업로드는 그 글을 고칠 수 있는 사람만 — 비회원 글은 검증 토큰을 싣는다(#883)
        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken(['title' => '원래 파일 이름', 'status' => 'published']);

        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => UploadedFile::fake()->createWithContent(self::SENT_NAME, 'plain text fixture'), 'post_id' => $postId],
            $uploadHeaders
        );

        $response->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        return [$response, $row];
    }

    /**
     * 내려받기 머리글의 `filename*` 가 원래 이름인지 단언한다.
     *
     * @param  string|null  $disposition  `Content-Disposition` 값
     * @return void 반환값 없음
     */
    private function assertDeclaresOriginalName(?string $disposition): void
    {
        $this->assertStringStartsWith('attachment;', (string) $disposition);
        $this->assertStringContainsString("filename*=utf-8''".rawurlencode(self::ORIGINAL_NAME), (string) $disposition);
        $this->assertStringNotContainsString('%2522', (string) $disposition, '내려받은 이름에 브라우저 인코딩(%22)이 남았다');
    }

    /**
     * 따옴표 이름 업로드는 응답과 저장 행 모두 원래 이름이고, 회원 내려받기 이름도 원래 이름이다.
     *
     * @scenario download_route=user
     *
     * @effects board_upload_response_carries_original_name, board_attachment_stores_original_name, board_download_declares_original_name
     *
     * @return void 반환값 없음
     */
    public function test_quoted_name_is_stored_and_downloaded_as_the_original(): void
    {
        [$response, $row] = $this->uploadQuotedAttachment();

        $this->assertSame(self::ORIGINAL_NAME, $response->json('data.data.original_filename'));
        $this->assertSame(self::ORIGINAL_NAME, $row->original_filename);

        $download = $this->get("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$row->hash}");

        $download->assertStatus(200);
        $this->assertDeclaresOriginalName($download->headers->get('Content-Disposition'));
    }

    /**
     * 관리자 내려받기도 같은 원래 이름을 선언한다.
     *
     * @scenario download_route=admin
     *
     * @effects board_download_declares_original_name
     *
     * @return void 반환값 없음
     */
    public function test_admin_download_declares_the_original_name(): void
    {
        [, $row] = $this->uploadQuotedAttachment();

        $admin = $this->createAdminUser([
            "sirsoft-board.{$this->board->slug}.admin.attachments.download",
        ]);

        $download = $this->actingAs($admin)
            ->get("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/attachments/download/{$row->hash}");

        $download->assertStatus(200);
        $this->assertDeclaresOriginalName($download->headers->get('Content-Disposition'));
    }
}
