<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 카운트 동기화 통합 테스트 (이슈 #269 - #11)
 *
 * Queue::fake() 를 사용해 "큐 워커가 없는 환경"을 재현합니다.
 * sync:true 선언으로 Listener 가 즉시 실행되어 DB 카운트가 API 응답 시점에
 * 이미 반영되어 있어야 합니다.
 *
 * 검증 목적:
 * - 댓글/게시글/첨부 생성 직후 DB 카운트가 올바른지 (큐 워커 무관)
 * - soft-delete 시 카운트가 즉시 감소하는지
 * - blind 처리 시 카운트가 유지되는지 (upgrade step SQL 기준에 맞춤)
 * - 첨부 임시 업로드 → linkTempAttachments 시 drift 가 발생하지 않는지
 *
 * @group board
 * @group count-sync
 */
class CountSyncIntegrationTest extends ModuleTestCase
{
    private Board $board;

    private string $slug = 'count-integration';

    public function beginDatabaseTransaction(): void
    {
        // 수동 정리 모드 (다른 count 테스트와 동일한 관행)
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('board_attachments')->where('board_id', 0)->delete();
        DB::table('boards')->where('slug', $this->slug)->delete();

        $this->board = Board::factory()->create([
            'slug' => $this->slug,
            'is_active' => true,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
        ]);

        // "큐 워커 없는 환경" 재현 — Job 은 실행되지 않고 저장만 됨
        Queue::fake();
    }

    protected function tearDown(): void
    {
        DB::table('board_attachments')->where('board_id', $this->board->id)->delete();
        DB::table('board_comments')->where('board_id', $this->board->id)->delete();
        DB::table('board_posts')->where('board_id', $this->board->id)->delete();
        DB::table('boards')->where('id', $this->board->id)->delete();
        parent::tearDown();
    }

    /**
     * 큐 워커가 없는 환경에서도 댓글 생성 직후 DB 카운트가 즉시 반영되어야 합니다.
     * (sync: true 옵션 실증 — 이슈 #269 작업 3 핵심)
     */
    public function test_comment_counts_update_immediately_without_queue_worker(): void
    {
        $postId = $this->insertPost();

        $commentService = app(CommentService::class);
        $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'author_name' => '익명',
            'content' => '테스트 댓글',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        // board_posts.comments_count 즉시 반영
        $this->assertEquals(
            1,
            DB::table('board_posts')->where('id', $postId)->value('comments_count'),
            'board_posts.comments_count 는 댓글 생성 직후 즉시 1 이어야 합니다 (sync:true).'
        );

        // boards.comments_count 즉시 반영
        $this->assertEquals(
            1,
            DB::table('boards')->where('id', $this->board->id)->value('comments_count'),
            'boards.comments_count 는 댓글 생성 직후 즉시 1 이어야 합니다.'
        );
    }

    /**
     * 댓글을 여러 개 연달아 생성해도 모든 생성이 즉시 누적 반영되어야 합니다.
     */
    public function test_multiple_comments_accumulate_count(): void
    {
        $postId = $this->insertPost();
        $commentService = app(CommentService::class);

        for ($i = 1; $i <= 3; $i++) {
            $commentService->createComment($this->slug, [
                'post_id' => $postId,
                'author_name' => '익명',
                'content' => "테스트 댓글 {$i}",
                'ip_address' => '127.0.0.1',
                'password' => null,
            ]);
        }

        $this->assertEquals(3, DB::table('board_posts')->where('id', $postId)->value('comments_count'));
        $this->assertEquals(3, DB::table('boards')->where('id', $this->board->id)->value('comments_count'));
    }

    /**
     * 댓글 soft delete 시 카운트가 즉시 감소해야 합니다.
     */
    public function test_comment_delete_decrements_counts_immediately(): void
    {
        $postId = $this->insertPost();
        $commentService = app(CommentService::class);

        $c = $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'author_name' => '익명',
            'content' => '삭제될 댓글',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        $this->assertEquals(1, DB::table('board_posts')->where('id', $postId)->value('comments_count'));

        $commentService->deleteComment($this->slug, $c->id);

        $this->assertEquals(0, DB::table('board_posts')->where('id', $postId)->value('comments_count'));
        $this->assertEquals(0, DB::table('boards')->where('id', $this->board->id)->value('comments_count'));
    }

    /**
     * 블라인드 처리는 카운트를 변경하지 않아야 합니다.
     * (upgrade step SQL 기준: deleted_at IS NULL 만 필터, status 무관)
     */
    public function test_comment_blind_does_not_change_counts(): void
    {
        $postId = $this->insertPost();
        $commentService = app(CommentService::class);

        $c = $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'author_name' => '익명',
            'content' => '블라인드 대상',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        $this->assertEquals(1, DB::table('board_posts')->where('id', $postId)->value('comments_count'));

        $commentService->blindComment($this->slug, $c->id, '테스트 블라인드');

        $this->assertEquals(
            1,
            DB::table('board_posts')->where('id', $postId)->value('comments_count'),
            '블라인드는 deleted_at 변경 없음 → 카운트 유지되어야 합니다.'
        );
    }

    /**
     * 대댓글 생성 시 부모 댓글의 replies_count 가 즉시 반영되어야 합니다.
     */
    public function test_comment_reply_increments_parent_replies_count(): void
    {
        $postId = $this->insertPost();
        $commentService = app(CommentService::class);

        $parent = $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'author_name' => '익명',
            'content' => '원댓글',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'parent_id' => $parent->id,
            'author_name' => '익명',
            'content' => '대댓글',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        $this->assertEquals(
            1,
            DB::table('board_comments')->where('id', $parent->id)->value('replies_count'),
            '대댓글 생성 시 부모 댓글의 replies_count 는 즉시 1 이어야 합니다.'
        );
    }

    /**
     * 게시글 생성 시 boards.posts_count 가 즉시 반영되어야 합니다.
     * PostService 를 통한 경로는 after_create 훅을 발화 → Listener 가 COUNT 재계산.
     */
    public function test_post_create_increments_board_posts_count(): void
    {
        $postService = app(PostService::class);
        $postService->createPost($this->slug, [
            'title' => '서비스 경로',
            'content' => '본문',
            'content_mode' => 'text',
            'author_name' => '익명',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
        ]);

        $this->assertEquals(
            1,
            DB::table('boards')->where('id', $this->board->id)->value('posts_count'),
            'PostService::createPost 호출 직후 boards.posts_count 가 즉시 반영되어야 합니다.'
        );
    }

    /**
     * drift 상태에서 댓글 생성 훅이 발화되면 카운트가 정확히 재동기화되어야 합니다.
     * (Listener 는 증분 연산이 아닌 COUNT(*) 재계산 방식이라 drift 복구 가능)
     */
    public function test_drift_state_is_recovered_by_listener_on_next_event(): void
    {
        $postId = $this->insertPost();

        // 인위적 drift 삽입: 실제 댓글 0건인데 stored 는 99
        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 99]);
        DB::table('boards')->where('id', $this->board->id)->update(['comments_count' => 99]);

        $commentService = app(CommentService::class);
        $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'author_name' => '익명',
            'content' => 'drift 복구용',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        $this->assertEquals(
            1,
            DB::table('board_posts')->where('id', $postId)->value('comments_count'),
            'drift(99) 상태여도 다음 훅 발화 시 실제 개수(1) 로 재동기화되어야 합니다.'
        );
        $this->assertEquals(
            1,
            DB::table('boards')->where('id', $this->board->id)->value('comments_count'),
        );
    }

    /**
     * 게시글 상세 API 응답의 comment_count 필드가 댓글 생성 직후 최신값이어야 합니다.
     * (UI 헤더 바인딩이 실제 최신 카운트를 받는지 실증)
     */
    public function test_post_api_response_exposes_up_to_date_comment_count(): void
    {
        // guest 에게 posts.read 권한 부여 (이미 존재하면 재사용)
        $identifier = 'sirsoft-board.'.$this->slug.'.posts.read';
        $permId = DB::table('permissions')->where('identifier', $identifier)->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'identifier' => $identifier,
                'name' => json_encode(['ko' => '읽기', 'en' => 'read']),
                'type' => 'user',
                'extension_type' => 'module',
                'extension_identifier' => 'sirsoft-board',
                'resource_route_key' => 'post',
                'owner_key' => 'user_id',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $guestRoleId = DB::table('roles')->where('identifier', 'guest')->value('id');
        DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $guestRoleId, 'permission_id' => $permId],
            ['scope_type' => null, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );

        $postId = $this->insertPost();

        $commentService = app(CommentService::class);
        $commentService->createComment($this->slug, [
            'post_id' => $postId,
            'author_name' => '익명',
            'content' => '댓글',
            'ip_address' => '127.0.0.1',
            'password' => null,
        ]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->slug}/posts/{$postId}");

        $response->assertStatus(200);
        $this->assertSame(
            1,
            $response->json('data.comment_count'),
            'API 응답의 comment_count 는 댓글 생성 직후 즉시 최신값이어야 합니다.'
        );
    }

    /**
     * 테스트용 게시글을 직접 INSERT 합니다.
     */
    private function insertPost(array $overrides = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '테스트글',
            'content' => '본문',
            'content_mode' => 'text',
            'author_name' => '작성자',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'view_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_posts')->insertGetId(array_merge($defaults, $overrides));
    }
}
