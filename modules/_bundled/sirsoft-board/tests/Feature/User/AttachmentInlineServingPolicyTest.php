<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 첨부 서빙 인라인 정책 회귀 테스트 (KVE-2026-2366 전수 T-B)
 *
 * 게시판 업로드는 `mimes:` 로 내용 검증을 하지만, 운영자가 허용 확장자에 svg 를 추가하면
 * SVG 가 저장되고 미리보기(`image/` 접두사 판정 → Content-Disposition: inline)가 인라인 sink 가 된다.
 * 서빙 계층은 업로드 설정과 무관하게 래스터 이미지만 인라인으로 내보내야 한다.
 */
class AttachmentInlineServingPolicyTest extends BoardTestCase
{
    /**
     * 테스트 게시판 slug
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'kve-inline-serving';
    }

    /**
     * 저장된 SVG 첨부는 미리보기 대상이 아니다 (이미지 아님 400).
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=board_attachment
     *
     * @effects svg_is_not_treated_as_inline_image
     *
     * @return void 반환값 없음
     */
    public function test_svg_attachment_preview_is_refused(): void
    {
        $postId = $this->createTestPost();
        $this->createAttachment($postId, 'kvesvgprevAA', 'evil.png', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>');

        $response = $this->get($this->previewUrl('kvesvgprevAA'));

        $response->assertStatus(400);
    }

    /**
     * 래스터 이미지 미리보기는 인라인을 유지하고 nosniff 가 붙는다 (회귀 가드).
     *
     * @scenario entry_point=serving, content=real_png, surface=board_attachment
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_raster_preview_stays_inline_with_nosniff(): void
    {
        $postId = $this->createTestPost();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->createAttachment($postId, 'kvepngprevAA', 'real.png', 'image/png', $png);

        $response = $this->get($this->previewUrl('kvepngprevAA'));

        $response->assertStatus(200);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * 서비스 미리보기 스트림도 SVG 는 내보내지 않는다 (서비스 계층 게이트).
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=board_attachment
     *
     * @effects svg_is_not_treated_as_inline_image
     *
     * @return void 반환값 없음
     */
    public function test_service_preview_refuses_svg(): void
    {
        $postId = $this->createTestPost();
        $id = $this->createAttachment($postId, 'kvesvgservAA', 'evil.png', 'image/svg+xml', '<svg/>');

        $this->assertNull(app(AttachmentService::class)->preview($this->board->slug, $id));
    }

    /**
     * 서비스 다운로드 응답은 SVG 를 다운로드로 강제하고 nosniff·CSP 를 붙인다.
     *
     * 사용자·관리자 다운로드 라우트가 모두 이 서비스 메서드를 거친다.
     *
     * @scenario entry_point=serving, content=svg_as_png, surface=board_attachment
     *
     * @effects svg_served_as_attachment_with_nosniff_and_csp
     *
     * @return void 반환값 없음
     */
    public function test_service_download_hardens_svg(): void
    {
        $this->actingAs(User::factory()->create(['is_super' => true, 'status' => 'active']));
        $postId = $this->createTestPost();
        $id = $this->createAttachment($postId, 'kvesvgdownAA', 'evil.png', 'image/svg+xml', '<svg/>');

        $response = app(AttachmentService::class)->download($this->board->slug, $id);

        $this->assertNotNull($response);
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    /**
     * 서비스 미리보기 스트림은 래스터 이미지를 인라인으로 내보내며 nosniff 를 붙인다.
     *
     * @scenario entry_point=serving, content=real_png, surface=board_attachment
     *
     * @effects raster_image_stays_inline_with_nosniff
     *
     * @return void 반환값 없음
     */
    public function test_service_preview_hardens_raster(): void
    {
        $postId = $this->createTestPost();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $id = $this->createAttachment($postId, 'kvepngservAA', 'real.png', 'image/png', $png);

        $response = app(AttachmentService::class)->preview($this->board->slug, $id);

        $this->assertNotNull($response);
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * 첨부 행과 실제 파일을 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $hash  첨부 해시 (12자)
     * @param  string  $name  원본 파일명
     * @param  string  $mime  저장 MIME
     * @param  string  $content  파일 내용
     * @return int 첨부 ID
     */
    private function createAttachment(int $postId, string $hash, string $name, string $mime, string $content): int
    {
        $path = $this->board->slug.'/kve/'.$hash.'.bin';
        $this->boardStorage()->put('attachments', $path, $content);

        return DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => $name,
            'stored_filename' => $hash.'.bin',
            'hash' => $hash,
            'mime_type' => $mime,
            'size' => strlen($content),
            'path' => $path,
            'collection' => 'attachments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 게시판 첨부 서비스가 쓰는 모듈 스토리지 드라이버를 꺼냅니다.
     *
     * @return StorageInterface 모듈 스토리지
     */
    private function boardStorage(): StorageInterface
    {
        $service = app(AttachmentService::class);
        $property = (new \ReflectionClass($service))->getProperty('storage');
        $property->setAccessible(true);

        return $property->getValue($service);
    }

    /**
     * 미리보기 URL 을 반환합니다.
     *
     * @param  string  $hash  첨부 해시
     * @return string URL 반환값
     */
    private function previewUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}/preview";
    }

    /**
     * 테스트가 만든 파일을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->boardStorage()->deleteDirectory('attachments', $this->board->slug.'/kve');

        parent::tearDown();
    }
}
