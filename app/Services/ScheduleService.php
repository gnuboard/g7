<?php

namespace App\Services;

use App\Contracts\Repositories\ScheduleHistoryRepositoryInterface;
use App\Contracts\Repositories\ScheduleRepositoryInterface;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    public function __construct(
        private ScheduleRepositoryInterface $scheduleRepository,
        private ScheduleHistoryRepositoryInterface $historyRepository
    ) {}

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
            // before_delete 훅 실행
            HookManager::doAction('core.schedule.before_delete', $schedule);

            // 실행 이력 삭제 (명시적 삭제 - CASCADE 의존 금지)
            $schedule->histories()->delete();

            $result = $this->scheduleRepository->delete($schedule);

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
     * 스케줄을 즉시 실행합니다.
     *
     * @param  Schedule  $schedule  실행할 스케줄
     * @param  int|null  $triggeredBy  수동 실행자 ID
     * @return ScheduleHistory 실행 이력
     *
     * @throws ValidationException 실행 실패 시
     */
    public function runSchedule(Schedule $schedule, ?int $triggeredBy = null): ScheduleHistory
    {
        // before_run 훅 실행
        HookManager::doAction('core.schedule.before_run', $schedule);

        // 실행 이력 생성
        $history = $this->historyRepository->create([
            'schedule_id' => $schedule->id,
            'started_at' => now(),
            'status' => ScheduleResultStatus::Running,
            'trigger_type' => $triggeredBy ? ScheduleTriggerType::Manual : ScheduleTriggerType::Scheduled,
            'triggered_by' => $triggeredBy,
        ]);

        // 스케줄 상태 업데이트
        $this->scheduleRepository->update($schedule, [
            'last_result' => ScheduleResultStatus::Running,
            'last_run_at' => now(),
        ]);

        try {
            $startTime = microtime(true);
            $memoryStart = memory_get_usage();

            // 타입별 실행
            $result = match ($schedule->type) {
                ScheduleType::Artisan => $this->executeArtisanCommand($schedule),
                ScheduleType::Shell => $this->executeShellCommand($schedule),
                ScheduleType::Url => $this->executeUrlCall($schedule),
            };

            $endTime = microtime(true);
            $memoryEnd = memory_get_usage();

            // 이력 업데이트 (성공)
            $this->historyRepository->update($history, [
                'ended_at' => now(),
                'duration' => (int) ($endTime - $startTime),
                'status' => ScheduleResultStatus::Success,
                'exit_code' => 0,
                'memory_usage' => $memoryEnd - $memoryStart,
                'output' => $result['output'] ?? null,
            ]);

            // 스케줄 상태 업데이트
            $schedule->last_result = ScheduleResultStatus::Success;
            $schedule->calculateNextRunAt();
            $schedule->save();

            // after_run 훅 실행
            HookManager::doAction('core.schedule.after_run', $schedule, $history);

            return $history->fresh();

        } catch (Exception $e) {
            // 이력 업데이트 (실패)
            $this->historyRepository->update($history, [
                'ended_at' => now(),
                'status' => ScheduleResultStatus::Failed,
                'exit_code' => $e->getCode() ?: 1,
                'error_output' => $e->getMessage(),
            ]);

            // 스케줄 상태 업데이트
            $schedule->last_result = ScheduleResultStatus::Failed;
            $schedule->calculateNextRunAt();
            $schedule->save();

            Log::error('Schedule execution failed', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'general' => [__('schedule.run_failed', ['error' => $e->getMessage()])],
            ]);
        }
    }

    /**
     * Artisan 커맨드를 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array 실행 결과
     */
    private function executeArtisanCommand(Schedule $schedule): array
    {
        $output = '';

        Artisan::call($schedule->command, [], new \Symfony\Component\Console\Output\BufferedOutput);

        return [
            'output' => Artisan::output(),
            'exit_code' => 0,
        ];
    }

    /**
     * 쉘 명령을 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array 실행 결과
     *
     * @throws Exception 실행 실패 시
     */
    private function executeShellCommand(Schedule $schedule): array
    {
        $timeout = $schedule->timeout ?? 60;

        $result = Process::timeout($timeout)->run($schedule->command);

        if ($result->failed()) {
            throw new Exception($result->errorOutput() ?: __('schedule.shell_command_failed'), $result->exitCode());
        }

        return [
            'output' => $result->output(),
            'exit_code' => $result->exitCode(),
        ];
    }

    /**
     * URL 호출을 실행합니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @return array 실행 결과
     *
     * @throws Exception 호출 실패 시
     */
    private function executeUrlCall(Schedule $schedule): array
    {
        $timeout = $schedule->timeout ?? 30;

        $response = Http::timeout($timeout)->get($schedule->command);

        if ($response->failed()) {
            throw new Exception(__('schedule.http_request_failed', ['status' => $response->status()]), $response->status());
        }

        return [
            'output' => 'HTTP Status: '.$response->status()."\n".$response->body(),
            'exit_code' => 0,
        ];
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
     * 활성화된 스케줄들을 조회합니다.
     *
     * @return Collection 활성화된 스케줄 컬렉션
     */
    public function getActiveSchedules(): Collection
    {
        return $this->scheduleRepository->getActiveSchedules();
    }

    /**
     * 실행 대기 중인 스케줄들을 조회합니다.
     *
     * @return Collection 실행 대기 중인 스케줄 컬렉션
     */
    public function getDueSchedules(): Collection
    {
        return $this->scheduleRepository->getDueSchedules();
    }
}
