<?php

namespace App\Repositories;

use App\Contracts\Repositories\UserSearchHistoryRepositoryInterface;
use App\Models\User;
use App\Models\UserSearchHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 회원 최근 검색어 Repository
 *
 * @since 7.1.5
 */
class UserSearchHistoryRepository implements UserSearchHistoryRepositoryInterface
{
    /** 보관 기한 파기 한 번에 지울 행 수 */
    private const DELETE_CHUNK = 500;

    /**
     * 회원·범위의 최근 검색어를 최신순으로 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $scope  검색 범위 id
     * @param  int  $limit  최대 개수
     * @return Collection<int, UserSearchHistory> 검색어 목록
     */
    public function latestForUser(int $userId, string $scope, int $limit): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return UserSearchHistory::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->orderByDesc('searched_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->toBase();
    }

    /**
     * 회원의 검색어 한 건을 찾습니다 (다른 회원의 행은 null).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $id  검색어 ID
     * @return UserSearchHistory|null 검색어
     */
    public function findForUser(int $userId, int $id): ?UserSearchHistory
    {
        return UserSearchHistory::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->first();
    }

    /**
     * 검색어를 기록합니다 — 같은 회원·범위·검색어가 있으면 검색 일시와 보이는 글자만 새로 쓴다.
     *
     * 이미 있는 행의 검색 일시가 더 늦으면(병합 때 옛 기록이 들어온 경우) 일시를 뒤로 돌리지 않는다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $scope  검색 범위 id
     * @param  string  $keyword  정규화한 검색어
     * @param  Carbon  $searchedAt  검색 일시
     * @return UserSearchHistory 기록한 행
     */
    public function upsert(int $userId, string $scope, string $keyword, Carbon $searchedAt): UserSearchHistory
    {
        $existing = $this->findSame($userId, $scope, $keyword);

        if ($existing === null) {
            try {
                return UserSearchHistory::query()->create([
                    'user_id' => $userId,
                    'scope' => $scope,
                    'keyword' => $keyword,
                    'searched_at' => $searchedAt,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 같은 검색어를 동시에 두 번 보낸 경우 — 먼저 들어간 행을 갱신한다.
                $existing = $this->findSame($userId, $scope, $keyword);

                if ($existing === null) {
                    throw $e;
                }
            }
        }

        if ($existing->searched_at === null || $existing->searched_at->lte($searchedAt)) {
            $existing->keyword = $keyword;
            $existing->searched_at = $searchedAt;
            $existing->save();
        }

        return $existing;
    }

    /**
     * 회원·범위의 최신 $keep 건만 남기고 나머지를 삭제합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $scope  검색 범위 id
     * @param  int  $keep  남길 건수
     * @return int 삭제 건수
     */
    public function pruneBeyond(int $userId, string $scope, int $keep): int
    {
        $keepIds = $this->latestForUser($userId, $scope, max(0, $keep))->pluck('id')->all();

        return UserSearchHistory::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * 검색어 한 건을 삭제합니다.
     *
     * @param  UserSearchHistory  $history  삭제할 행
     * @return bool 삭제 여부
     */
    public function delete(UserSearchHistory $history): bool
    {
        return (bool) $history->delete();
    }

    /**
     * 회원의 검색어를 삭제합니다 (범위를 주면 그 범위만).
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $scope  검색 범위 id (null 이면 전 범위)
     * @return int 삭제 건수
     */
    public function deleteByUser(int $userId, ?string $scope = null): int
    {
        return UserSearchHistory::query()
            ->where('user_id', $userId)
            ->when($scope !== null, fn ($q) => $q->where('scope', $scope))
            ->delete();
    }

    /**
     * 범위별 보관 기한이 지난 검색어 건수를 셉니다.
     *
     * @param  array<string, int>  $policies  범위 id → 보관 기한(일, 1 이상)
     * @param  Carbon  $now  기준 시각
     * @return int 대상 건수
     */
    public function countExpired(array $policies, Carbon $now): int
    {
        if ($policies === []) {
            return 0;
        }

        return $this->expiredQuery($policies, $now)->count();
    }

    /**
     * 보관 기한이 지난 검색어를 회원·범위로 묶은 목록의 한 페이지를 반환합니다 (검색어는 싣지 않는다).
     *
     * 행: `user_id`·`user_uuid`·`user_name`·`scope`·`keyword_count`·`oldest_searched_at`. 가장 오래된 일시 오름차순.
     *
     * @param  array<string, int>  $policies  범위 id → 보관 기한(일, 1 이상)
     * @param  Carbon  $now  기준 시각
     * @param  int  $page  페이지 번호 (1부터)
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator<int, object> 묶음 행 페이지
     */
    public function paginateExpiredGroups(array $policies, Carbon $now, int $page, int $perPage): LengthAwarePaginator
    {
        if ($policies === []) {
            return new Paginator(collect(), 0, $perPage, $page);
        }

        $users = (new User)->getTable();
        $histories = (new UserSearchHistory)->getTable();

        $grouped = $this->expiredQuery($policies, $now)
            ->join($users, $users.'.id', '=', $histories.'.user_id')
            ->groupBy($histories.'.user_id', $histories.'.scope', $users.'.uuid', $users.'.name')
            ->select([
                $histories.'.user_id',
                $histories.'.scope',
                $users.'.uuid as user_uuid',
                $users.'.name as user_name',
                DB::raw('COUNT(*) as keyword_count'),
                // 원시 식에는 테이블 접두어가 붙지 않으므로 열 이름만 쓴다 — 조인한 users 에는 searched_at 이 없어 모호하지 않다
                DB::raw('MIN(searched_at) as oldest_searched_at'),
            ])
            ->toBase();

        // 묶음 쿼리의 총계는 묶음 수여야 한다 — 기본 paginate 의 count 는 행 수를 센다.
        $total = DB::query()->fromSub(clone $grouped, 'expired_groups')->count();

        $items = $grouped
            ->orderBy('oldest_searched_at')
            ->orderBy($histories.'.user_id')
            ->orderBy($histories.'.scope')
            ->forPage($page, $perPage)
            ->get();

        return new Paginator($items, $total, $perPage, $page);
    }

    /**
     * 범위별 보관 기한이 지난 검색어를 삭제합니다.
     *
     * @param  array<string, int>  $policies  범위 id → 보관 기한(일, 1 이상)
     * @param  Carbon  $now  기준 시각
     * @return int 삭제 건수
     */
    public function deleteExpired(array $policies, Carbon $now): int
    {
        if ($policies === []) {
            return 0;
        }

        $deleted = 0;

        // 대량 파기를 잘게 나눠 잠금 시간을 줄인다 — 지운 행은 조건에서 빠지므로 OFFSET 없이 반복한다.
        do {
            $ids = $this->expiredQuery($policies, $now)->limit(self::DELETE_CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += UserSearchHistory::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === self::DELETE_CHUNK);

        return $deleted;
    }

    /**
     * 같은 회원·범위·검색어 행을 찾습니다 (DB 콜레이션 기준 — 대소문자 구분 없음).
     *
     * @param  int  $userId  회원 ID
     * @param  string  $scope  검색 범위 id
     * @param  string  $keyword  정규화한 검색어
     * @return UserSearchHistory|null 행
     */
    private function findSame(int $userId, string $scope, string $keyword): ?UserSearchHistory
    {
        return UserSearchHistory::query()
            ->where('user_id', $userId)
            ->where('scope', $scope)
            ->where('keyword', $keyword)
            ->first();
    }

    /**
     * 보관 기한이 지난 검색어 쿼리를 만듭니다 — 건수·명단·파기가 같은 이 조건식을 쓴다.
     *
     * @param  array<string, int>  $policies  범위 id → 보관 기한(일, 1 이상)
     * @param  Carbon  $now  기준 시각
     * @return Builder<UserSearchHistory> 대상 쿼리
     */
    private function expiredQuery(array $policies, Carbon $now): Builder
    {
        return UserSearchHistory::query()->where(function (Builder $outer) use ($policies, $now) {
            foreach ($policies as $scope => $days) {
                $outer->orWhere(function (Builder $q) use ($scope, $days, $now) {
                    $q->where('user_search_histories.scope', $scope)
                        ->where('user_search_histories.searched_at', '<', $now->copy()->subDays(max(1, (int) $days)));
                });
            }
        });
    }
}
