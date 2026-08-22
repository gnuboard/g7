<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Ckeditor5\Http\Requests\BulkDeleteUploadsRequest;
use Plugins\Sirsoft\Ckeditor5\Http\Requests\IndexUploadsRequest;
use Plugins\Sirsoft\Ckeditor5\Http\Resources\ImageUploadResource;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadAdminService;

/**
 * CKEditor5 업로드 이미지 관리 컨트롤러
 *
 * GET    /api/plugins/sirsoft-ckeditor5/admin/uploads
 * DELETE /api/plugins/sirsoft-ckeditor5/admin/uploads/{id}
 * POST   /api/plugins/sirsoft-ckeditor5/admin/uploads/bulk-delete
 *
 * 권한은 라우트 미들웨어(`sirsoft-ckeditor5.uploads.read` / `.delete`)가 검증합니다.
 */
class ImageUploadAdminController extends AdminBaseController
{
    /**
     * @param  ImageUploadAdminService  $adminService  업로드 관리 서비스
     */
    public function __construct(
        private readonly ImageUploadAdminService $adminService,
    ) {
        parent::__construct();
    }

    /**
     * 업로드 이미지 목록을 반환합니다.
     *
     * @param  IndexUploadsRequest  $request  HTTP 요청
     * @return JsonResponse
     */
    public function index(IndexUploadsRequest $request): JsonResponse
    {
        $result = $this->adminService->paginate(
            $request->filters(),
            $request->perPage(),
            $request->pageNumber(),
        );

        return ResponseHelper::success('common.success', [
            'data' => ImageUploadResource::collection($result['items']),
            'pagination' => $result['pagination'],
            'meta' => [
                'scan_limited' => $result['scan_limited'],
                'scan_window' => ImageUploadAdminService::SCAN_WINDOW,
                'reference_sources_incomplete' => $result['reference_sources_incomplete'] ?? false,
            ],
        ]);
    }

    /**
     * 업로드 이미지 1건을 삭제합니다.
     *
     * @param  int  $id  업로드 ID
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $upload = $this->adminService->find($id);

        if ($upload === null) {
            return ResponseHelper::notFound('messages.uploads.not_found', domain: 'sirsoft-ckeditor5');
        }

        if (! $this->adminService->delete($upload)) {
            return ResponseHelper::error('messages.uploads.file_delete_failed', 500, domain: 'sirsoft-ckeditor5');
        }

        return ResponseHelper::success('messages.uploads.deleted', domain: 'sirsoft-ckeditor5');
    }

    /**
     * 업로드 이미지를 일괄 삭제합니다.
     *
     * @param  BulkDeleteUploadsRequest  $request  HTTP 요청
     * @return JsonResponse
     */
    public function bulkDestroy(BulkDeleteUploadsRequest $request): JsonResponse
    {
        $result = $this->adminService->bulkDelete($request->ids());

        if ($result['deleted'] === 0 && $result['failed'] > 0) {
            return ResponseHelper::error('messages.uploads.file_delete_failed', 500, $result, domain: 'sirsoft-ckeditor5');
        }

        // 부분 실패를 성공 문구("N건 삭제")로 접으면 운영자가 남은 파일을 모른다 —
        // 실패 건수를 문구에 함께 싣는다 (실패 상세는 data 페이로드).
        if ($result['failed'] > 0) {
            return ResponseHelper::success(
                'messages.uploads.bulk_partially_deleted',
                $result,
                messageParams: ['deleted' => $result['deleted'], 'failed' => $result['failed']],
                domain: 'sirsoft-ckeditor5',
            );
        }

        return ResponseHelper::success(
            'messages.uploads.bulk_deleted',
            $result,
            messageParams: ['deleted' => $result['deleted']],
            domain: 'sirsoft-ckeditor5',
        );
    }
}
