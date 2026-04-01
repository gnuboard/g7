<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\LayoutPreviewRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 저장 후 미리보기 정리 리스너
 *
 * 레이아웃이 저장되면 해당 관리자의 해당 레이아웃에 대한
 * 미리보기를 삭제합니다.
 */
class LayoutPreviewCleanupListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout.after_update' => [
                'method' => 'onLayoutUpdated',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        $this->onLayoutUpdated(...$args);
    }

    /**
     * 레이아웃 업데이트 후 관련 미리보기를 삭제합니다.
     *
     * @param mixed $layout 업데이트된 레이아웃 모델
     * @param int $templateId 템플릿 ID
     * @param string $name 레이아웃 이름
     * @param array $data 업데이트 데이터
     * @return void
     */
    public function onLayoutUpdated(mixed $layout, int $templateId, string $name, array $data): void
    {
        $adminId = Auth::id();

        if (! $adminId) {
            return;
        }

        try {
            $previewRepository = app(LayoutPreviewRepositoryInterface::class);
            $deleted = $previewRepository->deleteByLayoutAndAdmin($templateId, $name, $adminId);

            if ($deleted > 0) {
                Log::debug('레이아웃 저장 후 미리보기 정리 완료', [
                    'template_id' => $templateId,
                    'layout_name' => $name,
                    'admin_id' => $adminId,
                    'deleted_count' => $deleted,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('미리보기 정리 실패 (비차단)', [
                'template_id' => $templateId,
                'layout_name' => $name,
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
