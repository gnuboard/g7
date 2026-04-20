<?php

namespace Tests\Unit\Cache\Migration;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use App\Contracts\Repositories\NotificationTemplateRepositoryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationTemplateService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 그룹 D 이관 검증 테스트 (NotificationDefinitionService, NotificationTemplateService)
 *
 * 계획서 §13 D-1, D-2 의 10개 테스트 케이스를 검증합니다.
 * - NotificationDefinitionService: 정의 캐시 생성/히트/무효화/all_active (5건)
 * - NotificationTemplateService: 템플릿 캐시 생성/히트/수정/토글/복원 (5건)
 *
 * 실제 CoreCacheDriver(array store)를 사용하며, Repository는 mock 처리합니다.
 */
class NotificationCacheTest extends TestCase
{
    private CoreCacheDriver $cache;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // 훅 리스너가 큐 직렬화를 거쳐 model을 잃어버리는 것을 방지
        Bus::fake();

        $this->cache = new CoreCacheDriver('array');
        $this->app->instance(CacheInterface::class, $this->cache);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ========================================================================
    // D-1. NotificationDefinitionService
    // ========================================================================

    /**
     * D-1-1: 알림 정의 캐시 생성 — resolve() 호출 시 새 키에 저장.
     */
    #[Test]
    public function d_1_1_definition_cache_stored_under_new_key(): void
    {
        $definition = new NotificationDefinition(['type' => 'order.created']);

        $repo = $this->mock(NotificationDefinitionRepositoryInterface::class);
        $repo->shouldReceive('getActiveByType')->with('order.created')->once()->andReturn($definition);

        $service = app(NotificationDefinitionService::class);
        $service->resolve('order.created');

        $this->assertTrue($this->cache->has('notification.definition.order.created'));
        $this->assertSame(
            'g7:core:notification.definition.order.created',
            $this->cache->resolveKey('notification.definition.order.created')
        );
    }

    /**
     * D-1-2: 알림 정의 캐시 히트 — 두 번째 호출은 Repository 미호출.
     */
    #[Test]
    public function d_1_2_definition_cache_hit_skips_repository(): void
    {
        $definition = new NotificationDefinition(['type' => 'order.created']);

        $repo = $this->mock(NotificationDefinitionRepositoryInterface::class);
        $repo->shouldReceive('getActiveByType')->with('order.created')->once()->andReturn($definition);

        $service = app(NotificationDefinitionService::class);
        $service->resolve('order.created');
        $service->resolve('order.created');
    }

    /**
     * D-1-3: 정의 수정 시 type 키 + all_active 키 모두 무효화.
     */
    #[Test]
    public function d_1_3_update_definition_invalidates_both_keys(): void
    {
        $this->cache->put('notification.definition.order.created', 'cached');
        $this->cache->put('notification.definition.all_active', 'cached');

        $definition = new NotificationDefinition(['type' => 'order.created']);

        $repo = $this->mock(NotificationDefinitionRepositoryInterface::class);
        $repo->shouldReceive('update')->andReturn($definition);

        $service = app(NotificationDefinitionService::class);
        $service->updateDefinition($definition, ['title' => 'new']);

        $this->assertFalse($this->cache->has('notification.definition.order.created'));
        $this->assertFalse($this->cache->has('notification.definition.all_active'));
    }

    /**
     * D-1-4: 활성/비활성 토글 시 캐시 무효화.
     */
    #[Test]
    public function d_1_4_toggle_active_invalidates_cache(): void
    {
        $this->cache->put('notification.definition.order.created', 'cached');
        $this->cache->put('notification.definition.all_active', 'cached');

        $definition = new NotificationDefinition(['type' => 'order.created', 'is_active' => true]);

        $repo = $this->mock(NotificationDefinitionRepositoryInterface::class);
        $repo->shouldReceive('update')->andReturn($definition);

        $service = app(NotificationDefinitionService::class);
        $service->toggleActive($definition);

        $this->assertFalse($this->cache->has('notification.definition.order.created'));
        $this->assertFalse($this->cache->has('notification.definition.all_active'));
    }

    /**
     * D-1-5: 전체 활성 정의 캐시 생성 + 히트.
     */
    #[Test]
    public function d_1_5_all_active_cache_created_and_hit(): void
    {
        $definitions = new EloquentCollection([
            new NotificationDefinition(['type' => 'a']),
            new NotificationDefinition(['type' => 'b']),
        ]);

        $repo = $this->mock(NotificationDefinitionRepositoryInterface::class);
        $repo->shouldReceive('getAllActive')->once()->andReturn($definitions);

        $service = app(NotificationDefinitionService::class);
        $service->getAllActive();

        $this->assertTrue($this->cache->has('notification.definition.all_active'));

        // 히트 — Repository 추가 호출 없음 (mock once())
        $service->getAllActive();
    }

