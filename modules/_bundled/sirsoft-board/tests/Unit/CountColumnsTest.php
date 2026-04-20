<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_0_0_beta_2;

/**
 * STEP 3: 카운팅 컬럼 테스트
 *
 * 마이그레이션으로 컬럼 추가 후,
 * Upgrade_1_0_0_beta_2의 syncCountColumns()로 기존 데이터 동기화를 검증합니다.
 */
class CountColumnsTest extends ModuleTestCase
{
    /**
     * 테스트용 게시판
     */
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->board = Board::firstOrCreate(
            ['slug' => 'count-test'],
            [
                'name' => ['ko' => '카운트 테스트', 'en' => 'Count Test'],
                'is_active' => true,
            ]
        );
    }
    /**
     * 카운팅 컬럼이 board_posts, board_comments 테이블에 존재하는지 확인합니다.
     */
    public function test_count_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('board_posts', 'replies_count'));
        $this->assertTrue(Schema::hasColumn('board_posts', 'comments_count'));
        $this->assertTrue(Schema::hasColumn('board_posts', 'attachments_count'));
        $this->assertTrue(Schema::hasColumn('board_comments', 'replies_count'));
    }

    /**
     * 카운팅 컬럼의 기본값이 0인지 확인합니다.
     */
    public function test_count_columns_default_to_zero(): void
    {
        $postId = $this->createTestPost();

        $post = DB::table('board_posts')->where('id', $postId)->first();

        $this->assertEquals(0, $post->replies_count);
        $this->assertEquals(0, $post->comments_count);
        $this->assertEquals(0, $post->attachments_count);
    }

    /**
     * Upgrade_1_0_0이 replies_count를 올바르게 동기화하는지 확인합니다.
     */
    public function test_upgrade_syncs_replies_count(): void
    {
        // 원글 생성
        $parentId = $this->createTestPost(['title' => '원글']);

        // 답글 3개 생성
        $this->createTestPost(['title' => '답글1', 'parent_id' => $parentId, 'depth' => 1]);
        $this->createTestPost(['title' => '답글2', 'parent_id' => $parentId, 'depth' => 1]);
        $this->createTestPost(['title' => '답글3', 'parent_id' => $parentId, 'depth' => 1]);

        // 삭제된 답글 1개 (soft delete — 카운트에서 제외되어야 함)
        $this->createTestPost([
            'title' => '삭제된 답글',
            'parent_id' => $parentId,
            'depth' => 1,
            'deleted_at' => now(),
        ]);

        // replies_count를 0으로 리셋 (동기화 전 상태 시뮬레이션)
        DB::table('board_posts')->where('id', $parentId)->update(['replies_count' => 0]);

        // Upgrade 실행
        $this->runUpgrade();

        // 검증: 삭제된 답글 제외하여 3개
        $post = DB::table('board_posts')->where('id', $parentId)->first();
        $this->assertEquals(3, $post->replies_count);
    }

    /**
     * Upgrade_1_0_0이 comments_count를 올바르게 동기화하는지 확인합니다.
     */
    public function test_upgrade_syncs_comments_count(): void
    {
        $postId = $this->createTestPost();

        // 댓글 2개 생성
        $this->createTestComment($postId, ['content' => '댓글1']);
        $this->createTestComment($postId, ['content' => '댓글2']);

        // 삭제된 댓글 1개 (soft delete — 카운트에서 제외되어야 함)
        $this->createTestComment($postId, [
            'content' => '삭제된 댓글',
            'deleted_at' => now(),
        ]);

        // comments_count를 0으로 리셋 (동기화 전 상태 시뮬레이션)
        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 0]);

        // Upgrade 실행
        $this->runUpgrade();

        // 검증: 삭제된 댓글 제외하여 2개
        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertEquals(2, $post->comments_count);
    }

    /**
     * Upgrade가 attachments_count를 올바르게 동기화하는지 확인합니다.
     */
    public function test_upgrade_syncs_attachments_count(): void
    {
        $postId = $this->createTestPost();

        // 첨부파일 2개 생성
        $this->createTestAttachment($postId);
        $this->createTestAttachment($postId);

        // 삭제된 첨부파일 1개 (카운트에서 제외되어야 함)
        $this->createTestAttachment($postId, ['deleted_at' => now()]);

        DB::table('board_posts')->where('id', $postId)->update(['attachments_count' => 0]);

        $this->runUpgrade();

        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertEquals(2, $post->attachments_count);
    }

    /**
     * Upgrade가 board_comments.replies_count를 올바르게 동기화하는지 확인합니다.
     */
    public function test_upgrade_syncs_comment_replies_count(): void
    {
        $postId = $this->createTestPost();
        $parentCommentId = $this->createTestComment($postId, ['content' => '부모 댓글']);

        // 대댓글 2개 생성
        $this->createTestComment($postId, ['content' => '대댓글1', 'parent_id' => $parentCommentId]);
        $this->createTestComment($postId, ['content' => '대댓글2', 'parent_id' => $parentCommentId]);

        // 삭제된 대댓글 1개 (카운트에서 제외되어야 함)
        $this->createTestComment($postId, [
            'content' => '삭제된 대댓글',
            'parent_id' => $parentCommentId,
            'deleted_at' => now(),
        ]);

        DB::table('board_comments')->where('id', $parentCommentId)->update(['replies_count' => 0]);

        $this->runUpgrade();

        $comment = DB::table('board_comments')->where('id', $parentCommentId)->first();
        $this->assertEquals(2, $comment->replies_count);
    }

    /**
     * 답글도 댓글도 없는 게시글의 카운트가 0으로 유지되는지 확인합니다.
     */
    public function test_upgrade_keeps_zero_for_posts_without_replies_or_comments(): void
    {
        $postId = $this->createTestPost();

        $this->runUpgrade();

        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertEquals(0, $post->replies_count);
        $this->assertEquals(0, $post->comments_count);
        $this->assertEquals(0, $post->attachments_count);
    }

    /**
     * Upgrade_1_0_0_beta_2를 실행합니다.
     *
     * @return void
     */
    private function runUpgrade(): void
    {
        $context = new UpgradeContext(
            fromVersion: '0.0.0',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );

        $upgrade = new Upgrade_1_0_0_beta_2();
        $upgrade->run($context);
    }

    /**
     * 테스트 게시글을 직접 DB에 생성합니다.
     *
     * @param  array  $attributes  게시글 속성
     * @return int 생성된 게시글 ID
     */
    private function createTestPost(array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '테스트 게시글',
            'content' => '테스트 내용입니다.',
            'user_id' => null,
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
            'view_count' => 0,
            'replies_count' => 0,
            'comments_count' => 0,
            'attachments_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_posts')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 테스트 댓글을 직접 DB에 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array  $attributes  댓글 속성
     * @return int 생성된 댓글 ID
     */
    private function createTestComment(int $postId, array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'user_id' => null,
            'author_name' => '테스트',
            'content' => '테스트 댓글입니다.',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'replies_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_comments')->insertGetId(array_merge($defaults, $attributes));
    }

    /**
     * 테스트 첨부파일을 직접 DB에 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array  $attributes  첨부파일 속성
     * @return int 생성된 첨부파일 ID
     */
    private function createTestAttachment(int $postId, array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'hash' => substr(md5(uniqid()), 0, 12),
            'original_filename' => 'test.txt',
            'stored_filename' => 'test_' . uniqid() . '.txt',
            'disk' => 'local',
            'path' => 'attachments',
            'mime_type' => 'text/plain',
            'size' => 100,
            'collection' => 'default',
            'order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return DB::table('board_attachments')->insertGetId(array_merge($defaults, $attributes));
    }
}
