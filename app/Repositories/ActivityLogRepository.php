<?php

namespace App\Repositories;

use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Http\Resources\BaseApiCollection;
use App\Models\ActivityLog;
use App\Repositories\Concerns\DeletesInBatches;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use App\Repositories\Concerns\ResolvesSortSpec;
use App\Support\Query\KeysetPaginator;
use App\Support\Query\PaginationLimits;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 활동 로그 Repository
 */
class ActivityLogRepository implements ActivityLogRepositoryInterface
{
    use DeletesInBatches;
    use HasMultipleSearchFilters;
    use PaginatesWithDeferredJoin;
    use ResolvesSortSpec;

    /**
     * 활동 로그 목록 정렬 허용 컬럼
     *
     * 요청 값을 그대로 orderBy 에 넘기면 없는 컬럼으로 SQL 오류가 나거나 인덱스 없는 컬럼
     * 정렬을 강제할 수 있다.
     *
     * @var array<int, string>
     */
    private const SORTABLE_COLUMNS = [
        'id',
        'created_at',
        'action',
        'log_type',
        'user_id',
    ];

    /**
     * 특정 모델의 활동 로그를 페이지네이션하여 조회합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getPaginatedForModel(Model $model, array $filters = []): LengthAwarePaginator
    {
        $sortOrder = $this->normalizeSortDirection($filters['sort_order'] ?? null);
        $query = ActivityLog::forModel($model);

        if (isset($filters['action'])) {
            $query->action($filters['action']);
        }

        if (isset($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        // 컬럼을 좁히지 않는 이유는 getPaginated() 와 같다 — 리소스가 변경 내역을 그대로 노출한다
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: [['column' => 'created_at', 'direction' => $sortOrder]],
            perPage: (int) ($filters['per_page'] ?? 15),
            // 로그 테이블은 계속 쌓이기만 한다. 총 건수는 상한까지만 세고 "다음" 이동은
            // per_page + 1 실측으로 끝까지 열어 둔다 (계산 불가는 마지막 페이지 번호 하나뿐).
            resultCap: PaginationLimits::resultCap('admin.activity_logs'),
        );
    }

    /**
     * 활동 로그 목록을 페이지네이션하여 조회합니다.
     *
     * 요청에 `cursor` 가 있으면 키셋(커서) 방식으로, 없으면 페이지 번호 방식으로 응답합니다.
     * 두 방식의 응답 봉투 차이는 {@see BaseApiCollection} 이 흡수합니다.
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator|CursorPaginator 페이지네이션된 로그 목록
     */
    public function getPaginated(array $filters = []): LengthAwarePaginator|CursorPaginator
    {
        // 관계는 지연 조인의 outer 에서만 로드한다 (inner 는 키 컬럼만 조회한다)
        $query = ActivityLog::query();

        // 스코프 기반 권한 필터링 (self: 본인 로그만, role: 소유역할 로그, null: 전체)
        PermissionHelper::applyPermissionScope($query, 'core.activities.read');

        if (isset($filters['log_type'])) {
            if (is_array($filters['log_type'])) {
                $query->whereIn('log_type', $filters['log_type']);
            } else {
                $query->where('log_type', $filters['log_type']);
            }
        }

        if (isset($filters['action'])) {
            $query->action($filters['action']);
        }

        if (isset($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        if (isset($filters['loggable_type'])) {
            $query->where('loggable_type', $filters['loggable_type']);
        }

        if (isset($filters['created_by'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('uuid', $filters['created_by']);
            });
        }

        if (isset($filters['search'])) {
            $searchType = $filters['search_type'] ?? 'all';
            $this->applyBilingualSearch($query, $filters['search'], $searchType);
        }

        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', TimezoneHelper::fromSiteDateTime($filters['date_from']));
        }

        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', TimezoneHelper::fromSiteDateTime($filters['date_to']));
        }

        $sort = $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at');
        $perPage = (int) ($filters['per_page'] ?? 15);

        // 커서를 받은 요청은 키셋으로 응답한다. 로그는 계속 쌓이기만 해서 깊은 페이지를
        // OFFSET 으로 훑으면 건너뛸 행을 실제로 읽어야 하지만, 커서는 직전 페이지의 정렬
        // 키를 WHERE 경계로 삼아 깊이와 무관하게 일정하다.
        // 커서 모드에서는 OFFSET 자체가 없으므로 지연 조인이 해결하려던 문제도 함께 사라진다.
        $sortKeys = array_map(
            static fn (array $spec): array => [$spec['column'], $spec['direction']],
            $sort
        );

        if (! empty($filters['cursor']) && KeysetPaginator::supports($sortKeys, self::SORTABLE_COLUMNS)) {
            return KeysetPaginator::paginate(
                query: $query->with('user:id,uuid,name,email'),
                perPage: $perPage,
                sortKeys: $sortKeys,
                uniqueKey: 'id',
                cursor: (string) $filters['cursor'],
            );
        }

        // 목록 컬럼을 좁히지 않는 이유: 활동 로그 리소스는 변경 내역(mediumText `changes`)과
        // 부가 정보(`properties`)까지 그대로 노출하므로 컬럼을 빼면 응답 계약이 바뀐다.
        // 지연 조인만으로도 이 넓은 컬럼들을 읽는 행 수가 이번 페이지 분량으로 고정된다.
        return $this->paginateWithDeferredJoin(
            query: $query,
            columns: ['*'],
            sort: $sort,
            perPage: $perPage,
            relations: ['user:id,uuid,name,email'],
            // 로그 테이블은 계속 쌓이기만 한다. 총 건수는 상한까지만 세고 "다음" 이동은
            // per_page + 1 실측으로 끝까지 열어 둔다 (계산 불가는 마지막 페이지 번호 하나뿐).
            resultCap: PaginationLimits::resultCap('admin.activity_logs'),
        );
    }

