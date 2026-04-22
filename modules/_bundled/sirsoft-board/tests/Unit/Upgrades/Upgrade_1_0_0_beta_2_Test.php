<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Upgrades;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Upgrades\Upgrade_1_0_0_beta_2;

/**
 * Upgrade_1_0_0_beta_2 멱등성 테스트
 *
 * 검증 목적:
 * - syncCountColumns run() 2회 연속 호출 시 동일 결과 보장 (멱등성)
 * - 파티션 부재 상황에서 예외 없이 완료 (graceful)
 * - 카운트 0인 게시판/게시글에서 run() 후 0 유지
 *
 * @group board
 * @group upgrade
 */
class Upgrade_1_0_0_beta_2_Test extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'upgrade-idempotency';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '업그레이드 멱등성 테스트 게시판', 'en' => 'Upgrade Idempotency Test Board'],
            'is_active' => true,
        ];
    }

    // ==========================================
    // 멱등성 시나리오
    // ==========================================

    /**
     * syncCountColumns은 2회 연속 실행 시 동일한 카운트를 유지한다
     */
    public function test_sync_count_columns_is_idempotent(): void
    {
        // 데이터 준비: 게시글 + 댓글 2개 + 답글게시글 1개
        $postId = $this->createUpgradeTestPost(['board_id' => $this->board->id]);
        $this->createUpgradeTestComment($postId, ['board_id' => $this->board->id]);
        $this->createUpgradeTestComment($postId, ['board_id' => $this->board->id]);
        $replyId = $this->createUpgradeTestPost(['board_id' => $this->board->id, 'parent_id' => $postId]);

        // 카운트 0으로 리셋 (업그레이드 전 상태)
        DB::table('board_posts')->whereIn('id', [$postId, $replyId])->update([
            'comments_count' => 0,
            'replies_count' => 0,
        ]);
        DB::table('boards')->where('id', $this->board->id)->update([
            'posts_count' => 0,
            'comments_count' => 0,
        ]);

        // 첫 번째 실행
        $this->runUpgrade();

        $postAfterFirst = DB::table('board_posts')->where('id', $postId)->first();
        $boardAfterFirst = DB::table('boards')->where('id', $this->board->id)->first();

        // 두 번째 실행 (멱등성 검증)
        $this->runUpgrade();

        $postAfterSecond = DB::table('board_posts')->where('id', $postId)->first();
        $boardAfterSecond = DB::table('boards')->where('id', $this->board->id)->first();

        // 두 번 실행 결과가 동일해야 한다
        $this->assertEquals($postAfterFirst->comments_count, $postAfterSecond->comments_count);
        $this->assertEquals($postAfterFirst->replies_count, $postAfterSecond->replies_count);
        $this->assertEquals($boardAfterFirst->posts_count, $boardAfterSecond->posts_count);
        $this->assertEquals($boardAfterFirst->comments_count, $boardAfterSecond->comments_count);
    }

    /**
     * 게시글/댓글/첨부가 없는 빈 게시판에서 run() 후 카운트가 0이다
     */
    public function test_empty_board_counts_remain_zero_after_upgrade(): void
    {
        // 빈 게시판 카운트 리셋
        DB::table('boards')->where('id', $this->board->id)->update([
            'posts_count' => 999,
            'comments_count' => 999,
        ]);

        $this->runUpgrade();

        $board = DB::table('boards')->where('id', $this->board->id)->first();
        $this->assertEquals(0, $board->posts_count);
        $this->assertEquals(0, $board->comments_count);
    }

    /**
     * 삭제된(soft-delete) 게시글은 boards.posts_count에서 제외된다
     */
    public function test_soft_deleted_posts_excluded_from_posts_count(): void
    {
        // 정상 게시글 2개, soft-delete 1개
        $this->createUpgradeTestPost(['board_id' => $this->board->id]);
        $this->createUpgradeTestPost(['board_id' => $this->board->id]);
        $this->createUpgradeTestPost(['board_id' => $this->board->id, 'deleted_at' => now()]);

        DB::table('boards')->where('id', $this->board->id)->update(['posts_count' => 0]);

        $this->runUpgrade();

        $board = DB::table('boards')->where('id', $this->board->id)->first();
        $this->assertEquals(2, $board->posts_count);
    }

    /**
     * 삭제된(soft-delete) 댓글은 board_posts.comments_count에서 제외된다
     */
    public function test_soft_deleted_comments_excluded_from_comments_count(): void
    {
        $postId = $this->createUpgradeTestPost(['board_id' => $this->board->id]);
        $this->createUpgradeTestComment($postId, ['board_id' => $this->board->id]);
        $this->createUpgradeTestComment($postId, [
            'board_id' => $this->board->id,
            'deleted_at' => now(),
        ]);

        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 0]);

        $this->runUpgrade();

        $post = DB::table('board_posts')->where('id', $postId)->first();
        $this->assertEquals(1, $post->comments_count);
    }

    /**
     * run()은 파티션이 없는 환경에서도 예외 없이 완료된다
     */
    public function test_run_completes_without_exception_when_no_partitions(): void
    {
        // 파티션이 없는 표준 환경에서 예외 발생 없이 완료되어야 함
        $this->expectNotToPerformAssertions();

        try {
            $this->runUpgrade();
        } catch (\Throwable $e) {
            $this->fail("run() should not throw in partition-free environment: " . $e->getMessage());
        }
    }

    /**
     * run() 2회 실행 시 boards.comments_count가 중복 누적되지 않는다
     */
    public function test_boards_comments_count_does_not_accumulate_on_double_run(): void
    {
        $postId = $this->createUpgradeTestPost(['board_id' => $this->board->id]);
        $this->createUpgradeTestComment($postId, ['board_id' => $this->board->id]);
        $this->createUpgradeTestComment($postId, ['board_id' => $this->board->id]);

        DB::table('boards')->where('id', $this->board->id)->update(['comments_count' => 0]);

        $this->runUpgrade();
        $countAfterFirst = DB::table('boards')->where('id', $this->board->id)->value('comments_count');

        $this->runUpgrade();
        $countAfterSecond = DB::table('boards')->where('id', $this->board->id)->value('comments_count');

        // 두 번째 실행에서 중복 누적 없이 동일값
        $this->assertEquals($countAfterFirst, $countAfterSecond);
        $this->assertEquals(2, $countAfterFirst);
    }

    // ==========================================
    // Helper
    // ==========================================

    private function runUpgrade(): void
    {
        $context = new UpgradeContext(
            fromVersion: '1.0.0-beta.1',
            toVersion: '1.0.0-beta.2',
            currentStep: '1.0.0-beta.2',
        );

        $upgrade = new Upgrade_1_0_0_beta_2();
        $upgrade->run($context);
    }

    private function createUpgradeTestPost(array $attributes = []): int
    {
        $defaults = [
            'board_id' => $this->board->id,
            'title' => '업그레이드 테스트 게시글',
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

    private function createUpgradeTestComment(int $postId, array $attributes = []): int
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
}
