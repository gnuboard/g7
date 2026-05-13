<?php

namespace App\Contracts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 활동 로그 Repository 인터페이스
 */
interface ActivityLogRepositoryInterface
{
    /**
     * 특정 모델의 활동 로그를 페이지네이션하여 조회합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */

    public function getPaginatedForModel(Model $model, array $filters = []): LengthAwarePaginator;
    /**
     * 활동 로그 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getPaginated(array $filters = []): LengthAwarePaginator;

    /**
     * 활동 로그를 삭제합니다.
     *
     * @param int $id 삭제할 활동 로그 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 여러 활동 로그를 일괄 삭제합니다.
     *
     * @param array<int> $ids 삭제할 활동 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int;

    /**
     * 최근 활동 로그를 스코프 권한 적용하여 조회합니다.
     *
     * @param  string  $permission  권한 식별자
     * @param  int  $limit  조회할 활동 수
     * @return Collection 활동 로그 컬렉션
     */
    public function getRecent(string $permission, int $limit = 5): Collection;

    /**
     * 사용자 삭제 시 해당 사용자의 모든 activity_logs.user_id 컬럼을 NULL 로 익명화합니다.
     *
     * 외래키가 제거된 파티셔닝 호환 스키마에서 직접 익명화 책임을 갖습니다.
     *
     * @param  int  $userId  익명화 대상 사용자 ID
     * @return int 익명화된 row 수
     */
    public function anonymizeUserId(int $userId): int;
}
