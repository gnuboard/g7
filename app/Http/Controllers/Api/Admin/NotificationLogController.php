<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\NotificationLog\NotificationLogBulkDeleteRequest;
use App\Http\Requests\NotificationLog\NotificationLogDeleteRequest;
use App\Http\Requests\NotificationLog\NotificationLogIndexRequest;
use App\Http\Resources\NotificationLogCollection;
use App\Models\NotificationLog;
use App\Services\NotificationLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 알림 발송 이력 관리 컨트롤러
 */
class NotificationLogController extends AdminBaseController
{
    public function __construct(
        private readonly NotificationLogService $logService,
    ) {
        parent::__construct();
    }

    /**
     * 알림 발송 이력 목록을 조회합니다.
     */
    public function index(NotificationLogIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $perPage = $filters['per_page'] ?? 20;

            $logs = $this->logService->getLogs($filters, $perPage, $request->user());
            $collection = new NotificationLogCollection($logs);

            return $this->success(
                __('notification_log.list_success'),
                $collection->toArray($request)
            );
        } catch (\Exception $e) {
            Log::error('알림 발송 이력 목록 조회 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification_log.list_failed'), 500);
        }
    }

    /**
     * 알림 발송 이력을 삭제합니다.
     */
    public function destroy(NotificationLogDeleteRequest $request, NotificationLog $notificationLog): JsonResponse
    {
        try {
            $this->logService->deleteLog($notificationLog);

            return $this->success(__('notification_log.delete_success'));
        } catch (\Exception $e) {
            Log::error('알림 발송 이력 삭제 실패', [
                'id' => $notificationLog->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('notification_log.delete_failed'), 500);
        }
    }

    /**
     * 알림 발송 이력을 다건 삭제합니다.
     */
    public function bulkDestroy(NotificationLogBulkDeleteRequest $request): JsonResponse
    {
        try {
            $ids = $request->validated()['ids'];
            $count = $this->logService->bulkDelete($ids);

            return $this->success(
                __('notification_log.bulk_delete_success'),
                ['deleted_count' => $count]
            );
        } catch (\Exception $e) {
            Log::error('알림 발송 이력 다건 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error(__('notification_log.bulk_delete_failed'), 500);
        }
    }
}
