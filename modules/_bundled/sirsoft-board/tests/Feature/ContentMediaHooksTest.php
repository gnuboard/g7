<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Extension\HookManager;
use App\Extension\Storage\ModuleStorageDriver;
use App\Models\Role;
use App\Models\User;
use App\Support\ContentMedia\ContentMediaContext;
use App\Support\ContentMedia\ContentMediaHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 본문 미디어의 코어 공용 훅 `core.content_media.*` 발화 (#887)
 *
 * 업로드·정리 흐름은 코어 골격이 돌리므로 게시판 본문 미디어에도 공용 훅이 발화한다. 업로드는 `before_upload` →
 * `filter_upload_file` → `after_upload` 순서이고, 문맥(`ContentMediaContext`)은 모듈 `sirsoft-board`·문서 `post`·범위
 * = 게시판 슬러그다. 게시판 첨부 훅(`sirsoft-board.attachment.*`)은 본문 미디어에 발화하지 않는다. 영구 삭제(첨부 정리
 * 명령의 임시 정리·소프트 삭제 정리, 게시판 영구 삭제, 글 영구 삭제)는 본문 미디어 행마다 `before_delete` 를 한 번 발화하고,
 * 일반 첨부에는 발화하지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/content-media-hooks.yaml
 */
class ContentMediaHooksTest extends BoardTestCase
{
    /**
     * 이 시험이 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'content-media-hooks';
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
            'name' => ['ko' => '본문 미디어 훅', 'en' => 'Content Media Hooks'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 권한·쿠키를 준비하고 지난 실행이 남긴 행을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->withCredentials();
        DB::table('board_attachments')->where('path', 'like', $this->getTestBoardSlug().'/%')->delete();
    }

    /**
     * 등록한 시험 리스너와 저장소 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearAction(ContentMediaHooks::BEFORE_UPLOAD);
        HookManager::clearFilter(ContentMediaHooks::FILTER_UPLOAD_FILE);
        HookManager::clearAction(ContentMediaHooks::AFTER_UPLOAD);
        HookManager::clearAction(ContentMediaHooks::BEFORE_DELETE);
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * 본문 미디어 업로드는 공용 훅 세 개를 순서대로 발화하고, 게시판 첨부 훅은 발화하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=upload
     *
     * @effects upload_hooks_fired_in_order, context_names_board_post, attachment_hooks_not_fired
     */
    public function test_upload_fires_core_content_media_hooks_in_order(): void
    {
        $calls = [];
        $contexts = [];
        $afterRow = null;
        HookManager::addAction(ContentMediaHooks::BEFORE_UPLOAD, function (UploadedFile $file, ContentMediaContext $context) use (&$calls, &$contexts) {
            $calls[] = 'before_upload';
            $contexts[] = $context;
        });
        HookManager::addFilter(ContentMediaHooks::FILTER_UPLOAD_FILE, function (UploadedFile $file, ContentMediaContext $context) use (&$calls) {
            $calls[] = 'filter_upload_file';

            return $file;
        });
        HookManager::addAction(ContentMediaHooks::AFTER_UPLOAD, function (Model $row, ContentMediaContext $context) use (&$calls, &$contexts, &$afterRow) {
            $calls[] = 'after_upload';
            $contexts[] = $context;
            $afterRow = $row;
        });

        $attachmentHooks = 0;
        $countAttachmentHook = function () use (&$attachmentHooks) {
            $attachmentHooks++;
        };
        HookManager::addAction('sirsoft-board.attachment.before_upload', $countAttachmentHook);
        HookManager::addAction('sirsoft-board.attachment.after_upload', $countAttachmentHook);

        try {
            $member = $this->member('hooks-upload@test.com');
            $tempKey = (string) Str::uuid();
            $response = $this->actingAs($member, 'sanctum')->postJson($this->uploadUrl(), [
                'file' => UploadedFile::fake()->image('hook.png'),
                'temp_key' => $tempKey,
            ])->assertStatus(201);
        } finally {
            HookManager::removeAction('sirsoft-board.attachment.before_upload', $countAttachmentHook);
            HookManager::removeAction('sirsoft-board.attachment.after_upload', $countAttachmentHook);
        }

        $this->assertSame(['before_upload', 'filter_upload_file', 'after_upload'], $calls);
        $this->assertSame(0, $attachmentHooks, '게시판 첨부 훅은 본문 미디어에 발화하지 않는다');
        $this->assertSame((string) $response->json('data.hash'), (string) $afterRow?->getAttribute('hash'));

        foreach ($contexts as $context) {
            $this->assertSame(ContentMediaContext::EXTENSION_MODULE, $context->extensionType);
            $this->assertSame('sirsoft-board', $context->extension);
            $this->assertSame('post', $context->documentType);
            $this->assertSame($this->getTestBoardSlug(), $context->scope);
            $this->assertSame($tempKey, $context->tempKey);
            $this->assertSame((int) $member->id, $context->actorId);
            $this->assertSame('image', $context->kind?->value);
        }
    }

    /**
     * 첨부 정리 명령의 임시 정리는 본문 미디어 행에만 `before_delete` 를 발화하고, 일반 임시 첨부도 종전대로 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=prune_temp
     *
     * @effects before_delete_on_purge_only_for_content_media
     */
    public function test_temp_prune_fires_before_delete_only_for_content_media(): void
    {
        $deleted = $this->recordBeforeDelete();
        $member = $this->member('hooks-prune@test.com');

        $media = $this->actingAs($member, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('stale.png'),
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201);
        $mediaRow = Attachment::query()->where('hash', $media->json('data.hash'))->sole();

        $attachment = $this->actingAs($member, 'sanctum')->postJson($this->attachmentsUrl(), [
            'file' => UploadedFile::fake()->image('plain.png'),
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(201);
        $attachmentId = (int) $attachment->json('data.data.id');

        Attachment::query()->whereKey([$mediaRow->id, $attachmentId])->update(['created_at' => now()->subDays(5)]);

        $this->artisan('sirsoft-board:prune-attachments')->assertSuccessful();

        $mine = $this->callsInThisBoard($deleted->calls);
        $this->assertSame([$mediaRow->id], array_column($mine, 'id'), '이 게시판에서는 본문 미디어 행에만 발화');
        $this->assertNull(Attachment::withTrashed()->find($mediaRow->id));
        $this->assertFalse($this->disk()->exists('attachments', $mediaRow->path), '본문 미디어 파일도 지운다');
        $this->assertNull(Attachment::withTrashed()->find($attachmentId), '일반 임시 첨부도 종전대로 지운다');
    }

    /**
     * 본문에서 빠져 소프트 삭제된 본문 미디어를 보존기간 뒤 영구 정리할 때 `before_delete` 를 발화한다(소프트 삭제 때는 없음).
     *
     * @return void 반환값 없음
     *
     * @scenario path=purge_soft_deleted
     *
     * @effects soft_delete_does_not_fire_before_delete, before_delete_on_purge_only_for_content_media
     */
    public function test_soft_deleted_purge_fires_before_delete(): void
    {
        $deleted = $this->recordBeforeDelete();
        $member = $this->member('hooks-purge@test.com');
        $tempKey = (string) Str::uuid();
        $upload = $this->actingAs($member, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('removed.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $postId = (int) $this->actingAs($member, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '본문 미디어 훅',
            'content' => '<p><img src="'.$upload->json('data.url').'"></p>',
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($member, 'sanctum')->putJson("{$this->postsUrl()}/{$postId}", [
            'title' => '본문 미디어 훅',
            'content' => '<p>미디어 뺌</p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(200);

        $row = Attachment::withTrashed()->where('hash', $upload->json('data.hash'))->sole();
        $this->assertNotNull($row->deleted_at, '전제: 본문에서 빠져 소프트 삭제');
        $this->assertSame([], $this->callsInThisBoard($deleted->calls), '소프트 삭제 때는 발화하지 않는다');

        $row->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();
        $this->artisan('sirsoft-board:prune-attachments --purge-days=30')->assertSuccessful();

        $mine = $this->callsInThisBoard($deleted->calls);
        $this->assertSame([$row->id], array_column($mine, 'id'));
        $this->assertSame($postId, $mine[0]['document_id'], '문맥은 그 행이 속했던 글');
        $this->assertNull(Attachment::withTrashed()->find($row->id));
        $this->assertFalse($this->disk()->exists('attachments', $row->path));
    }

    /**
     * 게시판을 영구 삭제하면 그 게시판의 본문 미디어 행(글에 이어진 것·본문에서 빠져 소프트 삭제된 것)마다 `before_delete` 를
     * 한 번 발화하고 행을 지운다. 일반 첨부에는 발화하지 않고 종전대로 함께 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=board_force_delete
     *
     * @effects before_delete_once_per_row_on_document_force_delete, before_delete_on_purge_only_for_content_media
     */
    public function test_board_force_delete_fires_before_delete_once_per_content_media_row(): void
    {
        $deleted = $this->recordBeforeDelete();
        $member = $this->member('hooks-board-delete@test.com');
        [$postId, $keptId, $removedId] = $this->postWithKeptAndRemovedMedia($member);
        $attachmentId = $this->plainAttachment($postId);
        $boardId = (int) $this->board->id;

        app(BoardService::class)->deleteBoard($boardId);

        $mine = $this->callsInThisBoard($deleted->calls);
        $ids = array_column($mine, 'id');
        sort($ids);
        $expected = [$keptId, $removedId];
        sort($expected);
        $this->assertSame($expected, $ids, '본문 미디어 행마다 한 번씩, 일반 첨부에는 발화하지 않는다');
        $this->assertSame([$postId, $postId], array_column($mine, 'document_id'), '문맥은 그 행이 속했던 글');
        $this->assertSame(0, Attachment::withTrashed()->where('board_id', $boardId)->count(), '본문 미디어·일반 첨부 행 모두 지운다');
        $this->assertNull(Attachment::withTrashed()->find($attachmentId));
    }

    /**
     * 글을 영구 삭제하면(문의 샘플 글 정리) 그 글의 본문 미디어 행마다 `before_delete` 를 한 번 발화하고 행을 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario path=post_force_delete
     *
     * @effects before_delete_once_per_row_on_document_force_delete
     */
    public function test_post_force_delete_fires_before_delete_once_per_content_media_row(): void
    {
        $deleted = $this->recordBeforeDelete();
        $member = $this->member('hooks-post-delete@test.com');
        [$postId, $keptId, $removedId] = $this->postWithKeptAndRemovedMedia($member);

        $count = app(EcommerceInquiryHookListener::class)->purgeSamplePosts(0, $this->getTestBoardSlug(), [Post::class => [$postId]]);

        $this->assertSame(1, $count, '전제: 글을 영구 삭제했다');
        $ids = array_column($this->callsInThisBoard($deleted->calls), 'id');
        sort($ids);
        $expected = [$keptId, $removedId];
        sort($expected);
        $this->assertSame($expected, $ids, '본문 미디어 행마다 한 번씩');
        $this->assertNull(Post::withTrashed()->find($postId));
        $this->assertSame(0, Attachment::withTrashed()->whereKey([$keptId, $removedId])->count());
    }

    /**
     * 본문 미디어 두 개를 올려 글을 쓰고, 그중 하나를 본문에서 빼 소프트 삭제 상태로 만듭니다.
     *
     * @param  User  $member  작성 회원
     * @return array{0: int, 1: int, 2: int} [글 ID, 본문에 남은 행 ID, 본문에서 빠진(소프트 삭제) 행 ID]
     */
    private function postWithKeptAndRemovedMedia(User $member): array
    {
        $tempKey = (string) Str::uuid();
        $kept = $this->actingAs($member, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('kept.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);
        $removed = $this->actingAs($member, 'sanctum')->postJson($this->uploadUrl(), [
            'file' => UploadedFile::fake()->image('removed.png'),
            'temp_key' => $tempKey,
        ])->assertStatus(201);

        $postId = (int) $this->actingAs($member, 'sanctum')->postJson($this->postsUrl(), [
            'title' => '본문 미디어 영구 삭제',
            'content' => '<p><img src="'.$kept->json('data.url').'"><img src="'.$removed->json('data.url').'"></p>',
            'content_mode' => 'html',
            'temp_key' => $tempKey,
        ])->assertStatus(201)->json('data.id');

        $keptRow = Attachment::query()->where('hash', $kept->json('data.hash'))->sole();
        $this->actingAs($member, 'sanctum')->putJson("{$this->postsUrl()}/{$postId}", [
            'title' => '본문 미디어 영구 삭제',
            'content' => '<p><img src="'.$this->serveUrl((string) $keptRow->hash).'"></p>',
            'content_mode' => 'html',
            'temp_key' => (string) Str::uuid(),
        ])->assertStatus(200);

        $removedRow = Attachment::withTrashed()->where('hash', $removed->json('data.hash'))->sole();
        $this->assertNotNull($removedRow->deleted_at, '전제: 본문에서 빠진 행은 소프트 삭제');
        $this->assertSame($postId, (int) $keptRow->fresh()->post_id, '전제: 남은 행은 글에 이어져 있다');

        return [$postId, (int) $keptRow->id, (int) $removedRow->id];
    }

    /**
     * 글에 이어진 일반 첨부 행을 직접 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 첨부 ID
     */
    private function plainAttachment(int $postId): int
    {
        return (int) Attachment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => 'plain.txt',
            'stored_filename' => 'plain.txt',
            'disk' => 'modules',
            'path' => $this->getTestBoardSlug().'/2026/11/09/plain.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'collection' => 'attachments',
            'order' => 1,
        ])->id;
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
     * `before_delete` 호출을 기록하는 리스너를 겁니다.
     *
     * @return object{calls: array<int, array{id: int, scope: string|null, document_id: int|null}>} 기록 (calls)
     */
    private function recordBeforeDelete(): object
    {
        $record = new class
        {
            /** @var array<int, array{id: int, scope: string|null, document_id: int|null}> */
            public array $calls = [];
        };
        HookManager::addAction(ContentMediaHooks::BEFORE_DELETE, function (Model $row, ContentMediaContext $context) use ($record) {
            $record->calls[] = ['id' => (int) $row->getKey(), 'scope' => $context->scope, 'document_id' => $context->documentId];
        });

        return $record;
    }

    /**
     * 이 시험 게시판 범위의 `before_delete` 기록만 고릅니다(다른 시험이 남긴 오래된 행이 함께 정리될 수 있다).
     *
     * @param  array<int, array{id: int, scope: string|null, document_id: int|null}>  $calls  기록
     * @return array<int, array{id: int, scope: string|null, document_id: int|null}> 이 게시판 기록
     */
    private function callsInThisBoard(array $calls): array
    {
        return array_values(array_filter($calls, fn (array $c) => $c['scope'] === $this->getTestBoardSlug()));
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
     * 일반 첨부 업로드 주소를 돌려줍니다.
     *
     * @return string 업로드 주소
     */
    private function attachmentsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments";
    }

    /**
     * 사용자 글 주소를 돌려줍니다.
     *
     * @return string 글 주소
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }
}
