<?php

namespace App\Repositories;

use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Helpers\TimezoneHelper;
use App\Models\ActivityLog;
use App\Repositories\Concerns\HasMultipleSearchFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 활동 로그 Repository
 */
class ActivityLogRepository implements ActivityLogRepositoryInterface
{
    use HasMultipleSearchFilters;
    /**
     * 특정 모델의 활동 로그를 페이지네이션하여 조회합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getPaginatedForModel(Model $model, array $filters = []): LengthAwarePaginator
    {
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query = ActivityLog::forModel($model)->orderBy('created_at', $sortOrder);

        if (isset($filters['action'])) {
            $query->action($filters['action']);
        }

        if (isset($filters['user_id'])) {
            $query->byUser($filters['user_id']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * 활동 로그 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getPaginated(array $filters = []): LengthAwarePaginator
    {
        $query = ActivityLog::query()->with('user:id,uuid,name,email');

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

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($filters['per_page'] ?? 15);
    }

    /**
     * 양방향(한글/영어) 검색을 적용합니다.
     *
     * action/description 필드는 영어 키를 저장하므로,
     * 검색어가 번역된 라벨과 일치하는 원본 키도 함께 검색합니다.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 쿼리 빌더
     * @param string $search 검색어
     * @param string $searchType 검색 유형 (all, action, description, ip_address)
     * @return void
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
     * @param string $searchLower 소문자 검색어
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
     * @param string $searchLower 소문자 검색어
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
     * @param int $id 삭제할 활동 로그 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        return ActivityLog::where('id', $id)->delete() > 0;
    }

    /**
     * 여러 활동 로그를 일괄 삭제합니다.
     *
     * @param array<int> $ids 삭제할 활동 로그 ID 목록
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
}
