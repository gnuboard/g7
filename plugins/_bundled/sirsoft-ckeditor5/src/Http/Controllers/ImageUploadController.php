<?php

namespace Plugins\Sirsoft\Ckeditor5\Http\Controllers;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Ckeditor5\Http\Requests\ImageUploadRequest;
use Plugins\Sirsoft\Ckeditor5\Services\ImageUploadService;

/**
 * CKEditor5 이미지 업로드 컨트롤러
 *
 * CKEditor5 SimpleUploadAdapter가 요구하는 형식으로 이미지를 저장하고
 * 공개 URL을 반환합니다.
 *
 * 응답 형식: { "url": "https://..." }
 * ResponseHelper 사용 불가 — CKEditor SimpleUploadAdapter는 최상위 { url } 키를 요구합니다.
 */
class ImageUploadController extends AdminBaseController
{
    public function __construct(
        private ImageUploadService $imageUploadService
    ) {
        parent::__construct();
    }

    /**
     * 이미지를 업로드하고 공개 URL을 반환합니다.
     *
     * CKEditor5 SimpleUploadAdapter 규격:
     * - 성공: HTTP 201, { "url": "..." }
     * - 실패: HTTP 4xx/5xx, { "error": { "message": "..." } }
     *
     * @param  ImageUploadRequest  $request  업로드 요청
     * @return JsonResponse
     */
    public function upload(ImageUploadRequest $request): JsonResponse
    {
        $uploadPermission = $request->query('permission', '');

        $user = $this->getCurrentUser();
        if ($uploadPermission && $user && ! $user->hasPermission($uploadPermission)) {
            return response()->json([
                'error' => ['message' => __('sirsoft-ckeditor5::messages.upload.forbidden')],
            ], 403);
        }

        try {
            $image = $this->imageUploadService->upload(
                $request->file('upload'),
                $user?->id
            );

            // CKEditor SimpleUploadAdapter가 요구하는 최상위 { url } 형식
            return response()->json(['url' => $image->download_url], 201);
        } catch (\Exception $e) {
            Log::error('[sirsoft-ckeditor5] 이미지 업로드 실패', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => ['message' => __('sirsoft-ckeditor5::messages.upload.failed')],
            ], 500);
        }
    }
}
