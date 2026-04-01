<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 활동 로그 리스너
 *
 * 카테고리의 생성, 수정, 삭제, 상태 전환, 정렬 변경 시
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 */
class CategoryActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.category.after_create' => ['method' => 'handleAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.category.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.category.after_delete' => ['method' => 'handleAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.category.after_toggle_status' => ['method' => 'handleAfterToggleStatus', 'priority' => 20],
            'sirsoft-ecommerce.category.after_reorder' => ['method' => 'handleAfterReorder', 'priority' => 20],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // 이벤트 핸들러
    // ═══════════════════════════════════════════

    /**
     * 카테고리 생성 후 로그 기록
     *
     * @param Category $category 생성된 카테고리
     * @param array $data 생성 데이터
     * @return void
     */
    public function handleAfterCreate(Category $category, array $data): void
    {
        $this->logActivity('category.create', [

            'loggable' => $category,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.category_create',
            'description_params' => ['category_id' => $category->id],
            'properties' => ['name' => $category->name ?? null],
        ]);
    }

    /**
     * 카테고리 수정 후 로그 기록
     *
     * @param Category $category 수정된 카테고리
     * @param array $data 수정 데이터
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleAfterUpdate(Category $category, array $data, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($category, $snapshot);

        $this->logActivity('category.update', [

            'loggable' => $category,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.category_update',
            'description_params' => ['category_id' => $category->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 카테고리 삭제 후 로그 기록
     *
     * after_delete 훅은 $categoryId (int)만 전달합니다.
     *
     * @param int $categoryId 삭제된 카테고리 ID
     * @return void
     */
    public function handleAfterDelete(int $categoryId): void
    {
        $this->logActivity('category.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.category_delete',
            'description_params' => ['category_id' => $categoryId],
            'properties' => ['category_id' => $categoryId],
        ]);
    }

    /**
     * 카테고리 상태 전환 후 로그 기록
     *
     * @param Category $category 카테고리
     * @return void
     */
    public function handleAfterToggleStatus(Category $category): void
    {
        $this->logActivity('category.toggle_status', [

            'loggable' => $category,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.category_toggle_status',
            'description_params' => ['category_id' => $category->id],
        ]);
    }

    /**
     * 카테고리 정렬 변경 후 로그 기록
     *
     * @param array $orders 정렬 순서 배열
     * @return void
     */
    public function handleAfterReorder(array $orders): void
    {
        $this->logActivity('category.reorder', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.category_reorder',
            'properties' => ['count' => count($orders)],
        ]);
    }

}