    // ========================================================================
    // D-2. NotificationTemplateService
    // ========================================================================

    /**
     * D-2-1: 알림 템플릿 캐시 생성 — 새 키 형식.
     */
    #[Test]
    public function d_2_1_template_cache_stored_under_new_key(): void
    {
        $template = new NotificationTemplate(['channel' => 'database']);

        $templateRepo = $this->mock(NotificationTemplateRepositoryInterface::class);
        $templateRepo->shouldReceive('getActiveByTypeAndChannel')
            ->with('order.created', 'database')
            ->once()
            ->andReturn($template);
        $this->mock(NotificationDefinitionRepositoryInterface::class);

        $service = app(NotificationTemplateService::class);
        $service->resolve('order.created', 'database');

        $this->assertTrue($this->cache->has('notification.template.order.created.database'));
        $this->assertSame(
            'g7:core:notification.template.order.created.database',
            $this->cache->resolveKey('notification.template.order.created.database')
        );
    }

    /**
     * D-2-2: 알림 템플릿 캐시 히트 — 재호출 시 Repository 미호출.
     */
    #[Test]
    public function d_2_2_template_cache_hit_skips_repository(): void
    {
        $template = new NotificationTemplate(['channel' => 'database']);

        $templateRepo = $this->mock(NotificationTemplateRepositoryInterface::class);
        $templateRepo->shouldReceive('getActiveByTypeAndChannel')->once()->andReturn($template);
        $this->mock(NotificationDefinitionRepositoryInterface::class);

        $service = app(NotificationTemplateService::class);
        $service->resolve('order.created', 'database');
        $service->resolve('order.created', 'database');
    }

    /**
     * D-2-3: 템플릿 수정 시 캐시 무효화.
     */
    #[Test]
    public function d_2_3_update_template_invalidates_cache(): void
    {
        $this->cache->put('notification.template.order.created.database', 'cached');

        $definition = new NotificationDefinition(['type' => 'order.created']);
        $template = $this->makeTemplateWithDefinition($definition, 'database');

        $templateRepo = $this->mock(NotificationTemplateRepositoryInterface::class);
        $templateRepo->shouldReceive('update')->andReturn($template);
        $this->mock(NotificationDefinitionRepositoryInterface::class);

        $service = app(NotificationTemplateService::class);
        $service->updateTemplate($template, ['subject' => 'new']);

        $this->assertFalse($this->cache->has('notification.template.order.created.database'));
    }

    /**
     * D-2-4: 템플릿 활성/비활성 토글 시 무효화.
     */
    #[Test]
    public function d_2_4_toggle_template_active_invalidates_cache(): void
    {
        $this->cache->put('notification.template.order.created.database', 'cached');

        $definition = new NotificationDefinition(['type' => 'order.created']);
        $template = $this->makeTemplateWithDefinition($definition, 'database');
        $template->is_active = true;

        $templateRepo = $this->mock(NotificationTemplateRepositoryInterface::class);
        $templateRepo->shouldReceive('update')->andReturn($template);
        $this->mock(NotificationDefinitionRepositoryInterface::class);

        $service = app(NotificationTemplateService::class);
        $service->toggleActive($template);

        $this->assertFalse($this->cache->has('notification.template.order.created.database'));
    }

    /**
     * D-2-5: 기본값 복원 시 무효화.
     */
    #[Test]
    public function d_2_5_reset_to_default_invalidates_cache(): void
    {
        $this->cache->put('notification.template.order.created.database', 'cached');

        $definition = new NotificationDefinition(['type' => 'order.created']);
        $template = $this->makeTemplateWithDefinition($definition, 'database');

        $templateRepo = $this->mock(NotificationTemplateRepositoryInterface::class);
        $templateRepo->shouldReceive('update')->andReturn($template);
        $this->mock(NotificationDefinitionRepositoryInterface::class);

        $service = app(NotificationTemplateService::class);
        $service->resetToDefault($template, ['subject' => 'default', 'body' => 'default']);

        $this->assertFalse($this->cache->has('notification.template.order.created.database'));
    }

    // ------------------------------------------------------------------------
    // 헬퍼
    // ------------------------------------------------------------------------

    /**
     * NotificationTemplate 인스턴스에 definition 관계를 직접 주입합니다.
     * (Eloquent setRelation 패턴)
     */
    private function makeTemplateWithDefinition(NotificationDefinition $definition, string $channel): NotificationTemplate
    {
        $template = new NotificationTemplate(['channel' => $channel]);
        $template->setRelation('definition', $definition);

        return $template;
    }
}
