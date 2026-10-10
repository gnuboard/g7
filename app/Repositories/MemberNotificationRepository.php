<?php

namespace App\Repositories;

use App\Contracts\Repositories\MemberNotificationRepositoryInterface;
use App\Models\User;
use App\Repositories\Concerns\FiltersByDateRange;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Search\KeywordSearch;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * 회원 알림함(in-app 수신함) 관리자 조회 Repository 구현체
 */
class MemberNotificationRepository implements MemberNotificationRepositoryInterface
{
    use FiltersByDateRange;
    use PaginatesWithDeferredJoin;

    /**
     * {@inheritDoc}
     */
    public function paginateForAdmin(array $filters, int $perPage = 20): Paginator
    {
        $query = DatabaseNotification::query()
            // 회원 수신함만 본다 — notifiable 은 다형이라 관리자·시스템 대상 행이 섞일 수 있다
            ->where('notifications.notifiable_type', User::class)
            // 수신자 이름·이메일로 검색하려면 조인이 필요하다. FK 가 없는 다형 컬럼이라
            // leftJoin 으로 붙이고, 회원이 지워진 행도 목록에서 사라지지 않게 한다.
            // 테이블명은 모델에서 얻고 접두사는 붙이지 않는다 — Laravel 이 연결 접두사를 붙인다
            // (직접 붙이면 이중 접두가 된다).
            ->leftJoin((new User)->getTable().' as recipient', 'recipient.id', '=', 'notifications.notifiable_id');

        if (! empty($filters['user_id'])) {
            $query->where('notifications.notifiable_id', (int) $filters['user_id']);
        }

        $read = (string) ($filters['read'] ?? 'all');
        if ($read === 'unread') {
            $query->whereNull('notifications.read_at');
        } elseif ($read === 'read') {
            $query->whereNotNull('notifications.read_at');
        }

        // 목록은 받은 시각을 보는 사람의 시간대로 그린다 — 같은 하루로 자른다.
        $this->applyViewerDateRangeFilter(
            $query,
            'notifications.created_at',
            $filters['created_from'] ?? null,
            $filters['created_to'] ?? null
        );

        $this->applySearch($query, (string) ($filters['search_type'] ?? 'all'), $filters['search'] ?? null);

        // users 를 조인했으므로 정렬 컬럼은 테이블로 한정해야 한다 — 양쪽에 같은 이름이 있어
        // 한정하지 않으면 MySQL 이 "ambiguous" 로 거부한다.
        $sortBy = ($filters['sort_by'] ?? 'created_at') === 'read_at'
            ? 'notifications.read_at'
            : 'notifications.created_at';
        $direction = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['notifications.*'],
            sort: [['column' => $sortBy, 'direction' => $direction]],
            perPage: $perPage,
            // 수신자는 outer 에서만 eager load 한다 — 컨트롤러에서 loadMissing 을 부르면
            // 결과가 0건일 때 페이지네이터가 기본 Collection 을 들고 있어 터진다.
            relations: ['notifiable'],
            // PK 가 uuid 문자열이라 키 컬럼을 명시한다 — 기본값(id)은 테이블 한정자가 없어
            // 조인된 users.id 와 모호해진다.
            keyName: 'notifications.id',
            resultCap: PaginationLimits::resultCap('admin.member_notifications'),
        );
    }

    /**
     * 검색 조건을 적용합니다.
     *
     * 제목·본문은 `data` JSON 안에 있다(GenericNotification::toArray 가 subject/body 로 넣는다).
     *
     * @param  Builder  $query  대상 쿼리
     * @param  string  $type  검색 대상 (all|recipient|subject|body)
     * @param  string|null  $search  검색어
     * @return void 반환값 없음
     */
    private function applySearch(Builder $query, string $type, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        // `%`·`_` 는 글자 그대로 찾는다 — 회원 목록 검색과 같은 기준
        $needle = '%'.KeywordSearch::escapeLikeWildcards(trim($search)).'%';

        // 원시 SQL 은 쿼리 빌더가 접두사를 붙여 주지 않는다 — 여기서만 직접 붙인다.
        // (빌더 메서드에 넘기는 테이블명은 반대로 접두사를 붙이면 이중 접두가 된다)
        $table = DB::getTablePrefix().'notifications';
        $subject = "JSON_UNQUOTE(JSON_EXTRACT({$table}.data, '$.subject'))";
        $body = "JSON_UNQUOTE(JSON_EXTRACT({$table}.data, '$.body'))";

        match ($type) {
            'recipient' => $query->where(fn (Builder $w) => $w
                ->where('recipient.name', 'like', $needle)
                ->orWhere('recipient.email', 'like', $needle)),
            'subject' => $query->whereRaw("{$subject} LIKE ?", [$needle]),
            'body' => $query->whereRaw("{$body} LIKE ?", [$needle]),
            default => $query->where(fn (Builder $w) => $w
                ->where('recipient.name', 'like', $needle)
                ->orWhere('recipient.email', 'like', $needle)
                ->orWhereRaw("{$subject} LIKE ?", [$needle])
                ->orWhereRaw("{$body} LIKE ?", [$needle])),
        };
    }

    /**
     * {@inheritDoc}
     */
    public function deleteById(string $id): bool
    {
        return DatabaseNotification::query()->where('id', $id)->delete() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMany(array $ids): int
    {
        return (int) DatabaseNotification::query()->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function summaryByUser(int $userId): array
    {
        $row = DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->selectRaw('COUNT(*) as total_count, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread_count')
            ->first();

        return [
            'total' => (int) ($row->total_count ?? 0),
            'unread' => (int) ($row->unread_count ?? 0),
        ];
    }
}
