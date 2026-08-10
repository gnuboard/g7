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
 * 응답 형식: { "url": "https://..." } / 실패 시 { "error": { "message": "..." } }
 * ResponseHelper 사용 불가 — 응답을 파싱하는 SimpleUploadAdapter 가 CDN 으로 로드되는
 * CKEditor5 43.3.1 상위 코드라 봉투 구조를 해석하지 못합니다 (파싱 규약 변경 불가).
 * 이 사유로 각 응답 지점에 audit:allow response-helper-bypass 를 명시했습니다.
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
            // audit:allow response-helper-bypass reason: CDN 상위 SimpleUploadAdapter 가 최상위 error.message 를 읽는다 (봉투 불가)
            return response()->json([
                'error' => ['message' => __('sirsoft-ckeditor5::messages.upload.forbidden')],
            ], 403);
        }

        try {
            $image = $this->imageUploadService->upload(
                $request->file('upload'),
                $user?->id
            );

            // audit:allow response-helper-bypass reason: CDN 상위 SimpleUploadAdapter 가 최상위 url 을 읽는다 (봉투 불가)
            return response()->json(['url' => $image->download_url], 201);
        } catch (\Exception $e) {
            Log::error('[sirsoft-ckeditor5] 이미지 업로드 실패', [
                'error' => $e->getMessage(),
            ]);

            // audit:allow response-helper-bypass reason: CDN 상위 SimpleUploadAdapter 가 최상위 error.message 를 읽는다 (봉투 불가)
            return response()->json([
                'error' => ['message' => __('sirsoft-ckeditor5::messages.upload.failed')],
            ], 500);
        }
    }
}
