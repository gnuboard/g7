<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Seo\BoardSitemapContributor;
use Modules\Sirsoft\Board\Services\BoardDashboardService;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 목록 쿼리 확장 훅 sirsoft-board.post.list_query_extensions (#147)
 *
 * 확장이 돌려준 조건 함수가 목록 구분값 15개 경로에서 게시판 조건 뒤에 붙어 결과를 좁히는지 본다.
 * 시험 리스너는 이 게시판 글만 분류 EN 으로 좁히고 다른 게시판 글은 그대로 둔다(컬럼은 qualifyColumn 으로 한정).
 * 축 case 의 케이스 표시는 테스트 메서드마다 단다.
 */
class PostListQueryExtensionsTest extends BoardTestCase
{
    private const HOOK = 'sirsoft-board.post.list_query_extensions';

    /** @var array<string, int> 제목 → 글 ID */
    private array $ids = [];

    private User $author;

    /** @var array<int, array<string, mixed>> 리스너가 받은 맥락 */
    private array $payloads = [];

    /** @var array<int, array{0: string, 1: callable}> 이 테스트가 건 필터 훅 */
    private array $registered = [];

    /**
     * 분류 EN·KO 원글·공지·답글과 작성자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->updateBoardSettings(['order_by' => 'created_at', 'order_direction' => 'DESC', 'categories' => ['EN', 'KO']]);

        $this->author = User::factory()->create();
        $this->author->roles()->attach(Role::where('identifier', 'user')->value('id'));
        $this->grantUserRolePermissions(['posts.read']);

        // 원글: KO1 EN1 KO2 EN2 KO3 EN3 (시각·조회수 모두 이 순서로 커진다 — 다른 게시판 글보다 새롭고 많이 봤다)
        $base = now()->addMinutes(30);
        $order = ['KO1', 'EN1', 'KO2', 'EN2', 'KO3', 'EN3'];
        foreach ($order as $i => $name) {
            $this->ids[$name] = $this->createTestPost([
                'title' => "확장시험 {$name}",
                'content' => '확장공통어 본문',
                'category' => substr($name, 0, 2),
                'user_id' => $this->author->id,
                'view_count' => 800000 + $i * 10,
                'created_at' => $base->copy()->addMinutes($i),
            ]);
        }
        foreach (['EN', 'KO'] as $j => $cat) {
            $this->ids["{$cat}_NOTICE"] = $this->createTestPost([
                'title' => "확장시험 {$cat} 공지", 'content' => '확장공통어 공지', 'category' => $cat, 'is_notice' => true,
                'user_id' => $this->author->id, 'created_at' => $base->copy()->addMinutes(10 + $j),
            ]);
        }
        foreach (['KO', 'EN'] as $j => $cat) {
            $this->ids["{$cat}_REPLY"] = $this->createTestPost([
                'title' => "확장시험 {$cat} 답글", 'content' => '확장공통어 답글', 'category' => $cat,
                'parent_id' => $this->ids['EN3'], 'depth' => 1,
                'user_id' => $this->author->id, 'created_at' => $base->copy()->addMinutes(20 + $j),
            ]);
        }
        DB::table('board_posts')->where('id', $this->ids['EN3'])->update(['replies_count' => 2]);
    }

    /**
     * 테스트가 건 훅만 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->registered as [$name, $callback]) {
            HookManager::removeFilter($name, $callback);
        }
        Auth::forgetGuards();

        parent::tearDown();
    }

    /**
     * 이 게시판 글을 분류 EN 으로 좁히는 리스너를 겁니다.
     *
     * @return void 반환값 없음
     */
    private function hookNarrowToEn(): void
    {
        $boardId = $this->board->id;
        $callback = function (array $extensions, array $context) use ($boardId) {
            $this->payloads[] = $context;
            $extensions[] = function (Builder $query, array $ctx) use ($boardId) {
                $query->where(function (Builder $w) use ($query, $boardId) {
                    $w->where($query->qualifyColumn('board_id'), '<>', $boardId)
                        ->orWhere($query->qualifyColumn('category'), 'EN');
                });
            };

            return $extensions;
        };
        HookManager::addFilter(self::HOOK, $callback);
        $this->registered[] = [self::HOOK, $callback];
    }

    /**
     * 검색을 코어 LIKE 폴백으로 돌립니다 — InnoDB FULLTEXT 는 시험 트랜잭션 안의 커밋 전 행을 보지 못해 늘 0건이다.
     *
     * @return void 반환값 없음
     */
    private function useLikeSearch(): void
    {
        config(['scout.driver' => 'null']);
    }

    /**
     * 사용자 목록 첫 페이지의 [원글, 공지, 답글] 제목 접두를 돌려줍니다.
     *
     * @param  string  $context  목록 맥락 (user 또는 admin)
     * @return array{originals: array<int, string>, notices: array<int, string>, replies: array<int, string>, ids: array<int, int>} 분류별 제목
     */
    private function listPage(string $context = 'user'): array
    {
        $page = app(PostService::class)->getPosts($this->board->slug, [], 50, false, $context, $this->board);
        $out = ['originals' => [], 'notices' => [], 'replies' => [], 'ids' => []];
        foreach ($page->items() as $post) {
            $out['ids'][] = (int) $post->id;
            $key = $post->is_notice ? 'notices' : ($post->parent_id ? 'replies' : 'originals');
            $out[$key][] = (string) $post->category;
        }

        return $out;
    }

    /**
     * 게시판 목록: 원글이 EN 만 남는다.
     *
     * @scenario case=list
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_list_context_narrows_originals(): void
    {
        $this->hookNarrowToEn();

        $this->assertSame(['EN', 'EN', 'EN'], $this->listPage()['originals']);
    }

    /**
     * 공지: 첫 페이지 공지도 EN 만 남는다.
     *
     * @scenario case=notice
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_notice_context_narrows_notices(): void
    {
        $this->hookNarrowToEn();

        $this->assertSame(['EN'], $this->listPage()['notices']);
    }

    /**
     * 답글 줄: 원글 아래 답글도 EN 만 남는다.
     *
     * @scenario case=reply
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_reply_context_narrows_reply_rows(): void
    {
        $this->hookNarrowToEn();

        $this->assertSame(['EN'], $this->listPage()['replies']);
    }

    /**
     * 건수: 일반 글 수가 목록과 같은 조건으로 세어진다.
     *
     * @scenario case=count
     *
     * @effects list_and_count_share_conditions
     *
     * @return void 반환값 없음
     */
    public function test_count_context_matches_list(): void
    {
        $this->hookNarrowToEn();

        $total = app(PostService::class)->getTotalNormalPosts($this->board->slug, [], false, 'user')->total;
        $cached = app(PostService::class)->getCachedNormalPostCount($this->board->slug, $this->board->id, [], false, 'user')->total;

        $this->assertSame(3, $total);
        $this->assertSame(count($this->listPage()['originals']), $total, 'list 와 count 는 같은 조건이어야 페이지 수가 맞는다');
        $this->assertSame($total, $cached);
    }

    /**
     * 이전/다음: created_at(MAX/MIN 서브쿼리 경로)과 view_count 두 정렬 모두 EN 이웃만 고른다.
     *
     * @scenario case=adjacent
     *
     * @effects adjacent_subquery_covered
     *
     * @return void 반환값 없음
     */
    public function test_adjacent_context_covers_both_sort_paths(): void
    {
        $service = app(PostService::class);
        $without = $service->getAdjacentPosts($this->board->slug, $this->ids['EN2'], ['order_by' => 'created_at', 'order_direction' => 'desc'], false, $this->board, 'user');
        $this->assertSame($this->ids['KO3'], $without['prev']?->id, '사전: 확장이 없으면 바로 위는 KO3');

        Cache::flush();
        $this->hookNarrowToEn();

        foreach (['created_at', 'view_count'] as $orderBy) {
            $adjacent = $service->getAdjacentPosts($this->board->slug, $this->ids['EN2'], ['order_by' => $orderBy, 'order_direction' => 'desc'], false, $this->board, 'user');
            $this->assertSame($this->ids['EN3'], $adjacent['prev']?->id, "{$orderBy}: 이전 글은 EN3");
            $this->assertSame($this->ids['EN1'], $adjacent['next']?->id, "{$orderBy}: 다음 글은 EN1");
        }
    }

    /**
     * 상세의 답글 스레드: 원글 아래 답글도 EN 만 남는다(목록에서 숨긴 답글이 상세에서 보이지 않는다).
     *
     * @scenario case=thread
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_thread_context_narrows_detail_replies(): void
    {
        $service = app(PostService::class);
        $before = $service->getPostWithCounts($this->board->slug, $this->ids['EN3'], board: $this->board, context: 'user');
        $this->assertCount(2, $before->replies, '사전: 확장이 없으면 답글 2개');

        $this->hookNarrowToEn();

        $after = $service->getPostWithCounts($this->board->slug, $this->ids['EN3'], board: $this->board, context: 'user');
        $this->assertSame([$this->ids['EN_REPLY']], $after->replies->pluck('id')->all());
        $this->assertSame('user', collect($this->payloads)->firstWhere('context', 'thread')['audience']);
    }

    /**
     * 게시판 공개 검색: 목록·건수가 EN 만 센다.
     *
     * @scenario case=search
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_search_context_narrows_page_and_count(): void
    {
        $this->useLikeSearch();
        $service = app(PostService::class);
        $before = $service->countByKeyword($this->board->slug, '확장공통어')->total;
        $this->assertSame(10, $before, '사전: 공개 글 10건이 검색된다');

        $this->hookNarrowToEn();

        $page = $service->searchByKeyword($this->board->slug, '확장공통어', 'latest', 50);
        $this->assertSame(5, $service->countByKeyword($this->board->slug, '확장공통어')->total);
        $this->assertSame(['EN'], array_values(array_unique(array_map(fn ($p) => $p->category, $page->items()))));
    }

    /**
     * 통합검색: 목록·커서·배지 건수가 같은 집합이다.
     *
     * @scenario case=search_across
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_search_across_context_narrows_offset_cursor_and_badge(): void
    {
        $this->useLikeSearch();
        $this->hookNarrowToEn();
        $service = app(PostService::class);
        $boardIds = [$this->board->id];

        $offset = $service->searchAcrossBoards($boardIds, '확장공통어', 'latest', 50);
        $cursor = $service->searchAcrossBoardsByCursor($boardIds, '확장공통어', 'latest', 50, 'first', 1);
        $badge = $service->countAcrossBoards($boardIds, '확장공통어')->total;

        $this->assertSame(5, $badge);
        $this->assertCount(5, $offset->items());
        $this->assertNotNull($cursor);
        $this->assertSame(['EN'], array_values(array_unique(array_map(fn ($p) => $p->category, $cursor->items()))));
    }

    /**
     * 홈 최근 게시글: 이 게시판 항목은 EN 만, 다른 게시판 글은 그대로다.
     *
     * @scenario case=recent
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_recent_context_narrows_union_subqueries(): void
    {
        $this->hookNarrowToEn();

        $ids = array_column(app(BoardService::class)->getCachedRecentPosts(10), 'id');
        $this->assertSame([$this->ids['EN_NOTICE'], $this->ids['EN3'], $this->ids['EN2'], $this->ids['EN1']], array_values(array_intersect($ids, $this->ids)));
    }

    /**
     * 인기 게시글: 작성자·첨부 조인이 있어도 한정한 컬럼 조건이 동작한다.
     *
     * @scenario case=popular
     *
     * @effects joined_context_needs_qualified_columns
     *
     * @return void 반환값 없음
     */
    public function test_popular_context_works_with_joins(): void
    {
        $this->hookNarrowToEn();

        $ids = array_column(app(BoardService::class)->getCachedPopularPosts('week', 20), 'id');
        $this->assertSame([$this->ids['EN3'], $this->ids['EN2'], $this->ids['EN1']], array_values(array_intersect($ids, [$this->ids['KO1'], $this->ids['EN1'], $this->ids['KO2'], $this->ids['EN2'], $this->ids['KO3'], $this->ids['EN3']])));
    }

    /**
     * 게시판 카드 최근글: EN 만 남는다.
     *
     * @scenario case=board_recent
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_board_recent_context_narrows_card_posts(): void
    {
        $this->hookNarrowToEn();
        $service = app(BoardService::class);

        $byId = array_column($service->getBoardRecentPostsById($this->board->id, 10, $this->board), 'category');
        $bySlug = array_column($service->getBoardRecentPosts($this->board->slug, 10), 'category');

        $this->assertSame(['EN', 'EN', 'EN', 'EN'], $byId);
        $this->assertSame($byId, $bySlug);
    }

    /**
     * 마이페이지 활동·공개 프로필: 작성글과 댓글 단 글(조인 경로) 모두 EN 만, total 이 행 기준이다.
     *
     * @scenario case=user_activity
     *
     * @effects extension_narrows_each_list_context, joined_context_needs_qualified_columns
     *
     * @return void 반환값 없음
     */
    public function test_user_activity_context_narrows_authored_commented_and_public(): void
    {
        $this->createTestComment($this->ids['EN1'], ['user_id' => $this->author->id]);
        $this->createTestComment($this->ids['KO1'], ['user_id' => $this->author->id]);
        $this->hookNarrowToEn();
        Auth::setUser($this->author);
        $service = app(PostService::class);

        $authored = $service->getUserActivities($this->author->id, ['activity_type' => 'authored'], 50);
        $commented = $service->getUserActivities($this->author->id, ['activity_type' => 'commented'], 50);
        $public = $service->getUserPublicPosts($this->author->id, [], 50);

        $this->assertSame(5, $authored->total());
        $this->assertCount(5, $authored->items());
        $this->assertSame([$this->ids['EN1']], array_column($commented->items(), 'id'));
        $this->assertSame(5, $public->total());
    }

    /**
     * 활동 통계·공개 통계: 게시글 수가 EN 기준이다.
     *
     * @scenario case=user_stats
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_user_stats_context_narrows_post_counts(): void
    {
        $this->hookNarrowToEn();
        Auth::setUser($this->author);
        $service = app(PostService::class);

        $this->assertSame(5, $service->getUserActivityStats($this->author->id)['total_posts']);
        $this->assertSame(5, $service->getUserPublicStats($this->author->id)['posts_count']);
    }

    /**
     * 관리자 대시보드 최근글: 관리자 맥락으로 발행되고 조건이 걸린다.
     *
     * @scenario case=admin_dashboard
     *
     * @effects extension_narrows_each_list_context, context_payload_complete
     *
     * @return void 반환값 없음
     */
    public function test_admin_dashboard_context_is_admin_audience(): void
    {
        $this->hookNarrowToEn();

        $posts = app(BoardDashboardService::class)->getRecentPosts(50);
        $mine = $posts->where('board_id', $this->board->id);

        $this->assertSame(['EN'], $mine->pluck('category')->unique()->values()->all());
        $this->assertSame('admin', collect($this->payloads)->firstWhere('context', 'admin_dashboard')['audience']);
    }

    /**
     * 사이트맵: 게시글 URL 이 EN 글만 나온다.
     *
     * @scenario case=sitemap
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_sitemap_context_narrows_post_urls(): void
    {
        $this->hookNarrowToEn();

        $prefix = "/board/{$this->board->slug}/";
        $urls = [];
        foreach (app(BoardSitemapContributor::class)->getUrlsLazy() as $entry) {
            if (str_starts_with($entry['url'], $prefix)) {
                $urls[] = (int) substr($entry['url'], strlen($prefix));
            }
        }
        sort($urls);
        $expected = [$this->ids['EN1'], $this->ids['EN2'], $this->ids['EN3'], $this->ids['EN_NOTICE'], $this->ids['EN_REPLY']];
        sort($expected);

        $this->assertSame($expected, $urls);
    }

    /**
     * 리스너가 없으면 결과 id·순서가 지금과 같고, 조건 수집이 게시판 조회를 하지 않는다.
     *
     * @scenario case=no_listener
     *
     * @effects no_listener_keeps_current_result
     *
     * @return void 반환값 없음
     */
    public function test_no_listener_keeps_current_result(): void
    {
        $page = $this->listPage();

        $this->assertSame(['KO', 'EN'], $page['notices']);
        $this->assertSame(['EN', 'KO', 'EN', 'KO', 'EN', 'KO'], $page['originals']);
        $this->assertSame(['KO', 'EN'], $page['replies']);
        $this->assertSame(6, app(PostService::class)->getTotalNormalPosts($this->board->slug, [], false, 'user')->total);
    }

    /**
     * 호출할 수 없는 반환은 버리고 경고를 남기며, 나머지 조건은 그대로 건다.
     *
     * @scenario case=non_callable
     *
     * @effects non_callable_dropped_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_non_callable_entries_are_dropped_with_warning(): void
    {
        Log::spy();
        $this->hookNarrowToEn();
        $junk = fn (array $extensions) => array_merge($extensions, ['not-a-callable', 42]);
        HookManager::addFilter(self::HOOK, $junk, 20);
        $this->registered[] = [self::HOOK, $junk];

        $this->assertSame(['EN', 'EN', 'EN'], $this->listPage()['originals']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'list_query_extensions'))->atLeast()->times(2);
    }

    /**
     * 리스너가 받는 맥락 키 7개와 값(게시판 객체/null, audience, board_ids, user, locale, subject_user_id).
     *
     * @scenario case=context_payload
     *
     * @effects context_payload_complete
     *
     * @return void 반환값 없음
     */
    public function test_context_payload_has_all_keys(): void
    {
        $this->hookNarrowToEn();
        Auth::setUser($this->author);
        $service = app(PostService::class);

        $this->listPage('user');
        $this->listPage('admin');
        $service->countAcrossBoards([$this->board->id], '확장공통어');
        $service->getUserActivities($this->author->id, ['activity_type' => 'authored'], 10);

        $byContext = fn (string $c, string $audience = 'user') => collect($this->payloads)->first(fn ($p) => $p['context'] === $c && $p['audience'] === $audience);

        $list = $byContext('list');
        $this->assertSame(['context', 'audience', 'board', 'board_ids', 'user', 'locale', 'subject_user_id'], array_keys($list));
        $this->assertSame($this->board->id, $list['board']->id);
        $this->assertNull($list['board_ids']);
        $this->assertSame($this->author->id, $list['user']->id);
        $this->assertSame(app()->getLocale(), $list['locale']);
        $this->assertNotNull($byContext('list', 'admin'), '관리자 목록은 audience=admin');

        $across = $byContext('search_across');
        $this->assertNull($across['board']);
        $this->assertSame([$this->board->id], $across['board_ids']);

        $this->assertSame($this->author->id, $byContext('user_activity')['subject_user_id']);
    }

    /**
     * 리스너 등록 경로(HookListenerRegistrar)로 등록해도 필터가 동기로 돈다.
     *
     * @scenario case=registrar
     *
     * @effects extension_narrows_each_list_context
     *
     * @return void 반환값 없음
     */
    public function test_registrar_registered_listener_runs_synchronously(): void
    {
        ListQueryExtensionsTestListener::$boardId = $this->board->id;
        HookListenerRegistrar::register(ListQueryExtensionsTestListener::class, 'test-'.uniqid());

        $this->assertSame(['EN', 'EN', 'EN'], $this->listPage()['originals']);
    }
}

/**
 * 등록 경로 시험용 리스너 — 분류 EN 만 남긴다.
 */
class ListQueryExtensionsTestListener implements HookListenerInterface
{
    /** 좁힐 게시판 ID */
    public static int $boardId = 0;

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 구독 훅
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.post.list_query_extensions' => ['method' => 'extensions', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    /**
     * 인터페이스 요구 메서드 (필터는 지정 메서드를 직접 부른다).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 조건 함수를 더합니다.
     *
     * @param  array<int, callable>  $extensions  지금까지 모인 조건 함수
     * @param  array<string, mixed>  $context  목록 맥락
     * @return array<int, callable> 조건 함수
     */
    public function extensions(array $extensions, array $context): array
    {
        $boardId = self::$boardId;
        $extensions[] = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where($q->qualifyColumn('board_id'), '<>', $boardId)->orWhere($q->qualifyColumn('category'), 'EN'));

        return $extensions;
    }
}
