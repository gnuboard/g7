<?php

namespace App\Repositories;

use App\Contracts\Repositories\NotificationLogRepositoryInterface;
use App\Enums\NotificationLogStatus;
use App\Models\NotificationLog;
use App\Models\User;
use App\Repositories\Concerns\DeletesInBatches;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Support\Query\KeysetPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationLogRepository implements NotificationLogRepositoryInterface
{
    use DeletesInBatches;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /**
     * 발송 이력 목록 정렬 허용 컬럼
     *
     * 요청 값을 그대로 orderBy 에 넘기면 없는 컬럼으로 SQL 오류가 나거나 인덱스 없는 컬럼
     * 정렬을 강제할 수 있다.
     *
     * @var array<int, string>
     */
    private const SORTABLE_COLUMNS = [
        'id',
        'sent_at',
        'created_at',
        'status',
        'channel',
        'notification_type',
        'recipient_name',
        'subject',
    ];

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
     * 보존 기간이 지난 발송 이력을 삭제합니다.
     *
     * 보존 기간 하한(1일)은 이 계층이 소유한다 — 파기는 되돌릴 수 없으므로 호출자마다
     * 다시 막지 않고 실제로 지우는 자리에서 한 번 막는다.
     *
     * @param  int  $days  보존 기간 (일)
     * @return int 삭제된 건수
     */
    public function deleteOlderThan(int $days): int
    {
        return $this->deleteInBatches(
            NotificationLog::where('created_at', '<', now()->subDays(max(1, $days)))
        );
    }

    /**
     * 페이지네이션 목록 조회.
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지당 건수
     * @param  User|null  $scopeUser  스코프 적용 대상 사용자 (null이면 스코프 미적용)
     * @return LengthAwarePaginator|CursorPaginator 페이지네이션 결과 (커서 요청 시 키셋)
     */
    public function getPaginated(array $filters = [], int $perPage = 20, ?User $scopeUser = null): LengthAwarePaginator|CursorPaginator
    {
        // 관계는 지연 조인의 outer 에서만 로드한다 (inner 는 키 컬럼만 조회한다)
        $query = NotificationLog::query();

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

        $sort = $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'sent_at');

        // 커서를 받은 요청은 키셋으로 응답한다. 로그는 계속 쌓이기만 해서 깊은 페이지를
        // OFFSET 으로 훑으면 건너뛸 행을 실제로 읽어야 하지만, 커서는 직전 페이지의 정렬
        // 키를 WHERE 경계로 삼아 깊이와 무관하게 일정하다.
        $sortKeys = array_map(
            static fn (array $spec): array => [$spec['column'], $spec['direction']],
            $sort
        );

        if (! empty($filters['cursor']) && KeysetPaginator::supports($sortKeys, self::SORTABLE_COLUMNS)) {
            return KeysetPaginator::paginate(
                query: $query->with(['senderUser', 'recipientUser']),
                perPage: $perPage,
                sortKeys: $sortKeys,
                uniqueKey: 'id',
                cursor: (string) $filters['cursor'],
            );
        }

        // 목록 컬럼을 좁히지 않는 이유: 이 목록의 리소스는 렌더링된 본문(longText `body`)까지
        // 그대로 노출하므로 컬럼을 빼면 응답 계약이 바뀐다. 지연 조인만으로도 본문을 읽는
        // 행 수가 OFFSET 과 무관하게 이번 페이지 분량으로 고정된다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $sort,
            perPage: $perPage,
            relations: ['senderUser', 'recipientUser'],
            // 로그 테이블은 계속 쌓이기만 한다. 총 건수는 상한까지만 세고 "다음" 이동은
            // per_page + 1 실측으로 끝까지 열어 둔다 (계산 불가는 마지막 페이지 번호 하나뿐).
            resultCap: PaginationLimits::resultCap('admin.notification_logs'),
        );
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
