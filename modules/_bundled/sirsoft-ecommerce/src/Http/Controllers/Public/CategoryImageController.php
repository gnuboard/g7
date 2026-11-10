<?php

// audit:allow api-doc-coverage 609 후속 — download 응답 판정을 코어 파일 서빙 단일 지점으로 옮겨 조건부 요청 304 와 ETag, Last-Modified, Cache-Control 헤더가 더해졌다(라우트, 요청은 그대로). 그 캐시 계약과 304 는 docs/api/category-image.md 가 이미 다룬다.

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 공개 카테고리 이미지 컨트롤러
 *
 * 카테고리 이미지 서빙 기능을 제공합니다.
 */
class CategoryImageController extends PublicBaseController
{
    /**
     * 분류 이미지 서비스를 주입받습니다.
     *
     * @param  CategoryImageService  $categoryImageService  분류 이미지 서비스
     * @param  ThumbnailResponder  $thumbnails  썸네일 헬퍼 (`?size=`·`?w=` 응답)
     */
    public function __construct(
        private CategoryImageService $categoryImageService,
        private ThumbnailResponder $thumbnails
    ) {}

    /**
     * 카테고리 이미지 다운로드
     *
     * `?size={규격}`·`?w={숫자}` 가 붙으면 래스터 이미지는 썸네일로 낸다. 카테고리 이미지는 완전 공개 원본이라
     * 파생본은 공개 썸네일 디스크에 두고, 그 디스크가 공개 자산 디스크면 302 로 넘긴다. 낼 수 없으면 원본에
     * `X-G7-Thumbnail: bypass:{사유}` 를 붙인다.
     *
     * @param  Request  $request  요청 (`size`·`w`)
     * @param  string  $hash  이미지 해시
     * @return SymfonyResponse|JsonResponse 이미지·썸네일 응답(200·304·302) 또는 에러 응답
     */
    // audit:allow controller-base-request-injection reason: 썸네일 파라미터(size·w)를 원문 쿼리로 판정한다(중복 키·배열 키 거부). 검증할 body 없음(hash 는 라우트 파라미터)
    public function download(Request $request, string $hash): SymfonyResponse|JsonResponse
    {
        try {
            $info = $this->categoryImageService->getFileInfo($hash);

            if (! $info) {
                return ResponseHelper::notFound(
                    'messages.category_images.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            // 행 확인(게이트)을 지난 뒤, 원본 응답 바로 앞
            if ($thumbnail = $this->thumbnails->respond($request, $info['thumbnail_source'] ?? null)) {
                return $thumbnail;
            }

            // 캐시 계약(검증자 비교·환경별 Cache-Control·304 의 200 헤더 동반)과 메타 키 검증은
            // 코어 단일 지점(`streamedFileResponseFromMeta()`)이 소유한다 — 종전의 컨트롤러별 사본을
            // 접었다 (14차 → 16차 G3 라운드 E-7). `immutable` 은 `false` 고정(이 URL 에는 `?v=` 가 없다),
            // 압축은 선언으로 제외한다(사용자 업로드물 — 형제 `PublicStorageController` 와 같은 선언).
            // 만료 기간은 서비스 메타(`max_age`)가 정한다.
            $response = $this->streamedFileResponseFromMeta(
                $info,
                null,
                immutable: false,
                skipCompression: true,
                logContext: ['hash' => $hash]
            );

            return $response instanceof JsonResponse ? $response : ThumbnailResponder::markBypass($response, $request);
        } catch (\Exception $e) {
            Log::error('category_image.download failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'hash' => $hash,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }
}
