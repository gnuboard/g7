<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoCacheRegenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Board\Listeners\SeoBoardCacheListener;
use Modules\Sirsoft\Board\Listeners\SeoBoardSettingsCacheListener;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 SEO 캐시 — 비우기는 저장한 요청 안에서, 상세 재렌더·재활성 대량 재색인은 큐로
 *
 * 검색엔진용 캐시 비우기가 큐로 가면 워커가 돌 때까지(워커가 없으면 TTL 까지) 봇이 고치기 전 글·블라인드한 글을
 * 받는다. 비우기와 게시판 행 하나의 색인 갱신은 sync 로 돌리고, 상세 페이지를 미리 다시 그리는 일과 게시판
 * 재활성화 때 게시글 수천 건을 다시 색인하는 일만 별도 큐 리스너가 맡는다.
 */
class BoardSeoCacheQueuedTest extends BoardTestCase
{
    /**
     * 새 큐 리스너의 클래스 이름 (수정 전에는 클래스가 없어 문자열로 둔다).
     */
    private const REGENERATE_LISTENER = 'Modules\\Sirsoft\\Board\\Listeners\\SeoBoardRegenerateListener';

    /**
     * 큐를 database 로 바꾸고 워커는 돌리지 않습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /**
     * 비우기 리스너 2종의 전 구독이 sync 다. (실패-먼저)
     *
     * @scenario listener=seo_board, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_invalidation_listeners_are_synchronous(): void
    {
        foreach ([SeoBoardCacheListener::class, SeoBoardSettingsCacheListener::class] as $listener) {
            foreach ($listener::getSubscribedHooks() as $hook => $config) {
                $this->assertTrue(($config['sync'] ?? false) === true, class_basename($listener)." 의 {$hook} 가 sync 가 아닙니다.");
            }
        }
    }

    /**
     * 게시글을 수정한 직후, 워커가 돌지 않아도 그 글 상세의 SEO 캐시가 사라진다. (실패-먼저)
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_post_detail_cache_is_invalidated_without_worker(): void
    {
        $this->app->instance(SeoCacheRegenerator::class, Mockery::spy(SeoCacheRegenerator::class));
        $post = Post::findOrFail($this->createTestPost(['title' => '실측724 게시글']));
        $url = "/board/{$this->board->slug}/{$post->id}";
        $cache = app(SeoCacheManagerInterface::class);
        $cache->put($url, 'ko', '<html>옛 글</html>');
        $this->assertNotNull($cache->get($url, 'ko'));

        HookManager::doAction('sirsoft-board.post.after_update', $post, $this->board->slug);

        $this->assertNull($cache->get($url, 'ko'), '글을 수정했는데 SEO 캐시가 남았습니다 — 봇이 고치기 전 글을 받습니다.');
        $this->assertSame(
            0,
            DB::table('jobs')->where('payload', 'like', '%SeoBoardCacheListener%')->count(),
            'SEO 캐시 비우기가 큐 작업으로 쌓였습니다.'
        );
    }

    /**
     * 글 생성·수정·블라인드·복구의 상세 재렌더와 게시판 수정의 게시글 재색인은 새 큐 리스너로 넘어간다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects heavy_work_stays_queued
     *
     * @return void 반환값 없음
     */
    public function test_heavy_work_is_pushed_to_queue_listener(): void
    {
        Queue::fake();
        $regenerator = Mockery::mock(SeoCacheRegenerator::class);
        $regenerator->shouldNotReceive('renderAndCache');
        $this->app->instance(SeoCacheRegenerator::class, $regenerator);

        $post = Post::findOrFail($this->createTestPost(['title' => '실측724 큐']));

        foreach (['after_create', 'after_update', 'after_blind', 'after_restore'] as $event) {
            HookManager::doAction("sirsoft-board.post.{$event}", $post, $this->board->slug);
        }
        HookManager::doAction('sirsoft-board.board.after_update', $this->board->fresh(), ['is_active' => true], ['is_active' => false]);

        $pushed = Queue::pushed(DispatchHookListenerJob::class)
            ->filter(fn (DispatchHookListenerJob $job) => $job->listenerClass === self::REGENERATE_LISTENER)
            ->map(fn (DispatchHookListenerJob $job) => $job->method)
            ->values()
            ->all();

        $this->assertSame(
            ['onPostChange', 'onPostChange', 'onPostChange', 'onPostChange', 'onBoardUpdate'],
            $pushed,
            '상세 재렌더·재활성 재색인이 새 큐 리스너로 넘어가지 않았습니다.'
        );
        Queue::assertNotPushed(
            DispatchHookListenerJob::class,
            fn (DispatchHookListenerJob $job) => $job->listenerClass === SeoBoardCacheListener::class
        );
    }

    /**
     * 비우기 핸들러 7개 모두 트랜잭션 안에서 발화하면 커밋 전에는 SEO 캐시를 비우지 않고, 커밋 뒤에 비운다.
     *
     * 커밋 전에 비우면 그 사이 다른 요청(봇)이 옛 글·옛 설정으로 캐시를 다시 채운다.
     *
     * @scenario listener=seo_board, queue=database
     *
     * @effects invalidation_deferred_until_commit
     *
     * @return void 반환값 없음
     */
    public function test_every_invalidation_handler_waits_for_commit(): void
    {
        Queue::fake();
        $calls = 0;
        $cache = Mockery::mock(SeoCacheManagerInterface::class)->shouldIgnoreMissing();
        $cache->shouldReceive('invalidateByLayout', 'invalidateByUrl', 'clearAll')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return 0;
        });
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        $post = Post::findOrFail($this->createTestPost(['title' => '실측724 커밋']));
        $comment = (object) ['post_id' => $post->id];
        $slug = $this->board->slug;
        $posts = app(SeoBoardCacheListener::class);
        $settings = app(SeoBoardSettingsCacheListener::class);

        $handlers = [
            'post.onPostCreate' => fn () => $posts->onPostCreate($post, $slug),
            'post.onPostUpdate' => fn () => $posts->onPostUpdate($post, $slug),
            'post.onPostDelete' => fn () => $posts->onPostDelete($post, $slug),
            'post.onCommentChange' => fn () => $posts->onCommentChange($comment, $slug),
            'board.onBoardUpdate' => fn () => $posts->onBoardUpdate($this->board->fresh(), [], []),
            'settings.onModuleSettingsSave' => fn () => $settings->onModuleSettingsSave('sirsoft-board', []),
            'settings.onBulkApply' => fn () => $settings->onBulkApply([]),
        ];

        // 핸들러마다 따로 판정해 모은다 — 첫 실패에서 멈추면 나머지 핸들러가 잠겨 있는지 드러나지 않는다.
        $early = [];
        $missing = [];
        foreach ($handlers as $name => $fire) {
            $calls = 0;

            DB::transaction(function () use ($fire, $name, &$calls, &$early) {
                $fire();

                if ($calls > 0) {
                    $early[] = $name;
                }
            });

            if ($calls === 0) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $early, '커밋 전에 SEO 캐시를 비운 핸들러가 있습니다.');
        $this->assertSame([], $missing, '커밋 뒤에도 SEO 캐시를 비우지 않은 핸들러가 있습니다.');
    }
}
