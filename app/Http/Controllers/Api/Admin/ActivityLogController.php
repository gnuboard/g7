<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\ActivityLog\ActivityLogBulkDeleteRequest;
use App\Http\Requests\ActivityLog\ActivityLogDeleteRequest;
use App\Http\Requests\ActivityLog\ActivityLogIndexRequest;
use App\Http\Resources\ActivityLogCollection;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 관리자용 활동 로그 컨트롤러
 *
 * 관리자가 시스템 활동 로그를 조회하고 삭제할 수 있는 기능을 제공합니다.
 */
class ActivityLogController extends AdminBaseController
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {
        parent::__construct();
    }

    /**
     * 페이지네이션된 활동 로그 목록을 조회합니다.
     *
     * @param ActivityLogIndexRequest $request 검증된 요청 객체
     * @return JsonResponse 활동 로그 목록을 포함한 JSON 응답
     */
    public function index(ActivityLogIndexRequest $request): JsonResponse
    {
        try {
            $filters = array_filter($request->validated(), fn ($value) => $value !== null);

            $logs = $this->activityLogService->getList($filters);

            $collection = new ActivityLogCollection($logs);

            return $this->success('activity_log.fetch_success', $collection->toArray($request));
        } catch (Exception $e) {
            return $this->error('activity_log.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 활동 로그를 삭제합니다.
     *
     * @param ActivityLogDeleteRequest $request 검증된 삭제 요청
     * @param ActivityLog $activityLog 삭제할 활동 로그 모델
     * @return JsonResponse 삭제 결과
     */
    public function destroy(ActivityLogDeleteRequest $request, ActivityLog $activityLog): JsonResponse
    {
        try {
            $this->activityLogService->delete($activityLog->id);

            return $this->success('activity_log.delete_success');
        } catch (Exception $e) {
            Log::error('활동 로그 삭제 실패', ['id' => $activityLog->id, 'error' => $e->getMessage()]);

            return $this->error('activity_log.delete_failed', 500);
        }
    }

    /**
     * 활동 로그를 일괄 삭제합니다.
     *
     * @param ActivityLogBulkDeleteRequest $request 검증된 일괄 삭제 요청
     * @return JsonResponse 삭제 결과
     */
    public function bulkDestroy(ActivityLogBulkDeleteRequest $request): JsonResponse
    {
        try {
            $count = $this->activityLogService->deleteMany($request->validated('ids'));

            return $this->success('activity_log.bulk_delete_success', ['deleted_count' => $count]);
        } catch (Exception $e) {
            Log::error('활동 로그 일괄 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error('activity_log.delete_failed', 500);
        }
    }
}
