<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Schedule\BulkDeleteScheduleRequest;
use App\Http\Requests\Schedule\BulkUpdateScheduleStatusRequest;
use App\Http\Requests\Schedule\CreateScheduleRequest;
use App\Http\Requests\Schedule\ScheduleHistoryListRequest;
use App\Http\Requests\Schedule\ScheduleListRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ScheduleCollection;
use App\Http\Resources\ScheduleHistoryCollection;
use App\Http\Resources\ScheduleHistoryResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Services\ScheduleService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 스케줄 관리 컨트롤러
 *
 * 관리자가 시스템 스케줄(예약 작업)을 관리할 수 있는 기능을 제공합니다.
 */
class ScheduleController extends AdminBaseController
{
    public function __construct(
        private ScheduleService $scheduleService
    ) {
        parent::__construct();
    }

    /**
     * 필터링된 스케줄 목록을 조회합니다.
     *
     * @param  ScheduleListRequest  $request  스케줄 목록 요청 데이터
     * @return JsonResponse 스케줄 목록과 통계 정보를 포함한 JSON 응답
     */
    public function index(ScheduleListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $schedules = $this->scheduleService->getPaginatedSchedules($filters);
            $statistics = $this->scheduleService->getStatistics();

            $collection = new ScheduleCollection($schedules);

            return $this->success(
                'schedule.fetch_success',
                $collection->withStatistics($statistics)
            );
        } catch (Exception $e) {
            return $this->error('schedule.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 새로운 스케줄을 생성합니다.
     *
     * @param  CreateScheduleRequest  $request  스케줄 생성 요청 데이터
     * @return JsonResponse 생성된 스케줄 정보를 포함한 JSON 응답
     */
    public function store(CreateScheduleRequest $request): JsonResponse
    {
        try {
            $schedule = $this->scheduleService->create($request->validatedWithCreator());

            return $this->successWithResource(
                'schedule.create_success',
                new ScheduleResource($schedule),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('schedule.create_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('schedule.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 스케줄의 상세 정보를 조회합니다.
     *
     * @param  Schedule  $schedule  조회할 스케줄 모델
     * @return JsonResponse 스케줄 상세 정보를 포함한 JSON 응답
     */
    public function show(Schedule $schedule): JsonResponse
    {
        try {
            $schedule->load('creator');

            return $this->successWithResource(
                'schedule.fetch_success',
                new ScheduleResource($schedule)
            );
        } catch (Exception $e) {
            return $this->error('schedule.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 기존 스케줄 정보를 수정합니다.
     *
     * @param  UpdateScheduleRequest  $request  스케줄 수정 요청 데이터
     * @param  Schedule  $schedule  수정할 스케줄 모델
     * @return JsonResponse 수정된 스케줄 정보를 포함한 JSON 응답
     */
    public function update(UpdateScheduleRequest $request, Schedule $schedule): JsonResponse
    {
        try {
            $updatedSchedule = $this->scheduleService->update($schedule, $request->validated());

            return $this->successWithResource(
                'schedule.update_success',
                new ScheduleResource($updatedSchedule)
            );
        } catch (ValidationException $e) {
            return $this->error('schedule.update_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('schedule.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 스케줄을 삭제합니다.
     *
     * @param  Schedule  $schedule  삭제할 스케줄 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(Schedule $schedule): JsonResponse
    {
        try {
            $scheduleId = $schedule->id;
            $result = $this->scheduleService->delete($schedule);

            if ($result) {
                return $this->success('schedule.delete_success');
            } else {
                return $this->error('schedule.delete_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('schedule.delete_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('schedule.delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 스케줄을 즉시 실행합니다.
     *
     * @param  Schedule  $schedule  실행할 스케줄 모델
     * @return JsonResponse 실행 결과를 포함한 JSON 응답
     */
    public function run(Schedule $schedule): JsonResponse
    {
        try {
            $history = $this->scheduleService->runSchedule($schedule, Auth::id());

            return $this->successWithResource(
                'schedule.run_success',
                new ScheduleHistoryResource($history)
            );
        } catch (ValidationException $e) {
            return $this->error('schedule.run_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('schedule.run_failed', 500, $e->getMessage());
        }
    }

    /**
     * 스케줄을 복제합니다.
     *
     * @param  Schedule  $schedule  복제할 스케줄 모델
     * @return JsonResponse 복제된 스케줄 정보를 포함한 JSON 응답
     */
    public function duplicate(Schedule $schedule): JsonResponse
    {
        try {
            $duplicated = $this->scheduleService->duplicate($schedule);

            return $this->successWithResource(
                'schedule.duplicate_success',
                new ScheduleResource($duplicated),
                201
            );
        } catch (Exception $e) {
            return $this->error('schedule.duplicate_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 스케줄의 상태를 일괄 변경합니다.
     *
     * @param  BulkUpdateScheduleStatusRequest  $request  일괄 상태 변경 요청 데이터
     * @return JsonResponse 변경 결과를 포함한 JSON 응답
     */
    public function bulkUpdateStatus(BulkUpdateScheduleStatusRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->scheduleService->bulkUpdateStatus($validated['ids'], $validated['is_active']);

            return $this->success(
                'schedule.bulk_status_updated',
                $result
            );
        } catch (ValidationException $e) {
            return $this->error('schedule.bulk_update_status_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('schedule.bulk_update_status_failed', 500, $e->getMessage());
        }
    }

    /**
     * 여러 스케줄을 일괄 삭제합니다.
     *
     * @param  BulkDeleteScheduleRequest  $request  일괄 삭제 요청 데이터
     * @return JsonResponse 삭제 결과를 포함한 JSON 응답
     */
    public function bulkDelete(BulkDeleteScheduleRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->scheduleService->bulkDelete($validated['ids']);

            return $this->success(
                'schedule.bulk_delete_success',
                $result
            );
        } catch (ValidationException $e) {
            return $this->error('schedule.bulk_delete_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('schedule.bulk_delete_failed', 500, $e->getMessage());
        }
    }

    /**
     * 스케줄 관련 통계 정보를 조회합니다.
     *
     * @return JsonResponse 스케줄 통계 데이터를 포함한 JSON 응답
     */
    public function statistics(): JsonResponse
    {
        try {
            $statistics = $this->scheduleService->getStatistics();

            return $this->success('schedule.statistics_success', $statistics);
        } catch (Exception $e) {
            return $this->error('schedule.statistics_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 스케줄의 실행 이력을 조회합니다.
     *
     * @param  Schedule  $schedule  조회할 스케줄 모델
     * @param  ScheduleHistoryListRequest  $request  이력 목록 요청 데이터
     * @return JsonResponse 실행 이력 목록을 포함한 JSON 응답
     */
    public function history(Schedule $schedule, ScheduleHistoryListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $histories = $this->scheduleService->getHistory($schedule->id, $filters);

            return $this->success(
                'schedule.history_fetch_success',
                new ScheduleHistoryCollection($histories)
            );
        } catch (Exception $e) {
            return $this->error('schedule.history_fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 실행 이력을 삭제합니다.
     *
     * @param  int  $historyId  삭제할 이력 ID
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function deleteHistory(int $historyId): JsonResponse
    {
        try {
            $result = $this->scheduleService->deleteHistory($historyId);

            if ($result) {
                return $this->success('schedule.history_delete_success');
            } else {
                return $this->error('schedule.history_not_found', 404);
            }
        } catch (Exception $e) {
            return $this->error('schedule.history_delete_failed', 500, $e->getMessage());
        }
    }
}
