<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 이커머스 모듈 SEO 설정 변경 시 관련 SEO 캐시 무효화 리스너
 *
 * 이커머스 모듈 환경설정에서 SEO 관련 키(meta 템플릿, 토글)가 변경되면
 * 영향받는 레이아웃의 캐시만 선별적으로 무효화합니다.
 */
class SeoSettingsCacheListener implements HookListenerInterface
{
    /**
     * SEO 관련 설정 키 → 무효화 대상 레이아웃 매핑
     */
    private const SEO_KEY_LAYOUT_MAP = [
        'meta_product_title' => ['shop/show'],
        'meta_product_description' => ['shop/show'],
        'meta_product_keywords' => ['shop/show'],
        'meta_category_title' => ['shop/category', 'shop/index'],
        'meta_category_description' => ['shop/category', 'shop/index'],
        'meta_category_keywords' => ['shop/category', 'shop/index'],
        'meta_search_title' => ['search/index'],
        'meta_search_description' => ['search/index'],
        'meta_search_keywords' => ['search/index'],
        'seo_product_detail' => ['shop/show'],
        'seo_category' => ['shop/category', 'shop/index'],
        'seo_search_result' => ['search/index'],
    ];

    /**
     * sitemap에 영향을 주는 토글 키
     */
    private const SITEMAP_TOGGLE_KEYS = [
        'seo_product_detail',
        'seo_category',
        'seo_search_result',
    ];

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.module_settings.after_save' => [
                'method' => 'onModuleSettingsSave',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 모듈 설정 저장 시 이커머스 SEO 관련 캐시를 무효화합니다.
     *
     * 이커머스 모듈이 아니면 무시합니다.
     * SEO 관련 키가 변경된 경우에만 해당 레이아웃 캐시를 선별 무효화합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($identifier, $mergedSettings, $result)
     */
    public function onModuleSettingsSave(...$args): void
    {
        $identifier = $args[0] ?? null;
        $mergedSettings = $args[1] ?? [];

        // 이커머스 모듈이 아니면 무시
        if ($identifier !== 'sirsoft-ecommerce') {
            return;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);
            $invalidatedLayouts = [];
            $sitemapAffected = false;

            // SEO 관련 키가 존재하는지 확인 후 해당 레이아웃 무효화
            foreach (self::SEO_KEY_LAYOUT_MAP as $key => $layouts) {
                if ($this->hasNestedKey($mergedSettings, $key)) {
                    foreach ($layouts as $layout) {
                        if (! in_array($layout, $invalidatedLayouts, true)) {
                            $cache->invalidateByLayout($layout);
                            $invalidatedLayouts[] = $layout;
                        }
                    }

                    // 토글 키인 경우 sitemap도 무효화
                    if (in_array($key, self::SITEMAP_TOGGLE_KEYS, true)) {
                        $sitemapAffected = true;
                    }
                }
            }

            // SEO 관련 변경이 있었으면 sitemap 캐시 삭제
            if (! empty($invalidatedLayouts) || $sitemapAffected) {
                Cache::forget('seo:sitemap');
            }

            if (! empty($invalidatedLayouts)) {
                Log::info('[SEO] Ecommerce SEO settings changed — selective cache cleared', [
                    'layouts' => $invalidatedLayouts,
                    'sitemap_affected' => $sitemapAffected,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] Ecommerce SEO settings cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 설정 배열에서 SEO 관련 키가 존재하는지 확인합니다.
     *
     * seo.{key} 경로 또는 플랫 {key}로 탐색합니다.
     *
     * @param  array  $settings  병합된 설정 배열
     * @param  string  $key  검색할 키
     * @return bool 키 존재 여부
     */
    private function hasNestedKey(array $settings, string $key): bool
    {
        // seo 그룹 하위에서 탐색
        if (isset($settings['seo'][$key])) {
            return true;
        }

        // 플랫 구조에서 탐색
        if (array_key_exists($key, $settings)) {
            return true;
        }

        return false;
    }
}
