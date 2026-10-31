<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Exceptions\ScheduleExecutionException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Schedule\BulkDeleteScheduleRequest;
use App\Http\Requests\Schedule\BulkUpdateScheduleStatusRequest;
use App\Http\Requests\Schedule\CreateScheduleRequest;
use App\Http\Requests\Schedule\DeleteScheduleRequest;
use App\Http\Requests\Schedule\ScheduleHistoryListRequest;
use App\Http\Requests\Schedule\ScheduleListRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ScheduleCollection;
use App\Http\Resources\ScheduleHistoryCollection;
use App\Http\Resources\ScheduleHistoryResource;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\ScheduleSecurityResource;
use App\Http\Resources\ScheduleStatusResource;
use App\Models\Schedule;
use App\Services\JobQueueService;
use App\Services\Schedule\ScheduleHeartbeat;
use App\Services\Schedule\ScheduleLookupService;
use App\Services\Schedule\ScheduleSecurityPolicy;
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
    /**
     * 스케줄 서비스를 주입받습니다.
     *
     * @param  ScheduleService  $scheduleService  스케줄 서비스
     * @return void 반환값 없음
     */
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
     * 코어·확장 소유 행은 DeleteScheduleRequest 가 422 로 거절한다.
     *
     * @param  DeleteScheduleRequest  $request  삭제 요청(소유 행 거부)
     * @param  Schedule  $schedule  삭제할 스케줄 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(DeleteScheduleRequest $request, Schedule $schedule): JsonResponse
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
            $history = $this->scheduleService->runNow($schedule, Auth::id());
        } catch (ScheduleExecutionException $e) {
            // 소유 확장 비활성·앞선 실행이 잠금 중 — 실행 자체를 시작하지 않았다
            return $this->error('schedule.run_failed', 422, ['general' => [$e->getMessage()]]);
        } catch (Exception $e) {
            return $this->error('schedule.run_failed', 500, $e->getMessage());
        }

        if ($history === null) {
            // 큐로 보낸 행은 워커가 처리해야 결과가 생긴다(202). job 유형은 실행기가 Job 을 직접
            // 큐에 넣고 이력을 돌려주므로 여기 오지 않는다. 실패만 기록하는 행의 성공은 이력이 없다(200).
            $queued = $schedule->dispatch_mode === ScheduleDispatchMode::Queue && $schedule->type !== ScheduleType::Job;

            return $queued
                ? $this->success('schedule.run_queued', ['queued' => true, 'status' => null], 202)
                : $this->success('schedule.run_success', ['queued' => false, 'status' => ScheduleResultStatus::Success->value, 'recorded' => false]);
        }

        // 바로 실행은 실패도 예외 없이 실패 이력으로 온다 — 창이 결과(실패·오류 출력)를 그대로 보여 준다
        return $this->successWithResource(
            $history->status === ScheduleResultStatus::Failed ? 'schedule.run_failed' : 'schedule.run_success',
            new ScheduleHistoryResource($history)
        );
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
     * 스케줄 소유 필터 옵션(코어·확장·직접 등록별 스케줄 수)을 조회합니다.
     *
     * 확장 이름은 현재 로케일로 해석해 내려준다 — 필터의 확장 선택 상자가 그대로 표시한다.
     *
     * @param  ScheduleLookupService  $lookupService  스케줄 조회 서비스
     * @return JsonResponse 소유자 요약 목록을 포함한 JSON 응답
     */
    public function owners(ScheduleLookupService $lookupService): JsonResponse
    {
        try {
            return $this->success(
                'schedule.owners_fetch_success',
                $lookupService->ownersSummary(app()->getLocale())
            );
        } catch (Exception $e) {
            return $this->error('schedule.owners_fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 스케줄러 하트비트와 큐 요약(상태 카드용)을 조회합니다.
     *
     * 의존 서비스는 이 경로에서만 쓰므로 생성자가 아니라 메서드로 주입한다.
     *
     * @param  ScheduleHeartbeat  $heartbeat  스케줄러 하트비트
     * @param  JobQueueService  $jobQueueService  큐 서비스
     * @param  ScheduleSecurityPolicy  $securityPolicy  스케줄 보안 유효 정책(Shell 사용 여부)
     * @return JsonResponse 하트비트·큐 요약·Shell 사용 여부를 포함한 JSON 응답
     */
    public function status(ScheduleHeartbeat $heartbeat, JobQueueService $jobQueueService, ScheduleSecurityPolicy $securityPolicy): JsonResponse
    {
        try {
            return $this->successWithResource(
                'schedule.status_fetch_success',
                new ScheduleStatusResource([
                    'last_tick' => $heartbeat->lastTick(),
                    'queue' => $jobQueueService->summary(),
                    'shell_enabled' => $securityPolicy->shellEnabled(),
                ])
            );
        } catch (Exception $e) {
            return $this->error('schedule.status_fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 스케줄 실행 보안 유효 정책(읽기 전용)을 조회합니다.
     *
     * 코어 정책(config)에 서버 관리자 덮어쓰기 파일을 더한 결과를 항목별 출처와 함께 돌려준다.
     * 화면에서 바꾸는 경로는 없다 — 서버 관리자가 덮어쓰기 파일과 .env 로 바꾼다.
     *
     * @param  ScheduleSecurityPolicy  $securityPolicy  스케줄 보안 유효 정책
     * @return JsonResponse 유효 정책을 포함한 JSON 응답
     */
    public function security(ScheduleSecurityPolicy $securityPolicy): JsonResponse
    {
        try {
            return $this->successWithResource(
                'schedule.security_fetch_success',
                new ScheduleSecurityResource($securityPolicy->describe())
            );
        } catch (Exception $e) {
            return $this->error('schedule.security_fetch_failed', 500, $e->getMessage());
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
