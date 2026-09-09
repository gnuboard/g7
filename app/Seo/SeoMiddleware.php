<?php

namespace App\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SeoMiddleware
{
    public function __construct(
        private readonly BotDetector $botDetector,
        private readonly SeoCacheManagerInterface $cacheManager,
        private readonly SeoRendererInterface $renderer,
        private readonly SeoCacheStatsService $statsService,
    ) {}

    /**
     * 검색 봇 요청 시 SEO HTML을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response SEO HTML(HIT/MISS) 또는 SPA 폴백(BYPASS 포함)
     */
    public function handle(Request $request, Closure $next): Response
    {
        // SEO 캐시가 글로벌 비활성화 상태면 스킵
        if (! g7_core_settings('seo.bot_detection_enabled', true)) {
            return $next($request);
        }

        // 봇이 아니면 SPA 응답
        if (! $this->botDetector->isBot($request)) {
            return $next($request);
        }

        // 기본 로케일을 setLocale() 전에 저장 (setLocale이 config('app.locale')을 변경하므로)
        $defaultLocale = config('app.locale');

        // ?locale= 파라미터 해석 (봇 전용)
        $locale = $this->resolveSeoLocale($request, $defaultLocale);

        // 기본 로케일을 ?locale=xx로 명시한 경우 → clean URL로 301 리다이렉트 (중복 URL 방지)
        if ($request->query('locale') === $defaultLocale) {
            $cleanUrl = $request->url();

            // 기존 쿼리에서 locale 제거
            $query = $request->query();
            unset($query['locale']);
            if (! empty($query)) {
                $cleanUrl .= '?'.http_build_query($query);
            }

            return response('', 301, ['Location' => $cleanUrl]);
        }

        // SEO 로케일 설정 (setLocale은 config('app.locale')도 변경하므로 기본 로케일을 별도 전달)
        $request->attributes->set('seo_default_locale', $defaultLocale);
        app()->setLocale($locale);

        // 캐시 키용 쿼리 정규화 — 정규화할 수 없을 만큼 큰 쿼리는 색인 대상이 아니다.
        // 그런 URL 까지 렌더·저장하면 물음표 뒤 값만 바꾼 반복 요청이 무한한 미스가 된다.
        $normalizedQuery = SeoCacheBounds::normalizeQuery($request->query());

        if ($normalizedQuery === null) {
            return $this->bypass($request, $next);
        }

        $ip = (string) $request->ip();

        // 캐시 키용 URL 생성 (경로 + 정규화된 쿼리)
        $cacheUrl = $this->buildCacheUrl($request, $normalizedQuery);

        // 캐시 확인 — 적중은 비용이 없으므로 렌더 예산과 무관하게 서빙한다
        $entry = $this->readEntry($cacheUrl, $locale);
        if ($entry !== null) {
            $this->recordStat($ip, fn () => $this->statsService->recordHit(
                $cacheUrl,
                $locale,
                $entry['layout'] ?: null
            ));

            return response($entry['html'], 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-SEO-Cache' => 'HIT',
            ]);
        }

        // 미스 렌더는 IP 당 분당 예산 안에서만 — 초과분은 오류가 아니라 SPA 를 받는다.
        // 봇에게 429 를 주면 그 URL 이 색인에서 빠지므로 차단이 곧 손해가 된다.
        if (! SeoCacheBounds::renderAllowed($ip)) {
            return $this->bypass($request, $next);
        }

        SeoCacheBounds::recordRender($ip);

        $startedAt = microtime(true);

        // 렌더링
        try {
            $html = $this->renderer->render($request);
        } catch (\Throwable $e) {
            // 정확한 throw 지점 진단을 위한 상세 정보 — file/line/exception class/trace 첫 10프레임
            $traceFrames = array_slice(
                array_map(static function ($frame) {
                    $file = $frame['file'] ?? '?';
                    $line = $frame['line'] ?? '?';
                    $class = $frame['class'] ?? '';
                    $type = $frame['type'] ?? '';
                    $function = $frame['function'] ?? '?';

                    return $file.':'.$line.' '.$class.$type.$function;
                }, $e->getTrace()),
                0,
                10
            );

            Log::error('[SEO] Rendering failed, falling back to SPA', [
                'url' => $cacheUrl,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_agent' => $request->userAgent(),
                'locale' => $locale,
                'trace' => $traceFrames,
            ]);

            return $next($request);
        }

