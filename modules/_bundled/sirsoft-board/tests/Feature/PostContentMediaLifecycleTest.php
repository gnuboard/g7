<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Extension\Storage\ModuleStorageDriver;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 본문 미디어 수명 — 휴지통 삭제·복원과 임시 정리 (#887 잠금)
 *
 * 게시글 삭제는 휴지통 정책이라 본문 미디어도 일반 첨부처럼 행만 연쇄 소프트 삭제(trigger_type=cascade)하고 파일은 남긴다 —
 * 복원하면 함께 돌아온다. 파일은 소프트 삭제 첨부 영구 정리(운영자 옵트인)가 지운다. 저장하지 않고 나간 임시 본문 미디어
 * (board_id=0 + 임시 키)는 `sirsoft-board:prune-attachments` 의 임시 정리 파트가 보존기간 뒤 파일과 함께 지운다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media-lifecycle.yaml
 */
class PostContentMediaLifecycleTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-life';
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
            'name' => ['ko' => '본문 미디어 수명', 'en' => 'Content Media Lifecycle'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
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
     * 게시글을 지우면 본문 미디어 행이 연쇄 소프트 삭제되고 파일은 남으며, 복원하면 함께 돌아온다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=post_deleted_and_restored
     *
     * @effects post_delete_soft_deletes_media_cascade, post_delete_keeps_media_file, post_restore_restores_media
     */
    public function test_post_delete_soft_deletes_content_media_and_restore_brings_it_back(): void
    {
        $member = $this->member('life-member@test.com');
        $tempKey = (string) Str::uuid();
        [$hash, $url] = $this->upload($member, $tempKey);

        $postId = (int) $this->actingAs($member, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '본문 미디어 수명',
            'content' => '<p><img src="'.$url.'"></p>',
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $media = Attachment::query()->where('hash', $hash)->firstOrFail();
        $this->assertSame($postId, (int) $media->post_id, '전제: 본문 미디어가 글에 이어졌다');

        app(PostService::class)->deletePost($this->getTestBoardSlug(), $postId, TriggerType::Admin->value);

        $this->assertSoftDeleted('board_attachments', ['id' => $media->id, 'trigger_type' => TriggerType::Cascade->value]);
        $this->assertTrue($this->disk()->exists('attachments', $media->path), '휴지통 정책 — 파일은 남는다');

        app(PostService::class)->restorePost($this->getTestBoardSlug(), $postId, null, TriggerType::Admin->value);

        $this->assertNotSoftDeleted('board_attachments', ['id' => $media->id]);
    }

    /**
     * 저장하지 않고 나간 임시 본문 미디어는 보존기간이 지나면 첨부 정리 명령이 파일과 함께 지우고, 보존기간 안이면 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=temp_pruned
     *
     * @effects stale_temp_media_pruned, fresh_temp_media_kept
     */
    public function test_prune_command_removes_stale_temp_content_media(): void
    {
        $member = $this->member('life-prune@test.com');
        [$staleHash] = $this->upload($member, (string) Str::uuid());
        [$freshHash] = $this->upload($member, (string) Str::uuid());
        $stale = Attachment::query()->where('hash', $staleHash)->firstOrFail();
        $fresh = Attachment::query()->where('hash', $freshHash)->firstOrFail();
        $this->assertSame(Attachment::COLLECTION_CONTENT_MEDIA, $stale->collection);
        Attachment::query()->whereKey($stale->id)->update(['created_at' => now()->subDays(5)]);

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $this->assertNull(Attachment::withTrashed()->find($stale->id), '보존기간이 지난 임시 본문 미디어 행을 지운다');
        $this->assertFalse($this->disk()->exists('attachments', $stale->path), '임시 본문 미디어 파일도 지운다');
        $this->assertNotNull(Attachment::query()->find($fresh->id), '보존기간 안의 임시 본문 미디어는 남긴다');
        $this->assertTrue($this->disk()->exists('attachments', $fresh->path));
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
     * 게시판 모듈 저장소를 돌려줍니다.
     *
     * @return ModuleStorageDriver 저장소
     */
    private function disk(): ModuleStorageDriver
    {
        return new ModuleStorageDriver('sirsoft-board', 'modules');
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
