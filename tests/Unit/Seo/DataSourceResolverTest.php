<?php

namespace Tests\Unit\Seo;

use App\Seo\DataSourceResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DataSourceResolver 단위 테스트
 *
 * 내부 API 호출을 통한 데이터 소스 해석 기능을 테스트합니다.
 * Http::fake()를 사용하여 외부 HTTP 호출을 모킹합니다.
 */
class DataSourceResolverTest extends TestCase
{
    private DataSourceResolver $resolver;

    /**
     * 테스트 초기화 - DataSourceResolver 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DataSourceResolver;
    }

    /**
     * 정상 API 호출 시 데이터 소스 ID에 응답 데이터가 매핑됩니다.
     */
    public function test_normal_api_call_maps_data_to_ds_id(): void
    {
        $responseData = ['data' => ['id' => 1, 'name' => '상품A']];

        Http::fake([
            '*/api/products/123' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/123', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertArrayHasKey('product', $result);
        $this->assertSame($responseData, $result['product']);
    }

    /**
     * API 타임아웃 발생 시 fallback 데이터를 반환합니다.
     */
    public function test_api_timeout_returns_fallback_data(): void
    {
        Http::fake([
            '*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $fallback = ['data' => ['name' => '기본값']];
        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/1', 'method' => 'GET', 'fallback' => $fallback],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertSame($fallback, $result['product']);
    }

    /**
     * API 404 응답 시 fallback 데이터를 반환합니다.
     */
    public function test_api_404_returns_fallback_data(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $fallback = ['data' => []];
        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/999', 'method' => 'GET', 'fallback' => $fallback],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertSame($fallback, $result['product']);
    }

    /**
     * API 500 응답 시 fallback 데이터를 반환합니다.
     */
    public function test_api_500_returns_fallback_data(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'Server Error'], 500),
        ]);

        $fallback = ['data' => ['default' => true]];
        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/1', 'method' => 'GET', 'fallback' => $fallback],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertSame($fallback, $result['product']);
    }

