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
 * 플러그인 업로드 어댑터(resources/js/handlers/uploadAdapter.ts)가 요구하는 형식으로 이미지를 저장하고
 * 공개 URL을 반환합니다.
 *
 * 응답 형식: { "url": "https://..." } / 실패 시 { "error": { "message": "..." } }
 * ResponseHelper 를 쓰지 않는다 — 어댑터는 CKEditor5 의 SimpleUploadAdapter 응답 규약(최상위 url,
 * error.message)을 그대로 읽도록 만들어졌고, 이미 저장된 글·외부 연동이 같은 규약에 기대므로
 * 봉투 구조로 바꾸지 않습니다. 이 사유로 각 응답 지점에 audit:allow response-helper-bypass 를 명시했습니다.
 */
class ImageUploadController extends AdminBaseController
{
    /**
     * 이미지 업로드 서비스를 주입합니다.
     *
     * @param  ImageUploadService  $imageUploadService  이미지 저장·URL 생성 서비스
     */
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
        // 인가는 라우트 게이트(AdminBaseController: auth:sanctum + admin)가 전담한다.
        // 클라이언트가 검사 권한명을 지정하던 쿼리 분기는 죽은 인가라 제거했다.
        $user = $this->getCurrentUser();

        try {
            $image = $this->imageUploadService->upload(
                $request->file('upload'),
                $user?->id
            );

            // audit:allow response-helper-bypass reason: 플러그인 업로드 어댑터가 SimpleUploadAdapter 규약대로 최상위 url 을 읽는다
            return response()->json(['url' => $image->download_url], 201);
        } catch (\Exception $e) {
            Log::error('[sirsoft-ckeditor5] 이미지 업로드 실패', [
                'error' => $e->getMessage(),
            ]);

            // audit:allow response-helper-bypass reason: 플러그인 업로드 어댑터가 SimpleUploadAdapter 규약대로 최상위 error.message 를 읽는다
            return response()->json([
                'error' => ['message' => __('sirsoft-ckeditor5::messages.upload.failed')],
            ], 500);
        }
    }
}
