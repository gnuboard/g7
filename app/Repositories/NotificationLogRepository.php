<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationLogRepositoryInterface;
use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationLogRepository implements NotificationLogRepositoryInterface
{
    /**
     * 최근 발송된 알림 로그를 발송 시각 최신순으로 조회합니다 (대시보드 최근 알림).
     *
     * @param  int  $limit  조회 건수
     * @return Collection<int, NotificationLog> 최근 알림 로그 컬렉션
     */
    public function getRecent(int $limit): Collection
    {
        return NotificationLog::with(['recipientUser'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * ID로 알림 로그 조회.
     *
     * @param  int  $id  알림 로그 ID
     * @return NotificationLog|null 알림 로그 또는 null
     */
    public function findById(int $id): ?NotificationLog
    {
        return NotificationLog::find($id);
    }

    /**
     * 알림 로그 생성.
     *
     * @param  array<string, mixed>  $data  생성 데이터
     * @return NotificationLog 생성된 알림 로그
     */
    public function create(array $data): NotificationLog
    {
        return NotificationLog::create($data);
    }

    /**
     * 알림 로그 삭제.
     *
     * @param  NotificationLog  $log  삭제 대상 알림 로그
     * @return bool 삭제 성공 여부
     */
    public function delete(NotificationLog $log): bool
    {
        return (bool) $log->delete();
    }

    /**
     * 다건 삭제.
     *
     * @param  array<int, int>  $ids  삭제할 알림 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function bulkDelete(array $ids): int
    {
        return NotificationLog::whereIn('id', $ids)->delete();
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지당 건수
     * @param  User|null  $scopeUser  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     * @return LengthAwarePaginator 페이지네이션 결과
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
