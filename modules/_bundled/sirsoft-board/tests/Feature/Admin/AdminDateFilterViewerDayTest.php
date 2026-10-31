<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use App\Http\Middleware\SetTimezone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\PostScrapRepository;
use Modules\Sirsoft\Board\Repositories\ReactionRepository;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Services\PostScrapService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판 관리자 목록 날짜 필터의 「하루」 기준 회귀 테스트
 *
 * 결함: 화면에서 고른 날짜를 UTC 하루로 잘랐다(공용 `applyDateRangeFilter()` 또는
 * `'Y-m-d 00:00:00'` 문자열 연결). 목록은 날짜를 보는 사람의 시간대로 그리므로, 한국 시간 새벽에
 * 생긴 행이 목록에는 그날로 보이는데 그날로 거르면 빠졌다. 신고 통계의 기간은 종료일을
 * `'Y-m-d'` 그대로 비교해 종료일 하루가 통째로 빠졌다.
 *
 * 공통 데이터: 2026-03-09 15:30 UTC = 2026-03-10 00:30 KST(그날 안),
 * 2026-03-10 15:30 UTC = 2026-03-11 00:30 KST(다음날). 2026-03-10 로 거르면 앞의 것만 남아야 한다.
 *
 * 시나리오 SSoT: tests/scenarios/admin-date-filter-viewer-day.yaml
 */
#[Group('board')]
class AdminDateFilterViewerDayTest extends BoardTestCase
{
    private const DAY = '2026-03-10';

    private const INSIDE_UTC = '2026-03-09 15:30:00';

    private const OUTSIDE_UTC = '2026-03-10 15:30:00';

    /**
     * 보는 사람의 시간대를 한국 시간으로 둡니다(SetTimezone 미들웨어가 요청마다 심는 값).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(SetTimezone::TIMEZONE_KEY, 'Asia/Seoul');
    }

    /**
     * 게시글 목록의 작성일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=board_admin_posts
     *
     * @effects board_admin_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_post_created_range_follows_viewer_day(): void
    {
        $this->createTestPost(['title' => 'tz-in', 'created_at' => self::INSIDE_UTC]);
        $this->createTestPost(['title' => 'tz-out', 'created_at' => self::OUTSIDE_UTC]);

        $count = fn (string $day): int => app(PostRepositoryInterface::class)->countNormalPosts($this->board->slug, [
            'created_at_from' => $day,
            'created_at_to' => $day,
        ])->total;

        // 한국 시간으로 3월 9일에 쓴 글은 없다 — UTC 하루로 자르면 3월 10일 00:30 KST 글이 여기 잡힌다.
        $this->assertSame(0, $count('2026-03-09'));
        $this->assertSame(1, $count(self::DAY));
        $this->assertSame(1, $count('2026-03-11'));
    }

    /**
     * 스크랩 관리 목록의 스크랩일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=board_admin_scraps
     *
     * @effects board_admin_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_scrap_range_follows_viewer_day(): void
    {
        $member = User::factory()->create();
        $inside = $this->scrap($member, $this->createTestPost(['title' => 'scrap-in']), self::INSIDE_UTC);
        $this->scrap($member, $this->createTestPost(['title' => 'scrap-out']), self::OUTSIDE_UTC);

        $page = app(PostScrapRepository::class)->paginateForAdmin([
            'user_id' => $member->id,
            'scrapped_at_from' => self::DAY,
            'scrapped_at_to' => self::DAY,
        ]);

        $this->assertSame([$inside], collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * 반응 관리 목록의 반응일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=board_admin_reactions
     *
     * @effects board_admin_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_reaction_range_follows_viewer_day(): void
    {
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);
        $likeId = (int) ReactionType::query()->where('code', 'like')->value('id');

        $member = User::factory()->create();
        $inside = $this->reaction($member, $this->createTestPost(['title' => 'react-in']), $likeId, self::INSIDE_UTC);
        $this->reaction($member, $this->createTestPost(['title' => 'react-out']), $likeId, self::OUTSIDE_UTC);

        $page = app(ReactionRepository::class)->paginateForAdmin([
            'user_id' => $member->id,
            'reacted_at_from' => self::DAY,
            'reacted_at_to' => self::DAY,
        ]);

        $this->assertSame([$inside], collect($page->items())->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * 신고 목록(접수일)·묶음 목록(마지막 신고일)·신고 통계의 기간은 보는 사람의 시간대 하루로 자른다.
     * 통계는 종료일 하루를 통째로 빠뜨리지 않는다.
     *
     * @scenario surface=board_admin_reports
     *
     * @effects board_admin_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_report_ranges_follow_viewer_day(): void
    {
        $inside = Report::factory()->create([
            'board_id' => $this->board->id,
            'last_reported_at' => self::INSIDE_UTC,
        ]);
        $inside->forceFill(['created_at' => self::INSIDE_UTC])->saveQuietly();

        $outside = Report::factory()->create([
            'board_id' => $this->board->id,
            'last_reported_at' => self::OUTSIDE_UTC,
        ]);
        $outside->forceFill(['created_at' => self::OUTSIDE_UTC])->saveQuietly();

        $filters = [
            'board_id' => $this->board->id,
            'reported_at_from' => self::DAY,
            'reported_at_to' => self::DAY,
        ];
        $repository = app(ReportRepository::class);

        $listed = collect($repository->paginate($filters)->items())->pluck('id')->all();
        $this->assertSame([$inside->id], $listed);

        $grouped = collect($repository->paginateGrouped($filters)->items())->pluck('id')->all();
        $this->assertSame([$inside->id], $grouped);

        $this->assertSame(1, $repository->getStatistics($filters)['total']);
    }

    /**
     * 스크랩 한 건을 만들고 스크랩 시각을 맞춥니다.
     *
     * @param  User  $user  회원
     * @param  int  $postId  게시글 ID
     * @param  string  $createdAt  스크랩 시각(UTC)
     * @return int 스크랩 ID
     */
    private function scrap(User $user, int $postId, string $createdAt): int
    {
        app(PostScrapService::class)->toggle($user->id, $postId, $this->board->id);

        $id = (int) DB::table('board_post_scraps')
            ->where('user_id', $user->id)
            ->where('post_id', $postId)
            ->value('id');

        DB::table('board_post_scraps')->where('id', $id)->update(['created_at' => $createdAt]);

        return $id;
    }

    /**
     * 반응 행을 직접 만듭니다.
     *
     * @param  User  $user  회원
     * @param  int  $postId  게시글 ID
     * @param  int  $typeId  반응 유형 ID
     * @param  string  $createdAt  반응 시각(UTC)
     * @return int 반응 ID
     */
    private function reaction(User $user, int $postId, int $typeId, string $createdAt): int
    {
        return DB::table('board_reactions')->insertGetId([
            'user_id' => $user->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reaction_type_id' => $typeId,
            'board_id' => $this->board->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
