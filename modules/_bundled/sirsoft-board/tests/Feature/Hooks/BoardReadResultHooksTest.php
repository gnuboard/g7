<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Hooks;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\PostScrap;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 사용자 조회 결과 훅 테스트 (#142)
 *
 * 사용자 조회 19곳에서 조회 전(before_*)·결과(filter_*_result)·조회 후(after_*) 훅이 실제 HTTP 요청 한 번에
 * 각 1회 발행되고, ctx 에 현재 사용자·화면 언어·경로별 값이 실리며, 리스너가 고친 값이 응답에 나타나는지 본다.
 * 결과 훅은 캐시를 읽은 뒤에 발행되므로 리스너가 결과를 바꿔도 공용 캐시는 그대로여야 한다.
 */
class BoardReadResultHooksTest extends ModuleTestCase
{
    /**
     * 결과 변환 표식
     */
    private const MARK = '[T142] ';

    private Board $boardA;

    private Board $boardB;

    private User $member;

    private User $other;

    private Post $postA1;

    private Post $postA2;

    private Comment $comment;

    /**
     * 게시판 2개·회원 글 2개·댓글·스크랩·반응·신고를 만든다 — 모든 경로의 기준 응답이 비지 않게.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 다른 시험이 남긴 게시판이 결과에 섞이지 않게 비활성화한다(트랜잭션 롤백)
        DB::table('boards')->update(['is_active' => false]);

        $this->boardA = Board::factory()->create(['slug' => 'i142h-a-'.uniqid(), 'name' => ['ko' => '훅A', 'en' => 'HookA'], 'use_comment' => true]);
        $this->boardB = Board::factory()->create(['slug' => 'i142h-b-'.uniqid(), 'name' => ['ko' => '훅B', 'en' => 'HookB'], 'use_comment' => true]);

        foreach ([$this->boardA, $this->boardB] as $board) {
            $this->grantRead($board);
        }

        $this->member = $this->createUser();
        $this->other = $this->createUser();

        $this->postA1 = $this->makePost($this->member, '첫 글');
        $this->postA2 = $this->makePost($this->member, '둘째 글');

        $this->comment = Comment::create([
            'board_id' => $this->boardA->id,
            'post_id' => $this->postA1->id,
            'user_id' => $this->member->id,
            'content' => '회원 댓글입니다',
            'depth' => 0,
            'ip_address' => '127.0.0.1',
        ]);

        PostScrap::create(['user_id' => $this->member->id, 'post_id' => $this->postA2->id, 'board_id' => $this->boardA->id]);

        $type = ReactionType::where('code', 'like')->first()
            ?? ReactionType::create(['code' => 'like', 'name' => ['ko' => '좋아요'], 'display_order' => 0, 'is_active' => true]);
        Reaction::create([
            'user_id' => $this->member->id,
            'target_type' => 'post',
            'target_id' => $this->postA1->id,
            'reaction_type_id' => $type->id,
            'board_id' => $this->boardA->id,
        ]);

        $report = Report::create([
            'board_id' => $this->boardA->id,
            'target_type' => 'post',
            'target_id' => $this->postA2->id,
            'author_id' => $this->member->id,
            'status' => 'pending',
            'last_reported_at' => now(),
        ]);
        ReportLog::create([
            'report_id' => $report->id,
            'reporter_id' => $this->member->id,
            'snapshot' => ['title' => '둘째 글', 'content' => '본문'],
            'reason_type' => 'spam',
            'reason_detail' => '',
        ]);

        app(BoardService::class)->clearAllBoardCaches();
    }

    /**
     * 비회원·회원 역할에 게시판 열람(글·댓글)을 준다.
     *
     * @param  Board  $board  대상 게시판
     * @return void 반환값 없음
     */
    private function grantRead(Board $board): void
    {
        foreach (['posts.read', 'comments.read'] as $key) {
            $permission = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$board->slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            foreach (['guest', 'user'] as $roleIdentifier) {
                Role::where('identifier', $roleIdentifier)->first()->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }

    /**
     * 게시판 A 에 글 하나를 만든다.
     *
     * @param  User  $author  작성자
     * @param  string  $title  제목
     * @return Post 만든 글
     */
    private function makePost(User $author, string $title): Post
    {
        return Post::create([
            'board_id' => $this->boardA->id,
            'title' => $title,
            'content' => '본문입니다',
            'ip_address' => '127.0.0.1',
            'user_id' => $author->id,
        ]);
    }

    /**
     * 경로 정의 — 훅 접두·요청·ctx 키·변환·응답 단언.
     *
     * @param  string  $path  경로 키
     * @return array{hook: string, url: string, auth: bool, ctx: array<int, string>, transform: callable, assert: callable} 경로 정의
     */
    private function definition(string $path): array
    {
        $base = '/api/modules/sirsoft-board';
        $a = $this->boardA->slug;
        $reverse = fn ($boards) => $boards->reverse()->values();
        $slugs = fn (array $json) => collect($json['data'] ?? [])->pluck('slug')->all();
        $emptyPage = function (AbstractPaginator $page) {
            $page->setCollection($page->getCollection()->take(0));

            return $page;
        };
        $marked = fn (array $json) => str_contains(json_encode($json, JSON_UNESCAPED_UNICODE), self::MARK);
        $rowsDropped = fn (array $baseline, array $json) => $this->assertTrue(
            $this->rowCount($baseline) > 0 && $this->rowCount($json) === 0,
            '리스너가 비운 목록이 응답에 나타나야 한다 (기준 '.$this->rowCount($baseline).'건)'
        );
        $replacedList = [['id' => -142, 'title' => self::MARK.'replaced']];

        return match ($path) {
            'board_menu' => ['hook' => 'board.%s_menu_list', 'url' => "{$base}/boards/board-menu", 'auth' => false, 'ctx' => [],
                'transform' => $reverse,
                'assert' => fn ($baseline, $json) => $this->assertSame(array_reverse($slugs($baseline)), $slugs($json))],
            'public_board_list' => ['hook' => 'board.%s_public_list', 'url' => "{$base}/boards", 'auth' => false, 'ctx' => ['recent_posts_limit'],
                'transform' => $reverse,
                'assert' => fn ($baseline, $json) => $this->assertSame(array_reverse($slugs($baseline)), $slugs($json))],
            'public_board_show' => ['hook' => 'board.%s_public_show', 'url' => "{$base}/boards/{$a}", 'auth' => false, 'ctx' => ['slug'],
                'transform' => function (Board $board) {
                    $copy = clone $board;
                    $copy->name = ['ko' => self::MARK.'게시판', 'en' => self::MARK.'board'];

                    return $copy;
                },
                'assert' => fn ($baseline, $json) => $this->assertTrue(! $marked($baseline) && $marked($json))],
            'board_stats' => ['hook' => 'board.%s_stats', 'url' => "{$base}/boards/stats", 'auth' => false, 'ctx' => [],
                'transform' => fn (array $stats) => ['boards' => 142] + $stats,
                'assert' => fn ($baseline, $json) => $this->assertSame(142, $json['data']['boards'])],
            'recent_posts' => ['hook' => 'post.%s_recent_list', 'url' => "{$base}/boards/posts/recent?limit=5", 'auth' => false, 'ctx' => ['limit'],
                'transform' => fn (array $items) => array_map(fn ($i) => ['title' => self::MARK.$i['title']] + $i, $items),
                'assert' => fn ($baseline, $json) => $this->assertTrue(count($baseline['data']) > 0 && ! $marked($baseline) && $marked($json))],
            'popular_boards' => ['hook' => 'board.%s_popular_list', 'url' => "{$base}/boards/popular-boards?limit=4", 'auth' => false, 'ctx' => ['limit'],
                'transform' => fn (array $items) => array_map(fn ($i) => ['name' => self::MARK.$i['name']] + $i, $items),
                'assert' => fn ($baseline, $json) => $this->assertTrue(count($baseline['data']) > 0 && ! $marked($baseline) && $marked($json))],
            'popular_posts' => ['hook' => 'post.%s_popular_list', 'url' => "{$base}/boards/popular?period=week&limit=10", 'auth' => false, 'ctx' => ['period', 'limit'],
                'transform' => fn (array $items) => $replacedList,
                'assert' => fn ($baseline, $json) => $this->assertSame($replacedList, $json['data'])],
            'post_list' => ['hook' => 'post.%s_public_list', 'url' => "{$base}/boards/{$a}/posts", 'auth' => false, 'ctx' => ['board', 'filters', 'with_trashed'],
                'transform' => fn (AbstractPaginator $page) => $page->through(function (Post $p) {
                    $copy = clone $p;
                    $copy->title = self::MARK.$p->title;

                    return $copy;
                }),
                'assert' => fn ($baseline, $json) => $this->assertTrue(! $marked($baseline) && $marked($json))],
            'post_show' => ['hook' => 'post.%s_public_show', 'url' => "{$base}/boards/{$a}/posts/{$this->postA1->id}", 'auth' => false, 'ctx' => ['board', 'post_id'],
                'transform' => function (Post $p) {
                    $copy = clone $p;
                    $copy->title = self::MARK.$p->title;

                    return $copy;
                },
                'assert' => fn ($baseline, $json) => $this->assertTrue(! $marked($baseline) && $marked($json))],
            'post_adjacent' => ['hook' => 'post.%s_adjacent', 'url' => "{$base}/boards/{$a}/posts/{$this->postA2->id}/navigation", 'auth' => false, 'ctx' => ['board', 'post_id', 'filters'],
                'transform' => fn (array $adjacent) => ['prev' => null, 'next' => null],
                'assert' => function ($baseline, $json) {
                    $this->assertTrue(
                        ($baseline['data']['prev'] ?? null) !== null || ($baseline['data']['next'] ?? null) !== null,
                        '기준 응답에 이웃 글이 있어야 한다'
                    );
                    $this->assertNull($json['data']['prev']);
                    $this->assertNull($json['data']['next']);
                }],
            'comment_list' => ['hook' => 'comment.%s_public_list', 'url' => "{$base}/boards/{$a}/posts/{$this->postA1->id}/comments", 'auth' => false, 'ctx' => ['board', 'post_id', 'paginated'],
                'transform' => fn (EloquentCollection $comments) => $comments->map(function (Comment $c) {
                    $copy = clone $c;
                    $copy->content = self::MARK.$c->content;

                    return $copy;
                }),
                'assert' => fn ($baseline, $json) => $this->assertTrue(! $marked($baseline) && $marked($json))],
            'my_activities' => ['hook' => 'post.%s_user_activity_list', 'url' => "{$base}/me/board-activities", 'auth' => true, 'ctx' => ['subject_user_id', 'filters'],
                'transform' => $emptyPage, 'assert' => $rowsDropped],
            'my_activity_stats' => ['hook' => 'post.%s_user_activity_stats', 'url' => "{$base}/me/activity-stats", 'auth' => true, 'ctx' => ['subject_user_id'],
                'transform' => fn (array $stats) => ['total_posts' => 142] + $stats,
                'assert' => fn ($baseline, $json) => $this->assertSame(142, $json['data']['total_posts'])],
            'my_comments' => ['hook' => 'comment.%s_user_list', 'url' => "{$base}/me/my-comments", 'auth' => true, 'ctx' => ['subject_user_id', 'filters'],
                'transform' => $emptyPage, 'assert' => $rowsDropped],
            'user_public_posts' => ['hook' => 'post.%s_user_public_list', 'url' => "{$base}/users/{$this->member->uuid}/posts", 'auth' => false, 'ctx' => ['subject_user_id', 'filters'],
                'transform' => $emptyPage, 'assert' => $rowsDropped],
            'user_public_stats' => ['hook' => 'post.%s_user_public_stats', 'url' => "{$base}/users/{$this->member->uuid}/posts/stats", 'auth' => false, 'ctx' => ['subject_user_id'],
                'transform' => fn (array $stats) => ['posts_count' => 142] + $stats,
                'assert' => fn ($baseline, $json) => $this->assertSame(142, $json['data']['posts_count'])],
            'my_scraps' => ['hook' => 'scrap.%s_user_list', 'url' => "{$base}/me/scraps", 'auth' => true, 'ctx' => ['subject_user_id', 'search', 'search_field'],
                'transform' => $emptyPage, 'assert' => $rowsDropped],
            'my_reactions' => ['hook' => 'reaction.%s_user_list', 'url' => "{$base}/me/reactions", 'auth' => true, 'ctx' => ['subject_user_id', 'filters'],
                'transform' => $emptyPage, 'assert' => $rowsDropped],
            'my_reports' => ['hook' => 'report.%s_user_list', 'url' => "{$base}/me/reports", 'auth' => true, 'ctx' => ['subject_user_id', 'filters'],
                'transform' => $emptyPage, 'assert' => $rowsDropped],
        };
    }

    /**
     * 응답에서 목록 행 수를 센다 (`data` 가 목록이면 그 길이, 아니면 `data.data` 등 첫 목록 칸).
     *
     * @param  array<string, mixed>  $json  응답 본문
     * @return int 행 수
     */
    private function rowCount(array $json): int
    {
        $data = $json['data'] ?? [];
        if (is_array($data) && array_is_list($data)) {
            return count($data);
        }
        foreach (['data', 'items', 'posts', 'comments', 'scraps', 'reactions', 'reports', 'activities'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return count($data[$key]);
            }
        }

        return 0;
    }

    /**
     * 경로 요청을 보낸다.
     *
     * @param  array<string, mixed>  $def  경로 정의
     * @return TestResponse 응답
     */
    private function fetchPath(array $def): TestResponse
    {
        if (! $def['auth']) {
            // 앞 요청의 actingAs 가 남아 있으면 비회원 경로가 회원 요청이 된다
            $this->app['auth']->forgetGuards();
        }
        $request = $def['auth'] ? $this->actingAs($this->member) : $this;

        return $request->getJson($def['url'], ['Accept-Language' => 'ko']);
    }

    /**
     * 한 경로의 기준 응답 → 리스너 등록 → 같은 요청을 보내 세 훅의 발행·인자·변환을 단언한다.
     *
     * @param  string  $path  경로 키
     * @return void 반환값 없음
     */
    private function assertPathHooks(string $path): void
    {
        $def = $this->definition($path);
        $prefix = 'sirsoft-board.'.$def['hook'];
        $before = sprintf($prefix, 'before');
        $filter = str_replace('.%s_', '.filter_', $prefix).'_result';
        $after = sprintf($prefix, 'after');

        $baseline = $this->fetchPath($def)->assertOk()->json();

        $calls = ['before' => [], 'filter' => [], 'after' => []];
        HookManager::addAction($before, function (...$args) use (&$calls) {
            $calls['before'][] = $args;
        });
        HookManager::addFilter($filter, function ($value, ...$args) use (&$calls, $def) {
            $out = ($def['transform'])($value);
            $calls['filter'][] = ['in' => $value, 'out' => $out, 'args' => $args];

            return $out;
        });
        HookManager::addAction($after, function (...$args) use (&$calls) {
            $calls['after'][] = $args;
        });

        $json = $this->fetchPath($def)->assertOk()->json();

        $this->assertCount(1, $calls['before'], "{$before} 1회");
        $this->assertCount(1, $calls['filter'], "{$filter} 1회");
        $this->assertCount(1, $calls['after'], "{$after} 1회");

        // after 는 필터가 돌려준 값을 받는다
        $this->assertSame($calls['filter'][0]['out'], $calls['after'][0][0]);

        // ctx — 세 훅이 같은 요청 정보를 받는다
        $context = $calls['before'][0][0];
        $this->assertArrayHasKey('user', $context);
        $this->assertArrayHasKey('locale', $context);
        $this->assertSame('ko', $context['locale']);
        $this->assertSame($def['auth'] ? $this->member->id : null, $context['user']?->id);
        foreach ($def['ctx'] as $key) {
            $this->assertArrayHasKey($key, $context, "ctx.{$key}");
        }
        $this->assertSame($context, $calls['filter'][0]['args'][0]);
        $this->assertSame($context, $calls['after'][0][1]);

        ($def['assert'])($baseline, $json);
    }

    /**
     * 게시판 메뉴(board-menu) 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=board_menu, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, after_receives_filtered_value, context_carries_user_locale_and_path_keys, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_board_menu_hooks(): void
    {
        $this->assertPathHooks('board_menu');
    }

    /**
     * 게시판 목록 화면(/boards) 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=public_board_list, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_public_board_list_hooks(): void
    {
        $this->assertPathHooks('public_board_list');
    }

    /**
     * 게시판 정보 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=public_board_show, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_public_board_show_hooks(): void
    {
        $this->assertPathHooks('public_board_show');
    }

    /**
     * 게시판 통계 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=board_stats, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_board_stats_hooks(): void
    {
        $this->assertPathHooks('board_stats');
    }

    /**
     * 최근 게시글 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=recent_posts, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_recent_posts_hooks(): void
    {
        $this->assertPathHooks('recent_posts');
    }

    /**
     * 인기 게시판 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=popular_boards, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_popular_boards_hooks(): void
    {
        $this->assertPathHooks('popular_boards');
    }

    /**
     * 인기 게시글 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=popular_posts, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_popular_posts_hooks(): void
    {
        $this->assertPathHooks('popular_posts');
    }

    /**
     * 게시글 목록 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=post_list, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_post_list_hooks(): void
    {
        $this->assertPathHooks('post_list');
    }

    /**
     * 게시글 상세 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=post_show, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_post_show_hooks(): void
    {
        $this->assertPathHooks('post_show');
    }

    /**
     * 이전/다음 글 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=post_adjacent, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_post_adjacent_hooks(): void
    {
        $this->assertPathHooks('post_adjacent');
    }

    /**
     * 댓글 목록 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=comment_list, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_comment_list_hooks(): void
    {
        $this->assertPathHooks('comment_list');
    }

    /**
     * 마이페이지 활동 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=my_activities, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_my_activities_hooks(): void
    {
        $this->assertPathHooks('my_activities');
    }

    /**
     * 마이페이지 활동 통계 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=my_activity_stats, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_my_activity_stats_hooks(): void
    {
        $this->assertPathHooks('my_activity_stats');
    }

    /**
     * 마이페이지 내 댓글 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=my_comments, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_my_comments_hooks(): void
    {
        $this->assertPathHooks('my_comments');
    }

    /**
     * 회원 프로필 작성글 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=user_public_posts, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_user_public_posts_hooks(): void
    {
        $this->assertPathHooks('user_public_posts');
    }

    /**
     * 회원 프로필 통계 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=user_public_stats, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_user_public_stats_hooks(): void
    {
        $this->assertPathHooks('user_public_stats');
    }

    /**
     * 마이페이지 스크랩 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=my_scraps, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_my_scraps_hooks(): void
    {
        $this->assertPathHooks('my_scraps');
    }

    /**
     * 마이페이지 반응 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=my_reactions, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_my_reactions_hooks(): void
    {
        $this->assertPathHooks('my_reactions');
    }

    /**
     * 마이페이지 신고 경로에서 조회 전·결과·조회 후 훅이 한 번씩 발행되고, 리스너 변환이 응답에 나타난다.
     *
     * @scenario path=my_reports, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response
     *
     * @return void 반환값 없음
     */
    public function test_my_reports_hooks(): void
    {
        $this->assertPathHooks('my_reports');
    }

    /**
     * 비밀번호 확인으로 글을 열 때(비회원 글 비밀번호)도 게시글 상세 결과 훅이 한 번씩 발행되고 변환이 응답에 나타난다(W10).
     *
     * @scenario path=post_show, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response, password_and_paginated_variants_dispatch
     *
     * @return void 반환값 없음
     */
    public function test_post_show_hooks_on_password_verification(): void
    {
        $guestPost = Post::create([
            'board_id' => $this->boardA->id,
            'title' => '비회원 비밀글',
            'content' => '비회원 본문',
            'ip_address' => '127.0.0.1',
            'user_id' => null,
            'author_name' => '손님',
            'password' => bcrypt('i142-pass'),
            'is_secret' => true,
        ]);
        $calls = $this->listenPostShow();

        $this->app['auth']->forgetGuards();
        $json = $this->postJson("/api/modules/sirsoft-board/boards/{$this->boardA->slug}/posts/{$guestPost->id}/verify-password", ['password' => 'i142-pass'])
            ->assertOk()->json();

        $this->assertSame([1, 1, 1], [count($calls['before']), count($calls['filter']), count($calls['after'])]);
        $this->assertSame($guestPost->id, $calls['before'][0]['post_id']);
        $this->assertStringStartsWith(self::MARK, $json['data']['title']);
    }

    /**
     * 답변 글 비밀번호 확인(verify-thread-password)도 같은 상세 조회를 거치므로 결과 훅이 한 번씩 발행된다(W10).
     *
     * @scenario path=post_show, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, password_and_paginated_variants_dispatch
     *
     * @return void 반환값 없음
     */
    public function test_post_show_hooks_on_thread_password_verification(): void
    {
        $reply = Post::create([
            'board_id' => $this->boardA->id,
            'parent_id' => $this->postA1->id,
            'title' => '답변 글',
            'content' => '답변 본문',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->member->id,
            'depth' => 1,
        ]);
        $calls = $this->listenPostShow();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/modules/sirsoft-board/boards/{$this->boardA->slug}/posts/{$reply->id}/verify-thread-password", ['password' => 'whatever']);

        $this->assertSame([1, 1, 1], [count($calls['before']), count($calls['filter']), count($calls['after'])]);
        $this->assertSame($reply->id, $calls['before'][0]['post_id']);
    }

    /**
     * 댓글을 페이지로 나눠 싣는 상세(comment_page)도 댓글 결과 훅이 한 번씩 발행되고, ctx.paginated 가 true 이며
     * 변환이 응답에 나타난다.
     *
     * @scenario path=comment_list, listener=transform, audience=user
     *
     * @effects before_filter_after_dispatched_once, listener_transform_reaches_response, password_and_paginated_variants_dispatch
     *
     * @return void 반환값 없음
     */
    public function test_comment_list_hooks_on_paginated_detail(): void
    {
        $calls = new \ArrayObject(['before' => [], 'filter' => [], 'after' => []]);
        HookManager::addAction('sirsoft-board.comment.before_public_list', function ($ctx) use ($calls) {
            $calls['before'] = [...$calls['before'], $ctx];
        });
        HookManager::addFilter('sirsoft-board.comment.filter_public_list_result', function ($comments, $ctx) use ($calls) {
            $calls['filter'] = [...$calls['filter'], $ctx];

            return $comments->map(function (Comment $c) {
                $copy = clone $c;
                $copy->content = self::MARK.$c->content;

                return $copy;
            });
        });
        HookManager::addAction('sirsoft-board.comment.after_public_list', function ($comments, $ctx) use ($calls) {
            $calls['after'] = [...$calls['after'], $ctx];
        });

        $this->app['auth']->forgetGuards();
        $json = $this->getJson("/api/modules/sirsoft-board/boards/{$this->boardA->slug}/posts/{$this->postA1->id}?comment_page=1&comment_per_page=10")
            ->assertOk()->json();

        $this->assertSame([1, 1, 1], [count($calls['before']), count($calls['filter']), count($calls['after'])]);
        $this->assertTrue($calls['before'][0]['paginated']);
        $this->assertStringContainsString(self::MARK, json_encode($json['data']['comments'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 게시글 상세 결과 훅 세 개에 기록용 리스너를 건다(결과 리스너는 제목에 표식을 붙인 복제본을 돌려준다).
     *
     * @return \ArrayObject<string, array<int, mixed>> 훅별 받은 요청 정보
     */
    private function listenPostShow(): \ArrayObject
    {
        $calls = new \ArrayObject(['before' => [], 'filter' => [], 'after' => []]);
        HookManager::addAction('sirsoft-board.post.before_public_show', function ($ctx) use ($calls) {
            $calls['before'] = [...$calls['before'], $ctx];
        });
        HookManager::addFilter('sirsoft-board.post.filter_public_show_result', function (Post $post, $ctx) use ($calls) {
            $calls['filter'] = [...$calls['filter'], $ctx];
            $copy = clone $post;
            $copy->title = self::MARK.$post->title;

            return $copy;
        });
        HookManager::addAction('sirsoft-board.post.after_public_show', function ($post, $ctx) use ($calls) {
            $calls['after'] = [...$calls['after'], $ctx];
        });

        return $calls;
    }

    /**
     * 리스너가 없으면 응답은 훅이 없던 때와 같다(경로별 1회) — 같은 요청을 두 번 보내 바이트 동일을 보고, 값을 그대로
     * 돌려주는 리스너를 건 응답과도 바이트 동일을 본다(훅이 응답 모양을 바꾸지 않는다). 나머지 경로 표식은 본문 주석에 있다.
     *
     * @scenario path=board_menu, listener=none, audience=user
     *
     * @effects no_listener_response_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_responses_without_listener_are_unchanged(): void
    {
        // @scenario path=board_menu, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=public_board_list, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=public_board_show, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=board_stats, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=recent_posts, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=popular_boards, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=popular_posts, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=post_list, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=post_show, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=post_adjacent, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=comment_list, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=my_activities, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=my_activity_stats, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=my_comments, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=user_public_posts, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=user_public_stats, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=my_scraps, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=my_reactions, listener=none, audience=user
        // @effects no_listener_response_unchanged
        // @scenario path=my_reports, listener=none, audience=user
        // @effects no_listener_response_unchanged
        $paths = ['board_menu', 'public_board_list', 'public_board_show', 'board_stats', 'recent_posts', 'popular_boards',
            'popular_posts', 'post_list', 'post_show', 'post_adjacent', 'comment_list', 'my_activities', 'my_activity_stats',
            'my_comments', 'user_public_posts', 'user_public_stats', 'my_scraps', 'my_reactions', 'my_reports'];
        $this->assertCount(19, $paths);

        // 글 상세는 첫 조회에서 조회수가 1 오른다(같은 방문자의 다음 조회는 오르지 않는다) — 먼저 한 번 열어 둔다
        $this->fetchPath($this->definition('post_show'))->assertOk();

        foreach ($paths as $path) {
            $def = $this->definition($path);
            $first = $this->fetchPath($def)->assertOk()->getContent();
            $second = $this->fetchPath($def)->assertOk()->getContent();
            $this->assertSame($first, $second, "{$path} 응답이 리스너 없이도 같아야 한다");

            $filter = 'sirsoft-board.'.str_replace('.%s_', '.filter_', $def['hook']).'_result';
            $identity = fn ($value) => $value;
            HookManager::addFilter($filter, $identity);
            $passThrough = $this->fetchPath($def)->assertOk()->getContent();
            HookManager::removeFilter($filter, $identity);
            $this->assertSame($first, $passThrough, "{$path} 값을 그대로 돌려주는 리스너는 응답을 바꾸지 않는다");
        }

        // 메뉴 응답 계약(id·name·slug)은 그대로다 — 리스너용으로 더 읽은 컬럼이 새지 않는다
        $menu = $this->fetchPath($this->definition('board_menu'))->json('data.0');
        $this->assertSame(['id', 'name', 'slug'], array_keys($menu));
    }

    /**
     * 관리자 글 목록·글 상세(댓글 포함)는 사용자 조회 훅을 발행하지 않는다. 관리자 댓글 목록 조회 API 는 따로 없고
     * 관리자 상세가 댓글을 함께 싣는다. 나머지 경로 표식은 본문 주석에 있다.
     *
     * @scenario path=post_list, listener=transform, audience=admin
     *
     * @effects admin_paths_not_dispatched
     *
     * @return void 반환값 없음
     */
    public function test_admin_paths_do_not_dispatch_user_hooks(): void
    {
        // @scenario path=post_list, listener=transform, audience=admin
        // @effects admin_paths_not_dispatched
        // @scenario path=post_show, listener=transform, audience=admin
        // @effects admin_paths_not_dispatched
        // @scenario path=comment_list, listener=transform, audience=admin
        // @effects admin_paths_not_dispatched
        // @scenario path=post_adjacent, listener=transform, audience=admin
        // @effects admin_paths_not_dispatched
        $slug = $this->boardA->slug;
        $admin = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.posts.read",
            "sirsoft-board.{$slug}.admin.comments.read",
        ]);

        $count = 0;
        foreach (['post.filter_public_list_result', 'post.filter_public_show_result', 'post.filter_adjacent_result', 'comment.filter_public_list_result'] as $name) {
            HookManager::addFilter('sirsoft-board.'.$name, function ($value) use (&$count) {
                $count++;

                return $value;
            });
        }
        foreach (['post.before_public_list', 'post.before_public_show', 'post.before_adjacent', 'comment.before_public_list',
            'post.after_public_list', 'post.after_public_show', 'post.after_adjacent', 'comment.after_public_list'] as $name) {
            HookManager::addAction('sirsoft-board.'.$name, function () use (&$count) {
                $count++;
            });
        }

        $this->actingAs($admin)->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts")->assertOk();
        $this->actingAs($admin)->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts/{$this->postA1->id}")->assertOk();

        $this->assertSame(0, $count);
    }

    /**
     * 캐시된 결과 3종(메뉴·최근글·이전/다음 글): 리스너가 결과를 바꿔도 캐시 원본은 그대로이고,
     * 리스너를 지운 직후 다음 요청이 원래 값을 돌려준다(캐시 비우기 없이).
     *
     * @effects cached_source_unchanged_by_listener
     *
     * @return void 반환값 없음
     */
    public function test_cached_results_are_not_polluted_by_listener(): void
    {
        $cases = [
            'board_menu' => ['sirsoft-board.board.filter_menu_list_result', fn ($boards) => $boards->reverse()->values()],
            'recent_posts' => ['sirsoft-board.post.filter_recent_list_result', fn (array $items) => array_map(fn ($i) => ['title' => self::MARK.$i['title']] + $i, $items)],
            'post_adjacent' => ['sirsoft-board.post.filter_adjacent_result', fn (array $a) => ['prev' => null, 'next' => null]],
        ];

        foreach ($cases as $path => [$hook, $transform]) {
            $def = $this->definition($path);
            $original = $this->fetchPath($def)->assertOk()->getContent();

            HookManager::addFilter($hook, $transform);
            $changed = $this->fetchPath($def)->assertOk()->getContent();
            $this->assertNotSame($original, $changed, "{$path} 리스너 변환이 응답에 나타나야 한다");

            HookManager::removeFilter($hook, $transform);
            $this->assertSame($original, $this->fetchPath($def)->assertOk()->getContent(), "{$path} 리스너를 지우면 바로 원래 값");
        }
    }

    /**
     * 메뉴 리스너가 받은 모델의 속성을 제자리에서 바꿔도 같은 프로세스의 다음 호출 결과는 오염되지 않는다
     * — array 캐시 저장소는 같은 인스턴스를 돌려주므로 Service 가 복제본을 넘긴다.
     *
     * @effects menu_models_cloned_for_listener
     *
     * @return void 반환값 없음
     */
    public function test_menu_listener_cannot_mutate_cached_models(): void
    {
        $service = app(BoardService::class);
        $original = $service->getMenuBoards()->map(fn (Board $b) => $b->getLocalizedName())->all();

        $mutate = function ($boards) {
            foreach ($boards as $board) {
                $board->name = ['ko' => '오염', 'en' => 'polluted'];
            }

            return $boards;
        };
        HookManager::addFilter('sirsoft-board.board.filter_menu_list_result', $mutate);
        $service->getMenuBoards();
        HookManager::removeFilter('sirsoft-board.board.filter_menu_list_result', $mutate);

        $this->assertSame($original, $service->getMenuBoards()->map(fn (Board $b) => $b->getLocalizedName())->all());
    }

    /**
     * 확장이 선언형 리스너(`getSubscribedHooks` 의 type: filter)로 등록한 결과 훅도 동기로 돌아 응답을 바꾼다.
     *
     * @effects registrar_filter_listener_runs_sync
     *
     * @return void 반환값 없음
     */
    public function test_registrar_filter_listener_runs_synchronously(): void
    {
        $def = $this->definition('board_menu');
        $baseline = collect($this->fetchPath($def)->json('data'))->pluck('slug')->all();

        HookListenerRegistrar::register(ReverseMenuResultListener::class, 'i142-test-'.uniqid());

        $reversed = collect($this->fetchPath($def)->json('data'))->pluck('slug')->all();
        $this->assertSame(array_reverse($baseline), $reversed);
    }
}

/**
 * 시험용 선언형 리스너 — 게시판 메뉴 결과를 뒤집는다(#142 이슈 예시 A,B,C,D → D,C,B,A).
 */
class ReverseMenuResultListener implements HookListenerInterface
{
    /**
     * 구독 훅을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 구독 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-board.board.filter_menu_list_result' => ['method' => 'reverse', 'type' => 'filter', 'priority' => 10],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 메뉴 게시판 목록을 뒤집습니다.
     *
     * @param  Collection  $boards  메뉴 게시판 목록
     * @param  array<string, mixed>  $context  요청 정보
     * @return Collection 뒤집은 목록
     */
    public function reverse($boards, array $context = [])
    {
        return $boards->reverse()->values();
    }
}
