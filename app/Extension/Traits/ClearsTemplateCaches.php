<?php

namespace App\Extension\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 템플릿 관련 캐시를 무효화하는 기능을 제공하는 트레이트.
 *
 * 모듈, 플러그인 등의 확장 기능이 활성화/비활성화될 때
 * 캐시 버전을 증가시켜 프론트엔드가 새로운 캐시 키로 요청하도록 합니다.
 * 이전 버전 캐시는 TTL로 자연 만료됩니다.
 */
trait ClearsTemplateCaches
{
    /**
     * 확장 기능 캐시 버전을 증가시킵니다.
     *
     * 모듈/플러그인/템플릿 활성화/비활성화/설치/삭제 시 호출되어
     * 프론트엔드가 새로운 캐시 버전으로 API를 요청하도록 합니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     */
    protected function incrementExtensionCacheVersion(): void
    {
        try {
            $newVersion = time();
            Cache::put('extension_cache_version', $newVersion);

            Log::info('확장 기능 캐시 버전 증가', [
                'new_version' => $newVersion,
            ]);
        } catch (\Exception $e) {
            Log::warning('확장 기능 캐시 버전 증가 중 오류', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 현재 확장 기능 캐시 버전을 반환합니다.
     *
     * @return int 캐시 버전 (타임스탬프) 또는 0 (미설정 시)
     */
    public static function getExtensionCacheVersion(): int
    {
        return (int) Cache::get('extension_cache_version', 0);
    }

    /**
     * 모든 활성 템플릿의 언어 캐시를 무효화합니다.
     *
     * 버전 있는 캐시는 incrementExtensionCacheVersion()으로 무효화됩니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     * 이 메서드는 호출 호환성 유지용입니다.
     */
    protected function clearAllTemplateLanguageCaches(): void
    {
        Log::info('템플릿 언어 캐시 무효화 (캐시 버전 증가로 처리)');
    }

    /**
     * 모든 활성 템플릿의 routes 캐시를 무효화합니다.
     *
     * 버전 있는 캐시는 incrementExtensionCacheVersion()으로 무효화됩니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     * 이 메서드는 호출 호환성 유지용입니다.
     */
    protected function clearAllTemplateRoutesCaches(): void
    {
        Log::info('템플릿 routes 캐시 무효화 (캐시 버전 증가로 처리)');
    }

    /**
     * 모든 활성 템플릿의 레이아웃 캐시를 무효화합니다.
     *
     * 버전 있는 캐시는 incrementExtensionCacheVersion()으로 무효화됩니다.
     * 이전 버전 캐시는 TTL로 자연 만료됩니다.
     * 이 메서드는 호출 호환성 유지용입니다.
     */
    protected function clearAllTemplateLayoutCaches(): void
    {
        Log::info('템플릿 레이아웃 캐시 무효화 (캐시 버전 증가로 처리)');
    }
}
