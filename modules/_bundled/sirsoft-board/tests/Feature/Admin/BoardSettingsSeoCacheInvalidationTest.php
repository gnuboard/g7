<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Mockery;
use Modules\Sirsoft\Board\Listeners\SeoBoardSettingsCacheListener;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 모듈 설정 저장 시 SEO 캐시 무효화 훅 발화 (B-5)
 *
 * `SeoBoardSettingsCacheListener` 는 `core.module_settings.after_save` 를 구독하지만
 * 그 훅의 유일한 발화 지점(`ModuleSettingsService::save()`)이 프로덕션에서 호출되지 않아
 * 게시판 환경설정을 저장해도 SEO 캐시가 무효화되지 않았다.
 *
 * 일괄 적용(bulkApply)은 이미 발화 중인 `sirsoft-board.settings.after_bulk_apply` 를
 * 리스너가 함께 구독해 커버한다 (신규 훅 발명 없음).
 */
class BoardSettingsSeoCacheInvalidationTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-board/admin/settings';

    private User $adminUser;

    /**
     * 훅 수신 기록
     *
     * @var array<int, array>
     */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);

        $this->received = [];
        HookManager::addAction('core.module_settings.after_save', function (...$args) {
            $this->received[] = $args;
        }, 5);
    }

    /**
     * 게시판 설정 저장 API 가 모듈 설정 저장 훅을 발화한다. (실패-먼저)
     *
     * @scenario save_path=bulk
     *
     * @effects module_settings_after_save_hook_fired
     */
    public function test_settings_save_fires_module_settings_after_save_hook(): void
    {
        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => ['per_page' => 30],
        ])->assertOk();

        $this->assertCount(1, $this->received, '모듈 설정 저장 훅이 발화되지 않았습니다.');
        [$identifier, $settings, $result] = $this->received[0];

        $this->assertSame('sirsoft-board', $identifier);
        $this->assertArrayHasKey('basic_defaults', $settings);
        $this->assertTrue($result);
    }

    /**
     * 게시판 SEO 캐시 리스너가 일괄 적용 훅도 구독한다. (실패-먼저)
     *
     * @scenario save_path=bulk_apply
     *
     * @effects seo_cache_invalidated_on_bulk_apply
     */
    public function test_seo_listener_subscribes_bulk_apply_hook(): void
    {
        $hooks = SeoBoardSettingsCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey(
            'sirsoft-board.settings.after_bulk_apply',
            $hooks,
            '일괄 적용 경로가 SEO 캐시 무효화를 거치지 않습니다.'
        );
        $this->assertSame('onBulkApply', $hooks['sirsoft-board.settings.after_bulk_apply']['method']);
    }

    /**
     * 일괄 적용 훅 핸들러는 식별자 인자 없이도 안전하게 동작한다.
     *
     * `after_bulk_apply` payload 는 ($fields, $updatedCount) 라 모듈 식별자가 없다 —
     * 식별자 가드를 그대로 재사용하면 조용히 무효화가 건너뛰어진다.
     *
     * @scenario save_path=bulk_apply
     *
     * @effects seo_cache_invalidated_on_bulk_apply
     */
    public function test_bulk_apply_handler_invalidates_without_identifier_arg(): void
    {
        $listener = new SeoBoardSettingsCacheListener;

        $this->assertTrue(
            method_exists($listener, 'onBulkApply'),
            '일괄 적용 전용 핸들러가 없습니다.'
        );

        // 예외 없이 완료되어야 한다 (내부 실패는 로깅으로 흡수)
        $listener->onBulkApply(['per_page' => 20], 3);
        $this->addToAssertionCount(1);
    }

    // ─── 실제 무효화 수행 ──────────────────────────────────────

    /**
     * 일괄 적용 핸들러가 게시판 레이아웃 캐시를 실제로 지운다.
     *
     * 예외가 안 나는 것과 무효화를 수행하는 것은 다르다 — 리스너 본문이 통째로 비어도
     * "예외 없음" 은 그대로 통과한다. 캐시 매니저를 mock 해 호출 자체를 단언한다.
     *
     * @scenario save_path=bulk_apply
     *
     * @effects seo_cache_invalidated_on_bulk_apply
     */
    public function test_bulk_apply_actually_invalidates_board_layouts(): void
    {
        $cache = Mockery::mock(SeoCacheManagerInterface::class);
        foreach (['board/index', 'board/show', 'board/boards'] as $layout) {
            $cache->shouldReceive('invalidateByLayout')->with($layout)->once();
        }
        $cache->shouldReceive('invalidateByLayout')->andReturnNull();
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        (new SeoBoardSettingsCacheListener)->onBulkApply(['per_page' => 20], 3);

        $this->addToAssertionCount(1);
    }

    /**
     * 설정 저장 훅도 같은 무효화를 수행한다.
     *
     * @scenario save_path=bulk
     *
     * @effects seo_cache_invalidated_on_module_settings_save
     */
    public function test_settings_save_actually_invalidates_board_layouts(): void
    {
        $cache = Mockery::mock(SeoCacheManagerInterface::class);
        $cache->shouldReceive('invalidateByLayout')->with('board/index')->atLeast()->once();
        $cache->shouldReceive('invalidateByLayout')->andReturnNull();
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        $this->actingAs($this->adminUser)->putJson($this->apiBase, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => ['per_page' => 30],
        ])->assertOk();

        $this->addToAssertionCount(1);
    }

    /**
     * 다른 모듈의 저장 훅에는 반응하지 않는다.
     *
     * @scenario save_path=bulk
     *
     * @effects seo_cache_untouched_for_other_module
     */
    public function test_other_module_save_does_not_invalidate_board_layouts(): void
    {
        $cache = Mockery::mock(SeoCacheManagerInterface::class);
        $cache->shouldNotReceive('invalidateByLayout');
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        (new SeoBoardSettingsCacheListener)->onModuleSettingsSave('sirsoft-ecommerce', ['seo' => []], true);

        $this->addToAssertionCount(1);
    }
}
