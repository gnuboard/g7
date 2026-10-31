<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 변경 시 그 화면의 SEO(봇) 캐시 무효화 리스너
 *
 * 레이아웃 저장·버전 복원·파일 생성/이름 변경/삭제·오버레이 변경은 모두
 * `LayoutService::clearDependentLayoutsCache()` 를 거치고, 그 메서드는 자기 자신과
 * `extends` 로 물려받는 자식 레이아웃마다 `core.layout.after_cache_clear` 를 발화한다.
 * 그래서 이 훅 하나만 구독해도 부모(`_user_base`)를 고치면 자식 화면이 모두 지워진다.
 *
 * 종전에는 이 경로에 봇 캐시 정리가 없어 레이아웃을 편집해도 TTL 이 끝날 때까지 옛 SEO 가
 * 검색 봇에게 나갔다(공개#154). 봇 캐시 항목의 레이아웃 이름은 DB 이름(확장 화면은 전체 이름)과
 * 같으므로 훅이 넘기는 이름으로 그대로 지운다. 캐시 정리는 즉시 반영돼야 하는 부수효과라 동기 실행이다.
 */
class SeoLayoutCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout.after_cache_clear' => [
                'method' => 'onLayoutCacheCleared',
                'priority' => 30,
                'sync' => true,
            ],
        ];
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
     * 레이아웃 캐시가 지워진 뒤, 활성 사용자 템플릿의 레이아웃이면 같은 이름의 봇 캐시를 지웁니다.
     *
     * 관리자 템플릿·꺼진 사용자 템플릿의 레이아웃은 검색 봇이 받는 화면이 아니므로 건너뛴다.
     * 실패는 경고로 남기고 삼킨다 — 레이아웃 저장 흐름을 막지 않는다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름 (DB 이름 — 확장 화면은 `{확장식별자}.{레이아웃}`)
     * @param  string|null  $cacheKey  지워진 병합 레이아웃 캐시 키 (사용하지 않음)
     * @return void 반환값 없음
     */
    public function onLayoutCacheCleared(int $templateId, string $layoutName, ?string $cacheKey = null): void
    {
        try {
            $template = app(TemplateRepositoryInterface::class)->findById($templateId);
            if (! $template
                || $template->type !== 'user'
                || $template->status !== ExtensionStatus::Active->value) {
                return;
            }

            $count = app(SeoCacheManagerInterface::class)->invalidateByLayout($layoutName);

            if ($count > 0) {
                Log::info('[SEO] Layout changed — bot cache invalidated', [
                    'template' => $template->identifier,
                    'layout' => $layoutName,
                    'count' => $count,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] Layout cache invalidation failed', [
                'template_id' => $templateId,
                'layout' => $layoutName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
