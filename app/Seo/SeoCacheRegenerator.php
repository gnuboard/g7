<?php

namespace App\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 단건 SEO 캐시 즉시 재생성 서비스
 *
 * 특정 URL에 대한 SEO 캐시를 지원 로케일별로 즉시 렌더링하여 저장합니다.
 * 컨텐츠 생성/수정 시 해당 상세 페이지의 캐시를 즉시 재생성하는 데 사용됩니다.
 */
class SeoCacheRegenerator
{
    public function __construct(
        private readonly SeoRendererInterface $renderer,
        private readonly SeoCacheManagerInterface $cacheManager,
    ) {}

    /**
     * 지정된 URL에 대해 SEO 캐시를 즉시 재생성합니다.
     *
     * 지원 로케일별로 순회하며 렌더링 후 캐시에 저장합니다.
     * 렌더링 실패 시 해당 로케일은 건너뛰고 로그를 남깁니다.
     *
     * @param  string  $url  재생성할 URL 경로 (예: /shop/products/123)
     * @return bool 최소 1개 로케일에서 재생성 성공 시 true
     */
    public function renderAndCache(string $url): bool
    {
        $defaultLocale = config('app.locale');
        $supportedLocales = config('app.supported_locales', [$defaultLocale]);
        $success = false;

        foreach ($supportedLocales as $locale) {
            try {
                // fake Request 생성
                $request = Request::create($url);
                $request->attributes->set('seo_default_locale', $defaultLocale);

                // 로케일 설정
                $originalLocale = app()->getLocale();
                app()->setLocale($locale);

                try {
                    $html = $this->renderer->render($request);
                } finally {
                    // 로케일 복원
                    app()->setLocale($originalLocale);
                }

                if ($html === null) {
                    continue;
                }

                // 레이아웃명 가져오기 (SeoRenderer가 request attribute로 저장)
                $layoutName = $request->attributes->get('seo_layout_name', '');

                $this->cacheManager->putWithLayout($url, $locale, $html, $layoutName);
                $success = true;

                Log::debug('[SEO] Cache regenerated', [
                    'url' => $url,
                    'locale' => $locale,
                    'layout' => $layoutName,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SEO] Cache regeneration failed', [
                    'url' => $url,
                    'locale' => $locale,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $success;
    }
}
