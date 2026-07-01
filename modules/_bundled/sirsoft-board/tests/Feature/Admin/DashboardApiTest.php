<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 대시보드 API 테스트
 *
 * /api/modules/sirsoft-board/admin/dashboard/* 4 엔드포인트
 *  - overview / post-graph / recent-posts / pending-reports
 *  - 가드: admin 미들웨어 (admin 권한 보유자만)
 */
class DashboardApiTest extends ModuleTestCase
{
    private User $adminUser;

    private User $normalUser;

    private const BASE = '/api/modules/sirsoft-board/admin/dashboard';

    protected function setUp(): void
    {
        parent::setUp();

        BoardStat::query()->delete();

        // isAdmin() 은 admin 타입 권한 보유 여부로 판정되므로 임의 권한을 부여한다.
        // 대시보드 라우트는 admin 미들웨어만 사용 (별도 permission 가드 없음).
        $this->adminUser = $this->createAdminUser(['sirsoft-board.boards.read']);
        $this->normalUser = $this->createUser();

        Board::create([
            'name' => ['ko' => 'API 테스트', 'en' => 'API Test'],
            'slug' => 'api-test-'.uniqid(),
            'type' => 'basic',
        ]);
    }

    // ========== overview ==========

    #[Test]
    public function test_overview_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/overview')->assertStatus(401);
    }

    #[Test]
    public function test_overview_rejects_non_admin_user(): void
    {
        $this->actingAs($this->normalUser)->getJson(self::BASE.'/overview')->assertStatus(403);
    }

    #[Test]
    public function test_overview_returns_today_counts_for_admin(): void
    {
        BoardStat::create([
            'date' => CarbonImmutable::today()->toDateString(),
            'post_count' => 5,
            'comment_count' => 12,
        ]);

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/overview')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['today_posts', 'today_comments']])
            ->assertJsonPath('data.today_posts', 5)
            ->assertJsonPath('data.today_comments', 12);
    }

    // ========== post-graph ==========

    #[Test]
    public function test_post_graph_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/post-graph')->assertStatus(401);
    }

    #[Test]
    public function test_post_graph_returns_schema_for_admin(): void
    {
        $today = CarbonImmutable::today();
        for ($i = 0; $i < 7; $i++) {
            BoardStat::create([
                'date' => $today->subDays($i)->toDateString(),
                'post_count' => 1,
                'comment_count' => 2,
            ]);
        }

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/post-graph')
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'days' => [['date', 'post_count', 'comment_count']],
                    'total_posts',
                    'total_comments',
                    'posts_change',
                    'comments_change',
                    'updated_at',
                ],
            ])
            ->assertJsonCount(7, 'data.days')
            ->assertJsonPath('data.total_posts', 7)
            ->assertJsonPath('data.total_comments', 14);
    }

    // ========== recent-posts ==========

    #[Test]
    public function test_recent_posts_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/recent-posts')->assertStatus(401);
    }

    #[Test]
    public function test_recent_posts_returns_collection_for_admin(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/recent-posts')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);
    }

    #[Test]
    public function test_recent_posts_rejects_limit_over_max(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::BASE.'/recent-posts?limit=999')
            ->assertStatus(422);
    }

    #[Test]
    public function test_recent_posts_rejects_non_integer_limit(): void
    {
        $this->actingAs($this->adminUser)
            ->getJson(self::BASE.'/recent-posts?limit=abc')
            ->assertStatus(422);
    }

    // ========== pending-reports ==========

    #[Test]
    public function test_pending_reports_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/pending-reports')->assertStatus(401);
    }

    #[Test]
    public function test_pending_reports_returns_items_and_total_for_admin(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/pending-reports')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['items', 'total']]);
    }
}
