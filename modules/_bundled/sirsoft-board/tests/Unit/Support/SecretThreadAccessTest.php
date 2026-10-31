<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Support;

require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../Concerns/BuildsSecretThread.php';

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\SecretViewTokenService;
use Modules\Sirsoft\Board\Support\SecretThreadAccess;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Tests\Concerns\BuildsSecretThread;

/**
 * 스레드 경로 판정의 성능·안전 경계 (#789 §4.2)
 *
 * 쿼리 수 상한(토큰 없는 비회원 0, 메모리 트리 재조회 없음, 목록 prime 상수), 순환·다른 게시판
 * 데이터에서의 fail-closed, grant 변화에 따른 메모 무효화를 고정한다.
 *
 * @scenario link=board-secret-thread-visibility
 */
class SecretThreadAccessTest extends BoardTestCase
{
    use BuildsSecretThread;

    /**
     * 시험 게시판 슬러그
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-thread-access-unit';
    }

    /**
     * 판정기
     *
     * @return SecretThreadAccess 판정기
     */
    private function access(): SecretThreadAccess
    {
        return app(SecretThreadAccess::class);
    }

    /**
     * 게시판 관계를 붙인 게시글 모델을 읽습니다.
     *
     * @param  string  $key  스레드 글 키
     * @return Post 게시글
     */
    private function threadPostModel(string $key): Post
    {
        $post = Post::withTrashed()->findOrFail($this->thread[$key]);
        $post->setRelation('board', $this->board);

        return $post;
    }

    /**
     * 콜백이 돌린 쿼리 수를 셉니다.
     *
     * @param  callable  $callback  실행할 콜백
     * @return array{0: mixed, 1: int} [결과, 쿼리 수]
     */
    private function countQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $count];
    }

    /**
     * 회원 ID 도 grant 도 없는 비회원은 쿼리 없이 false 입니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects guest_without_token_runs_zero_queries
     *
     * @return void 반환값 없음
     */
    public function test_guest_without_token_runs_zero_queries(): void
    {
        $this->buildSecretThread();
        $post = $this->threadPostModel('D');
        Auth::logout();

        [$reaches, $queries] = $this->countQueries(fn () => $this->access()->reachesThroughThread($post, Request::create('/'), $this->board->slug));

        $this->assertFalse($reaches);
        $this->assertSame(0, $queries);
    }

    /**
     * 부모 사슬이 순환하면 상한 안에서 끝나고 열지 않습니다 (fail-closed).
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects cyclic_chain_fails_closed
     *
     * @return void 반환값 없음
     */
    public function test_ancestor_walk_terminates_on_cycle_fail_closed(): void
    {
        $this->buildSecretThread();
        // A 의 부모를 D 로 바꿔 A→D→B→A 순환을 만든다
        DB::table('board_posts')->where('id', $this->thread['A'])->update(['parent_id' => $this->thread['D']]);
        $this->actingAs($this->threadUsers['outsider']);

        $reaches = $this->access()->reachesThroughThread($this->threadPostModel('B'), Request::create('/'), $this->board->slug);
        $ancestors = $this->access()->secretAncestors($this->threadPostModel('B'), Request::create('/'));

        $this->assertFalse($reaches);
        $this->assertNull($ancestors, '순환 사슬은 판정 불가(null)로 답변을 막는다');
    }

    /**
     * 다른 게시판 글로 이어지는 부모 사슬은 따라가지 않습니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects walk_constrained_to_board
     *
     * @return void 반환값 없음
     */
    public function test_walk_constrained_to_board_id(): void
    {
        $this->buildSecretThread();
        $other = Board::updateOrCreate(
            ['slug' => 'secret-thread-access-unit-other'],
            [...$this->getDefaultBoardAttributes('secret-thread-access-unit-other')]
        );
        $foreignId = DB::table('board_posts')->insertGetId([
            'board_id' => $other->id,
            'title' => '다른 게시판 글',
            'content' => '본문',
            'user_id' => $this->threadUsers['outsider']->id,
            'author_name' => 'x',
            'is_secret' => true,
            'status' => 'published',
            'trigger_type' => 'user',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // 오염 데이터: A 의 부모가 다른 게시판의 outsider 글
        DB::table('board_posts')->where('id', $this->thread['A'])->update(['parent_id' => $foreignId]);
        $this->actingAs($this->threadUsers['outsider']);

        $this->assertFalse($this->access()->reachesThroughThread($this->threadPostModel('D'), Request::create('/'), $this->board->slug));
    }

    /**
     * 상세처럼 답변 트리와 부모가 메모리에 있으면 트리 안 글의 조상 판정에 추가 쿼리가 없습니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects loaded_tree_needs_no_extra_ancestor_query
     *
     * @return void 반환값 없음
     */
    public function test_loaded_reply_tree_needs_no_extra_query(): void
    {
        $this->buildSecretThread();
        $this->actingAs($this->threadUsers['a']);
        $root = app(PostRepositoryInterface::class)->findWithCounts($this->board->slug, $this->thread['A'], board: $this->board);
        $request = Request::create('/');

        // 루트를 먼저 판정하며 트리가 등록된다 — 이후 트리 안 D 의 조상 판정은 쿼리 0
        $this->access()->reachesThroughThread($root, $request, $this->board->slug);
        $d = $root->replies->firstWhere('id', $this->thread['B'])->replies->firstWhere('id', $this->thread['D']);

        [$reaches, $queries] = $this->countQueries(fn () => $this->access()->reachesThroughThread($d, $request, $this->board->slug));

        $this->assertTrue($reaches);
        $this->assertSame(0, $queries);
    }

    /**
     * 같은 요청에서 grant 가 늘면 판정 메모가 새 값으로 바뀝니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects verdict_memo_invalidated_after_new_grant
     *
     * @return void 반환값 없음
     */
    public function test_verdict_memo_invalidated_after_new_grant(): void
    {
        $this->buildSecretThread(guestRoot: true);
        Auth::logout();
        $request = Request::create('/');
        $post = $this->threadPostModel('D');

        $this->assertFalse($this->access()->reachesThroughThread($post, $request, $this->board->slug));

        $tokens = app(SecretViewTokenService::class);
        $issued = $tokens->grant($request, $this->board->slug, $this->thread['A']);
        $tokens->useForRequest($issued['token'], $request);

        $this->assertTrue($this->access()->reachesThroughThread($post, $request, $this->board->slug));
    }

    /**
     * 목록 prime 의 쿼리 수는 페이지 크기와 무관합니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects prime_query_budget_independent_of_page_size
     *
     * @return void 반환값 없음
     */
    public function test_prime_query_budget_is_independent_of_page_size(): void
    {
        $this->buildSecretThread();
        $this->actingAs($this->threadUsers['b']);

        $measure = function (int $count): int {
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = $this->createThreadPost($this->thread['A'], $this->threadUsers['c'], 1, null, true, "목록 답변 {$i}");
            }
            $posts = Post::query()->whereIn('id', $ids)->get();
            $posts->each(fn (Post $post) => $post->setRelation('board', $this->board));
            $request = Request::create('/');

            [, $queries] = $this->countQueries(function () use ($posts, $request) {
                $this->access()->prime($posts, $request);
                foreach ($posts as $post) {
                    $this->access()->reachesThroughThread($post, $request, $this->board->slug);
                }
            });

            return $queries;
        };

        $small = $measure(3);
        $large = $measure(15);

        $this->assertSame($small, $large, '목록 크기가 늘어도 경로 판정 쿼리 수는 같아야 한다');
        $this->assertLessThanOrEqual(21, $large);
    }
}
