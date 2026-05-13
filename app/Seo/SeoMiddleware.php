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
    ) {}

    /**
     * 검색 봇 요청 시 SEO HTML을 반환합니다.
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

        // 캐시 키용 URL 생성 (경로 + 쿼리 파라미터, locale 제외)
        $cacheUrl = $this->buildCacheUrl($request);

        // 캐시 확인
        $cachedHtml = $this->cacheManager->get($cacheUrl, $locale);
        if ($cachedHtml !== null) {
            return response($cachedHtml, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'X-SEO-Cache' => 'HIT',
            ]);
        }

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
            return $next($request);
        }

        // 캐시 저장 (레이아웃명 포함 — invalidateByLayout 동작을 위해 필수)
        $layoutName = $request->attributes->get('seo_layout_name', '');
        $this->cacheManager->putWithLayout($cacheUrl, $locale, $html, $layoutName);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-SEO-Cache' => 'MISS',
        ]);
    }

    /**
     * 캐시 키용 URL을 생성합니다.
     *
     * 경로 + 쿼리 파라미터를 포함하되, locale 파라미터는 제외합니다.
     * 쿼리 파라미터를 키 순서로 정렬하여 동일 파라미터 조합이 같은 캐시 키를 생성하도록 합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 캐시 키용 URL
     */
    private function buildCacheUrl(Request $request): string
    {
        $path = $request->getPathInfo();

        // locale을 제외한 쿼리 파라미터 추출
        $query = $request->query();
        unset($query['locale']);

        if (empty($query)) {
            return $path;
        }

        // 키 순서 정렬 (동일 파라미터 조합 → 동일 캐시 키 보장)
        ksort($query);

        return $path.'?'.http_build_query($query);
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
