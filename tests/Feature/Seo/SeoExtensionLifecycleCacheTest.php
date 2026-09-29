<?php

namespace Tests\Feature\Seo;

use App\Extension\HookManager;
use App\Listeners\SeoExtensionCacheListener;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 확장 생명주기 — 끄거나 지워도 SEO 캐시가 비워진다 (#138 후속 전수 점검 7번)
 *
 * SEO 캐시 정리는 설치·켜기·업데이트(그것도 deprecated 된 옛 훅 이름)에만 걸려 있어, 게시판 모듈을 끄거나
 * 지워도 검색 봇은 캐시에 남은 게시판 페이지를 계속 받았다(SeoMiddleware 는 캐시 적중 시 모듈 활성 여부를
 * 보지 않는다). 또 큐 실행이라 워커가 없는 사이트에서는 설치 때조차 비워지지 않았다.
 *
 * 핸들러를 직접 부르지 않고 실제 발화 이름으로 doAction 해 캐시가 비는지 본다.
 */
class SeoExtensionLifecycleCacheTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 생명주기 완료 훅과 인자 목록.
     *
     * @return array<string, array{0: string, 1: array<int, mixed>}> 케이스 목록
     */
    public static function lifecycleHookProvider(): array
    {
        return [
            'module deactivate' => ['core.modules.after_deactivate', ['sirsoft-board']],
            'module uninstall' => ['core.modules.after_uninstall', ['sirsoft-board', [], false]],
            'plugin deactivate' => ['core.plugins.after_deactivate', ['sirsoft-marketing']],
            'plugin uninstall' => ['core.plugins.after_uninstall', ['sirsoft-marketing', false, true]],
            'template deactivate' => ['core.templates.after_deactivate', ['sirsoft-basic']],
            'template uninstall' => ['core.templates.after_uninstall', ['sirsoft-basic', [], false]],
            'module install' => ['core.modules.after_install', ['sirsoft-board', []]],
            'module update' => ['core.modules.after_update', ['sirsoft-board', ['success' => true], []]],
            'template version update' => ['core.templates.after_version_update', ['sirsoft-basic', ['success' => true], []]],
        ];
    }

    /**
     * 확장 생명주기 완료 훅이 발화하면 SEO 페이지 캐시가 비워진다.
     *
     * @scenario lifecycle=deactivate_uninstall_install_update
     *
     * @effects seo_cache_cleared_on_extension_lifecycle
     *
     * @param  string  $hook  발화할 훅
     * @param  array<int, mixed>  $args  훅 인자
     * @return void 반환값 없음
     */
    #[DataProvider('lifecycleHookProvider')]
    public function test_lifecycle_hook_clears_seo_page_cache(string $hook, array $args): void
    {
        $cache = app(SeoCacheManagerInterface::class);
        $cache->put('/board/free', 'ko', '<html>게시판</html>');
        $this->assertNotNull($cache->get('/board/free', 'ko'));

        HookManager::doAction($hook, ...$args);

        $this->assertNull($cache->get('/board/free', 'ko'), "{$hook} 발화 뒤에도 SEO 캐시가 남았다");
    }

    /**
     * 캐시 정리는 즉시 반영돼야 하는 부수효과라 모든 구독이 동기 실행이다(큐 워커가 없어도 돈다).
     *
     * @scenario lifecycle=subscription_contract
     *
     * @effects seo_cache_listener_is_sync
     *
     * @return void 반환값 없음
     */
    public function test_all_subscriptions_are_synchronous(): void
    {
        foreach (SeoExtensionCacheListener::getSubscribedHooks() as $hook => $config) {
            $this->assertTrue($config['sync'] ?? false, "{$hook} 구독이 큐로 간다");
        }
    }
}
