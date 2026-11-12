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
 * 응답 형식: { "url": "https://...", "urls"?: { "default": "...", "640": "...", ... } } / 실패 시 { "error": { "message": "..." } }
 * `urls` 는 직접 URL(공개 자산 디스크)로 저장된 이미지에만 싣는다 — 편집기가 본문에 srcset 으로 저장한다.
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
     * - 성공: HTTP 201, { "url": "..." } — 직접 URL 행이면 반응형 후보 `urls`({default, 가로: ?size= API 주소}) 추가
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
            $file = $request->file('upload');
            $image = $this->imageUploadService->upload($file, $user?->id);

            // 원본보다 큰 후보는 만들지 않는다 — 저장 직전 축소(ImageResizer)가 같은 파일을 제자리에서 바꾸므로 축소 뒤 가로다
            $urls = $this->imageUploadService->responsiveUrls($image, $this->imageWidth($file->getRealPath()));

            // audit:allow response-helper-bypass reason: 플러그인 업로드 어댑터가 SimpleUploadAdapter 규약대로 최상위 url 을 읽는다
            return response()->json(['url' => $image->download_url] + ($urls !== [] ? ['urls' => $urls] : []), 201);
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

    /**
     * 이미지 파일의 가로(px)를 읽습니다.
     *
     * @param  string|false  $path  파일 경로
     * @return int|null 가로 (읽지 못하면 null — 그때는 후보를 가로로 자르지 않는다)
     */
    private function imageWidth(string|false $path): ?int
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        try {
            $size = getimagesize($path);
        } catch (\Throwable) {
            return null;
        }

        return is_array($size) && is_int($size[0] ?? null) && $size[0] > 0 ? $size[0] : null;
    }
}