    /**
     * 양방향(한글/영어) 검색을 적용합니다.
     *
     * action/description 필드는 영어 키를 저장하므로,
     * 검색어가 번역된 라벨과 일치하는 원본 키도 함께 검색합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $search  검색어
     * @param  string  $searchType  검색 유형 (all, action, description, ip_address)
     */
    private function applyBilingualSearch($query, string $search, string $searchType): void
    {
        $query->where(function ($q) use ($search, $searchType) {
            $searchLower = mb_strtolower($search);

            if ($searchType === 'action' || $searchType === 'all') {
                // 영어 원문 검색
                $q->orWhere('action', 'LIKE', "%{$search}%");

                // 번역된 라벨로 원본 action 값 역추적
                $matchingActions = $this->findActionsByTranslatedLabel($searchLower);
                if (! empty($matchingActions)) {
                    $q->orWhereIn('action', $matchingActions);
                }
            }

            if ($searchType === 'description' || $searchType === 'all') {
                // 영어 원문 검색
                $q->orWhere('description_key', 'LIKE', "%{$search}%");

                // 번역된 설명으로 원본 description_key 역추적
                $matchingKeys = $this->findDescriptionKeysByTranslatedText($searchLower);
                if (! empty($matchingKeys)) {
                    $q->orWhereIn('description_key', $matchingKeys);
                }
            }

            if ($searchType === 'ip_address' || $searchType === 'all') {
                $q->orWhere('ip_address', 'LIKE', "%{$search}%");
            }
        });
    }

    /**
     * 번역된 액션 라벨로 원본 action 값을 역추적합니다.
     *
     * @param  string  $searchLower  소문자 검색어
     * @return array<string> 일치하는 action 값 목록
     */
    private function findActionsByTranslatedLabel(string $searchLower): array
    {
        $distinctActions = ActivityLog::distinct()->pluck('action')->toArray();
        $matching = [];

        foreach ($distinctActions as $action) {
            // 전체 키 번역 (activity_log.action.user.create)
            $fullKey = "activity_log.action.{$action}";
            $translated = __($fullKey);
            if ($translated !== $fullKey && str_contains(mb_strtolower($translated), $searchLower)) {
                $matching[] = $action;

                continue;
            }

            // 마지막 세그먼트 번역 (activity_log.action.create)
            $lastSegment = last(explode('.', $action));
            $segmentKey = "activity_log.action.{$lastSegment}";
            $translated = __($segmentKey);
            if ($translated !== $segmentKey && str_contains(mb_strtolower($translated), $searchLower)) {
                $matching[] = $action;
            }
        }

        return $matching;
    }

    /**
     * 번역된 설명 텍스트로 원본 description_key를 역추적합니다.
     *
     * @param  string  $searchLower  소문자 검색어
     * @return array<string> 일치하는 description_key 목록
     */
    private function findDescriptionKeysByTranslatedText(string $searchLower): array
    {
        $distinctKeys = ActivityLog::distinct()->pluck('description_key')->filter()->toArray();
        $matching = [];

        foreach ($distinctKeys as $key) {
            $translated = __($key);
            if ($translated !== $key && str_contains(mb_strtolower($translated), $searchLower)) {
                $matching[] = $key;
            }
        }

        return $matching;
    }

    /**
     * 활동 로그를 삭제합니다.
     *
     * @param  int  $id  삭제할 활동 로그 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        return ActivityLog::where('id', $id)->delete() > 0;
    }

    /**
     * 여러 활동 로그를 일괄 삭제합니다.
     *
     * @param  array<int>  $ids  삭제할 활동 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int
    {
        return ActivityLog::whereIn('id', $ids)->delete();
    }

    /**
     * 최근 활동 로그를 스코프 권한 적용하여 조회합니다.
     *
     * @param  string  $permission  권한 식별자
     * @param  int  $limit  조회할 활동 수
     * @return Collection 활동 로그 컬렉션
     */
    public function getRecent(string $permission, int $limit = 5): Collection
    {
        $query = ActivityLog::query()->with('user:id,uuid,name,email');

        // 스코프 기반 권한 필터링 (self: 본인 활동만, role: 소유역할 활동, null: 전체)
        PermissionHelper::applyPermissionScope($query, $permission);

        return $query->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * 사용자 삭제 시 해당 사용자의 모든 activity_logs.user_id 컬럼을 NULL 로 익명화합니다.
     *
     * @param  int  $userId  익명화 대상 사용자 ID
     * @return int 익명화된 row 수
     */
    public function anonymizeUserId(int $userId): int
    {
        return ActivityLog::where('user_id', $userId)->update(['user_id' => null]);
    }

    /**
     * 보존 기간이 지난 활동 로그를 삭제합니다.
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
            ActivityLog::where('created_at', '<', now()->subDays(max(1, $days)))
        );
    }
}
