<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Dashboard;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 대시보드 최신글(getRecentAcrossBoards)의 노출 제한 필터 테스트 (⑱-2)
 *
 * 관리자 대시보드 최신글은 블라인드·삭제 글과 비활성 게시판 글을 제외한다.
 * 비밀글은 제목 공개 정책(2026-01-02: 관리자는 제목을 본다)에 따라 노출한다(제외하지 않음).
 * (status=published + board.is_active=true 컨벤션 — 비밀글 필터는 적용하지 않음)
 */
class RecentAcrossBoardsVisibilityTest extends ModuleTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/admin/dashboard/recent-posts';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser(['sirsoft-board.dashboard.view']);
    }

    /**
     * @scenario case=dashboard_recent_visibility
     *
     * @effects dashboard_summary_excludes_hidden_posts
     */
    #[Test]
    public function recent_posts_shows_secret_but_excludes_blinded_and_inactive_board_posts(): void
    {
        $activeBoard = Board::factory()->create(['is_active' => true]);
        $inactiveBoard = Board::factory()->create(['is_active' => false]);

        $this->insertPost($activeBoard->id, '공개 발행글', ['is_secret' => false, 'status' => PostStatus::Published->value]);
        $this->insertPost($activeBoard->id, '비밀글', ['is_secret' => true, 'status' => PostStatus::Published->value]);
        $this->insertPost($activeBoard->id, '블라인드글', ['is_secret' => false, 'status' => PostStatus::Blinded->value]);
        $this->insertPost($inactiveBoard->id, '비활성게시판글', ['is_secret' => false, 'status' => PostStatus::Published->value]);

        $response = $this->actingAs($this->admin)->getJson(self::ENDPOINT.'?limit=20');
        $response->assertStatus(200);

        $titles = array_column($response->json('data'), 'title');

        $this->assertContains('공개 발행글', $titles, '공개 발행글은 최신글에 노출되어야 한다.');
        $this->assertContains('비밀글', $titles, '비밀글은 제목 공개 정책에 따라 관리자 대시보드에 노출되어야 한다.');
        $this->assertNotContains('블라인드글', $titles, '블라인드글은 최신글에서 제외되어야 한다.');
        $this->assertNotContains('비활성게시판글', $titles, '비활성 게시판 글은 최신글에서 제외되어야 한다.');
    }

    /**
     * 게시글을 직접 삽입합니다.
     *
     * @param  int  $boardId  게시판 ID
     * @param  string  $title  제목
     * @param  array<string, mixed>  $attributes  추가 속성
     */
    private function insertPost(int $boardId, string $title, array $attributes = []): void
    {
        DB::table('board_posts')->insert(array_merge([
            'board_id' => $boardId,
            'title' => $title,
            'content' => '내용',
            'author_name' => '작성자',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
