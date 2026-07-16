<?php

namespace App\Services;

use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * 활동 로그 서비스
 *
 * 활동 로그의 조회 및 삭제 기능을 제공합니다.
 * 기록은 Log::channel('activity') → ActivityLogHandler를 통해 직접 수행합니다.
 */
class ActivityLogService
{
    /**
     * ActivityLogService 생성자
     *
     * @param ActivityLogRepositoryInterface $repository 활동 로그 리포지토리
     */
    public function __construct(
        private ActivityLogRepositoryInterface $repository
    ) {}

    /**
     * 특정 모델의 활동 로그 목록을 조회합니다.
     *
     * @param Model $model 대상 모델
     * @param array $filters 필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getLogsForModel(Model $model, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->getPaginatedForModel($model, $filters);
    }

    /**
     * 활동 로그 목록을 조회합니다.
     *
     * @param array $filters 필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getList(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->getPaginated($filters);
    }

    /**
     * 활동 로그를 삭제합니다.
     *
     * @param int $id 삭제할 활동 로그 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        HookManager::doAction('core.activity_log.before_delete', $id);

        $result = $this->repository->delete($id);

        HookManager::doAction('core.activity_log.after_delete', $id);

        return $result;
    }

    /**
     * 여러 활동 로그를 일괄 삭제합니다.
     *
     * @param array<int> $ids 삭제할 활동 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int
    {
        HookManager::doAction('core.activity_log.before_delete_many', $ids);

        $count = $this->repository->deleteMany($ids);

        HookManager::doAction('core.activity_log.after_delete_many', $ids, $count);

        return $count;
    }
}
