<?php

namespace App\Contracts\Repositories;

use App\Models\UserSearchHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 회원 최근 검색어 Repository 인터페이스
 *
 * @since 7.1.5
 */
interface UserSearchHistoryRepositoryInterface
{
    /**
     * 회원·범위의 최근 검색어를 최신순으로 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $scope  검색 범위 id
     * @param  int  $limit  최대 개수
     * @return Collection<int, UserSearchHistory> 검색어 목록
     */
    public function latestForUser(int $userId, string $scope, int $limit): Collection;

    /**
     * 회원의 검색어 한 건을 찾습니다 (다른 회원의 행은 null).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $id  검색어 ID
     * @return UserSearchHistory|null 검색어
     */
    public function findForUser(int $userId, int $id): ?UserSearchHistory;

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
    public function upsert(int $userId, string $scope, string $keyword, Carbon $searchedAt): UserSearchHistory;

    /**
     * 회원·범위의 최신 $keep 건만 남기고 나머지를 삭제합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $scope  검색 범위 id
     * @param  int  $keep  남길 건수
     * @return int 삭제 건수
     */
    public function pruneBeyond(int $userId, string $scope, int $keep): int;

    /**
     * 검색어 한 건을 삭제합니다.
     *
     * @param  UserSearchHistory  $history  삭제할 행
     * @return bool 삭제 여부
     */
    public function delete(UserSearchHistory $history): bool;

    /**
     * 회원의 검색어를 삭제합니다 (범위를 주면 그 범위만).
     *
     * @param  int  $userId  회원 ID
     * @param  string|null  $scope  검색 범위 id (null 이면 전 범위)
     * @return int 삭제 건수
     */
    public function deleteByUser(int $userId, ?string $scope = null): int;

    /**
     * 범위별 보관 기한이 지난 검색어 건수를 셉니다.
     *
     * @param  array<string, int>  $policies  범위 id → 보관 기한(일, 1 이상)
     * @param  Carbon  $now  기준 시각
     * @return int 대상 건수
     */
    public function countExpired(array $policies, Carbon $now): int;

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
    public function paginateExpiredGroups(array $policies, Carbon $now, int $page, int $perPage): LengthAwarePaginator;

    /**
     * 범위별 보관 기한이 지난 검색어를 삭제합니다.
     *
     * @param  array<string, int>  $policies  범위 id → 보관 기한(일, 1 이상)
     * @param  Carbon  $now  기준 시각
     * @return int 삭제 건수
     */
    public function deleteExpired(array $policies, Carbon $now): int;
}
