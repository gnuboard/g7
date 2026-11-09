<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 본문 미디어 업로드 (#883 4단계)
 *
 * 업로드는 그 게시판 `attachments.upload` 권한과 본문 미디어 정책으로만 판정한다 — 「파일 첨부 사용」이 꺼져 있어도 올라간다(D26).
 * 파일은 처음부터 최종 경로(`{slug}/content-media/…`)에 두고 행은 임시(board_id=0 + 임시 키)다. 응답 주소는 서명 주소다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media.yaml
 */
class ContentMediaUploadTest extends BoardTestCase
{
    private const COOKIE = 'g7_board_guest_upload';

    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-upload';
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
            'name' => ['ko' => '본문 미디어 업로드', 'en' => 'Content Media Upload'],
            'is_active' => true,
            // 첨부가 꺼져 있어도 본문 이미지는 올라간다 (D26)
            'use_file_upload' => false,
            'max_file_size' => 5,
            'allowed_extensions' => ['pdf'],
            'content_media_policy' => null,
        ];
    }

    /**
     * 권한·쿠키 준비, 지난 실행의 임시 본문 미디어 정리.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->withCredentials();
        DB::table('board_attachments')->where('path', 'like', $this->getTestBoardSlug().'/content-media/%')->delete();
    }

    /**
     * 저장소 파일 정리.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 비회원이 첨부가 꺼진 게시판에 본문 이미지를 올린다 — 201, 서명 주소, 소유 쿠키, 임시 행, 최종 경로.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_image_upload
     *
     * @effects temp_row_created, signed_url_returned, guest_cookie_issued, final_path_used
     */
    public function test_guest_uploads_image_even_when_file_upload_disabled(): void
    {
        $tempKey = (string) Str::uuid();

        $response = $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('photo.png', 40, 30),
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $url = (string) $response->json('data.url');
        $hash = (string) $response->json('data.hash');
        $this->assertSame('image', $response->json('data.kind'));
        $this->assertStringStartsWith("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/content-media/{$hash}?", $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertNotNull(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === self::COOKIE));

        $row = Attachment::query()->where('hash', $hash)->firstOrFail();
        $this->assertSame(0, $row->board_id);
        $this->assertNull($row->post_id);
        $this->assertSame($tempKey, $row->temp_key);
        $this->assertSame(Attachment::COLLECTION_CONTENT_MEDIA, $row->collection);
        $this->assertStringStartsWith($this->getTestBoardSlug().'/content-media/', $row->path);
        $this->assertNotNull($row->guest_owner_hash);
        $this->assertSame(40, $row->meta['width'] ?? null);
    }

    /**
     * 정책 밖 파일은 422 — 첨부 확장자(pdf)는 본문 미디어가 아니고, 동영상은 대체 정책에서 꺼져 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=policy_violation
     *
     * @effects rejected_outside_policy
     */
    public function test_files_outside_policy_are_rejected(): void
    {
        $tempKey = (string) Str::uuid();

        $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            'temp_key' => $tempKey,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->createWithContent('clip.mp4', self::mp4Bytes()),
            'temp_key' => $tempKey,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // 이름만 png 인 HTML — 내용 추론으로 거부
        $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->createWithContent('evil.png', '<html><script>alert(1)</script></html>'),
            'temp_key' => $tempKey,
        ])->assertStatus(422);

        // 파일당 크기(대체 정책 = 첨부 파일당 5MB)
        $this->updateBoardSettings(['content_media_policy' => ['image' => ['enabled' => true, 'max_size_mb' => 1, 'extensions' => ['png']]]]);
        $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('big.png')->size(1500),
            'temp_key' => $tempKey,
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Attachment::query()->where('temp_key', $tempKey)->count());
    }

    /**
     * 게시판 정책이 동영상을 켜면 동영상도 받는다(코어 「본문 콘텐츠」 후보가 켜졌을 때).
     *
     * @return void 반환값 없음
     *
     * @scenario case=video_enabled
     *
     * @effects video_accepted_when_policy_and_core_allow
     */
    public function test_video_is_accepted_when_board_and_core_allow(): void
    {
        config(['g7_settings.core.content.allow_uploaded_video' => true]);
        $this->updateBoardSettings(['content_media_policy' => [
            'video' => ['enabled' => true, 'max_size_mb' => 2, 'extensions' => ['mp4']],
        ]]);

        $response = $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->createWithContent('clip.mp4', self::mp4Bytes()),
            'temp_key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(201);
        $this->assertSame('video', $response->json('data.kind'));
    }

    /**
     * 업로드 권한이 없으면 403, 임시 키가 없으면 422, 남의 글 번호면 403.
     *
     * @return void 반환값 없음
     *
     * @scenario case=gates
     *
     * @effects permission_required, temp_key_required, post_id_gate
     */
    public function test_permission_temp_key_and_post_gate(): void
    {
        $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('a.png'),
        ])->assertStatus(422)->assertJsonValidationErrors('temp_key');

        $owner = $this->member('cm-owner@test.com');
        $postId = $this->createTestPost(['user_id' => $owner->id, 'author_name' => 'owner']);

        $other = $this->member('cm-other@test.com');
        $this->actingAs($other, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('a.png'),
            'temp_key' => (string) Str::uuid(),
            'post_id' => $postId,
        ])->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->setGuestPermissions(['posts.read']);
        $status = $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('a.png'),
            'temp_key' => (string) Str::uuid(),
        ])->status();
        // 비회원 권한 거부는 권한 미들웨어 규약대로 401(로그인 필요) 또는 403
        $this->assertContains($status, [401, 403]);
    }

    /**
     * 업로드 시점 상한 — 같은 임시 키로 문서당 최대 × 2 를 넘기면 422.
     *
     * @return void 반환값 없음
     *
     * @scenario case=upload_headroom
     *
     * @effects upload_headroom_enforced
     */
    public function test_upload_headroom_limit(): void
    {
        $this->updateBoardSettings(['content_media_policy' => ['max_per_document' => 1]]);
        $tempKey = (string) Str::uuid();

        foreach ([201, 201, 422] as $status) {
            $this->postJson($this->uploadUrl(), [
                'file' => UploadedFile::fake()->image('a.png'),
                'temp_key' => $tempKey,
            ])->assertStatus($status);
        }
    }

    /**
     * 최소 mp4(ftyp 상자) 바이트 — finfo 가 video/mp4 로 본다.
     *
     * @return string 바이트
     */
    public static function mp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64);
    }

    /**
     * 사용자 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 사용자 역할 회원
     */
    private function member(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        return $user;
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
