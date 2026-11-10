<?php

namespace Tests\Unit\Extension;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\ExtensionMiddlewareRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 미들웨어 인덱스 요청 메모 (#860 W7)
 *
 * 게이트는 한 요청에서 before·after 두 시점에 인덱스를 찾는다. 인덱스는 요청 안에서 바뀌지 않으므로
 * 캐시 저장소는 한 번만 읽고, 확장 상태가 바뀌어 `flush()` 가 불리면 같은 요청 뒤쪽도 새로 읽는다.
 */
class ExtensionMiddlewareRegistryMemoTest extends TestCase
{
    /**
     * 인덱스 키 조회 횟수를 세는 캐시
     */
    private CoreCacheDriver $spy;

    /**
     * 조회 계수 캐시를 바인딩합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->spy = new class('array') extends CoreCacheDriver
        {
            /**
             * 인덱스 키 조회 횟수
             */
            public int $indexReads = 0;

            /**
             * 인덱스 키 조회를 세고 원래 동작을 수행합니다.
             *
             * @param  string  $key  캐시 키
             * @param  mixed  $default  기본값
             * @return mixed 캐시 값
             */
            public function get(string $key, mixed $default = null): mixed
            {
                if ($key === ExtensionMiddlewareRegistry::CACHE_KEY) {
                    $this->indexReads++;
                }

                return parent::get($key, $default);
            }

            /**
             * remember 도 get 을 거치게 합니다.
             *
             * @param  string  $key  캐시 키
             * @param  callable  $callback  값 계산
             * @param  int|null  $ttl  만료(초)
             * @param  array<int, string>  $tags  태그
             * @return mixed 캐시 값
             */
            public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
            {
                $cached = $this->get($key);
                if ($cached !== null) {
                    return $cached;
                }

                $value = $callback();
                $this->put($key, $value, $ttl);

                return $value;
            }
        };

        $this->app->instance(CacheInterface::class, $this->spy);
        $this->app->forgetInstance(ExtensionMiddlewareRegistryInterface::class);
        $this->app->forgetScopedInstances();
    }

    /**
     * 한 요청의 before·after 해석은 인덱스를 한 번만 읽고, flush 뒤에는 다시 읽습니다.
     *
     * @effects middleware_index_is_read_once_per_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 한_요청에서_인덱스는_한_번만_읽는다(): void
    {
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        $registry->resolveForRoute('api.modules.sirsoft-board.boards.index', 'api/modules/sirsoft-board/boards', 'api', 'before');
        $registry->resolveForRoute('api.modules.sirsoft-board.boards.index', 'api/modules/sirsoft-board/boards', 'api', 'after');
        $registry->resolveForRoute('api.plugins.sirsoft-gdpr.settings', 'api/plugins/sirsoft-gdpr/settings', 'api', 'before');

        $this->assertSame(1, $this->spy->indexReads);

        // 확장 상태가 바뀌어 flush 되면 같은 요청 뒤쪽도 새 인덱스를 본다
        ExtensionMiddlewareRegistry::flush();
        $registry->resolveForRoute('api.modules.sirsoft-board.boards.index', 'api/modules/sirsoft-board/boards', 'api', 'before');
        $this->assertSame(2, $this->spy->indexReads);

        // 요청·큐 잡이 바뀌면(scoped 폐기) 새 레지스트리가 다시 읽는다
        $this->app->forgetScopedInstances();
        $this->app->make(ExtensionMiddlewareRegistryInterface::class)
            ->resolveForRoute('api.modules.sirsoft-board.boards.index', 'api/modules/sirsoft-board/boards', 'api', 'before');
        $this->assertSame(3, $this->spy->indexReads);
    }
}
