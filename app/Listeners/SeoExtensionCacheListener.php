<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoConfigMerger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 확장 라이프사이클 이벤트 시 SEO 캐시 전체 무효화 리스너
 *
 * 모듈/플러그인/템플릿이 설치·활성화·업데이트될 때
 * 전체 SEO 캐시를 삭제합니다.
 * 확장 변경은 레이아웃 구조, 컴포넌트 맵, layout_extensions,
 * seo-config.json 등 광범위한 영향을 미치므로
 * 전체 캐시 클리어로 안전하게 처리합니다.
 */
class SeoExtensionCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        $hooks = [];
        $hookNames = [
            'core.modules.installed',
            'core.modules.activated',
            'core.modules.updated',
            'core.plugins.installed',
            'core.plugins.activated',
            'core.plugins.updated',
            'core.templates.installed',
            'core.templates.activated',
            'core.templates.updated',
        ];

        foreach ($hookNames as $hookName) {
            $hooks[$hookName] = [
                'method' => 'onExtensionChanged',
                'priority' => 30,
            ];
        }

        return $hooks;
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
     * 확장 라이프사이클 변경 시 SEO 캐시를 무효화합니다.
     *
     * 확장 설치/활성화/업데이트는 드문 이벤트이므로
     * 전체 캐시 클리어(clearAll)로 안전하게 처리합니다.
     *
     * @param  mixed  ...$args  훅 인자 (확장 타입별 상이)
     */
    public function onExtensionChanged(...$args): void
    {
        $identifier = $args[0] ?? 'unknown';

        // Template model 객체인 경우 식별자 추출
        if (is_object($identifier)) {
            $identifier = method_exists($identifier, 'getIdentifier')
                ? $identifier->getIdentifier()
                : (string) $identifier;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 전체 SEO 캐시 삭제
            $cache->clearAll();

            // SEO config 병합 캐시 삭제
            app(SeoConfigMerger::class)->clearCache();

            // Sitemap 캐시 삭제
            Cache::forget('seo:sitemap');

            Log::info('[SEO] Extension changed — all cache cleared', [
                'identifier' => $identifier,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Extension cache invalidation failed', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
