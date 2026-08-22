<?php

namespace App\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
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
            // 단건 저장(`PUT /api/admin/settings/{key}`)은 after_save 가 아니라 after_set 을
            // 발화한다. 이 구독이 없으면 SEO 설정을 단건으로 바꿔도 캐시가 남아 있다.
            // (after_save 를 추가 발화하는 대신 구독을 늘리는 이유: 활동로그 리스너가 두 훅을
            //  각각 기록해 저장 1회가 로그 2건이 된다)
            'core.settings.after_set' => [
                'method' => 'onSettingSet',
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

        $this->clearSeoCaches(['tab' => $tab]);
    }

    /**
     * 코어 설정 단건 저장 시 SEO 캐시를 무효화합니다.
     *
     * seo 카테고리 키가 실제로 저장된 경우에만 전체 캐시를 삭제합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($key, $value, $result)
     */
    public function onSettingSet(...$args): void
    {
        $key = $args[0] ?? null;
        $result = $args[2] ?? false;

        if (! is_string($key) || ! str_starts_with($key, 'seo.')) {
            return;
        }

        // 저장 실패는 상태를 바꾸지 않았으므로 캐시도 그대로 둔다
        if ($result !== true) {
            return;
        }

        $this->clearSeoCaches(['key' => $key]);
    }

    /**
     * SEO 전체 캐시와 sitemap 캐시를 삭제합니다.
     *
     * @param  array  $logContext  로그에 남길 컨텍스트
     */
    private function clearSeoCaches(array $logContext): void
    {
        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 전체 SEO 캐시 삭제 (title suffix, cache TTL 등 전역 영향)
            $cache->clearAll();

            // Sitemap 캐시 삭제
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::info('[SEO] Core SEO settings changed — all cache cleared', $logContext);
        } catch (\Throwable $e) {
            Log::warning('[SEO] Core SEO settings cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
