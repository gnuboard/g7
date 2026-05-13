<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository implements NotificationRepositoryInterface
{
    /**
     * 사용자의 알림 목록을 페이지네이션으로 조회합니다.
     */
    public function getByUser(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $user->notifications();

        if (isset($filters['read']) && $filters['read'] === 'unread') {
            $query->whereNull('read_at');
        } elseif (isset($filters['read']) && $filters['read'] === 'read') {
            $query->whereNotNull('read_at');
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * 사용자의 미읽음 알림 수를 반환합니다.
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * 특정 알림을 읽음 처리합니다.
     */
    public function markAsRead(User $user, string $notificationId): ?DatabaseNotification
    {
        $notification = $user->notifications()->find($notificationId);

        if (! $notification) {
            return null;
        }

        $notification->markAsRead();

        return $notification;
    }

    /**
     * 지정된 알림들을 일괄 읽음 처리합니다.
     */
    public function markBatchAsRead(User $user, array $ids): int
    {
        return $user->unreadNotifications()
            ->whereIn('id', $ids)
            ->update(['read_at' => now()]);
    }

    /**
     * 사용자의 모든 미읽음 알림을 읽음 처리합니다.
     */
    public function markAllAsRead(User $user): int
    {
        $count = $user->unreadNotifications()->count();

        $user->unreadNotifications->markAsRead();

        return $count;
    }

    /**
     * 알림을 삭제합니다.
     */
    public function delete(User $user, string $notificationId): bool
    {
        $notification = $user->notifications()->find($notificationId);

        if (! $notification) {
            return false;
        }

        return (bool) $notification->delete();
    }

    /**
     * 사용자의 모든 알림을 삭제합니다.
     *
     * @param User $user
     * @return int 삭제된 알림 수
     */
    public function deleteAll(User $user): int
    {
        return $user->notifications()->delete();
    }

    /**
     * 오래된 알림을 정리합니다.
     *
     * @return array{deleted_read: int, deleted_unread: int}
     */
    public function cleanup(int $readRetentionDays, int $unreadRetentionDays): array
    {
        $deletedRead = 0;
        $deletedUnread = 0;

        if ($readRetentionDays > 0) {
            $deletedRead = DatabaseNotification::whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays($readRetentionDays))
                ->delete();
        }

        if ($unreadRetentionDays > 0) {
            $deletedUnread = DatabaseNotification::whereNull('read_at')
                ->where('created_at', '<', now()->subDays($unreadRetentionDays))
                ->delete();
        }

        return [
            'deleted_read' => $deletedRead,
            'deleted_unread' => $deletedUnread,
        ];
    }
}
