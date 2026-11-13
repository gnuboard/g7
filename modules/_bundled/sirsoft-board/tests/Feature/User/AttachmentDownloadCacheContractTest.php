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
use Modules\Sirsoft\Board\Services\AttachmentService as BoardAttachmentService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 첨부 다운로드 조건부 요청 계약 테스트 (사용자·관리자 두 통로)
 *
 * 미리보기 통로(`AttachmentPreviewCacheContractTest`)는 코어 형제와 같은 캐시 계약을 갖는데
 * 다운로드 두 통로는 `download()` 의 스트림을 **그대로** 돌려줘 검증자가 하나도 없었다 —
 * 같은 첨부가 미리보기로는 304 로 재검증되고 내려받기로는 매번 전문을 받아 갔다. 오류도
 * 로그도 없이 대역폭으로만 나타나고, 링크가 되는 서버의 정적 서빙은 두 통로를 가리지
 * 않으므로 링크 유무로 동작이 갈리는 상태였다.
 *
 * @scenario serving_type=download, contract_axis=conditional_request
 */
class AttachmentDownloadCacheContractTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려준다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'download-cache';
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
            'name' => ['ko' => '다운로드 캐시 계약 테스트', 'en' => 'Download Cache Contract'],
            'is_active' => true,
            'use_file_upload' => true,
            // 비이미지(문서) 축을 재기 위해 텍스트 확장자를 허용한다 — 팩토리 기본 목록에는 없다.
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'txt'],
        ];
    }

    /**
     * 공개 디스크를 가짜로 바꾸고 비회원에게 읽기, 쓰기, 첨부 업로드와 다운로드 권한을 준다.
     *
     * @return void 반환값 없음
     */
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
        // 기존 글에 붙이는 업로드는 그 글을 고칠 수 있는 사람만 — 비회원 글은 검증 토큰을 싣는다(#883)
        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken([
            'title' => '다운로드 캐시 계약',
            'status' => 'published',
        ]);

        $file = UploadedFile::fake()->createWithContent('notes.txt', str_repeat('plain text fixture ', 16));

        $response = $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => $file, 'post_id' => $postId],
            $uploadHeaders
        );

        $response->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * 사용자 다운로드 통로 주소를 만든다.
     *
     * @param  string  $hash  첨부 해시
     * @return string 사용자 다운로드 API 경로
     */
    private function userDownloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/{$hash}";
    }

    /**
     * 관리자 다운로드 통로 주소를 만든다.
     *
     * @param  string  $hash  첨부 해시
     * @return string 관리자 다운로드 API 경로
     */
    private function adminDownloadUrl(string $hash): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/attachments/download/{$hash}";
    }

    /**
     * 304 가 본문 없는 전용 응답이고 200 의 캐시 축을 그대로 싣는지 단언합니다.
     *
     * @param  TestResponse  $full  검증자 없이 받은 200 응답
     * @param  TestResponse  $notModified  조건부 요청으로 받은 304 응답
     * @return void 반환값 없음
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
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_carries_row_meta_validators_and_keeps_attachment_disposition
     *
     * @return void 반환값 없음
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
     * 사용자 다운로드가 200 의 ETag 를 If-None-Match 로 되보내면 본문 없는 304 를 같은 캐시 헤더로 돌려주는지 확인한다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_answers_conditional_requests_with_304_carrying_the_cache_headers_of_200
     *
     * @return void 반환값 없음
     */
    public function test_user_download_answers_if_none_match_with_304(): void
    {
        $row = $this->uploadTextAttachment();

        // `Expires` 는 응답 시각(PHP time())에 max-age 를 더한 값이라 Carbon 시간 고정이 듣지 않는다.
        // 두 요청이 초 경계를 넘으면 같은 계약인데도 1초 차이로 갈린다 — 다음 초가 시작될 때 두 요청을 보낸다.
        time_sleep_until(floor(microtime(true)) + 1);

        $full = $this->get($this->userDownloadUrl($row->hash));
        $full->assertStatus(200);

        $notModified = $this->get($this->userDownloadUrl($row->hash), ['If-None-Match' => (string) $full->headers->get('ETag')]);

        $this->assertNotModifiedMirrors200($full, $notModified);
    }

    /**
     * 사용자 다운로드가 200 의 Last-Modified 를 If-Modified-Since 로 되보내면 304 를 같은 캐시 헤더로 돌려주는지 확인한다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_answers_conditional_requests_with_304_carrying_the_cache_headers_of_200
     *
     * @return void 반환값 없음
     */
    public function test_user_download_answers_if_modified_since_with_304(): void
    {
        $row = $this->uploadTextAttachment();

        // `Expires` 는 응답 시각(PHP time())에 max-age 를 더한 값이라 Carbon 시간 고정이 듣지 않는다.
        // 두 요청이 초 경계를 넘으면 같은 계약인데도 1초 차이로 갈린다 — 다음 초가 시작될 때 두 요청을 보낸다.
        time_sleep_until(floor(microtime(true)) + 1);

        $full = $this->get($this->userDownloadUrl($row->hash));
        $full->assertStatus(200);

        $notModified = $this->get($this->userDownloadUrl($row->hash), ['If-Modified-Since' => (string) $full->headers->get('Last-Modified')]);

        $this->assertNotModifiedMirrors200($full, $notModified);
    }

    /**
     * 현재 ETag 와 다른 검증자를 보내면 304 대신 attachment 방식의 전체 200 을 돌려주는지 확인한다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_returns_full_200_for_a_non_matching_validator
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_declares_compression_skip
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
     */
    public function test_admin_download_shares_the_same_conditional_contract(): void
    {
        $row = $this->uploadTextAttachment();

        $admin = $this->createAdminUser([
            "sirsoft-board.{$this->board->slug}.admin.attachments.download",
        ]);

        // `Expires` 는 응답 시각(PHP time())에 max-age 를 더한 값이라 Carbon 시간 고정이 듣지 않는다.
        // 두 요청이 초 경계를 넘으면 같은 계약인데도 1초 차이로 갈린다 — 다음 초가 시작될 때 두 요청을 보낸다.
        time_sleep_until(floor(microtime(true)) + 1);

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

    /**
     * 관리자 다운로드는 글 상태와 무관하게 공유 캐시에 남기지 않는다 — 관리자 권한을 지난 응답이다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_is_always_private_in_production
     *
     * @return void 반환값 없음
     */
    public function test_admin_download_is_private_in_production(): void
    {
        $row = $this->uploadTextAttachment();
        $admin = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.admin.attachments.download"]);

        $this->app['env'] = 'production';

        $cacheControl = (string) $this->actingAs($admin)->get($this->adminDownloadUrl($row->hash))
            ->assertStatus(200)->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    /**
     * 정상글 첨부의 사용자 다운로드도 운영 환경에서 공유 캐시에 남기지 않는다.
     *
     * 다운로드 권한에서 비회원을 빼도 공개 캐시로 나가면 앞단 CDN 이 저장한 사본을 비회원이
     * 받고, 다운로드 기록 훅도 건너뛴다. 권한이 요청자마다 갈리는 통로라 글 상태와 무관하게 private 이다.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_is_always_private_in_production
     *
     * @return void 반환값 없음
     */
    public function test_user_download_of_a_normal_post_is_private_in_production(): void
    {
        $row = $this->uploadTextAttachment();

        $this->app['env'] = 'production';

        $cacheControl = (string) $this->get($this->userDownloadUrl($row->hash))->assertStatus(200)->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    /**
     * 정상글 이미지 첨부의 미리보기 원본은 종전처럼 공유 캐시 대상이다 — 다운로드만 private 로 바뀐다.
     *
     * @scenario serving_type=preview, contract_axis=conditional_request
     *
     * @effects preview_cache_keeps_the_post_state_gate
     *
     * @return void 반환값 없음
     */
    public function test_preview_of_a_normal_post_image_stays_public_in_production(): void
    {
        [$postId, $uploadHeaders] = $this->createGuestPostWithUploadToken(['title' => '미리보기 캐시 대조', 'status' => 'published']);

        $this->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments",
            ['file' => UploadedFile::fake()->image('photo.jpg', 40, 40), 'post_id' => $postId],
            $uploadHeaders
        )->assertStatus(201);

        $row = DB::table('board_attachments')->orderByDesc('id')->first();
        $this->assertNotNull($row);

        $this->app['env'] = 'production';

        $cacheControl = (string) $this->get($this->userDownloadUrl($row->hash).'/preview')
            ->assertStatus(200)->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl);
    }

    /**
     * 비밀글 첨부의 사용자 다운로드는 공유 캐시에 남기지 않는다 — 다음 방문자가 게이트 없이 받지 않게.
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_is_always_private_in_production
     *
     * @return void 반환값 없음
     */
    public function test_user_download_of_a_secret_post_is_private(): void
    {
        $row = $this->uploadTextAttachment();
        $owner = $this->createAdminUser(["sirsoft-board.{$this->board->slug}.attachments.download"]);
        DB::table('board_posts')->where('id', $row->post_id)->update(['is_secret' => true, 'user_id' => $owner->id]);

        $this->app['env'] = 'production';

        $cacheControl = (string) $this->actingAs($owner)->get($this->userDownloadUrl($row->hash))
            ->assertStatus(200)->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    /**
     * 사용자 다운로드 컨트롤러는 공유 캐시 여부를 서비스(`getDownloadInfo()` 의 `private`)에서 읽는다 — 판정을 컨트롤러 사본으로
     * 따로 두지 않는다. 서비스가 공개로 내라고 하면 공개로 나간다(지금 서비스는 늘 private 이다).
     *
     * @scenario serving_type=download, contract_axis=conditional_request
     *
     * @effects download_cache_scope_read_from_service
     *
     * @return void 반환값 없음
     */
    public function test_user_download_reads_the_cache_scope_from_the_service(): void
    {
        $row = $this->uploadTextAttachment();

        $real = app(BoardAttachmentService::class);
        [$repository, $boards, $storage] = (fn () => [$this->repository, $this->boardRepository, $this->storage])->call($real);

        $this->app->instance(BoardAttachmentService::class, new class($repository, $boards, $storage) extends BoardAttachmentService
        {
            /**
             * 서비스가 공개로 내라고 판정한 경우를 흉내 냅니다.
             *
             * @param  string  $slug  게시판 슬러그
             * @param  int  $id  첨부 ID
             * @param  string  $context  호출 문맥 (admin|user)
             * @return array<string, mixed>|null 서빙 정보 (private false)
             */
            public function getDownloadInfo(string $slug, int $id, string $context = 'admin'): ?array
            {
                $info = parent::getDownloadInfo($slug, $id, $context);

                return $info === null ? null : ['private' => false] + $info;
            }
        });

        $this->app['env'] = 'production';

        $cacheControl = (string) $this->get($this->userDownloadUrl($row->hash))->assertStatus(200)->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('private', $cacheControl);
    }
}
