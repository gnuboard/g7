<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\Log;

/**
 * 게시판 모듈 설정 변경 시 관련 SEO 캐시 무효화 리스너
 *
 * 게시판 모듈 환경설정이 변경되면 게시판 관련 SEO 캐시를 무효화합니다.
 * 게시판 목록, 게시글 상세, 게시판 인덱스 등의 캐시가 대상입니다.
 */
class SeoBoardSettingsCacheListener implements HookListenerInterface
{
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
            // 환경설정 기본값 일괄 적용도 게시판 화면 출력을 바꾸므로 같은 무효화가 필요하다.
            // payload 가 ($fields, $updatedCount) 라 식별자 인자가 없어 전용 핸들러로 받는다.
            'sirsoft-board.settings.after_bulk_apply' => [
                'method' => 'onBulkApply',
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
     * 모듈 설정 저장 시 게시판 SEO 관련 캐시를 무효화합니다.
     *
     * 게시판 모듈이 아니면 무시합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($identifier, $mergedSettings, $result)
     */
    public function onModuleSettingsSave(...$args): void
    {
        $identifier = $args[0] ?? null;

        // 게시판 모듈이 아니면 무시
        if ($identifier !== 'sirsoft-board') {
            return;
        }

        $this->invalidateBoardSeoCache();
    }

    /**
     * 환경설정 기본값 일괄 적용 후 게시판 SEO 캐시를 무효화합니다.
     *
     * 이 훅의 payload 는 ($fields, $updatedCount) 로 모듈 식별자를 담지 않는다 —
     * 게시판 모듈이 발행하는 훅이므로 식별자 가드 자체가 불필요하다.
     *
     * @param  mixed  ...$args  훅 인자 ($fields, $updatedCount)
     */
    public function onBulkApply(...$args): void
    {
        $this->invalidateBoardSeoCache();
    }

    /**
     * 게시판 관련 SEO 레이아웃 캐시와 sitemap 캐시를 무효화합니다.
     */
    private function invalidateBoardSeoCache(): void
    {
        try {
            $cache = app(SeoCacheManagerInterface::class);

            // 게시판 관련 레이아웃 캐시 무효화
            $cache->invalidateByLayout('board/index');
            $cache->invalidateByLayout('board/show');
            $cache->invalidateByLayout('board/boards');

            // Sitemap 캐시 무효화
            app(CacheInterface::class)->forget('seo.sitemap');

            Log::info('[SEO] Board module settings changed — board cache cleared');
        } catch (\Throwable $e) {
            Log::warning('[SEO] Board settings cache invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
