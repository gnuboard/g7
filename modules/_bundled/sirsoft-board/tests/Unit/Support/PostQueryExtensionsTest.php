<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Support;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\PostQueryExtensions;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 목록 쿼리 확장 조건 묶음(PostQueryExtensions) 단위 시험 (#147)
 *
 * 축 case 의 케이스 표시는 테스트 메서드마다 단다.
 */
class PostQueryExtensionsTest extends ModuleTestCase
{
    private const HOOK = 'sirsoft-board.post.list_query_extensions';

    /** @var array<int, callable> 이 테스트가 건 필터 */
    private array $registered = [];

    /**
     * 테스트가 건 훅만 걷어냅니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->registered as $callback) {
            HookManager::removeFilter(self::HOOK, $callback);
        }

        parent::tearDown();
    }

    /**
     * 필터를 걸고 tearDown 에서 걷도록 기록합니다.
     *
     * @param  callable  $callback  콜백
     * @return void 반환값 없음
     */
    private function hook(callable $callback): void
    {
        HookManager::addFilter(self::HOOK, $callback);
        $this->registered[] = $callback;
    }

    /**
     * 리스너가 없으면 훅을 부르지 않고 게시판 조회 클로저도 부르지 않으며 쿼리가 0건이다.
     *
     * @scenario case=no_listener
     *
     * @effects no_listener_keeps_current_result, no_extra_queries_without_listener
     *
     * @return void 반환값 없음
     */
    public function test_collect_without_listener_is_free(): void
    {
        $resolved = false;
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $extensions = PostQueryExtensions::collect(['list', 'count'], ['board' => function () use (&$resolved) {
            $resolved = true;

            return null;
        }]);

        $this->assertTrue($extensions->isEmpty());
        $this->assertFalse($resolved, '리스너가 없으면 게시판을 조회하지 않는다');
        $this->assertSame(0, $queries);
        $this->assertTrue(PostQueryExtensions::none()->isEmpty());
    }

    /**
     * 배열이 아닌 반환·호출할 수 없는 원소는 버리고 경고를 남긴다.
     *
     * @scenario case=non_callable
     *
     * @effects non_callable_dropped_with_warning
     *
     * @return void 반환값 없음
     */
    public function test_non_callable_and_non_array_returns_are_dropped(): void
    {
        Log::spy();
        $this->hook(fn (array $extensions, array $context) => $context['context'] === 'list' ? 'oops' : ['nope']);

        $extensions = PostQueryExtensions::collect(['list', 'count']);

        $this->assertTrue($extensions->isEmpty());
        Log::shouldHaveReceived('warning')->twice();
    }

    /**
     * 구분값마다 한 번 발행하고, 그 구분값의 조건만 순서대로 맥락과 함께 적용한다.
     *
     * @scenario case=context_payload
     *
     * @effects context_payload_complete
     *
     * @return void 반환값 없음
     */
    public function test_apply_runs_only_that_contexts_closures_in_order(): void
    {
        $calls = [];
        $this->hook(function (array $extensions, array $context) use (&$calls) {
            $extensions[] = function ($query, array $ctx) use (&$calls) {
                $calls[] = $ctx['context'].':first';
            };
            $extensions[] = function ($query, array $ctx) use (&$calls) {
                $calls[] = $ctx['context'].':second';
            };

            return $extensions;
        });

        $extensions = PostQueryExtensions::collect(['list', 'count'], ['audience' => 'admin', 'board_ids' => ['3', 4], 'subject_user_id' => '7']);
        $extensions->apply('count', Post::query());
        $extensions->apply('sitemap', Post::query());

        $this->assertFalse($extensions->isEmpty());
        $this->assertSame(['count:first', 'count:second'], $calls);

        $seen = null;
        $this->hook(function (array $extensions, array $context) use (&$seen) {
            $seen = $context;

            return $extensions;
        });
        PostQueryExtensions::collect(['list'], ['audience' => 'nonsense', 'board_ids' => ['3', 4], 'subject_user_id' => '7']);

        $this->assertSame('user', $seen['audience'], '모르는 audience 는 user 로 떨어진다');
        $this->assertSame([3, 4], $seen['board_ids']);
        $this->assertSame(7, $seen['subject_user_id']);
        $this->assertNull($seen['board']);
        $this->assertSame(app()->getLocale(), $seen['locale']);
    }
}
