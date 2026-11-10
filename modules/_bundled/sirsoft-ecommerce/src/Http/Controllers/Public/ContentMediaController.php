<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Thumbnails\ThumbnailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\ContentMediaService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 이커머스 본문 미디어 공개 서빙 컨트롤러 (#883 4단계)
 *
 * 게이트는 상품이면 「전시 중 또는 관리자 또는 서명」, 공통 정보는 공개. 권한 미들웨어는 없다(`<img>`·`<video>` 는 Bearer 없음).
 */
class ContentMediaController extends PublicBaseController
{
    /**
     * @param  ContentMediaService  $contentMediaService  본문 미디어 서비스
     */
    public function __construct(private ContentMediaService $contentMediaService)
    {
        parent::__construct();
    }

    /**
     * 본문 미디어 서빙 (Range 지원).
     *
     * 이미지 주소에 `?size={규격}`·`?w={숫자}` 가 붙으면 게이트(`findServable`)를 지난 뒤 코어 골격이 썸네일로 낸다. 서명은
     * `size`·`w` 를 빼고 검증한다 — 화면이 서명 주소 뒤에 규격을 덧붙여도 서명이 깨지지 않게(썸네일은 같은 게이트를 지난 뒤 크기만 바꾼다).
     *
     * @param  Request  $request  요청 (한시 서명·Range·썸네일 파라미터)
     * @param  string  $hash  해시
     * @return Response|JsonResponse 파일 응답 또는 오류
     */
    // audit:allow controller-base-request-injection reason: GET 미디어 서빙. 요청 URL 서명 검증과 Range 헤더, 썸네일 쿼리(size·w)만 읽는다(검증할 본문 없음)
    public function show(Request $request, string $hash): Response|JsonResponse
    {
        try {
            $media = $this->contentMediaService->findServable($hash, $request->hasValidRelativeSignatureWhileIgnoring(ThumbnailQuery::ignoreClosure()));

            return $this->contentMediaService->respond($media, $request)
                ?? $this->notFound('sirsoft-ecommerce::messages.content_media.not_found');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->notFound('sirsoft-ecommerce::messages.content_media.not_found');
        }
    }
}
