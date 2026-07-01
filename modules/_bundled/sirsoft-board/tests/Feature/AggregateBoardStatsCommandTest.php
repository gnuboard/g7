<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * AggregateBoardStatsCommand Feature 테스트
 *
 * php artisan sirsoft-board:aggregate-stats [--dry-run]
 */
class AggregateBoardStatsCommandTest extends ModuleTestCase
{
    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        BoardStat::query()->delete();

        $this->board = Board::create([
            'name' => ['ko' => '커맨드 테스트', 'en' => 'Command Test'],
            'slug' => 'command-test-'.uniqid(),
            'type' => 'basic',
        ]);
    }

    private function makePost(?bool $trashed = false): Post
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
        ]);

        if ($trashed) {
            $post->delete();
        }

        return $post;
    }

    private function makeComment(int $postId): Comment
    {
        return Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => '댓글',
            'ip_address' => '127.0.0.1',
        ]);
    }

    #[Test]
    public function test_dry_run_does_not_persist_any_row(): void
    {
        $this->makePost();
        $this->makeComment($this->makePost()->id);

        $this->artisan('sirsoft-board:aggregate-stats', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertSame(0, BoardStat::count());
    }

    #[Test]
    public function test_real_run_upserts_7_days_with_correct_today_counts(): void
    {
        // baseline: 트랜잭션 외부에 이전 테스트/수동 실행 잔존 게시글이 있을 수 있어
        // 절대값이 아닌 증가량으로 검증한다 (board_posts/board_comments 는 단일 테이블).
        $baselinePosts = Post::query()->whereNull('deleted_at')
            ->whereDate('created_at', CarbonImmutable::today())->count();
        $baselineComments = Comment::query()->whereNull('deleted_at')
            ->whereDate('created_at', CarbonImmutable::today())->count();

        // 오늘 게시글 2건(1건 삭제 — 제외돼야), 댓글 1건
        $p = $this->makePost();
        $this->makePost(trashed: true);
        $this->makeComment($p->id);

        $this->artisan('sirsoft-board:aggregate-stats')
            ->expectsOutputToContain('집계 완료')
            ->assertSuccessful();

        // 7일치 행 생성
        $this->assertSame(7, BoardStat::count());

        // 오늘 행: baseline + 추가(삭제 제외 게시글 1, 댓글 1)
        $todayRow = BoardStat::where('date', CarbonImmutable::today()->toDateString())->first();
        $this->assertSame($baselinePosts + 1, $todayRow->post_count);
        $this->assertSame($baselineComments + 1, $todayRow->comment_count);
    }

    #[Test]
    public function test_real_run_preserves_8_days_old_row(): void
    {
        $eightDaysAgo = CarbonImmutable::today()->subDays(8)->toDateString();
        BoardStat::create(['date' => $eightDaysAgo, 'post_count' => 99, 'comment_count' => 99]);

        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();

        $oldRow = BoardStat::where('date', $eightDaysAgo)->first();
        $this->assertSame(99, $oldRow->post_count);
        $this->assertSame(99, $oldRow->comment_count);

        // 7일치 신규 + 8일전 1행 = 8행
        $this->assertSame(8, BoardStat::count());
    }

    #[Test]
    public function test_repeated_runs_are_idempotent(): void
    {
        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();
        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();
        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();

        $this->assertSame(7, BoardStat::count());
    }
}
