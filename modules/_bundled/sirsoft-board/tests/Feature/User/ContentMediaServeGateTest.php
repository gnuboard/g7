<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Services\PostContentMediaService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 본문 미디어 서빙 게이트 (#883 4단계)
 *
 * 서빙 라우트에는 권한 미들웨어가 없다(`<img>`·`<video>` 요청에는 Bearer 가 없다). 서명이 있으면 통과, 없으면 글 읽기 권한 +
 * 삭제·비밀·블라인드 게이트. 임시 행은 서명이 있을 때만. 글 응답(PostResource)은 본문 주소를 서명 주소로 내보낸다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media.yaml
 */
class ContentMediaServeGateTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-serve';
    }

    /**
     * 이 시험 게시판의 기본 속성을 돌려줍니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '본문 미디어 서빙', 'en' => 'Content Media Serve'],
            'is_active' => true,
            'use_file_upload' => false,
            'secret_mode' => 'enabled',
        ];
    }

    /**
     * 권한·쿠키를 준비하고 지난 실행이 남긴 임시 본문 미디어 행을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->withCredentials();
        DB::table('board_attachments')->where('path', 'like', $this->getTestBoardSlug().'/content-media/%')->delete();
    }

    /**
     * 시험이 만든 저장소 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 공개 글: 서명 없이 200, 인라인 이미지·nosniff·private 캐시. 글 상세 응답은 본문 주소를 서명해 내보낸다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=public_post
     *
     * @effects public_served_without_signature, detail_content_signed
     */
    public function test_public_post_media_served_and_detail_signed(): void
    {
        [$hash, $postId] = $this->linkedMedia(isSecret: false);

        $response = $this->get($this->serveUrl($hash));
        $response->assertStatus(200);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $content = (string) $this->getJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts/{$postId}")
            ->assertStatus(200)->json('data.content');
        $this->assertMatchesRegularExpression('#/content-media/'.$hash.'\?expires=\d+&(amp;)?signature=#', $content);
    }

    /**
     * 비밀글: 서명 없으면 403, 서명 있으면 200, 서명 변조·만료는 403.
     *
     * @return void 반환값 없음
     *
     * @scenario case=secret_post
     *
     * @effects secret_requires_signature, tampered_or_expired_denied
     */
    public function test_secret_post_requires_signature(): void
    {
        [$hash] = $this->linkedMedia(isSecret: true);
        $service = app(PostContentMediaService::class);

        $this->get($this->serveUrl($hash))->assertStatus(403);
        $this->get($service->signedUrl($this->getTestBoardSlug(), $hash))->assertStatus(200);

        $tampered = preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $service->signedUrl($this->getTestBoardSlug(), $hash));
        $this->get($tampered)->assertStatus(403);

        $expired = $service->signedUrl($this->getTestBoardSlug(), $hash, now()->subMinute());
        $this->get($expired)->assertStatus(403);
    }

    /**
     * 임시 행(글에 잇기 전): 서명 없으면 403, 서명 있으면 200(편집 중 미리보기).
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_row
     *
     * @effects temp_requires_signature
     */
    public function test_temp_row_requires_signature(): void
    {
        $upload = $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('t.png'),
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201);

        $this->get($this->serveUrl((string) $upload->json('data.hash')))->assertStatus(403);
        $this->get((string) $upload->json('data.url'))->assertStatus(200);
    }

    /**
     * 글 읽기 권한이 없는 비회원(회원 전용 읽기)·삭제글·블라인드글은 서명 없이 403.
     *
     * @return void 반환값 없음
     *
     * @scenario case=read_permission_and_status
     *
     * @effects read_permission_required, deleted_and_blinded_denied
     */
    public function test_read_permission_deleted_and_blinded_gates(): void
    {
        [$hash, $postId] = $this->linkedMedia(isSecret: false);

        $this->setGuestPermissions(['posts.write', 'attachments.upload']);
        $this->get($this->serveUrl($hash))->assertStatus(403);
        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->get($this->serveUrl($hash))->assertStatus(200);

        DB::table('board_posts')->where('id', $postId)->update(['status' => 'blinded']);
        $this->get($this->serveUrl($hash))->assertStatus(403);

        DB::table('board_posts')->where('id', $postId)->update(['status' => 'published', 'deleted_at' => now()]);
        $this->get($this->serveUrl($hash))->assertStatus(403);
    }

    /**
     * 없는 해시·다른 게시판 주소는 404.
     *
     * @return void 반환값 없음
     *
     * @scenario case=not_found
     *
     * @effects unknown_hash_404
     */
    public function test_unknown_hash_is_404(): void
    {
        $this->get($this->serveUrl('AAAAAAAAAAAA'))->assertStatus(404);
    }

    /**
     * 서빙 라우트가 구간 요청을 받는다 — 1바이트 206 · 끝 기준 206 · 범위 밖 416 (#883 5단계 Responder 경유).
     *
     * @return void 반환값 없음
     *
     * @scenario case=public_post
     *
     * @effects public_served_without_signature
     */
    public function test_serve_route_honours_range_requests(): void
    {
        [$hash] = $this->linkedMedia(isSecret: false);
        $url = $this->serveUrl($hash);

        $first = $this->get($url, ['Range' => 'bytes=0-0']);
        $first->assertStatus(206);
        $this->assertMatchesRegularExpression('#^bytes 0-0/\d+$#', (string) $first->headers->get('Content-Range'));
        $this->assertSame('bytes', $first->headers->get('Accept-Ranges'));

        $this->get($url, ['Range' => 'bytes=-4'])->assertStatus(206);
        $this->get($url, ['Range' => 'bytes=99999999-'])->assertStatus(416);
    }

    /**
     * 비회원으로 올려 공개(또는 비밀) 비회원 글에 이은 본문 이미지.
     *
     * @param  bool  $isSecret  비밀글 여부
     * @return array{0: string, 1: int} [해시, 글 ID]
     */
    private function linkedMedia(bool $isSecret): array
    {
        $tempKey = (string) Str::uuid();
        $upload = $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('s.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
        $hash = (string) $upload->json('data.hash');

        $postId = (int) $this->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts", [
            'title' => $isSecret ? '비밀 본문 미디어' : '공개 본문 미디어',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'is_secret' => $isSecret,
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $this->assertSame($postId, (int) Attachment::query()->where('hash', $hash)->firstOrFail()->post_id);

        return [$hash, $postId];
    }

    /**
     * 서명 없는 본문 미디어 서빙 주소를 돌려줍니다.
     *
     * @param  string  $hash  해시
     * @return string 서명 없는 서빙 주소
     */
    private function serveUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media/{$hash}";
    }

    /**
     * 본문 미디어 업로드 주소를 돌려줍니다.
     *
     * @return string 업로드 주소
     */
    private function uploadUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media";
    }
}
