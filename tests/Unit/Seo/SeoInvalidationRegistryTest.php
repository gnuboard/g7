<?php

namespace Tests\Unit\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoInvalidationRegistry;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeoInvalidationRegistryTest extends TestCase
{
    private SeoCacheManagerInterface&MockInterface $cacheManager;

    private SeoInvalidationRegistry $registry;

    /**
     * 테스트 초기화 - SeoInvalidationRegistry 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheManager = Mockery::mock(SeoCacheManagerInterface::class);
        $this->registry = new SeoInvalidationRegistry($this->cacheManager);
    }

    /**
     * registerRule이 규칙을 올바르게 저장하는지 확인합니다.
     */
    public function test_register_rule_stores_rules_correctly(): void
    {
        $this->registry->registerRule('product.updated', ['product-detail', 'product-list'], '/products/*');

        $rules = $this->registry->getRulesForHook('product.updated');

        $this->assertCount(1, $rules);
        $this->assertSame(['product-detail', 'product-list'], $rules[0]['layouts']);
        $this->assertSame('/products/*', $rules[0]['urlPattern']);
    }

    /**
     * registerRule이 메서드 체이닝(fluent)을 지원하는지 확인합니다.
     */
    public function test_register_rule_supports_fluent_chaining(): void
    {
        $result = $this->registry
            ->registerRule('product.updated', ['product-detail'])
            ->registerRule('category.updated', ['category-list']);

        $this->assertInstanceOf(SeoInvalidationRegistry::class, $result);
        $this->assertCount(1, $this->registry->getRulesForHook('product.updated'));
        $this->assertCount(1, $this->registry->getRulesForHook('category.updated'));
    }

    /**
     * 동일 훅에 여러 규칙을 등록할 수 있는지 확인합니다.
     */
    public function test_multiple_rules_for_same_hook(): void
    {
        $this->registry->registerRule('product.updated', ['product-detail'], '/products/*');
        $this->registry->registerRule('product.updated', ['product-list'], '/categories/*');

        $rules = $this->registry->getRulesForHook('product.updated');

        $this->assertCount(2, $rules);
        $this->assertSame(['product-detail'], $rules[0]['layouts']);
        $this->assertSame('/products/*', $rules[0]['urlPattern']);
        $this->assertSame(['product-list'], $rules[1]['layouts']);
        $this->assertSame('/categories/*', $rules[1]['urlPattern']);
    }

    /**
     * 등록되지 않은 훅에 대해 invalidate가 0을 반환하는지 확인합니다.
     */
    public function test_invalidate_returns_zero_for_unregistered_hook(): void
    {
        $result = $this->registry->invalidate('unknown.hook');

        $this->assertSame(0, $result);
    }

    /**
     * invalidate가 invalidateByUrl과 invalidateByLayout을 올바르게 호출하는지 확인합니다.
     */
    public function test_invalidate_calls_cache_manager_correctly(): void
    {
        $this->registry->registerRule('product.updated', ['product-detail', 'product-list'], '/products/*');

        $this->cacheManager
            ->shouldReceive('invalidateByUrl')
            ->once()
            ->with('/products/*')
            ->andReturn(3);

        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->once()
            ->with('product-detail')
            ->andReturn(2);

        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->once()
            ->with('product-list')
            ->andReturn(1);

        $result = $this->registry->invalidate('product.updated');

        $this->assertSame(6, $result);
    }

    /**
     * urlPattern이 null일 때 URL 무효화를 건너뛰는지 확인합니다.
     */
    public function test_invalidate_skips_url_when_pattern_is_null(): void
    {
        $this->registry->registerRule('product.updated', ['product-detail']);

        $this->cacheManager
            ->shouldNotReceive('invalidateByUrl');

        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->once()
            ->with('product-detail')
            ->andReturn(2);

        $result = $this->registry->invalidate('product.updated');

        $this->assertSame(2, $result);
    }

    /**
     * 예외 발생 시 경고 로그를 남기고 계속 처리하는지 확인합니다.
     */
    public function test_invalidate_handles_exceptions_gracefully(): void
    {
        $this->registry->registerRule('product.updated', ['product-detail'], '/products/*');
        $this->registry->registerRule('product.updated', ['category-list']);

        // 첫 번째 규칙에서 예외 발생
        $this->cacheManager
            ->shouldReceive('invalidateByUrl')
            ->once()
            ->with('/products/*')
            ->andThrow(new \RuntimeException('Cache connection failed'));

        // 두 번째 규칙은 정상 처리
        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->once()
            ->with('category-list')
            ->andReturn(1);

        Log::shouldReceive('warning')
            ->once()
            ->with('[SEO] Registry invalidation failed', Mockery::on(function (array $context) {
                return $context['hook'] === 'product.updated'
                    && $context['error'] === 'Cache connection failed';
            }));

        $result = $this->registry->invalidate('product.updated');

        $this->assertSame(1, $result);
    }

    /**
     * invalidate가 캐시 매니저의 반환값 합계를 반환하는지 확인합니다.
     */
    public function test_invalidate_returns_total_count(): void
    {
        $this->registry->registerRule('hook.a', ['layout-1'], '/url-a/*');
        $this->registry->registerRule('hook.a', ['layout-2', 'layout-3']);

        $this->cacheManager
            ->shouldReceive('invalidateByUrl')
            ->with('/url-a/*')
            ->andReturn(5);

        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->with('layout-1')
            ->andReturn(3);

        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->with('layout-2')
            ->andReturn(2);

        $this->cacheManager
            ->shouldReceive('invalidateByLayout')
            ->with('layout-3')
            ->andReturn(4);

        $result = $this->registry->invalidate('hook.a');

        $this->assertSame(14, $result);
    }

    /**
     * getRules가 모든 등록된 규칙을 반환하는지 확인합니다.
     */
    public function test_get_rules_returns_all_registered_rules(): void
    {
        $this->registry->registerRule('product.updated', ['product-detail'], '/products/*');
        $this->registry->registerRule('category.updated', ['category-list']);

        $allRules = $this->registry->getRules();

        $this->assertArrayHasKey('product.updated', $allRules);
        $this->assertArrayHasKey('category.updated', $allRules);
        $this->assertCount(1, $allRules['product.updated']);
        $this->assertCount(1, $allRules['category.updated']);
        $this->assertSame('/products/*', $allRules['product.updated'][0]['urlPattern']);
        $this->assertNull($allRules['category.updated'][0]['urlPattern']);
    }

    /**
     * 존재하지 않는 훅에 대해 getRulesForHook이 빈 배열을 반환하는지 확인합니다.
     */
    public function test_get_rules_for_hook_returns_empty_array_for_unknown_hook(): void
    {
        $result = $this->registry->getRulesForHook('nonexistent.hook');

        $this->assertSame([], $result);
    }
}
