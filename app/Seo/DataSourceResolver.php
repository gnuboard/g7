<?php

namespace App\Seo;

use App\Support\OutboundUrlValidator;
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
     * @param  array  $extraContext  엔드포인트/params 표현식 평가에 사용할 추가 컨텍스트 (_global 등)
     * @return array data_source ID → 응답 데이터 매핑
     */
    public function resolve(array $dataSources, array $seoDataSourceIds, array $routeParams, string $locale, array $queryParams = [], array $extraContext = []): array
    {
        $context = [];

        $evalContext = array_merge($extraContext, [
            'route' => array_merge($extraContext['route'] ?? [], $routeParams),
            'query' => $queryParams,
        ]);

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

            // 엔드포인트 표현식 치환 ({{route.id}}, {{query.q}}, {{_global.xxx}})
            $endpoint = $this->resolveEndpoint($endpoint, $evalContext);

            // params 해석 ({{query.xxx}}, {{route.xxx}} 표현식 치환)
            $resolvedParams = $this->resolveParams($ds['params'] ?? [], $routeParams, $queryParams, $extraContext);

            // API 호출
            $data = $this->callApi($endpoint, $ds, $locale, $resolvedParams);
            $context[$dsId] = $data;
        }

        return $context;
    }

    /**
     * 엔드포인트의 표현식을 치환합니다.
     *
     * `{{route.xxx}}` 뿐 아니라 `{{query.xxx}}` / `{{_global.xxx}}` 등 컨텍스트 표현식을
     * 모두 해석합니다 — 검색 결과처럼 엔드포인트 문자열 안에서 쿼리·전역 상태를 보간하는
     * 데이터소스가 있기 때문입니다. 미해석 시 표현식이 URL에 그대로 남아 호출이 실패합니다.
     *
     * @param  string  $endpoint  원본 엔드포인트
     * @param  array  $evalContext  평가 컨텍스트 (route/query/_global 등)
     * @return string 치환된 엔드포인트
     */
    private function resolveEndpoint(string $endpoint, array $evalContext): string
    {
        if (! str_contains($endpoint, '{{')) {
            return $endpoint;
        }

        return app(ExpressionEvaluator::class)->evaluate($endpoint, $evalContext);
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
     * @param  array  $extraContext  추가 평가 컨텍스트 (_global 등)
     * @return array 해석된 params (키 → 값)
     */
    private function resolveParams(array $params, array $routeParams, array $queryParams, array $extraContext = []): array
    {
        if (empty($params)) {
            return [];
        }

        $resolved = [];
        $evalContext = array_merge($extraContext, [
            'route' => $routeParams,
            'query' => $queryParams,
        ]);

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
     * 호출 주소는 요청 Host 가 아니라 사이트 주소(`config('app.url')`)로 만든다. 예전에는 `url()` 로
     * 만들어 Host 헤더를 위조한 요청 하나가 서버를 공격자가 고른 호스트·포트로 요청을 보내게 했고,
     * 그 응답이 봇 화면에 실려 돌아왔다(응답 회수형 SSRF). TLS 검증은 목적지가 이 서버 자신
     * (루프백)일 때만 생략한다 — `loopbackTransport()` 참고.
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
        $timeout = max(1, (int) config('core.seo_datasource.timeout', 2));

        try {
            $fullUrl = $this->internalUrl($endpoint);

            if ($fullUrl === null) {
                Log::warning('[SEO] DataSource endpoint rejected (not the site origin)', [
                    'endpoint' => $endpoint,
                ]);

                return $fallback;
            }

            $request = Http::timeout($timeout)
                ->withOptions($this->loopbackTransport($fullUrl) + [
                    'allow_redirects' => [
                        'max' => 3,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function ($request, $response, $uri): void {
                            // 리다이렉트도 사이트 주소 안에서만 따른다 — 밖으로 나가는 순간 위의
                            // 고정이 무의미해진다(http→https 전환 같은 정상 리다이렉트는 통과).
                            if ($this->internalUrl((string) $uri, false) === null) {
                                throw new \RuntimeException('redirect outside the site origin');
                            }
                        },
                    ],
                ])
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Language' => $locale,
                    // 서버 자신의 렌더 호출임을 알린다 — 받는 쪽이 조회수 같은 열람 집계에서 뺀다
                    ...SeoRenderRequest::headers(),
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

    /**
     * 엔드포인트를 사이트 주소 기준 호출 URL 로 만들고, 목적지가 사이트 자신인지 검증합니다.
     *
     * 엔드포인트는 `{{query.*}}` 같은 표현식 치환을 거치므로 방문자 입력이 섞일 수 있다. 사이트
     * 주소 뒤에 경로로 이어 붙이면 `https://attacker/…` 같은 값도 경로 조각이 될 뿐이지만, 조립
     * 결과를 한 번 더 검증기로 확인한다(scheme·userinfo·host·port 가 사이트 주소와 같아야 통과).
     *
     * @param  string  $endpoint  엔드포인트 (경로) 또는 검증할 절대 URL
     * @param  bool  $join  true 면 사이트 주소 뒤에 이어 붙이고, false 면 그대로 검증만 한다
     * @return string|null 호출 URL (사이트 밖이면 null)
     */
    private function internalUrl(string $endpoint, bool $join = true): ?string
    {
        $base = SeoUrl::base();
        $baseParts = parse_url($base);

        if (! is_array($baseParts) || ! isset($baseParts['host'])) {
            return null;
        }

        $fullUrl = $join ? $base.'/'.ltrim($endpoint, '/') : $endpoint;
        $siteHost = OutboundUrlValidator::normalizeHost($baseParts['host']);

        if ($siteHost === null || ! OutboundUrlValidator::isHostAllowed(
            $fullUrl,
            [$siteHost],
            ['schemes' => ['http', 'https'], 'allowPort' => true],
        )) {
            return null;
        }

        // 리다이렉트로 스킴이 바뀌면 기본 포트도 함께 바뀐다 — 스킴별 기본 포트로 맞춰 비교한다.
        $parts = parse_url($fullUrl);
        $port = $parts['port'] ?? null;
        $sitePort = $baseParts['port'] ?? null;

        if ($port !== null && $port !== $sitePort && ! ($sitePort === null && in_array($port, [80, 443], true))) {
            return null;
        }

        return $fullUrl;
    }

    /**
     * 목적지가 이 서버 자신(루프백)이면 TLS 검증을 생략하고 연결을 그 주소로 고정합니다.
     *
     * 예전에는 `withoutVerifying()` 로 모든 호출의 검증을 껐다. 사이트 주소가 루프백으로 풀리는
     * 서버(자기 도메인을 hosts 로 127.0.0.1 에 묶은 구성, 로컬 개발의 자가서명 인증서)에서는 그
     * 연결을 가로챌 수 있는 경로가 없으므로 검증이 지키는 것이 없고, 켜 두면 자가서명 인증서에서
     * 내부 호출이 모두 실패한다. 그 밖의 경우(사이트 주소가 외부 IP 로 풀림)에는 검증을 켠다.
     * 판정한 주소로 연결을 고정(CURLOPT_RESOLVE)해 판정과 실제 연결 사이에 이름 해석이 바뀌지
     * 않게 한다.
     *
     * @param  string  $fullUrl  호출 URL (사이트 주소 기준으로 검증된 값)
     * @return array<string, mixed> HTTP 클라이언트 옵션 (루프백이 아니면 빈 배열)
     */
    private function loopbackTransport(string $fullUrl): array
    {
        $parts = parse_url($fullUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return [];
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return $this->isLoopbackIp($literal) ? ['verify' => false] : [];
        }

        $addresses = gethostbynamel($host);
        if (! is_array($addresses) || $addresses === []) {
            return [];
        }

        foreach ($addresses as $address) {
            if (! $this->isLoopbackIp($address)) {
                return [];
            }
        }

        $ports = array_unique(array_filter([$parts['port'] ?? null, 80, 443]));

        return [
            'verify' => false,
            'curl' => [
                CURLOPT_RESOLVE => array_map(
                    static fn (int $port): string => "{$host}:{$port}:{$addresses[0]}",
                    $ports,
                ),
            ],
        ];
    }

    /**
     * IP 가 루프백 대역(127.0.0.0/8, ::1)인지 판정합니다.
     *
     * @param  string  $ip  IP 주소
     * @return bool 루프백이면 true
     */
    private function isLoopbackIp(string $ip): bool
    {
        return $ip === '::1' || str_starts_with($ip, '127.');
    }
}
