<?php

namespace Tests\Feature\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoInvalidationRegistry;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SEO 캐시 무효화 통합 테스트
 *
 * SeoInvalidationRegistry의 서비스 컨테이너 등록, 규칙 등록 및 무효화 실행을 검증합니다.
 */
class SeoCacheInvalidationTest extends TestCase
{
    /**
     * SeoInvalidationRegistry가 서비스 컨테이너에 싱글톤으로 등록되어 있는지 검증합니다.
     */
    public function test_registry_is_registered_as_singleton(): void
    {
        $instance1 = $this->app->make(SeoInvalidationRegistry::class);
        $instance2 = $this->app->make(SeoInvalidationRegistry::class);

        $this->assertSame($instance1, $instance2);
    }

    /**
     * 레지스트리에 등록된 규칙이 여러 resolve에서도 유지되는지 검증합니다.
     */
    public function test_registered_rules_persist_across_resolves(): void
    {
        $this->mock(SeoCacheManagerInterface::class);

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);
        $registry->registerRule('product.created', ['product-list', 'home']);

        /** @var SeoInvalidationRegistry $resolved */
        $resolved = $this->app->make(SeoInvalidationRegistry::class);

        $rules = $resolved->getRulesForHook('product.created');
        $this->assertCount(1, $rules);
        $this->assertSame(['product-list', 'home'], $rules[0]['layouts']);
    }

    /**
     * invalidate()가 레이아웃 기반 캐시 무효화를 올바르게 호출하는지 검증합니다.
     */
    public function test_invalidate_calls_cache_manager_for_layouts(): void
    {
        $mockManager = $this->mock(SeoCacheManagerInterface::class);
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('product-list')
            ->once()
            ->andReturn(3);
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('home')
            ->once()
            ->andReturn(1);

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);
        $registry->registerRule('product.created', ['product-list', 'home']);

        $count = $registry->invalidate('product.created');

        $this->assertSame(4, $count);
    }

    /**
     * URL 패턴이 지정된 규칙에서 invalidateByUrl도 호출되는지 검증합니다.
     */
    public function test_invalidate_calls_cache_manager_for_url_pattern(): void
    {
        $mockManager = $this->mock(SeoCacheManagerInterface::class);
        $mockManager->shouldReceive('invalidateByUrl')
            ->with('/products/*')
            ->once()
            ->andReturn(5);
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('product-detail')
            ->once()
            ->andReturn(2);

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);
        $registry->registerRule('product.updated', ['product-detail'], '/products/*');

        $count = $registry->invalidate('product.updated');

        $this->assertSame(7, $count);
    }

    /**
     * 등록되지 않은 훅에 대한 invalidate()가 0을 반환하는지 검증합니다.
     */
    public function test_invalidate_returns_zero_for_unregistered_hook(): void
    {
        $this->mock(SeoCacheManagerInterface::class);

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);

        $count = $registry->invalidate('unknown.hook');

        $this->assertSame(0, $count);
    }

    /**
     * 여러 훅에 각각 다른 레이아웃 규칙이 독립적으로 무효화되는지 검증합니다.
     */
    public function test_multiple_hooks_with_different_layouts_invalidate_correctly(): void
    {
        $mockManager = $this->mock(SeoCacheManagerInterface::class);

        // product.created 훅 호출 시
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('product-list')
            ->once()
            ->andReturn(2);

        // order.completed 훅에 등록된 레이아웃은 호출되지 않아야 함
        $mockManager->shouldNotReceive('invalidateByUrl');

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);
        $registry->registerRule('product.created', ['product-list']);
        $registry->registerRule('order.completed', ['order-confirm'], '/orders/*');

        $count = $registry->invalidate('product.created');

        $this->assertSame(2, $count);

        // 각 훅의 규칙이 독립적으로 존재하는지 확인
        $this->assertCount(1, $registry->getRulesForHook('product.created'));
        $this->assertCount(1, $registry->getRulesForHook('order.completed'));
    }

    /**
     * 캐시 매니저에서 예외 발생 시 로그를 남기고 계속 진행하는지 검증합니다.
     */
    public function test_invalidate_logs_warning_on_cache_manager_exception(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, '[SEO] Registry invalidation failed')
                    && $context['hook'] === 'product.deleted'
                    && str_contains($context['error'], 'Cache error');
            });

        $mockManager = $this->mock(SeoCacheManagerInterface::class);
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('product-list')
            ->once()
            ->andThrow(new \RuntimeException('Cache error'));

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);
        $registry->registerRule('product.deleted', ['product-list']);

        $count = $registry->invalidate('product.deleted');

        $this->assertSame(0, $count);
    }

    /**
     * 동일 훅에 여러 규칙을 등록했을 때 모두 실행되는지 검증합니다.
     */
    public function test_multiple_rules_for_same_hook_all_execute(): void
    {
        $mockManager = $this->mock(SeoCacheManagerInterface::class);
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('product-list')
            ->once()
            ->andReturn(2);
        $mockManager->shouldReceive('invalidateByLayout')
            ->with('category-page')
            ->once()
            ->andReturn(3);
        $mockManager->shouldReceive('invalidateByUrl')
            ->with('/categories/*')
            ->once()
            ->andReturn(1);

        /** @var SeoInvalidationRegistry $registry */
        $registry = $this->app->make(SeoInvalidationRegistry::class);
        $registry->registerRule('product.created', ['product-list']);
        $registry->registerRule('product.created', ['category-page'], '/categories/*');

        $count = $registry->invalidate('product.created');

        $this->assertSame(6, $count);
        $this->assertCount(2, $registry->getRulesForHook('product.created'));
    }
}
