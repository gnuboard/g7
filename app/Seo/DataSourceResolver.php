<?php

namespace App\Seo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DataSourceResolver
{
    /**
     * 레이아웃의 data_sources를 서버에서 호출하여 데이터를 로드합니다.
     *
     * @param  array  $dataSources  전체 data_sources 배열
     * @param  array  $seoDataSourceIds  SEO에 필요한 data_source ID 목록
     * @param  array  $routeParams  라우트 파라미터 (예: ['id' => '123', 'slug' => 'clothes'])
     * @param  string  $locale  로케일
     * @param  array  $queryParams  URL 쿼리 파라미터 (예: ['page' => '2', 'sort' => 'latest'])
     * @return array data_source ID → 응답 데이터 매핑
     */
    public function resolve(array $dataSources, array $seoDataSourceIds, array $routeParams, string $locale, array $queryParams = []): array
    {
        $context = [];

        foreach ($dataSources as $ds) {
            $dsId = $ds['id'] ?? '';

            // seo.data_sources에 명시된 ID만 호출
            if (! in_array($dsId, $seoDataSourceIds)) {
                continue;
            }

            $endpoint = $ds['endpoint'] ?? '';
            if ($endpoint === '') {
                continue;
            }

            // 라우트 파라미터 치환 (예: {{route.id}} → 123)
            $endpoint = $this->resolveEndpoint($endpoint, $routeParams);

            // params 해석 ({{query.xxx}}, {{route.xxx}} 표현식 치환)
            $resolvedParams = $this->resolveParams($ds['params'] ?? [], $routeParams, $queryParams);

            // API 호출
            $data = $this->callApi($endpoint, $ds, $locale, $resolvedParams);
            $context[$dsId] = $data;
        }

        return $context;
    }

    /**
     * 엔드포인트의 표현식을 치환합니다.
     *
     * @param  string  $endpoint  원본 엔드포인트
     * @param  array  $routeParams  라우트 파라미터
     * @return string 치환된 엔드포인트
     */
    private function resolveEndpoint(string $endpoint, array $routeParams): string
    {
        // {{route.xxx}} 및 {{route?.xxx}} 패턴 치환 (optional chaining 포함)
        return (string) preg_replace_callback('/\{\{route\??\.(\w+)\}\}/', function ($matches) use ($routeParams) {
            return $routeParams[$matches[1]] ?? '';
        }, $endpoint);
    }

    /**
     * 데이터소스 params의 표현식을 해석합니다.
     *
     * {{query.xxx}} → 쿼리 파라미터, {{route.xxx}} → 라우트 파라미터,
     * 숫자/문자열 리터럴 → 그대로 유지합니다.
     *
     * @param  array  $params  원본 params 정의
     * @param  array  $routeParams  라우트 파라미터
     * @param  array  $queryParams  쿼리 파라미터
     * @return array 해석된 params (키 → 값)
     */
    private function resolveParams(array $params, array $routeParams, array $queryParams): array
    {
        if (empty($params)) {
            return [];
        }

        $resolved = [];
        $evalContext = [
            'route' => $routeParams,
            'query' => $queryParams,
        ];

        $evaluator = app(ExpressionEvaluator::class);

        foreach ($params as $key => $value) {
            if (is_numeric($value)) {
                $resolved[$key] = $value;

                continue;
            }

            $resolvedValue = $evaluator->evaluate((string) $value, $evalContext);

            // 빈 문자열은 전달하지 않음 (선택적 파라미터)
            if ($resolvedValue !== '') {
                $resolved[$key] = $resolvedValue;
            }
        }

        return $resolved;
    }

    /**
     * 내부 API를 호출합니다.
     *
     * @param  string  $endpoint  API 엔드포인트
     * @param  array  $ds  data_source 정의
     * @param  string  $locale  로케일
     * @param  array  $resolvedParams  해석된 쿼리 파라미터
     * @return array 응답 데이터
     */
    private function callApi(string $endpoint, array $ds, string $locale, array $resolvedParams = []): array
    {
        $method = strtolower($ds['method'] ?? 'GET');
        $fallback = $ds['fallback'] ?? [];
        $timeout = 5;

        try {
            $fullUrl = url($endpoint);

            $request = Http::timeout($timeout)
                ->withoutVerifying()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Language' => $locale,
                ]);

            // GET 요청 시 쿼리 파라미터 전달
            if ($method === 'get' && ! empty($resolvedParams)) {
                $fullUrl .= '?'.http_build_query($resolvedParams);
            }

            $response = $request->$method($fullUrl);

            if ($response->successful()) {
                return $response->json() ?? $fallback;
            }

            Log::warning('[SEO] DataSource API failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return $fallback;
        } catch (\Throwable $e) {
            Log::warning('[SEO] DataSource API error', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }
}
