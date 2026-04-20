<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationLogRepositoryInterface;
use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationLogRepository implements NotificationLogRepositoryInterface
{
    /**
     * ID로 알림 로그 조회.
     */
    public function findById(int $id): ?NotificationLog
    {
        return NotificationLog::find($id);
    }

    /**
     * 알림 로그 생성.
     */
    public function create(array $data): NotificationLog
    {
        return NotificationLog::create($data);
    }

    /**
     * 알림 로그 삭제.
     */
    public function delete(NotificationLog $log): bool
    {
        return (bool) $log->delete();
    }

    /**
     * 다건 삭제.
     */
    public function bulkDelete(array $ids): int
    {
        return NotificationLog::whereIn('id', $ids)->delete();
    }

    /**
     * 페이지네이션 목록 조회.
     */
    public function getPaginated(array $filters = [], int $perPage = 20, ?User $scopeUser = null): LengthAwarePaginator
    {
        $query = NotificationLog::with(['senderUser', 'recipientUser']);

        // notification-logs scope: 전달된 사용자의 권한 스코프 적용
        if ($scopeUser) {
            $this->applyNotificationLogScope($query, $scopeUser);
        }

        if (! empty($filters['sender_user_id'])) {
            $query->where('sender_user_id', $filters['sender_user_id']);
        }

        if (! empty($filters['recipient_user_id'])) {
            $query->where('recipient_user_id', $filters['recipient_user_id']);
        }

        if (! empty($filters['channel'])) {
            $query->byChannel($filters['channel']);
        }

        if (! empty($filters['notification_type'])) {
            $query->byNotificationType($filters['notification_type']);
        }

        if (! empty($filters['status'])) {
            $status = NotificationLogStatus::tryFrom($filters['status']);
            if ($status) {
                $query->byStatus($status);
            }
        }

        if (! empty($filters['extension_type'])) {
            $query->where('extension_type', $filters['extension_type']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('recipient_name', 'like', "%{$search}%")
                    ->orWhere('recipient_identifier', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('notification_type', 'like', "%{$search}%");
            });
        }

        $sortBy = $filters['sort_by'] ?? 'sent_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * notification-logs 전용 스코프를 적용합니다.
     *
     * self 스코프: 본인이 발송했거나 수신한 알림 이력만 조회
     */
    private function applyNotificationLogScope(Builder $query, User $user): void
    {
        $effectiveScope = $user->getEffectiveScopeForPermission('core.notification-logs.read');

        if ($effectiveScope === null) {
            return; // 전체 접근
        }

        if ($effectiveScope === 'self') {
            $query->where(function (Builder $q) use ($user) {
                $q->where('sender_user_id', $user->id)
                    ->orWhere('recipient_user_id', $user->id);
            });
        }
    }
}
