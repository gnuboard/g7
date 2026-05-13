<?php

namespace App\Services;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notificationRepository,
    ) {}

    /**
     * 사용자의 알림 목록을 조회합니다.
     */
    public function getNotifications(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $filters = HookManager::applyFilters('core.notification.filter_list_params', $filters, $user);

        $result = $this->notificationRepository->getByUser($user, $filters, $perPage);

        HookManager::doAction('core.notification.after_list', $result, $user);

        return $result;
    }

    /**
     * 미읽음 알림 수를 반환합니다.
     */
    public function getUnreadCount(User $user): int
    {
        return $this->notificationRepository->getUnreadCount($user);
    }

    /**
     * 알림을 읽음 처리합니다.
     */
    public function markAsRead(User $user, string $notificationId): ?DatabaseNotification
    {
        HookManager::doAction('core.notification.before_mark_read', $user, $notificationId);

        $notification = $this->notificationRepository->markAsRead($user, $notificationId);

        if ($notification) {
            HookManager::doAction('core.notification.after_mark_read', $notification, $user);
        }

        return $notification;
    }

    /**
     * 지정된 알림들을 일괄 읽음 처리합니다.
     *
     * @param  array  $ids  읽음 처리할 알림 ID 목록
     * @return int 읽음 처리된 알림 수
     */
    public function markBatchAsRead(User $user, array $ids): int
    {
        HookManager::doAction('core.notification.before_mark_batch_read', $user, $ids);

        $count = $this->notificationRepository->markBatchAsRead($user, $ids);

        HookManager::doAction('core.notification.after_mark_batch_read', $user, $ids, $count);

        return $count;
    }

    /**
     * 사용자의 모든 알림을 읽음 처리합니다.
     *
     * @return int 읽음 처리된 알림 수
     */
    public function markAllAsRead(User $user): int
    {
        HookManager::doAction('core.notification.before_mark_all_read', $user);

        $count = $this->notificationRepository->markAllAsRead($user);

        HookManager::doAction('core.notification.after_mark_all_read', $user, $count);

        return $count;
    }

    /**
     * 알림을 삭제합니다.
     */
    public function deleteNotification(User $user, string $notificationId): bool
    {
        HookManager::doAction('core.notification.before_delete', $user, $notificationId);

        $result = $this->notificationRepository->delete($user, $notificationId);

        if ($result) {
            HookManager::doAction('core.notification.after_delete', $user, $notificationId);
        }

        return $result;
    }

    /**
     * 사용자의 모든 알림을 삭제합니다.
     *
     * @param User $user
     * @return int 삭제된 알림 수
     */
    public function deleteAllNotifications(User $user): int
    {
        HookManager::doAction('core.notification.before_delete_all', $user);

        $count = $this->notificationRepository->deleteAll($user);

        HookManager::doAction('core.notification.after_delete_all', $user, $count);

        return $count;
    }

    /**
     * 오래된 알림을 정리합니다.
     *
     * @return array{deleted_read: int, deleted_unread: int}
     */
    public function cleanup(): array
    {
        $config = config('notification.database_channel', []);
        $readDays = $config['read_retention_days'] ?? 30;
        $unreadDays = $config['unread_retention_days'] ?? 90;

        HookManager::doAction('core.notification.before_cleanup', $readDays, $unreadDays);

        $result = $this->notificationRepository->cleanup($readDays, $unreadDays);

        HookManager::doAction('core.notification.after_cleanup', $result);

        Log::info('알림 정리 완료', $result);

        return $result;
    }
}
