<?php

namespace App\Contracts\Repositories;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

interface NotificationRepositoryInterface
{
    /**
     * 사용자의 알림 목록을 페이지네이션으로 조회합니다.
     */
    public function getByUser(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 사용자의 미읽음 알림 수를 반환합니다.
     */
    public function getUnreadCount(User $user): int;

    /**
     * 특정 알림을 읽음 처리합니다.
     */
    public function markAsRead(User $user, string $notificationId): ?DatabaseNotification;

    /**
     * 지정된 알림들을 일괄 읽음 처리합니다.
     *
     * @return int 읽음 처리된 건수
     */
    public function markBatchAsRead(User $user, array $ids): int;

    /**
     * 사용자의 모든 미읽음 알림을 읽음 처리합니다.
     *
     * @return int 읽음 처리된 건수
     */
    public function markAllAsRead(User $user): int;

    /**
     * 알림을 삭제합니다.
     */
    public function delete(User $user, string $notificationId): bool;

    /**
     * 사용자의 모든 알림을 삭제합니다.
     *
     * @return int 삭제된 알림 수
     */
    public function deleteAll(User $user): int;

    /**
     * 오래된 알림을 정리합니다.
     *
     * @return array{deleted_read: int, deleted_unread: int}
     */
    public function cleanup(int $readRetentionDays, int $unreadRetentionDays): array;
}
