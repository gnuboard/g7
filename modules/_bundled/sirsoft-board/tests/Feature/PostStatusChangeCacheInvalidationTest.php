<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Services\ReportService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 블라인드·복원 뒤 목록 캐시 무효화 (#147 같은 결함군)
 *
 * 삭제만 게시글 캐시를 비우고 블라인드·복원은 비우지 않아, 홈 최근·인기 게시글과 이웃 글의 이전/다음이
 * 캐시 TTL 동안 예전 상태로 남았다. 상태 변경 공통 지점에서 비우므로 단건·일괄·신고 처리·자동 숨김이 함께 덮인다.
 *
 * 축 path 의 케이스 표시는 테스트 메서드마다 단다.
 */
class PostStatusChangeCacheInvalidationTest extends BoardTestCase
{
    /** 대상 글(가장 최근·가장 많이 본 글) */
    private int $targetId;

    /** 대상 글 바로 아래 이웃 — 이 글의 이전 글이 대상이다 */
    private int $neighborId;

    /**
     * 대상 글·이웃 글을 만들고 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->updateBoardSettings(['order_by' => 'created_at', 'order_direction' => 'DESC']);

        // 다른 게시판 글보다 새롭고 조회수가 높아야 최근·인기 상위에 든다
        $base = now()->addMinutes(30);
        $this->createTestPost(['title' => '아래 글', 'view_count' => 900001, 'created_at' => $base->copy()->subMinutes(2)]);
        $this->neighborId = $this->createTestPost(['title' => '이웃 글', 'view_count' => 900002, 'created_at' => $base->copy()->subMinute()]);
        $this->targetId = $this->createTestPost(['title' => '대상 글', 'view_count' => 900003, 'created_at' => $base]);
    }

    /**
     * 관리자 단건 블라인드 직후 최근·인기·이웃 글 캐시에서 대상이 빠진다.
     *
     * @scenario path=blind
     *
     * @effects blinded_post_leaves_recent, blinded_post_leaves_popular, blinded_post_leaves_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_blind_refreshes_cached_surfaces(): void
    {
        $this->assertBlindLeavesSurfaces('blind');
    }

    /**
     * 관리자 일괄 블라인드 직후 최근·인기·이웃 글 캐시에서 대상이 빠진다.
     *
     * @scenario path=bulk_blind
     *
     * @effects blinded_post_leaves_recent, blinded_post_leaves_popular, blinded_post_leaves_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_bulk_blind_refreshes_cached_surfaces(): void
    {
        $this->assertBlindLeavesSurfaces('bulk_blind');
    }

    /**
     * 신고 처리 블라인드 직후 최근·인기·이웃 글 캐시에서 대상이 빠진다.
     *
     * @scenario path=report_blind
     *
     * @effects blinded_post_leaves_recent, blinded_post_leaves_popular, blinded_post_leaves_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_report_blind_refreshes_cached_surfaces(): void
    {
        $this->assertBlindLeavesSurfaces('report_blind');
    }

    /**
     * 신고 누적 자동 숨김 직후 최근·인기·이웃 글 캐시에서 대상이 빠진다.
     *
     * @scenario path=auto_hide
     *
     * @effects blinded_post_leaves_recent, blinded_post_leaves_popular, blinded_post_leaves_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_auto_hide_refreshes_cached_surfaces(): void
    {
        $this->assertBlindLeavesSurfaces('auto_hide');
    }

    /**
     * 관리자 단건 복원 직후 최근·인기·이웃 글에 대상이 다시 나온다.
     *
     * @scenario path=restore
     *
     * @effects restored_post_returns_to_recent, restored_post_returns_to_popular, restored_post_returns_to_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_restore_refreshes_cached_surfaces(): void
    {
        $this->assertRestoreReturnsToSurfaces('restore');
    }

    /**
     * 관리자 일괄 복원 직후 최근·인기·이웃 글에 대상이 다시 나온다.
     *
     * @scenario path=bulk_restore
     *
     * @effects restored_post_returns_to_recent, restored_post_returns_to_popular, restored_post_returns_to_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_bulk_restore_refreshes_cached_surfaces(): void
    {
        $this->assertRestoreReturnsToSurfaces('bulk_restore');
    }

    /**
     * 신고 처리 복원 직후 최근·인기·이웃 글에 대상이 다시 나온다.
     *
     * @scenario path=report_restore
     *
     * @effects restored_post_returns_to_recent, restored_post_returns_to_popular, restored_post_returns_to_adjacent
     *
     * @return void 반환값 없음
     */
    public function test_report_restore_refreshes_cached_surfaces(): void
    {
        $this->assertRestoreReturnsToSurfaces('report_restore');
    }

    /**
     * 경로 하나로 블라인드한 뒤 세 표면에서 대상이 빠졌는지 확인합니다.
     *
     * @param  string  $path  상태 변경 경로
     * @return void 반환값 없음
     */
    private function assertBlindLeavesSurfaces(string $path): void
    {
        $before = $this->surfaces();
        $this->assertContains($this->targetId, $before['recent'], '사전: 최근 게시글에 대상이 있어야 한다');
        $this->assertContains($this->targetId, $before['popular'], '사전: 인기 게시글에 대상이 있어야 한다');
        $this->assertSame($this->targetId, $before['adjacent_prev'], '사전: 이웃 글의 이전 글이 대상이어야 한다');

        $this->runPath($path);
        $this->assertSame('blinded', DB::table('board_posts')->where('id', $this->targetId)->value('status'));

        $after = $this->surfaces();
        $this->assertNotContains($this->targetId, $after['recent'], "{$path}: 최근 게시글 캐시에 블라인드 글이 남았다");
        $this->assertNotContains($this->targetId, $after['popular'], "{$path}: 인기 게시글 캐시에 블라인드 글이 남았다");
        $this->assertNotSame($this->targetId, $after['adjacent_prev'], "{$path}: 이전/다음 캐시에 블라인드 글이 남았다");
    }

    /**
     * 블라인드 상태로 캐시를 채운 뒤 경로 하나로 복원하고 세 표면에 대상이 돌아왔는지 확인합니다.
     *
     * @param  string  $path  상태 변경 경로
     * @return void 반환값 없음
     */
    private function assertRestoreReturnsToSurfaces(string $path): void
    {
        // 캐시를 비우지 않는 직접 갱신으로 블라인드 상태를 만든 뒤, 그 상태로 캐시를 채운다
        DB::table('board_posts')->where('id', $this->targetId)->update(['status' => 'blinded']);
        $before = $this->surfaces();
        $this->assertNotContains($this->targetId, $before['recent']);
        $this->assertNotSame($this->targetId, $before['adjacent_prev']);

        $this->runPath($path);
        $this->assertSame('published', DB::table('board_posts')->where('id', $this->targetId)->value('status'));

        $after = $this->surfaces();
        $this->assertContains($this->targetId, $after['recent'], "{$path}: 복원한 글이 최근 게시글에 돌아오지 않았다");
        $this->assertContains($this->targetId, $after['popular'], "{$path}: 복원한 글이 인기 게시글에 돌아오지 않았다");
        $this->assertSame($this->targetId, $after['adjacent_prev'], "{$path}: 복원한 글이 이전/다음에 돌아오지 않았다");
    }

    /**
     * 캐시를 거치는 세 표면의 현재 값을 읽습니다.
     *
     * @return array{recent: array<int, int>, popular: array<int, int>, adjacent_prev: int|null} 표면별 글 ID
     */
    private function surfaces(): array
    {
        $boardService = app(BoardService::class);
        $postService = app(PostService::class);

        $adjacent = $postService->getAdjacentPosts($this->board->slug, $this->neighborId, [], false, $this->board, 'user');

        return [
            'recent' => array_map('intval', array_column($boardService->getCachedRecentPosts(10), 'id')),
            'popular' => array_map('intval', array_column($boardService->getCachedPopularPosts('week', 10), 'id')),
            'adjacent_prev' => $adjacent['prev']?->id,
        ];
    }

    /**
     * 상태 변경 경로 하나를 실행합니다.
     *
     * @param  string  $path  경로 이름
     * @return void 반환값 없음
     */
    private function runPath(string $path): void
    {
        $postService = app(PostService::class);
        $slug = $this->board->slug;

        match ($path) {
            'blind' => $postService->blindPost($slug, $this->targetId, '시험'),
            'restore' => $postService->restorePost($slug, $this->targetId, '시험'),
            'bulk_blind' => $postService->bulkAction($slug, 'blind', [$this->targetId], '시험'),
            'bulk_restore' => $postService->bulkAction($slug, 'restore', [$this->targetId], '시험'),
            'report_blind' => $this->callReportService('blindContent'),
            'report_restore' => $this->callReportService('restoreContent'),
            'auto_hide' => $this->callReportService('blindContentForAutoHide'),
        };
    }

    /**
     * 신고 처리 경로(보호·비공개 메서드)를 대상 글의 신고로 실행합니다.
     *
     * @param  string  $method  ReportService 메서드 이름
     * @return void 반환값 없음
     */
    private function callReportService(string $method): void
    {
        $report = new Report;
        $report->forceFill(['board_id' => $this->board->id, 'target_type' => 'post', 'target_id' => $this->targetId]);
        $report->setRelation('board', $this->board);

        $ref = new \ReflectionMethod(ReportService::class, $method);
        $ref->setAccessible(true);
        $ref->invoke(app(ReportService::class), $report);
    }
}