        // 렌더링 실패 시 SPA fallback
        if ($html === null) {
            // "그릴 게 없음" 은 비용을 치르지 않았다 — 되돌리지 않으면 캐시에 남지 않는 죽은
            // 주소 재크롤이 올 때마다 정상 페이지의 예산을 태운다.
            SeoCacheBounds::refundRender($ip);

            return $next($request);
        }

        // 캐시 저장 (레이아웃명 포함 — invalidateByLayout 동작을 위해 필수)
        $layoutName = $request->attributes->get('seo_layout_name', '');
        $this->cacheManager->putWithLayout($cacheUrl, $locale, $html, $layoutName);

        $this->recordStat($ip, fn () => $this->statsService->recordMiss(
            $cacheUrl,
            $locale,
            $layoutName ?: null,
            null,
            (int) round((microtime(true) - $startedAt) * 1000)
        ));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-SEO-Cache' => 'MISS',
        ]);
    }

    /**
     * 캐시 항목(HTML + 레이아웃명)을 읽습니다.
     *
     * 적중 경로는 렌더러를 거치지 않아 요청 속성에 레이아웃명이 없다 — 통계를 화면별로
     * 귀속하려면 캐시 항목이 그것을 알아야 한다. 인터페이스(`get`)는 HTML 만 돌려주므로
     * 코어 매니저일 때만 항목 전체를 읽고, 다른 구현이 바인딩된 경우에는 레이아웃명 없이
     * HTML 만 쓴다(그 통계는 레이아웃 미상으로 귀속된다).
     *
     * @param  string  $cacheUrl  캐시 키용 URL
     * @param  string  $locale  로케일
     * @return array{html: string, layout: string|null}|null 캐시 항목 (없으면 null)
     */
    private function readEntry(string $cacheUrl, string $locale): ?array
    {
        if ($this->cacheManager instanceof SeoCacheManager) {
            return $this->cacheManager->getEntry($cacheUrl, $locale);
        }

        $html = $this->cacheManager->get($cacheUrl, $locale);

        return $html === null ? null : ['html' => $html, 'layout' => null];
    }

    /**
     * 캐시·렌더를 건너뛰고 SPA 응답을 돌려줍니다.
     *
     * 상한 초과는 오류가 아니라 "이 요청은 봇 렌더 대상이 아니다" 라는 판정이다. 헤더는
     * 운영 진단의 유일한 통로다 — 응답 본문만으로는 일반 SPA 폴백과 구분되지 않는다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response SPA 응답
     */
    private function bypass(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-SEO-Cache', 'BYPASS');

        return $response;
    }

    /**
     * 통계 기록을 IP 당 상한 안에서만 수행합니다.
     *
     * 기록 자체에 상한이 없으면 통계 테이블이 새로운 증식 축이 된다.
     *
     * @param  string  $ip  요청 IP
     * @param  callable  $record  기록 동작
     */
    private function recordStat(string $ip, callable $record): void
    {
        if (! SeoCacheBounds::statsAllowed($ip)) {
            return;
        }

        SeoCacheBounds::recordStat($ip);
        $record();
    }

    /**
     * 캐시 키용 URL을 생성합니다.
     *
     * 경로 + 정규화된 쿼리 파라미터로 구성한다. 정규화는 시스템 파라미터(`locale`,
     * `_escaped_fragment_`)를 제거하고 키 순서로 정렬하므로, 같은 조합은 순서와 무관하게
     * 같은 키가 되고 봇 렌더 표식만 다른 두 URL 이 두 벌로 저장되지 않는다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  array<string, mixed>  $normalizedQuery  정규화된 쿼리 파라미터
     * @return string 캐시 키용 URL
     */
    private function buildCacheUrl(Request $request, array $normalizedQuery): string
    {
        $path = $request->getPathInfo();

        if ($normalizedQuery === []) {
            return $path;
        }

        return $path.'?'.http_build_query($normalizedQuery);
    }

    /**
     * SEO 요청의 로케일을 해석합니다.
     *
     * ?locale= 쿼리 파라미터가 supported_locales에 포함되면 사용하고,
     * 그렇지 않으면 기본 로케일을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $defaultLocale  기본 로케일
     * @return string 해석된 로케일
     */
    private function resolveSeoLocale(Request $request, string $defaultLocale): string
    {
        $requestedLocale = $request->query('locale');

        if (! $requestedLocale) {
            return $defaultLocale;
        }

        $supportedLocales = config('app.supported_locales', [$defaultLocale]);

        if (in_array($requestedLocale, $supportedLocales, true)) {
            return $requestedLocale;
        }

        return $defaultLocale;
    }
}
