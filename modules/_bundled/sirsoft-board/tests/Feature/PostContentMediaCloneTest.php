<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 다른 글의 본문 미디어를 쓴 글 저장 — 새 글 소유로 복제 (#887)
 *
 * 종전에는 글 A 에 올린 이미지 주소를 글 B(답글·복사·붙여넣기)가 쓰고 저장하면 B 에 행이 생기지 않아, A 가 미디어를 빼거나
 * 지워지면 B 의 이미지가 404 로 깨졌다. 이제 저장하는 사람이 A 의 미디어를 볼 수 있으면 파일을 복사해 B 소유의 새 해시로
 * 바꿔 저장한다. 볼 수 없으면(남의 비밀글) 복제하지 않고 주소를 그대로 두며, B 의 응답은 그 해시를 서명하지 않는다.
 *
 * 복제 범위는 게시판 전체다 — 다른 게시판 글의 미디어도 그 게시판의 글 읽기 권한(또는 관리 권한)과 콘텐츠 게이트를 통과하면
 * 이 게시판 주소의 사본으로 바꾼다. 다른 임시 키로 올린 임시 미디어는 회원이면 본인 업로드, 비회원이면 업로드 소유 쿠키가
 * 맞을 때만 복제한다. 복제 재저장이 있어도 글 저장은 한 번으로 기록된다(after_* 1회·활동 로그 1건).
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media-clone.yaml
 */
class PostContentMediaCloneTest extends BoardTestCase
{
    /** 복제 원본을 두는 다른 게시판 슬러그 */
    private const OTHER_BOARD = 'content-media-clone-src';

    /** 비회원 업로드 소유 쿠키 이름 */
    private const COOKIE = 'g7_board_guest_upload';

    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-clone';
    }

    /**
     * 이 시험 게시판의 기본 속성을 돌려줍니다 — 비밀글 사용, 문서당 최대 2.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '본문 미디어 복제', 'en' => 'Content Media Clone'],
            'is_active' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
            'content_media_policy' => ['max_per_document' => 2],
        ];
    }

    /**
     * 권한·쿠키를 준비하고 지난 실행이 남긴 본문 미디어 행을 지웁니다.
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
        DB::table('board_attachments')->where('path', 'like', self::OTHER_BOARD.'/content-media/%')->delete();
    }

    /**
     * 시험이 만든 저장소 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.self::OTHER_BOARD);

        parent::tearDown();
    }

    /**
     * 실측 재현 — 원글의 미디어를 쓴 글은 자기 사본을 갖고, 원글이 미디어를 빼도 그 글의 이미지는 200 이다.
     * 같은 미디어를 두 번 써도 사본은 하나다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=readable_source
     *
     * @effects copied_to_new_owner, content_rewritten, duplicate_ref_single_copy, survives_source_removal
     */
    public function test_reused_media_is_copied_and_survives_source_removal(): void
    {
        $owner = $this->member('clone-owner@test.com');
        [$sourcePostId, $sourceHash] = $this->postWithMedia($owner, isSecret: false);

        $replier = $this->member('clone-replier@test.com');
        $reuse = '<p><img src="'.$this->serveUrl($sourceHash).'"></p>';
        $postId = (int) $this->actingAs($replier, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '원글 이미지를 쓴 글',
            'content' => $reuse.$reuse,
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201)->json('data.id');

        $content = (string) Post::query()->findOrFail($postId)->content;
        $this->assertStringNotContainsString($sourceHash, $content, '원글 해시를 그대로 저장하지 않는다');

        $copies = Attachment::query()->where('post_id', $postId)->where('collection', Attachment::COLLECTION_CONTENT_MEDIA)->get();
        $this->assertCount(1, $copies, '같은 미디어를 두 번 써도 사본은 하나');
        $copy = $copies->first();
        $this->assertNotSame($sourceHash, $copy->hash);
        $this->assertSame(2, substr_count($content, '/content-media/'.$copy->hash.'"'), '두 자리 모두 새 주소');
        $this->assertSame((int) $replier->id, (int) $copy->created_by);
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$copy->path), '파일도 복사된다');
        $this->assertSame($sourcePostId, (int) Attachment::query()->where('hash', $sourceHash)->firstOrFail()->post_id, '원본 행은 원글 소유 그대로');

        // 원글에서 미디어를 뺀다 — 종전에는 이 뒤 사본 쪽 주소가 404 였다
        $this->actingAs($owner, 'sanctum')->putJson("{$this->postsUrl()}/{$sourcePostId}", [
            'title' => '원글',
            'content' => '<p>미디어 없음</p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(200);
        $this->assertSoftDeleted('board_attachments', ['hash' => $sourceHash]);

        $this->app['auth']->forgetGuards();
        $this->get($this->serveUrl($copy->hash))->assertStatus(200);
        $this->get($this->serveUrl($sourceHash))->assertStatus(404);
    }

    /**
     * 볼 수 없는 원본(남의 비밀글 미디어)은 복제하지 않고 주소를 그대로 둔다. 그 글의 응답은 그 해시를 서명하지 않아
     * 서빙은 원본 비밀글 게이트(403)를 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=unreadable_source
     *
     * @effects unreadable_not_copied, foreign_hash_not_signed
     */
    public function test_unreadable_secret_source_is_not_copied_nor_signed(): void
    {
        $owner = $this->member('clone-secret-owner@test.com');
        [, $secretHash] = $this->postWithMedia($owner, isSecret: true);

        $other = $this->member('clone-secret-other@test.com');
        $postId = (int) $this->actingAs($other, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '남의 비밀글 해시',
            'content' => '<p><img src="'.$this->serveUrl($secretHash).'"></p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201)->json('data.id');

        $this->assertStringContainsString('/content-media/'.$secretHash.'"', (string) Post::query()->findOrFail($postId)->content, '주소는 그대로');
        $this->assertSame(0, Attachment::query()->where('post_id', $postId)->count(), '복제하지 않는다');

        $this->app['auth']->forgetGuards();
        $detail = (string) $this->getJson("{$this->postsUrl()}/{$postId}")->assertStatus(200)->json('data.content');
        $this->assertStringContainsString('/content-media/'.$secretHash, $detail);
        $this->assertStringNotContainsString('signature=', $detail, '이 글 소유가 아닌 해시는 서명하지 않는다');
        $this->get($this->serveUrl($secretHash))->assertStatus(403);
    }

    /**
     * 복제할 미디어도 문서당 최대 개수(2)에 든다 — 넘으면 422 이고 사본 행·글이 남지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=limit
     *
     * @effects clones_counted_in_limit
     */
    public function test_clones_count_toward_per_document_limit(): void
    {
        $owner = $this->member('clone-limit-owner@test.com');
        [, $hashA] = $this->postWithMedia($owner, isSecret: false);
        [, $hashB] = $this->postWithMedia($owner, isSecret: false);

        $writer = $this->member('clone-limit-writer@test.com');
        $tempKey = (string) Str::uuid();
        $own = $this->actingAs($writer, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('own.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $rowsBefore = Attachment::query()->where('collection', Attachment::COLLECTION_CONTENT_MEDIA)->count();
        $postsBefore = Post::query()->where('board_id', $this->board->id)->count();

        $response = $this->actingAs($writer, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '한도 초과',
            'content' => '<p><img src="'.$this->serveUrl($hashA).'"><img src="'.$this->serveUrl($hashB).'"><img src="'.$own->json('data.url').'"></p>',
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ]);

        $response->assertStatus(422);
        $this->assertSame('content_media_limit_exceeded', $response->json('errors.code'));
        $this->assertSame($postsBefore, Post::query()->where('board_id', $this->board->id)->count());
        $this->assertSame($rowsBefore, Attachment::query()->where('collection', Attachment::COLLECTION_CONTENT_MEDIA)->count());
    }

    /**
     * 이미 이 글 소유인 미디어는 다시 저장해도 복제하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=already_owned
     *
     * @effects owned_not_copied
     */
    public function test_owned_media_is_not_copied_on_resave(): void
    {
        $owner = $this->member('clone-resave@test.com');
        [$postId, $hash] = $this->postWithMedia($owner, isSecret: false);
        $ref = '<p><img src="'.$this->serveUrl($hash).'"></p>';

        $this->actingAs($owner, 'sanctum')->putJson("{$this->postsUrl()}/{$postId}", [
            'title' => '다시 저장',
            'content' => $ref.$ref,
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(200);

        $this->assertSame([$hash], Attachment::query()->where('post_id', $postId)->pluck('hash')->all());
        $this->assertSame(2, substr_count((string) Post::query()->findOrFail($postId)->content, '/content-media/'.$hash.'"'), '주소도 그대로');
    }

    /**
     * 다른 게시판 글의 미디어도 그 게시판을 읽을 수 있으면 이 게시판 소유 사본으로 복제하고, 본문 주소를 이 게시판 주소로 바꾼다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_board_readable
     *
     * @effects other_board_copied_into_this_board, content_rewritten_to_this_board
     */
    public function test_media_from_another_readable_board_is_copied_into_this_board(): void
    {
        $owner = $this->member('clone-xb-owner@test.com');
        [$sourcePostId, $sourceHash] = $this->otherBoardMedia($owner, readable: true);

        $writer = $this->member('clone-xb-writer@test.com');
        $postId = (int) $this->actingAs($writer, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '다른 게시판 이미지를 쓴 글',
            'content' => '<p><img src="'.$this->otherServeUrl($sourceHash).'"></p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201)->json('data.id');

        $copy = Attachment::query()->where('post_id', $postId)->where('collection', Attachment::COLLECTION_CONTENT_MEDIA)->sole();
        $content = (string) Post::query()->findOrFail($postId)->content;

        $this->assertNotSame($sourceHash, $copy->hash);
        $this->assertSame((int) $this->board->id, (int) $copy->board_id, '사본은 이 게시판 소유');
        $this->assertStringStartsWith($this->getTestBoardSlug().'/content-media/', $copy->path, '사본 파일은 이 게시판 경로');
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$copy->path));
        $this->assertStringContainsString($this->serveUrl($copy->hash).'"', $content, '본문 주소가 이 게시판 사본 주소로 바뀐다');
        $this->assertStringNotContainsString(self::OTHER_BOARD, $content);

        $source = Attachment::query()->where('hash', $sourceHash)->sole();
        $this->assertSame($sourcePostId, (int) $source->post_id, '원본 행은 다른 게시판 글 소유 그대로');

        $this->app['auth']->forgetGuards();
        $this->get($this->serveUrl($copy->hash))->assertStatus(200);
    }

    /**
     * 원본 게시판의 글 읽기 권한이 없으면 복제하지 않고 다른 게시판 주소를 그대로 둔다(그 게시판 게이트를 따른다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=other_board_unreadable
     *
     * @effects other_board_unreadable_not_copied
     */
    public function test_media_from_another_board_without_read_permission_is_not_copied(): void
    {
        $owner = $this->member('clone-xb-hidden-owner@test.com');
        [, $sourceHash] = $this->otherBoardMedia($owner, readable: false);

        $writer = $this->member('clone-xb-hidden-writer@test.com');
        $postId = (int) $this->actingAs($writer, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '읽을 수 없는 게시판 이미지',
            'content' => '<p><img src="'.$this->otherServeUrl($sourceHash).'"></p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201)->json('data.id');

        $this->assertSame(0, Attachment::query()->where('post_id', $postId)->count(), '복제하지 않는다');
        $this->assertStringContainsString(
            $this->otherServeUrl($sourceHash).'"',
            (string) Post::query()->findOrFail($postId)->content,
            '다른 게시판 주소를 그대로 둔다'
        );
    }

    /**
     * 비회원이 다른 임시 키로 올린 자기 임시 미디어는 업로드 소유 쿠키가 맞으면 이 글 소유로 복제한다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_cookie_match
     *
     * @effects guest_temp_copied_with_matching_cookie
     */
    public function test_guest_temp_media_from_another_temp_key_is_copied_with_matching_cookie(): void
    {
        $upload = $this->guestUpload((string) Str::uuid());
        $hash = (string) $upload->json('data.hash');

        $postId = (int) $this->withUnencryptedCookie(self::COOKIE, $this->cookieFrom($upload))->postJson($this->postsUrl(), [
            'title' => '비회원 — 다른 창에서 올린 이미지',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201)->json('data.id');

        $copy = Attachment::query()->where('post_id', $postId)->where('collection', Attachment::COLLECTION_CONTENT_MEDIA)->sole();
        $this->assertNotSame($hash, $copy->hash);
        $this->assertNull($copy->created_by);
        $this->assertNull($copy->guest_owner_hash, '글에 이은 사본에는 소유 쿠키 해시를 남기지 않는다');
        $this->assertStringContainsString($this->serveUrl($copy->hash).'"', (string) Post::query()->findOrFail($postId)->content);
        $this->assertNull(Attachment::query()->where('hash', $hash)->sole()->post_id, '원본 임시 행은 그대로(임시 정리 대상)');
    }

    /**
     * 업로드 소유 쿠키가 없거나 다른 비회원 쿠키면 남의 임시 미디어를 복제하지도 잇지도 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_cookie_mismatch
     *
     * @effects guest_temp_not_copied_without_matching_cookie
     */
    public function test_guest_temp_media_is_not_copied_without_matching_cookie(): void
    {
        $upload = $this->guestUpload((string) Str::uuid());
        $hash = (string) $upload->json('data.hash');

        $postId = (int) $this->withUnencryptedCookie(self::COOKIE, Str::random(40))->postJson($this->postsUrl(), [
            'title' => '비회원 — 남의 임시 이미지',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'author_name' => '다른 비회원',
            'password' => 'guest1234',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201)->json('data.id');

        $this->assertSame(0, Attachment::query()->where('post_id', $postId)->count(), '복제하지 않는다');
        $this->assertNull(Attachment::query()->where('hash', $hash)->sole()->post_id, '잇지도 않는다');
        $this->assertStringContainsString($this->serveUrl($hash).'"', (string) Post::query()->findOrFail($postId)->content);
    }

    /**
     * 글 응답은 그 글이 소유한 해시만 서명하고, 본문에 남은 다른 글 해시는 정식 주소로 둔다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=owned_signing
     *
     * @effects only_owned_hashes_signed
     */
    public function test_detail_signs_only_hashes_owned_by_the_post(): void
    {
        $owner = $this->member('clone-sign-owner@test.com');
        [$postId, $ownHash] = $this->postWithMedia($owner, isSecret: false);
        [, $otherHash] = $this->postWithMedia($owner, isSecret: false);

        // 저장 동기화를 거치지 않고 다른 글 해시를 넣는다(복제가 없던 때 저장된 본문)
        Post::query()->whereKey($postId)->update([
            'content' => '<p><img src="'.$this->serveUrl($ownHash).'"><img src="'.$this->serveUrl($otherHash).'"></p>',
        ]);

        $detail = (string) $this->actingAs($owner, 'sanctum')->getJson("{$this->postsUrl()}/{$postId}")->assertStatus(200)->json('data.content');

        $this->assertMatchesRegularExpression('#/content-media/'.$ownHash.'\?[^"]*signature=#', $detail, '이 글 소유 해시는 서명');
        $this->assertStringContainsString('/content-media/'.$otherHash.'"', $detail, '다른 글 해시는 정식 주소');
    }

    /**
     * 복제 재저장이 있어도 글 작성·수정은 한 번으로 기록된다 — after_create·after_update 1회, 활동 로그 1건.
     *
     * @return void 반환값 없음
     *
     * @scenario case=record_once
     *
     * @effects clone_resave_recorded_once
     */
    public function test_clone_resave_is_recorded_as_a_single_save(): void
    {
        $owner = $this->member('clone-once-owner@test.com');
        [, $hashA] = $this->postWithMedia($owner, isSecret: false);
        [, $hashB] = $this->postWithMedia($owner, isSecret: false);

        $created = 0;
        $updated = 0;
        $countCreate = function () use (&$created) {
            $created++;
        };
        $countUpdate = function () use (&$updated) {
            $updated++;
        };
        HookManager::addAction('sirsoft-board.post.after_create', $countCreate);
        HookManager::addAction('sirsoft-board.post.after_update', $countUpdate);

        try {
            $writer = $this->member('clone-once-writer@test.com');
            $postId = (int) $this->actingAs($writer, 'sanctum')->postJson($this->postsUrl(), [
                'title' => '복제가 있는 글',
                'content' => '<p><img src="'.$this->serveUrl($hashA).'"></p>',
                'content_mode' => 'html',
                'temp_key' => (string) Str::uuid(),
            ])->assertStatus(201)->json('data.id');

            $this->assertSame(1, Attachment::query()->where('post_id', $postId)->count(), '전제: 작성에서 복제가 일어났다');
            $this->assertSame(1, $created, 'after_create 는 한 번');
            $this->assertSame(1, $this->activityLogCount('post.create', $postId), '작성 활동 로그는 한 건');

            $this->actingAs($writer, 'sanctum')->putJson("{$this->postsUrl()}/{$postId}", [
                'title' => '복제가 있는 글',
                'content' => (string) Post::query()->findOrFail($postId)->content.'<p><img src="'.$this->serveUrl($hashB).'"></p>',
                'content_mode' => 'html',
                'temp_key' => (string) Str::uuid(),
            ])->assertStatus(200);

            $this->assertSame(2, Attachment::query()->where('post_id', $postId)->count(), '전제: 수정에서 복제가 일어났다');
            $this->assertSame(1, $updated, 'after_update 는 한 번');
            $this->assertSame(1, $this->activityLogCount('post.update', $postId), '수정 활동 로그는 한 건');
        } finally {
            HookManager::removeAction('sirsoft-board.post.after_create', $countCreate);
            HookManager::removeAction('sirsoft-board.post.after_update', $countUpdate);
        }
    }

    /**
     * 회원이 본문 이미지를 올려 글(공개 또는 비밀)을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  bool  $isSecret  비밀글 여부
     * @return array{0: int, 1: string} [글 ID, 본문 미디어 해시]
     */
    private function postWithMedia(User $user, bool $isSecret): array
    {
        $tempKey = (string) Str::uuid();
        $upload = $this->actingAs($user, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('src-'.Str::random(4).'.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
        $hash = (string) $upload->json('data.hash');

        $postId = (int) $this->actingAs($user, 'sanctum')->postJson($this->postsUrl(), [
            'title' => $isSecret ? '비밀 원글' : '원글',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'is_secret' => $isSecret,
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $this->assertSame($postId, (int) Attachment::query()->where('hash', $hash)->firstOrFail()->post_id);

        return [$postId, $hash];
    }

    /**
     * 다른 게시판에 회원 글과 그 글 소유 본문 미디어(파일 포함)를 직접 만듭니다.
     *
     * @param  User  $owner  원본 글 작성자
     * @param  bool  $readable  user 역할에 그 게시판 글 읽기 권한을 줄지
     * @return array{0: int, 1: string} [원본 글 ID, 원본 해시]
     */
    private function otherBoardMedia(User $owner, bool $readable): array
    {
        $other = Board::updateOrCreate(['slug' => self::OTHER_BOARD], [
            'slug' => self::OTHER_BOARD,
            'name' => ['ko' => '복제 원본 게시판', 'en' => 'Clone Source Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
        ]);
        DB::table('board_posts')->where('board_id', $other->id)->delete();

        if ($readable) {
            $permission = Permission::firstOrCreate(
                ['identifier' => 'sirsoft-board.'.self::OTHER_BOARD.'.posts.read'],
                ['name' => ['ko' => 'posts.read', 'en' => 'posts.read'], 'type' => 'user']
            );
            Role::where('identifier', 'user')->firstOrFail()->permissions()->syncWithoutDetaching([$permission->id]);
            $this->resetPermissionMiddlewareCache();
        }

        $postId = $this->createTestPost([
            'board_id' => $other->id,
            'title' => '다른 게시판 원글',
            'content' => '<p>원글</p>',
            'content_mode' => 'html',
            'user_id' => $owner->id,
            'author_name' => $owner->name,
        ]);

        $storedFilename = Str::uuid()->toString().'.png';
        $path = self::OTHER_BOARD.'/content-media/2026/11/09/'.$storedFilename;
        Storage::disk('modules')->put('sirsoft-board/attachments/'.$path, UploadedFile::fake()->image('src.png')->get());

        $row = Attachment::create([
            'board_id' => $other->id,
            'post_id' => $postId,
            'temp_key' => null,
            'original_filename' => 'src.png',
            'stored_filename' => $storedFilename,
            'disk' => 'modules',
            'path' => $path,
            'mime_type' => 'image/png',
            'size' => 100,
            'collection' => Attachment::COLLECTION_CONTENT_MEDIA,
            'order' => 1,
            'meta' => ['kind' => 'image'],
            'created_by' => $owner->id,
        ]);

        return [$postId, (string) $row->hash];
    }

    /**
     * 비회원으로 본문 이미지를 하나 올립니다(응답에 업로드 소유 쿠키가 붙는다).
     *
     * @param  string  $tempKey  임시 키
     * @return TestResponse 업로드 응답
     */
    private function guestUpload(string $tempKey): TestResponse
    {
        return $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('guest-'.Str::random(4).'.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
    }

    /**
     * 응답의 업로드 소유 쿠키 값을 꺼냅니다.
     *
     * @param  TestResponse  $response  업로드 응답
     * @return string 쿠키 값
     */
    private function cookieFrom(TestResponse $response): string
    {
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === self::COOKIE);
        $this->assertNotNull($cookie, '비회원 업로드 응답에 소유 쿠키가 있어야 한다');

        return (string) $cookie->getValue();
    }

    /**
     * 이 글 대상 활동 로그 건수를 셉니다.
     *
     * @param  string  $action  활동 로그 동작
     * @param  int  $postId  게시글 ID
     * @return int 건수
     */
    private function activityLogCount(string $action, int $postId): int
    {
        return DB::table('activity_logs')
            ->where('action', $action)
            ->where('loggable_id', $postId)
            ->count();
    }

    /**
     * 다른 게시판의 서명 없는 서빙 주소(정식 주소)를 돌려줍니다.
     *
     * @param  string  $hash  해시
     * @return string 정식 주소
     */
    private function otherServeUrl(string $hash): string
    {
        return '/api/modules/sirsoft-board/boards/'.self::OTHER_BOARD.'/content-media/'.$hash;
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
     * 서명 없는 서빙 주소(정식 주소)를 돌려줍니다.
     *
     * @param  string  $hash  해시
     * @return string 정식 주소
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

    /**
     * 사용자 글 작성 주소를 돌려줍니다.
     *
     * @return string 글 주소
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }
}
