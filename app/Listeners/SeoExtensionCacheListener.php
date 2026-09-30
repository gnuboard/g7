<?php

namespace App\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\SeoConfigMerger;
use Illuminate\Support\Facades\Log;

/**
 * 확장 라이프사이클 이벤트 시 SEO 캐시 전체 무효화 리스너
 *
 * 모듈/플러그인/템플릿이 설치·활성화·비활성화·업데이트·제거될 때
 * 전체 SEO 캐시를 삭제합니다.
 * 확장 변경은 레이아웃 구조, 컴포넌트 맵, layout_extensions,
 * seo-config.json 등 광범위한 영향을 미치므로
 * 전체 캐시 클리어로 안전하게 처리합니다.
 *
 * 비활성화·제거도 구독한다 — SeoMiddleware 는 캐시 적중 시 확장 활성 여부를 보지 않으므로, 끈 게시판의
 * 페이지가 캐시에 남으면 검색 봇에게 계속 나간다(#138 후속). 캐시 정리는 즉시 반영돼야 하는 부수효과라
 * 동기 실행이다 — 큐로 가면 워커가 없는 사이트에서는 영영 비워지지 않는다.
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
        // 정규 생명주기 훅(Manager 가 CLI·어드민 UI 공통으로 한 번 발화). 옛 이름(installed·activated·
        // updated)은 deprecated 라 구독하지 않는다 — 둘 다 구독하면 한 사건에 캐시를 두 번 비운다.
        $hookNames = [
            'core.modules.after_install',
            'core.modules.after_activate',
            'core.modules.after_deactivate',
            'core.modules.after_update',
            'core.modules.after_uninstall',
            'core.plugins.after_install',
            'core.plugins.after_activate',
            'core.plugins.after_deactivate',
            'core.plugins.after_update',
            'core.plugins.after_uninstall',
            'core.templates.after_install',
            'core.templates.after_activate',
            'core.templates.after_deactivate',
            'core.templates.after_version_update',
            'core.templates.after_uninstall',
        ];

        foreach ($hookNames as $hookName) {
            $hooks[$hookName] = [
                'method' => 'onExtensionChanged',
                'priority' => 30,
                'sync' => true,
            ];
        }

        return $hooks;
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    public function onExtensionChanged(...$args): void
    {
        $identifier = $args[0] ?? 'unknown';

        // 템플릿 활성화 훅은 템플릿 정보 배열을 첫 인자로 넘긴다
        if (is_array($identifier)) {
            $identifier = (string) ($identifier['identifier'] ?? 'unknown');
        }

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
            app(CacheInterface::class)->forget('seo.sitemap');

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
