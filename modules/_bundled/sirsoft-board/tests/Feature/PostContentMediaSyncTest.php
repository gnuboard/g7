<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 글 저장과 본문 미디어 동기화 (#883 4단계)
 *
 * 글 저장 트랜잭션 안에서 본문이 참조한 임시 본문 미디어만(같은 임시 키 + 소유 규칙) 글에 잇고, 본문에서 빠진 것은 소프트 삭제한다.
 * 문서당 최대 개수를 넘으면 422 로 저장 전체를 되돌린다. 남의 해시는 무시한다. 서명 주소는 정식 주소로 바꿔 저장한다.
 * 본문 미디어는 첨부 목록·첨부 개수에 들지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media.yaml
 */
class PostContentMediaSyncTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-sync';
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
            'name' => ['ko' => '본문 미디어 동기화', 'en' => 'Content Media Sync'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
            'content_media_policy' => ['max_per_document' => 2],
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
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
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
     * 본문이 참조한 것만 잇고(서명 쿼리는 떼어 저장), 첨부 목록·개수에 들지 않는다. 고쳐서 빼면 소프트 삭제된다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=link_and_remove
     *
     * @effects referenced_linked, unreferenced_temp_left, signed_query_stripped, excluded_from_attachments, removed_soft_deleted
     */
    public function test_links_referenced_media_and_soft_deletes_removed(): void
    {
        $member = $this->member('sync-member@test.com');
        $tempKey = (string) Str::uuid();

        [$hashA, $urlA] = $this->upload($member, $tempKey);
        [$hashB, $urlB] = $this->upload($member, $tempKey);
        [$hashC] = $this->upload($member, $tempKey);

        $postId = (int) $this->actingAs($member, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '본문 미디어 글',
            'content' => '<p>본문</p><p><img src="'.$urlA.'"></p><p><img src="'.$urlB.'"></p>',
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $post = Post::query()->findOrFail($postId);
        $this->assertStringNotContainsString('signature=', $post->content, '서명 주소는 저장하지 않는다');
        $this->assertStringContainsString("/content-media/{$hashA}\"", $post->content);

        foreach ([$hashA, $hashB] as $hash) {
            $row = Attachment::query()->where('hash', $hash)->firstOrFail();
            $this->assertSame($postId, (int) $row->post_id);
            $this->assertSame($this->board->id, $row->board_id);
            $this->assertNull($row->temp_key);
        }
        $this->assertNull(Attachment::query()->where('hash', $hashC)->firstOrFail()->post_id, '참조하지 않은 것은 임시로 남는다(정리 명령이 지운다)');

        $this->assertSame(0, (int) $post->attachments_count);
        $this->assertCount(0, $post->attachments);
        $this->assertCount(2, $post->contentMedia);

        // 수정 — B 를 뺀다
        $this->actingAs($member, 'sanctum')->putJson("{$this->postsUrl()}/{$postId}", [
            'title' => '본문 미디어 글',
            'content' => '<p><img src="/api/modules/sirsoft-board/boards/'.$this->getTestBoardSlug().'/content-media/'.$hashA.'"></p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(200);

        $this->assertNotSoftDeleted('board_attachments', ['hash' => $hashA]);
        $this->assertSoftDeleted('board_attachments', ['hash' => $hashB, 'trigger_type' => 'user']);
    }

    /**
     * 문서당 최대 개수(2)를 넘기면 422 이고 글이 만들어지지 않으며 임시 행은 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=limit_exceeded
     *
     * @effects limit_exceeded_rolls_back
     */
    public function test_limit_exceeded_rolls_back_whole_save(): void
    {
        $member = $this->member('sync-limit@test.com');
        $tempKey = (string) Str::uuid();
        $urls = [];
        $hashes = [];
        foreach (range(1, 3) as $i) {
            [$hashes[], $urls[]] = $this->upload($member, $tempKey);
        }

        $before = Post::query()->where('board_id', $this->board->id)->count();

        $response = $this->actingAs($member, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '한도 초과',
            'content' => implode('', array_map(fn ($u) => '<p><img src="'.$u.'"></p>', $urls)),
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ]);

        $response->assertStatus(422);
        $this->assertSame('content_media_limit_exceeded', $response->json('errors.code'));
        $this->assertSame($before, Post::query()->where('board_id', $this->board->id)->count());
        foreach ($hashes as $hash) {
            $this->assertNull(Attachment::query()->where('hash', $hash)->firstOrFail()->post_id);
        }
    }

    /**
     * 남이 올린 임시 본문 미디어 해시(다른 임시 키)는 본문에 넣어도 잇지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=foreign_hash
     *
     * @effects foreign_hash_ignored
     */
    public function test_foreign_hash_is_not_linked(): void
    {
        $owner = $this->member('sync-owner@test.com');
        [$foreignHash, $foreignUrl] = $this->upload($owner, (string) Str::uuid());

        $thief = $this->member('sync-thief@test.com');
        $tempKey = (string) Str::uuid();
        $postId = (int) $this->actingAs($thief, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '남의 해시',
            'content' => '<p><img src="'.$foreignUrl.'"></p>',
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $this->assertNull(Attachment::query()->where('hash', $foreignHash)->firstOrFail()->post_id);
        $this->assertCount(0, Post::query()->findOrFail($postId)->contentMedia);
    }

    /**
     * 비회원 글 — 비회원이 올린 본문 미디어가 같은 임시 키로 이어지고 소유 해시가 지워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_post
     *
     * @effects guest_media_linked_hash_cleared
     */
    public function test_guest_post_links_guest_media(): void
    {
        $tempKey = (string) Str::uuid();
        $upload = $this->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('g.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
        $hash = (string) $upload->json('data.hash');

        $this->postJson($this->postsUrl(), [
            'title' => '비회원 본문 미디어',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'author_name' => '비회원',
            'password' => 'guest1234',
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $row = Attachment::query()->where('hash', $hash)->firstOrFail();
        $this->assertNotNull($row->post_id);
        $this->assertNull($row->guest_owner_hash);
    }

    /**
     * 회원으로 본문 미디어를 하나 올립니다.
     *
     * @param  User  $user  회원
     * @param  string  $tempKey  임시 키
     * @return array{0: string, 1: string} [해시, 서명 주소]
     */
    private function upload(User $user, string $tempKey): array
    {
        $response = $this->actingAs($user, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('m-'.Str::random(4).'.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        return [(string) $response->json('data.hash'), (string) $response->json('data.url')];
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
