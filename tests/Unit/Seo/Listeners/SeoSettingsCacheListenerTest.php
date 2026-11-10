<?php

namespace Tests\Unit\Seo\Listeners;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Listeners\SeoExtensionCacheListener;
use App\Listeners\SeoSettingsCacheListener;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * 코어 SEO 설정 리스너 테스트
 *
 * 코어 환경설정 SEO 탭 저장 시 전체 캐시 삭제를 검증합니다.
 */
class SeoSettingsCacheListenerTest extends TestCase
{
    private SeoSettingsCacheListener $listener;

    private SeoCacheManagerInterface $cacheMock;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = Mockery::mock(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $this->cacheMock);

        $this->listener = new SeoSettingsCacheListener;
    }

    /**
     * 테스트가 바꾼 상태를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── 훅 구독 등록 ──────────────────────────────────────

    /**
     * 훅 구독이 올바르게 등록되어 있는지 확인합니다.
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_returns_correct_mapping(): void
    {
        $hooks = SeoSettingsCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.settings.after_save', $hooks);
        $this->assertEquals('onSettingsSave', $hooks['core.settings.after_save']['method']);
        $this->assertEquals(20, $hooks['core.settings.after_save']['priority']);
    }

    /**
     * 두 구독이 모두 sync 다 — 큐로 가면 워커가 돌 때까지(없으면 TTL 까지) 옛 SEO 캐시가 남는다. (실패-먼저)
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_every_hook_is_synchronous(): void
    {
        foreach (SeoSettingsCacheListener::getSubscribedHooks() as $hook => $config) {
            $this->assertTrue(($config['sync'] ?? false) === true, "{$hook} 가 sync 가 아닙니다.");
        }
    }

    // ─── SEO 탭 저장 ──────────────────────────────────────

    /**
     * SEO 탭 저장 시 전체 캐시 삭제 + sitemap 삭제 확인
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_settings_save_clears_all_cache_for_seo_tab(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Core SEO settings changed — all cache cleared', Mockery::type('array'));

        $this->listener->onSettingsSave('seo', ['cache_ttl' => 3600], ['success' => true]);

        $this->addToAssertionCount(1);
    }

    // ─── 비-SEO 탭 저장 ──────────────────────────────────────

    /**
     * 봇 화면에 쓰이지 않는 탭(메일 등)은 아무 동작도 하지 않는지 확인
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_settings_save_does_nothing_for_non_seo_tab(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onSettingsSave('mail', ['host' => 'smtp.example.com'], ['success' => true]);

        $this->addToAssertionCount(1);
    }

    /**
     * 일반 탭 저장도 SEO 캐시를 비운다 (#434 결함 ②).
     *
     * 봇 화면은 사이트 이름(제목 접미 폴백)·로고·파비콘을 일반 탭에서 읽는다. 종전에는 SEO 탭만 구독해
     * 이름·아이콘을 바꿔도 봇 화면이 캐시 TTL 까지 옛 값이었다.
     *
     * @scenario case=core_seo_sync
     *
     * @effects seo_cache_cleared_on_general_save
     *
     * @return void 반환값 없음
     */
    public function test_on_settings_save_clears_all_cache_for_general_tab(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        $this->listener->onSettingsSave('general', ['site_name' => 'Test'], true);

        $this->addToAssertionCount(1);
    }

    /**
     * 템플릿 설정 저장(파비콘 등)도 SEO 캐시를 비운다 (#434).
     *
     * @scenario case=core_seo_sync
     *
     * @effects seo_cache_cleared_on_save
     *
     * @return void 반환값 없음
     */
    public function test_template_settings_save_clears_all_cache(): void
    {
        $hooks = SeoSettingsCacheListener::getSubscribedHooks();
        $this->assertSame('onTemplateSettingsSave', $hooks['core.template_settings.after_save']['method']);
        $this->assertTrue($hooks['core.template_settings.after_save']['sync']);

        $this->cacheMock->shouldReceive('clearAll')->once();

        $this->listener->onTemplateSettingsSave('sirsoft-basic', ['favicon' => [1]], true);
    }

    /**
     * 템플릿 설정 저장이 실패했으면 캐시를 두는지 확인 (#434).
     *
     * @scenario case=core_seo_sync
     *
     * @effects seo_cache_cleared_on_save
     *
     * @return void 반환값 없음
     */
    public function test_failed_template_settings_save_keeps_cache(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onTemplateSettingsSave('sirsoft-basic', [], false);

        $this->addToAssertionCount(1);
    }

    /**
     * 탭이 null인 경우 무시하는지 확인
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_settings_save_ignores_null_tab(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onSettingsSave(null);

        $this->addToAssertionCount(1);
    }

    // ─── 업로드 탭 썸네일 설정 (#869) ──────────────────────────

    /**
     * 업로드 탭에서 썸네일 값이 실제로 바뀌면 SEO 캐시를 비웁니다.
     *
     * 봇 화면의 og:image 와 본문 이미지 주소에 규격(`?size=`)이 구워져 있어, 바꾼 뒤에도 캐시가 남으면
     * 봇이 옛 규격 주소를 계속 받는다.
     *
     * @scenario surface=seo_cache
     *
     * @effects seo_cache_cleared_on_thumbnail_change_only
     *
     * @return void 반환값 없음
     */
    public function test_upload_tab_clears_cache_when_thumbnail_values_change(): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('upload', ['max_file_size' => 10, 'thumbnail_enabled' => true]);
        $this->cacheMock->shouldReceive('clearAll')->once();

        $this->listener->onBeforeSettingsSave(['_tab' => 'upload', 'upload' => ['thumbnail_enabled' => false]]);
        $this->listener->onSettingsSave('upload', ['max_file_size' => 10, 'thumbnail_enabled' => false], true);

        $this->addToAssertionCount(1);
    }

    /**
     * 업로드 탭의 다른 칸만 바꾼 저장은 SEO 캐시를 비우지 않습니다 — 저장할 때마다 전체 캐시를 다시 만들지 않는다.
     *
     * @scenario surface=seo_cache
     *
     * @effects seo_cache_cleared_on_thumbnail_change_only
     *
     * @return void 반환값 없음
     */
    public function test_upload_tab_keeps_cache_when_only_other_values_change(): void
    {
        app(ConfigRepositoryInterface::class)->saveCategory('upload', ['max_file_size' => 10, 'thumbnail_enabled' => true]);
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onBeforeSettingsSave(['_tab' => 'upload', 'upload' => ['max_file_size' => 20]]);

        // after_save 는 저장소 값(기본값 병합)에 보낸 값을 덮은 결과를 넘긴다 — SettingsService::saveSettings 와 같게 만든다.
        $merged = array_merge(app(ConfigRepositoryInterface::class)->getCategory('upload'), ['max_file_size' => 20]);
        $this->listener->onSettingsSave('upload', $merged, true);

        $this->addToAssertionCount(1);
    }

    /**
     * 저장 전 사본이 없으면(before_save 를 거치지 않은 발화) 바뀌었는지 알 수 없어 비웁니다. 실패한 저장은 비우지 않습니다.
     *
     * @scenario surface=seo_cache
     *
     * @effects seo_cache_cleared_on_thumbnail_change_only
     *
     * @return void 반환값 없음
     */
    public function test_upload_tab_without_snapshot_clears_and_failed_save_does_not(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        // 앞선 사본이 남아 있지 않게 다른 탭 before_save 로 비운다
        $this->listener->onBeforeSettingsSave(['_tab' => 'general']);
        $this->listener->onSettingsSave('upload', ['thumbnail_enabled' => true], true);
        $this->listener->onSettingsSave('upload', ['thumbnail_enabled' => false], false);

        $this->addToAssertionCount(1);
    }

    /**
     * 단건 저장으로 썸네일 키를 바꿔도 비웁니다.
     *
     * @scenario surface=seo_cache
     *
     * @effects seo_cache_cleared_on_thumbnail_change_only
     *
     * @return void 반환값 없음
     */
    public function test_on_setting_set_clears_cache_for_upload_thumbnail_key(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        $this->listener->onSettingSet('upload.thumbnail_content_preset', 'md', true);
        $this->listener->onSettingSet('upload.max_file_size', 20, true);

        $this->addToAssertionCount(1);
    }

    /**
     * 확장 활성·비활성(규격과 서빙 경로 패턴이 바뀐다)은 확장 리스너가 이미 전체를 비웁니다 — 이 리스너가 다시 구독하지 않는다.
     *
     * @scenario surface=seo_cache
     *
     * @effects seo_cache_cleared_on_thumbnail_change_only
     *
     * @return void 반환값 없음
     */
    public function test_extension_lifecycle_is_covered_by_extension_listener(): void
    {
        $extensionHooks = SeoExtensionCacheListener::getSubscribedHooks();

        foreach (['core.modules.after_activate', 'core.modules.after_deactivate', 'core.plugins.after_activate', 'core.plugins.after_deactivate'] as $hook) {
            $this->assertArrayHasKey($hook, $extensionHooks);
            $this->assertArrayNotHasKey($hook, SeoSettingsCacheListener::getSubscribedHooks(), '한 사건에 SEO 캐시를 두 번 비우게 됩니다.');
        }
    }

    // ─── 예외 처리 ──────────────────────────────────────

    /**
     * 캐시 삭제 중 예외 발생 시 graceful하게 처리되는지 확인
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_handles_exceptions_gracefully(): void
    {
        $this->cacheMock->shouldReceive('clearAll')
            ->andThrow(new \RuntimeException('Cache unavailable'));

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Core SEO settings cache invalidation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Cache unavailable';
            }));

        $this->listener->onSettingsSave('seo', [], []);

        $this->addToAssertionCount(1);
    }

    // ─── 단건 저장(after_set) ──────────────────────────────

    /**
     * 단건 저장 훅도 구독하는지 확인합니다. (실패-먼저)
     *
     * 단건 저장(`PUT /api/admin/settings/{key}`)은 `after_set` 을 발화하는데 리스너가
     * `after_save` 만 구독해, SEO 설정을 단건으로 바꾸면 캐시가 무효화되지 않았다.
     * `after_save` 를 추가 발화하지 않는 이유: 활동로그 리스너가 두 훅을 각각 기록해
     * 저장 1회가 로그 2건이 된다.
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_get_subscribed_hooks_includes_after_set(): void
    {
        $hooks = SeoSettingsCacheListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.settings.after_set', $hooks);
        $this->assertEquals('onSettingSet', $hooks['core.settings.after_set']['method']);
        $this->assertEquals(20, $hooks['core.settings.after_set']['priority']);
    }

    /**
     * seo 카테고리 단건 저장 시 전체 캐시를 삭제합니다. (실패-먼저)
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_setting_set_clears_cache_for_seo_key(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        $this->listener->onSettingSet('seo.title_suffix', ' | G7', true);

        $this->addToAssertionCount(1);
    }

    /**
     * 다른 카테고리의 단건 저장은 SEO 캐시를 건드리지 않습니다.
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_setting_set_ignores_non_seo_key(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onSettingSet('mail.host', 'smtp.example.com', true);

        $this->addToAssertionCount(1);
    }

    /**
     * 일반 범주 단건 저장도 SEO 캐시를 비운다 (#434 결함 ②).
     *
     * @scenario case=core_seo_sync
     *
     * @effects seo_cache_cleared_on_general_save
     *
     * @return void 반환값 없음
     */
    public function test_on_setting_set_clears_cache_for_general_key(): void
    {
        $this->cacheMock->shouldReceive('clearAll')->once();

        $this->listener->onSettingSet('general.site_name', 'Test', true);

        $this->addToAssertionCount(1);
    }

    /**
     * 저장 실패(result=false)면 캐시를 비우지 않습니다.
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_on_setting_set_ignores_failed_save(): void
    {
        $this->cacheMock->shouldNotReceive('clearAll');

        $this->listener->onSettingSet('seo.title_suffix', ' | G7', false);

        $this->addToAssertionCount(1);
    }

    // ─── 트랜잭션 안 발화 ───────────────────────────────────

    /**
     * 두 핸들러 모두 트랜잭션 안에서 발화하면 커밋 전에는 캐시를 비우지 않고, 커밋 뒤에 비운다.
     *
     * 커밋 전에 비우면 그 사이 다른 요청이 옛 설정으로 캐시를 다시 채운다.
     *
     * @scenario case=core_seo_sync
     *
     * @effects invalidation_deferred_until_commit
     *
     * @return void 반환값 없음
     */
    public function test_invalidation_inside_transaction_waits_for_commit(): void
    {
        $calls = 0;
        $this->cacheMock->shouldReceive('clearAll')->andReturnUsing(function () use (&$calls) {
            $calls++;
        });

        $handlers = [
            'onSettingsSave' => fn () => $this->listener->onSettingsSave('seo', [], true),
            'onSettingSet' => fn () => $this->listener->onSettingSet('seo.title_suffix', ' | G7', true),
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

    // ─── handle (인터페이스 준수) ───────────────────────────

    /**
     * handle 메서드가 존재하는지 확인합니다 (HookListenerInterface 준수).
     *
     * @scenario case=core_seo_sync
     *
     * @effects shared_cache_invalidated_without_worker
     *
     * @return void 반환값 없음
     */
    public function test_handle_method_exists(): void
    {
        $this->assertTrue(method_exists($this->listener, 'handle'));
        $this->listener->handle();
    }
}
