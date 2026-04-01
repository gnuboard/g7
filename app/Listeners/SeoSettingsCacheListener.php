<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 코어 SEO 설정 변경 시 SEO 캐시 전체 무효화 리스너
 *
 * 코어 환경설정의 SEO 탭 저장 시 전체 SEO 캐시를 삭제합니다.
 * SEO 설정(title suffix, cache TTL 등)은 모든 페이지에 영향을 미치므로
 * 전체 캐시를 무효화합니다.
 */
class SeoSettingsCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.settings.after_save' => [
                'method' => 'onSettingsSave',
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
     * 코어 설정 저장 시 SEO 캐시를 무효화합니다.
     *
     * SEO 탭 설정이 저장된 경우에만 전체 캐시를 삭제합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($tab, $mergedSettings, $result)
     */
    public function onSettingsSave(...$args): void
    {
        $tab = $args[0] ?? null;

        // SEO 탭이 아니면 무시
        if ($tab !== 'seo') {
            return;
        }

        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 전체 SEO 캐시 삭제 (title suffix, cache TTL 등 전역 영향)
            $cache->clearAll();

            // Sitemap 캐시 삭제
            Cache::forget('seo:sitemap');

            Log::info('[SEO] Core SEO settings changed — all cache cleared', [
                'tab' => $tab,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Core SEO settings cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
