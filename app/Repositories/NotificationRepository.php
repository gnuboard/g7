<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationRepositoryInterface;
use App\Models\User;
use App\Support\Query\BoundedPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository implements NotificationRepositoryInterface
{
    /**
     * 사용자의 알림 목록을 페이지네이션으로 조회합니다.
     *
     * @param  User  $user  대상 사용자
     * @param  array  $filters  필터 조건 (read: unread|read)
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 알림 목록
     */
    public function getByUser(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $user->notifications();

        if (isset($filters['read']) && $filters['read'] === 'unread') {
            $query->whereNull('read_at');
        } elseif (isset($filters['read']) && $filters['read'] === 'read') {
            $query->whereNotNull('read_at');
        }

        // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 알림함 —
        // $user->notifications() 로 이미 좁혀지고, 목록이 표시하는 본문 자체가 data(JSON) 라
        // 걷어낼 넓은 컬럼이 없다
        // 정렬 마지막의 기본키는 전순서 보장용이다 — created_at 동률에서 페이지 경계가
        // 흔들려 인접 페이지가 같은 알림을 중복 노출하고 다른 알림을 누락하는 것을 막는다.
        //
        // 알림함은 한 사용자에 묶이지만 시간이 지날수록 계속 쌓인다(설정성 테이블이 아니다).
        // 총 건수는 상한까지만 세고, 뒤쪽 페이지 이동은 실측으로 끝까지 열어 둔다.
        return BoundedPaginator::paginate(
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc'),
            perPage: $perPage,
            resultCap: PaginationLimits::resultCap('user.notifications'),
        );
    }

    /**
     * 사용자의 미읽음 알림 수를 반환합니다.
     *
     * @param  User  $user  대상 사용자
     * @return int 미읽음 알림 수
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * 특정 알림을 읽음 처리합니다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $notificationId  알림 ID
     * @return DatabaseNotification|null 읽음 처리된 알림 (없으면 null)
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
     *
     * @param  User  $user  대상 사용자
     * @param  array  $ids  알림 ID 배열
     * @return int 읽음 처리된 알림 수
     */
    public function markBatchAsRead(User $user, array $ids): int
    {
        return $user->unreadNotifications()
            ->whereIn('id', $ids)
            ->update(['read_at' => now()]);
    }

    /**
     * 사용자의 모든 미읽음 알림을 읽음 처리합니다.
     *
     * @param  User  $user  대상 사용자
     * @return int 읽음 처리된 알림 수
     */
    public function markAllAsRead(User $user): int
    {
        $count = $user->unreadNotifications()->count();

        $user->unreadNotifications->markAsRead();

        return $count;
    }

    /**
     * 알림을 삭제합니다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $notificationId  알림 ID
     * @return bool 삭제 성공 여부 (알림이 없으면 false)
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
     * @param  User  $user
     * @return int 삭제된 알림 수
     */
    public function deleteAll(User $user): int
    {
        return $user->notifications()->delete();
    }

    /**
     * 오래된 알림을 정리합니다.
     *
     * @param  int  $readRetentionDays  읽은 알림 보관 일수 (0 이하면 정리하지 않음)
     * @param  int  $unreadRetentionDays  미읽음 알림 보관 일수 (0 이하면 정리하지 않음)
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
