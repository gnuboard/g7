<?php

namespace App\Services;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleTriggerType;
use App\Exceptions\ScheduleAlreadyRunningException;
use App\Exceptions\ScheduleExecutionException;
use App\Exceptions\ScheduleRunLockUnavailableException;
use App\Extension\HookManager;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Services\Schedule\ScheduleDispatcher;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * 저장소와 실행 디스패처를 주입받습니다.
     *
     * @param  ScheduleRepositoryInterface  $scheduleRepository  스케줄 저장소
     * @param  ScheduleHistoryRepositoryInterface  $historyRepository  실행 이력 저장소
     * @param  ScheduleDispatcher  $dispatcher  실행 디스패처(즉시 실행용)
     * @return void 반환값 없음
     */
    public function __construct(
        private ScheduleRepositoryInterface $scheduleRepository,
        private ScheduleHistoryRepositoryInterface $historyRepository,
        private ScheduleDispatcher $dispatcher
    ) {}

    /**
     * 화면의 「즉시 실행」으로 스케줄을 한 번 실행합니다.
     *
     * 예약 실행과 같은 실행기·허용목록을 거친다(수동 트리거). 실행 조건이 미충족이어도 실행한다 —
     * 화면이 미충족 조건을 미리 경고한다.
     *
     * @param  Schedule  $schedule  실행할 스케줄
     * @param  int|null  $userId  실행한 관리자 ID
     * @return ScheduleHistory|null 바로 실행이면 이력(실패도 이력으로 온다), 큐로 보냈거나
     *                              실패만 기록하는 행이 성공했으면 null
     *
     * @throws ScheduleExecutionException 소유 확장이 비활성일 때
     * @throws ScheduleAlreadyRunningException 「중복 실행 방지」 스케줄의 앞선 실행이 아직 도는 중일 때 (409)
     * @throws ScheduleRunLockUnavailableException 실행 잠금 저장소 오류일 때 (503)
     */
    public function runNow(Schedule $schedule, ?int $userId = null): ?ScheduleHistory
    {
        try {
            return $this->dispatcher->dispatch($schedule, ScheduleTriggerType::Manual, $userId);
        } catch (ScheduleExecutionException $e) {
            // 「지금 실행」이 앞선 실행과 겹치면 409 로 거절한다(#161 F4). 실행기는 틱·큐 경로와 같은 사유 코드로 알리고,
            // 잠금을 이력 생성보다 먼저 잡으므로 거절된 요청은 이력·last_result 를 남기지 않는다.
            if ($e->reasonCode === 'already_running') {
                throw new ScheduleAlreadyRunningException((int) $schedule->id);
            }

            throw $e;
        }
    }

    /**
     * 필터링된 스케줄 목록을 페이지네이션으로 조회합니다.
     *
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 스케줄 목록
     */
    public function getPaginatedSchedules(array $filters = []): LengthAwarePaginator
    {
        return $this->scheduleRepository->getPaginatedSchedules($filters);
    }

    /**
     * 새로운 스케줄을 생성합니다.
     *
     * @param  array  $data  스케줄 생성 데이터
     * @return Schedule 생성된 스케줄 모델
     *
     * @throws ValidationException 생성 실패 시
     */
    public function create(array $data): Schedule
    {
        try {
            // before_create 훅 실행
            HookManager::doAction('core.schedule.before_create', $data);
            $data = HookManager::applyFilters('core.schedule.filter_create_data', $data);

            $schedule = $this->scheduleRepository->create($data);

            // after_create 훅 실행
            HookManager::doAction('core.schedule.after_create', $schedule);

            return $schedule->fresh('creator');
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('schedule.create_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 기존 스케줄 정보를 수정합니다.
     *
     * @param  Schedule  $schedule  수정할 스케줄 모델
     * @param  array  $data  수정할 데이터
     * @return Schedule 수정된 스케줄 모델
     *
     * @throws ValidationException 수정 실패 시
     */
    public function update(Schedule $schedule, array $data): Schedule
    {
        try {
            // before_update 훅 실행
            HookManager::doAction('core.schedule.before_update', $schedule, $data);

            // 스냅샷 캡처 (ChangeDetector용)
            $snapshot = $schedule->toArray();

            $data = HookManager::applyFilters('core.schedule.filter_update_data', $data, $schedule);

            $this->scheduleRepository->update($schedule, $data);

            // after_update 훅 실행 (스냅샷 전달)
            HookManager::doAction('core.schedule.after_update', $schedule, $snapshot);

            return $schedule->fresh('creator');
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('schedule.update_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * 스케줄을 삭제합니다.
     *
     * @param  Schedule  $schedule  삭제할 스케줄 모델
     * @return bool 삭제 성공 여부
     *
     * @throws ValidationException 삭제 실패 시
     */
    public function delete(Schedule $schedule): bool
    {
        try {
            // 이력만 지워지고 스케줄은 남는 상태를 막기 위해 두 단계를 하나로 묶는다.
            $result = DB::transaction(function () use ($schedule) {
                // before_delete 훅 실행
                HookManager::doAction('core.schedule.before_delete', $schedule);

                // 실행 이력 삭제 (명시적 삭제 - CASCADE 의존 금지)
                $schedule->histories()->delete();

                return $this->scheduleRepository->delete($schedule);
            });

            // after_delete 훅 실행
            HookManager::doAction('core.schedule.after_delete', $schedule->id);

            return $result;
        } catch (Exception $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'general' => [__('schedule.delete_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * ID로 스케줄 상세 정보를 조회합니다.
     *
     * @param  int  $id  스케줄 ID
     * @return Schedule|null 스케줄 모델 또는 null
     */
    public function getScheduleById(int $id): ?Schedule
    {
        return $this->scheduleRepository->findById($id);
    }

    /**
     * 스케줄을 복제합니다.
     *
     * @param  Schedule  $schedule  복제할 스케줄
     * @return Schedule 복제된 스케줄
     */
    public function duplicate(Schedule $schedule): Schedule
    {
        return $this->scheduleRepository->duplicate($schedule);
    }

    /**
     * 여러 스케줄의 상태를 일괄 업데이트합니다.
     *
     * @param  array  $ids  스케줄 ID 배열
     * @param  bool  $isActive  활성화 여부
     * @return array 업데이트 결과
     */
    public function bulkUpdateStatus(array $ids, bool $isActive): array
    {
        // before_bulk_update 훅 실행
        HookManager::doAction('core.schedule.before_bulk_update', $ids, $isActive);

        $updatedCount = DB::transaction(function () use ($ids, $isActive) {
            return $this->scheduleRepository->bulkUpdateStatus($ids, $isActive);
        });

        // after_bulk_update 훅 실행
        HookManager::doAction('core.schedule.after_bulk_update', $ids, $isActive, $updatedCount);

        return [
            'updated_count' => $updatedCount,
        ];
    }

    /**
     * 여러 스케줄을 일괄 삭제합니다.
     *
     * @param  array  $ids  스케줄 ID 배열
     * @return array 삭제 결과
     */
    public function bulkDelete(array $ids): array
    {
        // before_bulk_delete 훅 실행
        HookManager::doAction('core.schedule.before_bulk_delete', $ids);

        $deletedCount = DB::transaction(function () use ($ids) {
            // 실행 이력 삭제 (명시적 삭제 - CASCADE 의존 금지) — 단건 delete() 와 같은 순서
            $this->historyRepository->deleteByScheduleIds($ids);

            return $this->scheduleRepository->bulkDelete($ids);
        });

        // after_bulk_delete 훅 실행
        HookManager::doAction('core.schedule.after_bulk_delete', $ids, $deletedCount);

        return [
            'deleted_count' => $deletedCount,
        ];
    }

    /**
     * 스케줄 관련 통계 정보를 조회합니다.
     *
     * @return array 스케줄 통계 데이터
     */
    public function getStatistics(): array
    {
        return $this->scheduleRepository->getStatistics();
    }

    /**
     * 특정 스케줄의 실행 이력을 페이지네이션으로 조회합니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     * @param  array  $filters  필터 조건 배열
     * @return LengthAwarePaginator 페이지네이션된 이력 목록
     */
    public function getHistory(int $scheduleId, array $filters = []): LengthAwarePaginator
    {
        return $this->historyRepository->getPaginatedByScheduleId($scheduleId, $filters);
    }

    /**
     * 실행 이력을 삭제합니다.
     *
     * @param  int  $historyId  이력 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteHistory(int $historyId): bool
    {
        $history = $this->historyRepository->findById($historyId);

        if (! $history) {
            return false;
        }

        return $this->historyRepository->delete($history);
    }

    /**
     * 여러 실행 이력을 일괄 삭제합니다.
     *
     * @param  array  $ids  이력 ID 배열
     * @return array 삭제 결과
     */
    public function bulkDeleteHistory(array $ids): array
    {
        $deletedCount = $this->historyRepository->bulkDelete($ids);

        return [
            'deleted_count' => $deletedCount,
        ];
    }

    /**
     * 보존 기간이 지난 실행 이력을 정리합니다 (자동 파기).
     *
     * 운영자가 고른 ID 를 지우는 bulkDeleteHistory 와 달리 대상을 기간으로 정하고
     * 사람 없이 예약 실행되므로 별개의 훅을 발행합니다.
     *
     * @param  int  $days  보존 기간 (일)
     * @return int 삭제된 건수
     */
    public function pruneHistory(int $days): int
    {
        HookManager::doAction('core.schedule.before_prune_history', $days);

        $deletedCount = $this->historyRepository->deleteOlderThan($days);

        HookManager::doAction('core.schedule.after_prune_history', $days, $deletedCount);

        return $deletedCount;
    }

    /**
     * 활성화된 스케줄들을 조회합니다.
     *
     * @return Collection 활성화된 스케줄 컬렉션
     */
    public function getActiveSchedules(): Collection
    {
        return $this->scheduleRepository->getActiveSchedules();
    }
}
