<?php

namespace App\Services;

use App\Contracts\Repositories\ActivityLogRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\CursorPaginator;
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
     * @param  ActivityLogRepositoryInterface  $repository  활동 로그 리포지토리
     */
    public function __construct(
        private ActivityLogRepositoryInterface $repository
    ) {}

    /**
     * 특정 모델의 활동 로그 목록을 조회합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 로그 목록
     */
    public function getLogsForModel(Model $model, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->getPaginatedForModel($model, $filters);
    }

    /**
     * 활동 로그 목록을 조회합니다.
     *
     * 요청에 `cursor` 가 있으면 키셋(커서) 방식으로 응답합니다.
     *
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator|CursorPaginator 페이지네이션된 로그 목록
     */
    public function getList(array $filters = []): LengthAwarePaginator|CursorPaginator
    {
        return $this->repository->getPaginated($filters);
    }

    /**
     * 활동 로그를 삭제합니다.
     *
     * @param  int  $id  삭제할 활동 로그 ID
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
     * @param  array<int>  $ids  삭제할 활동 로그 ID 목록
     * @return int 삭제된 건수
     */
    public function deleteMany(array $ids): int
    {
        HookManager::doAction('core.activity_log.before_delete_many', $ids);

        $count = $this->repository->deleteMany($ids);

        HookManager::doAction('core.activity_log.after_delete_many', $ids, $count);

        return $count;
    }

    /**
     * 보존 기간이 지난 활동 로그를 정리합니다 (자동 파기).
     *
     * 운영자가 고른 ID 를 지우는 deleteMany 와는 별개의 훅을 발행한다. 자동 파기는
     * 대상을 ID 로 지목하지 않고(기간으로 정한다), 사람이 없는 예약 실행이라
     * 본인인증 같은 대화형 가드를 태울 수 없기 때문이다. 확장은 이 훅으로
     * 외부 보관 등 자기 처리를 붙인다.
     *
     * @param  int  $days  보존 기간 (일)
     * @return int 삭제된 건수
     */
    public function prune(int $days): int
    {
        HookManager::doAction('core.activity_log.before_prune', $days);

        $count = $this->repository->deleteOlderThan($days);

        HookManager::doAction('core.activity_log.after_prune', $days, $count);

        return $count;
    }
}
