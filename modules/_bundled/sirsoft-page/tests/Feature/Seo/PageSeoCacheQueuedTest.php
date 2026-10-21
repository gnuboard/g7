<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Listeners\SeoPageCacheListener;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * 페이지 SEO 캐시 — 큐 워커 없이도 저장 직후 비워진다
 *
 * 검색엔진용 캐시 비우기가 큐로 가면 워커가 돌 때까지(워커가 없으면 TTL 까지) 봇이 고치기 전 페이지나
 * 미발행으로 바꾼 페이지를 받는다. 페이지 리스너는 재렌더가 없어 전 구독을 sync 로 돌린다.
 */
class PageSeoCacheQueuedTest extends ModuleTestCase
{
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
     * 페이지 SEO 리스너의 전 구독이 sync 다. (실패-먼저)
     *
     * @scenario listener=seo_page, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_every_hook_is_synchronous(): void
    {
        foreach (SeoPageCacheListener::getSubscribedHooks() as $hook => $config) {
            $this->assertTrue(($config['sync'] ?? false) === true, "{$hook} 가 sync 가 아닙니다.");
        }
    }

    /**
     * 페이지를 수정한 직후, 워커가 돌지 않아도 그 페이지의 SEO 캐시가 사라진다. (실패-먼저)
     *
     * @scenario listener=seo_page, queue=database
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_page_cache_is_invalidated_without_worker(): void
    {
        $page = (object) ['id' => 724, 'slug' => 'queue-724', 'published' => true, 'updated_at' => now()];
        $cache = app(SeoCacheManagerInterface::class);
        $cache->put('/page/queue-724', 'ko', '<html>옛 페이지</html>');
        $this->assertNotNull($cache->get('/page/queue-724', 'ko'));

        HookManager::doAction('sirsoft-page.page.after_update', $page, []);

        $this->assertNull($cache->get('/page/queue-724', 'ko'), '페이지를 수정했는데 SEO 캐시가 남았습니다.');
        $this->assertSame(
            0,
            DB::table('jobs')->where('payload', 'like', '%SeoPageCacheListener%')->count(),
            'SEO 캐시 비우기가 큐 작업으로 쌓였습니다.'
        );
    }

    /**
     * 수정·삭제 두 핸들러 모두 트랜잭션 안에서 발화하면 커밋 전에는 SEO 캐시를 남겨 두고, 커밋 뒤에 비운다.
     *
     * 커밋 전에 비우면 그 사이 다른 요청(봇)이 옛 페이지로 캐시를 다시 채운다.
     *
     * @scenario listener=seo_page, queue=database
     *
     * @effects invalidation_deferred_until_commit
     *
     * @return void 반환값 없음
     */
    public function test_every_invalidation_handler_waits_for_commit(): void
    {
        $page = (object) ['id' => 7241, 'slug' => 'queue-724-tx', 'published' => true, 'updated_at' => now()];
        $cache = app(SeoCacheManagerInterface::class);
        $listener = app(SeoPageCacheListener::class);

        // 핸들러마다 따로 판정해 모은다 — 첫 실패에서 멈추면 나머지 핸들러가 잠겨 있는지 드러나지 않는다.
        $early = [];
        $missing = [];
        foreach (['onPageChange', 'onPageDelete'] as $method) {
            $cache->put('/page/queue-724-tx', 'ko', '<html>옛 페이지</html>');

            DB::transaction(function () use ($cache, $listener, $method, $page, &$early) {
                $listener->{$method}($page, []);

                if ($cache->get('/page/queue-724-tx', 'ko') === null) {
                    $early[] = $method;
                }
            });

            if ($cache->get('/page/queue-724-tx', 'ko') !== null) {
                $missing[] = $method;
            }
        }

        $this->assertSame([], $early, '커밋 전에 SEO 캐시를 비운 핸들러가 있습니다.');
        $this->assertSame([], $missing, '커밋 뒤에도 SEO 캐시가 남은 핸들러가 있습니다.');
    }
}
