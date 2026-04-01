<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\MailSendLog\MailSendLogBulkDeleteRequest;
use App\Http\Requests\MailSendLog\MailSendLogDeleteRequest;
use App\Http\Requests\MailSendLog\MailSendLogIndexRequest;
use App\Http\Resources\MailSendLogCollection;
use App\Models\MailSendLog;
use App\Services\MailSendLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * 메일 발송 이력 관리 컨트롤러
 */
class MailSendLogController extends AdminBaseController
{
    /**
     * MailSendLogController 생성자.
     *
     * @param MailSendLogService $mailSendLogService 메일 발송 이력 서비스
     */
    public function __construct(
        private MailSendLogService $mailSendLogService
    ) {
        parent::__construct();
    }

    /**
     * 메일 발송 이력 목록을 조회합니다.
     *
     * @param MailSendLogIndexRequest $request 검증된 조회 요청
     * @return JsonResponse 발송 이력 목록
     */
    public function index(MailSendLogIndexRequest $request): JsonResponse
    {
        try {
            $filters = array_filter([
                'extension_type' => $request->validated('extension_type'),
                'extension_identifier' => $request->validated('extension_identifier'),
                'template_type' => $request->validated('template_type'),
                'status' => $request->validated('status'),
                'search' => $request->validated('search'),
                'search_type' => $request->validated('search_type'),
                'date_from' => $request->validated('date_from'),
                'date_to' => $request->validated('date_to'),
                'sort_by' => $request->validated('sort_by'),
                'sort_order' => $request->validated('sort_order'),
            ], fn ($value) => $value !== null);

            $perPage = (int) ($request->validated('per_page') ?? 20);
            $logs = $this->mailSendLogService->getLogs($filters, $perPage);

            $collection = new MailSendLogCollection($logs);

            $responseData = $collection->toArray($request);

            // 컬렉션 레벨 abilities (페이지 레벨 버튼 제어용)
            $responseData['abilities'] = [
                'can_delete' => PermissionHelper::check('core.mail-send-logs.delete', $request->user()),
            ];

            return $this->success('mail_send_log.fetch_success', $responseData);
        } catch (\Exception $e) {
            Log::error('메일 발송 이력 조회 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_send_log.fetch_failed', 500);
        }
    }

    /**
     * 메일 발송 이력을 삭제합니다.
     *
     * @param MailSendLogDeleteRequest $request 검증된 삭제 요청
     * @param MailSendLog $mailSendLog 삭제할 발송 이력 모델
     * @return JsonResponse 삭제 결과
     */
    public function destroy(MailSendLogDeleteRequest $request, MailSendLog $mailSendLog): JsonResponse
    {
        try {
            $this->mailSendLogService->delete($mailSendLog->id);

            return $this->success('mail_send_log.delete_success');
        } catch (\Exception $e) {
            Log::error('메일 발송 이력 삭제 실패', ['id' => $mailSendLog->id, 'error' => $e->getMessage()]);

            return $this->error('mail_send_log.delete_failed', 500);
        }
    }

    /**
     * 메일 발송 이력을 일괄 삭제합니다.
     *
     * @param MailSendLogBulkDeleteRequest $request 검증된 일괄 삭제 요청
     * @return JsonResponse 삭제 결과
     */
    public function bulkDestroy(MailSendLogBulkDeleteRequest $request): JsonResponse
    {
        try {
            $count = $this->mailSendLogService->deleteMany($request->validated('ids'));

            return $this->success('mail_send_log.bulk_delete_success', ['deleted_count' => $count]);
        } catch (\Exception $e) {
            Log::error('메일 발송 이력 일괄 삭제 실패', ['error' => $e->getMessage()]);

            return $this->error('mail_send_log.delete_failed', 500);
        }
    }
}
