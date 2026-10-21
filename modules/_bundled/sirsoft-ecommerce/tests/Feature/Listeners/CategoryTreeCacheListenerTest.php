<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Listeners;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Listeners\CategoryTreeCacheListener;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 카테고리 트리 캐시 — 큐 워커 없이도 저장 직후 비워진다
 *
 * 트리 캐시는 모든 쇼핑 화면이 함께 쓰는 공유 캐시다. 비우기가 큐로 가면 워커가 돌 때까지 옛 트리가 보이고,
 * 워커가 없는 사이트에서는 TTL 이 끝날 때까지 새 카테고리가 메뉴에 나타나지 않는다. 비우기는 저장한 요청 안에서
 * 하되, 트랜잭션 안에서 발화하면 커밋 뒤에 비운다 — 커밋 전에 비우면 다른 요청이 옛 데이터로 다시 채운다.
 */
class CategoryTreeCacheListenerTest extends ModuleTestCase
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
        Category::flushTreeCache();
    }

    /**
     * 테스트용 루트 카테고리를 모델로 직접 만듭니다 (서비스 훅을 거치지 않는다).
     *
     * @param  string  $slug  슬러그
     * @return Category 생성된 카테고리
     */
    private function makeCategory(string $slug): Category
    {
        $category = Category::create([
            'name' => ['ko' => $slug],
            'slug' => $slug,
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'path' => '',
        ]);
        $category->update(['path' => (string) $category->id]);

        return $category;
    }

    /**
     * 트리 캐시 비우기 9훅이 모두 sync 다. (실패-먼저)
     *
     * @scenario listener=category_tree, queue=sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_every_tree_hook_is_synchronous(): void
    {
        $hooks = CategoryTreeCacheListener::getSubscribedHooks();

        $this->assertCount(9, $hooks);
        foreach ($hooks as $hook => $config) {
            $this->assertTrue(($config['sync'] ?? false) === true, "{$hook} 가 sync 가 아닙니다 — 큐로 가면 워커가 돌 때까지 옛 트리가 보입니다.");
        }
    }

    /**
     * 카테고리를 만든 직후, 워커가 돌지 않아도 트리에 새 카테고리가 보인다. (실패-먼저)
     *
     * @scenario listener=category_tree, queue=database
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_new_category_appears_in_tree_without_worker(): void
    {
        $before = count(Category::getTree());

        app(CategoryService::class)->createCategory([
            'name' => ['ko' => '실측724-T', 'en' => 'Queue 724'],
            'slug' => 'queue-724-tree',
            'is_active' => true,
        ]);

        $this->assertCount($before + 1, Category::getTree(), '카테고리를 만들었는데 트리 캐시가 비워지지 않았습니다.');
        $this->assertSame(
            0,
            DB::table('jobs')->where('payload', 'like', '%CategoryTreeCacheListener%')->count(),
            '트리 캐시 비우기가 큐 작업으로 쌓였습니다.'
        );
    }

    /**
     * 트랜잭션 안에서 발화하면 커밋 전에는 비우지 않고, 커밋 뒤에 비운다.
     *
     * @scenario listener=category_tree, queue=database
     *
     * @effects invalidation_deferred_until_commit
     *
     * @return void 반환값 없음
     */
    public function test_flush_inside_transaction_waits_for_commit(): void
    {
        $this->makeCategory('tx-base');
        $before = count(Category::getTree());

        DB::transaction(function () use ($before) {
            $category = $this->makeCategory('tx-new');

            HookManager::doAction('sirsoft-ecommerce.category.after_create', $category, []);

            $this->assertCount($before, Category::getTree(), '커밋 전에 트리 캐시가 비워졌습니다 — 다른 요청이 옛 데이터로 다시 채울 틈이 생깁니다.');
        });

        $this->assertCount($before + 1, Category::getTree(), '커밋 뒤에도 트리 캐시가 비워지지 않았습니다.');
    }
}
