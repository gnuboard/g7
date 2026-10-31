<?php

// audit:allow api-doc-coverage 609 후속 — 메서드 PHPDoc(목적 설명, @param, @return)만 더했다. 라우트, 요청, 응답 모양은 그대로라 API 문서에 반영할 표면이 없다.

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 카테고리 이미지 컨트롤러
 *
 * 카테고리 이미지 서빙 기능을 제공합니다.
 */
class CategoryImageController extends PublicBaseController
{
    /**
     * 카테고리 이미지 서빙에 쓸 서비스를 주입받는다.
     *
     * @param  CategoryImageService  $categoryImageService  카테고리 이미지 서비스
     */
    public function __construct(
        private CategoryImageService $categoryImageService
    ) {}

    /**
     * 카테고리 이미지 다운로드
     *
     * @param  string  $hash  이미지 해시
     * @return StreamedResponse|Response|JsonResponse 이미지 스트림, 304 또는 에러 응답
     */
    public function download(string $hash): StreamedResponse|Response|JsonResponse
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

            // 캐시 계약(검증자 비교·환경별 Cache-Control·304 의 200 헤더 동반)과 메타 키 검증은
            // 코어 단일 지점(`streamedFileResponseFromMeta()`)이 소유한다 — 종전의 컨트롤러별 사본을
            // 접었다 (14차 → 16차 G3 라운드 E-7). `immutable` 은 `false` 고정(이 URL 에는 `?v=` 가 없다),
            // 압축은 선언으로 제외한다(사용자 업로드물 — 형제 `PublicStorageController` 와 같은 선언).
            // 만료 기간은 서비스 메타(`max_age`)가 정한다.
            return $this->streamedFileResponseFromMeta(
                $info,
                null,
                immutable: false,
                skipCompression: true,
                logContext: ['hash' => $hash]
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }
}