    /**
     * 라우트 파라미터 {{route.id}} 패턴이 실제 값으로 치환됩니다.
     */
    public function test_route_params_substitution(): void
    {
        $responseData = ['data' => ['id' => 456, 'name' => '상품B']];

        Http::fake([
            '*/api/products/456' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/{{route.id}}', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], ['id' => '456'], 'ko');

        $this->assertArrayHasKey('product', $result);
        $this->assertSame($responseData, $result['product']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/products/456');
        });
    }

    /**
     * seoDataSourceIds에 포함되지 않은 데이터 소스는 호출하지 않습니다.
     */
    public function test_ds_id_not_in_seo_data_source_ids_is_not_called(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/1', 'method' => 'GET'],
            ['id' => 'sidebar', 'endpoint' => '/api/sidebar', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertArrayHasKey('product', $result);
        $this->assertArrayNotHasKey('sidebar', $result);

        Http::assertSentCount(1);
    }

    /**
     * Accept-Language 헤더가 로케일 값으로 전달됩니다.
     */
    public function test_accept_language_header_passed(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/1', 'method' => 'GET'],
        ];

        $this->resolver->resolve($dataSources, ['product'], [], 'en');

        Http::assertSent(function ($request) {
            return $request->header('Accept-Language')[0] === 'en'
                && $request->header('Accept')[0] === 'application/json';
        });
    }

    /**
     * 쿼리 파라미터가 포함된 엔드포인트를 정상 호출합니다.
     */
    public function test_query_params_in_endpoint(): void
    {
        $responseData = ['data' => ['items' => []]];

        Http::fake([
            '*' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            ['id' => 'search', 'endpoint' => '/api/products?category=shoes&page=1', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['search'], [], 'ko');

        $this->assertSame($responseData, $result['search']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'category=shoes')
                && str_contains($request->url(), 'page=1');
        });
    }

    /**
     * 빈 엔드포인트는 건너뜁니다.
     */
    public function test_empty_endpoint_skipped(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            ['id' => 'product', 'endpoint' => '', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertArrayNotHasKey('product', $result);
        Http::assertNothingSent();
    }

    /**
     * 여러 라우트 파라미터가 동시에 치환됩니다.
     */
    public function test_multiple_route_params_substituted(): void
    {
        $responseData = ['data' => ['id' => 10]];

        Http::fake([
            '*' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            ['id' => 'item', 'endpoint' => '/api/categories/{{route.category}}/products/{{route.id}}', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['item'], ['category' => 'shoes', 'id' => '10'], 'ko');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/categories/shoes/products/10');
        });

        $this->assertSame($responseData, $result['item']);
    }

    /**
     * fallback이 정의되지 않은 경우 빈 배열을 반환합니다.
     */
    public function test_no_fallback_defined_returns_empty_array(): void
    {
        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $dataSources = [
            ['id' => 'product', 'endpoint' => '/api/products/1', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['product'], [], 'ko');

        $this->assertSame([], $result['product']);
    }

    /**
     * optional chaining {{route?.slug}} 패턴이 정상 치환됩니다.
     */
    public function test_optional_chaining_route_params_substitution(): void
    {
        $responseData = ['data' => ['id' => 1, 'title' => '페이지']];

        Http::fake([
            '*/api/pages/my-page' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            ['id' => 'page', 'endpoint' => '/api/pages/{{route?.slug}}', 'method' => 'GET'],
        ];

        $result = $this->resolver->resolve($dataSources, ['page'], ['slug' => 'my-page'], 'ko');

        $this->assertArrayHasKey('page', $result);
        $this->assertSame($responseData, $result['page']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/pages/my-page');
        });
    }

    /**
     * POST 메서드 데이터 소스를 호출합니다.
     */
    public function test_post_method_data_source(): void
    {
        $responseData = ['data' => ['results' => []]];

        Http::fake([
            '*' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            ['id' => 'search', 'endpoint' => '/api/search', 'method' => 'POST'],
        ];

        $result = $this->resolver->resolve($dataSources, ['search'], [], 'ko');

        $this->assertSame($responseData, $result['search']);
    }

    // =========================================================================
    // params 해석 + 쿼리 파라미터 전달 테스트
    // =========================================================================

    /**
     * params의 {{query.xxx}} 표현식이 쿼리 파라미터에서 해석됩니다.
     */
    public function test_params_resolve_query_expressions(): void
    {
        $responseData = ['data' => [['id' => 1]]];

        Http::fake([
            '*' => Http::response($responseData, 200),
        ]);

        $dataSources = [
            [
                'id' => 'products',
                'endpoint' => '/api/products',
                'method' => 'GET',
                'params' => [
                    'page' => '{{query.page ?? 1}}',
                    'per_page' => 12,
                    'sort' => '{{query.sort ?? \'latest\'}}',
                ],
            ],
        ];

        $queryParams = ['page' => '2', 'sort' => 'price_asc'];

        $result = $this->resolver->resolve($dataSources, ['products'], [], 'ko', $queryParams);

        $this->assertSame($responseData, $result['products']);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'page=2')
                && str_contains($url, 'per_page=12')
                && str_contains($url, 'sort=price_asc');
        });
    }

    /**
     * params의 {{route.xxx}} 표현식이 라우트 파라미터에서 해석됩니다.
     */
    public function test_params_resolve_route_expressions(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            [
                'id' => 'products',
                'endpoint' => '/api/products',
                'method' => 'GET',
                'params' => [
                    'category_slug' => '{{route.slug}}',
                ],
            ],
        ];

        $this->resolver->resolve($dataSources, ['products'], ['slug' => 'shoes'], 'ko');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'category_slug=shoes');
        });
    }

    /**
     * params에서 빈 문자열로 해석된 선택적 파라미터는 전달되지 않습니다.
     */
    public function test_params_empty_optional_param_not_sent(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            [
                'id' => 'products',
                'endpoint' => '/api/products',
                'method' => 'GET',
                'params' => [
                    'search' => "{{query.keyword ?? ''}}",
                    'page' => '{{query.page ?? 1}}',
                ],
            ],
        ];

        // keyword 없음 → search 파라미터 미전달
        $this->resolver->resolve($dataSources, ['products'], [], 'ko', []);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'page=1')
                && ! str_contains($url, 'search=');
        });
    }

    /**
     * params가 비어있으면 쿼리 파라미터 없이 호출합니다.
     */
    public function test_params_empty_no_query_string(): void
    {
        Http::fake([
            '*/api/products' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            [
                'id' => 'products',
                'endpoint' => '/api/products',
                'method' => 'GET',
                'params' => [],
            ],
        ];

        $this->resolver->resolve($dataSources, ['products'], [], 'ko', ['page' => '2']);

        Http::assertSent(function ($request) {
            // params가 빈 배열이면 query string 미추가
            return ! str_contains($request->url(), '?');
        });
    }

    /**
     * 숫자 리터럴 params는 그대로 전달됩니다.
     */
    public function test_params_numeric_literal_passed_as_is(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        $dataSources = [
            [
                'id' => 'products',
                'endpoint' => '/api/products',
                'method' => 'GET',
                'params' => [
                    'per_page' => 12,
                    'page' => '{{query.page ?? 1}}',
                ],
            ],
        ];

        $this->resolver->resolve($dataSources, ['products'], [], 'ko', ['page' => '3']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'per_page=12')
                && str_contains($request->url(), 'page=3');
        });
    }
}
