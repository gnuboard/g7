<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Hooks;

require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../Fixtures/TransactionHookProbeListener.php';
require_once __DIR__.'/../../Fixtures/AsyncTransactionHookProbeListener.php';

use App\Extension\HookListenerRegistrar;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Tests\Fixtures\AsyncTransactionHookProbeListener;
use Modules\Sirsoft\Board\Tests\Fixtures\TransactionHookProbeListener;

/**
 * 글 저장 트랜잭션 안 훅 `before_create_commit`·`before_update_commit` (#883 3단계)
 *
 * 기존 `after_*` 는 커밋 뒤라 확장이 같은 트랜잭션에서 자기 데이터를 저장할 자리가 없었다. 새 훅은 트랜잭션 안·글 ID 확정·
 * 첨부 연결 뒤에 돈다. sync 리스너의 예외는 저장 전체를 되돌리고, 옮긴 파일도 제자리로 돌아간다(StorageTransaction).
 * 수정이 실패해도 이미 그 글에 연결된 첨부는 지우지 않는다(F5).
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/post-save-transaction-hooks.yaml
 */
class PostSaveTransactionHookTest extends BoardTestCase
{
    private User $member;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'post-tx-hook';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '저장 훅 게시판', 'en' => 'Save Hook Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
        ];
    }

    /**
     * 권한·회원·리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->member = User::factory()->create(['email' => 'tx-hook@test.com']);
        $role = Role::where('identifier', 'user')->first();
        if ($role) {
            $this->member->roles()->attach($role->id);
        }

        TransactionHookProbeListener::$seen = [];
        TransactionHookProbeListener::$throw = false;
        HookListenerRegistrar::clear();
    }

    /**
     * 리스너를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookListenerRegistrar::clear();
        TransactionHookProbeListener::$throw = false;
        Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$this->getTestBoardSlug());

        parent::tearDown();
    }

    /**
     * sync 리스너는 트랜잭션 안에서 글 행과 연결된 첨부를 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=sync_listener_sees_row
     *
     * @effects hook_runs_inside_transaction_after_link
     */
    public function test_sync_listener_sees_post_and_linked_attachments_inside_transaction(): void
    {
        HookListenerRegistrar::register(TransactionHookProbeListener::class, 'test');
        $tempKey = $this->uploadTemp();

        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(), $this->payload(['temp_key' => $tempKey]))
            ->assertStatus(201);

        $this->assertCount(1, TransactionHookProbeListener::$seen);
        [$hook, , $rowVisible, $linked, $level] = TransactionHookProbeListener::$seen[0];
        $this->assertSame('create', $hook);
        $this->assertTrue($rowVisible);
        $this->assertSame(1, $linked);
        $this->assertGreaterThan(1, $level, '테스트 트랜잭션 위의 저장 트랜잭션 안');
    }

    /**
     * 리스너가 예외를 던지면 글이 남지 않고, 임시 첨부와 파일이 원래 자리로 돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=listener_throws_on_create
     *
     * @effects listener_exception_rolls_back_post_and_files
     */
    public function test_listener_exception_rolls_back_post_temp_attachment_and_file(): void
    {
        HookListenerRegistrar::register(TransactionHookProbeListener::class, 'test');
        TransactionHookProbeListener::$throw = true;
        $tempKey = $this->uploadTemp();
        $attachment = Attachment::query()->where('temp_key', $tempKey)->firstOrFail();
        $tempPath = $attachment->path;
        $postsBefore = Post::query()->count();

        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(), $this->payload(['temp_key' => $tempKey]))
            ->assertStatus(500);

        $this->assertSame($postsBefore, Post::query()->count());
        $attachment->refresh();
        $this->assertNull($attachment->post_id);
        $this->assertSame($tempPath, $attachment->path);
        $this->assertTrue(Storage::disk('modules')->exists('sirsoft-board/attachments/'.$tempPath), '임시 파일이 제자리에 있다');
        $this->assertSame([], Storage::disk('modules')->allFiles('sirsoft-board/attachments/'.$this->getTestBoardSlug().'/'.date('Y')), '본경로 사본이 남지 않는다');
    }

    /**
     * sync 를 빼면 커밋 뒤에 돈다(큐 래핑 + afterCommit) — 트랜잭션 안 훅에는 sync 가 필수다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=async_listener_runs_after_commit
     *
     * @effects async_listener_runs_after_commit
     */
    public function test_async_listener_runs_after_commit(): void
    {
        HookListenerRegistrar::register(AsyncTransactionHookProbeListener::class, 'test');

        $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(), $this->payload())
            ->assertStatus(201);

        $this->assertCount(1, TransactionHookProbeListener::$seen);
        $this->assertSame(1, TransactionHookProbeListener::$seen[0][4], '저장 트랜잭션이 끝난 뒤(테스트 트랜잭션 깊이)');
    }

    /**
     * 수정이 실패해도 이미 그 글에 연결된 첨부는 지우지 않는다(F5).
     *
     * @return void 반환값 없음
     *
     * @scenario case=update_failure_keeps_existing
     *
     * @effects update_failure_keeps_existing_attachments
     */
    public function test_update_failure_keeps_already_linked_attachments(): void
    {
        $tempKey = $this->uploadTemp();
        $postId = (int) $this->actingAs($this->member, 'sanctum')
            ->postJson($this->postsUrl(), $this->payload(['temp_key' => $tempKey]))
            ->assertStatus(201)
            ->json('data.id');
        $existing = Attachment::query()->where('post_id', $postId)->firstOrFail();

        HookListenerRegistrar::register(TransactionHookProbeListener::class, 'test');
        TransactionHookProbeListener::$throw = true;

        $this->actingAs($this->member, 'sanctum')
            ->putJson("{$this->postsUrl()}/{$postId}", $this->payload(['attachment_ids' => [$existing->id]]))
            ->assertStatus(500);

        $this->assertNotSoftDeleted('board_attachments', ['id' => $existing->id]);
        $this->assertSame('update', TransactionHookProbeListener::$seen[0][0]);
    }

    /**
     * 회원으로 임시 첨부 하나를 올립니다.
     *
     * @return string 임시 키
     */
    private function uploadTemp(): string
    {
        $tempKey = (string) Str::uuid();
        $this->actingAs($this->member, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments", [
                'file' => UploadedFile::fake()->image('hook.png'),
                'temp_key' => $tempKey,
            ])
            ->assertStatus(201);

        return $tempKey;
    }

    /**
     * 사용자 글 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function postsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/posts";
    }

    /**
     * 글 요청 본문을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 요청 본문
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => '저장 훅',
            'content' => '본문은 최소 길이를 넘겨야 검증을 통과합니다.',
        ], $overrides);
    }
}
