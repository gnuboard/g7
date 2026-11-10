<?php

namespace Modules\Sirsoft\Page\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Page\Services\PageContentMediaService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 페이지 본문 미디어 공개 서빙 컨트롤러 (#883 4단계)
 *
 * 게이트는 「발행 또는 열람권 또는 서명」(기존 미리보기와 같음). 권한 미들웨어는 없다 — `<img>`·`<video>` 요청에는 Bearer 가 없다.
 */
class PublicPageContentMediaController extends PublicBaseController
{
    /**
     * @param  PageContentMediaService  $contentMediaService  본문 미디어 서비스
     */
    public function __construct(private PageContentMediaService $contentMediaService)
    {
        parent::__construct();
    }

    /**
     * 본문 미디어 서빙 (Range 지원).
     *
     * @param  Request  $request  요청 (한시 서명·Range)
     * @param  string  $hash  해시
     * @return Response|JsonResponse 파일 응답 또는 오류
     */
    // audit:allow controller-base-request-injection reason: GET 미디어 서빙. 요청 URL 서명 검증과 Range 헤더만 읽는다(검증할 본문 없음)
    public function show(Request $request, string $hash): Response|JsonResponse
    {
        try {
            $attachment = $this->contentMediaService->findServable($hash, $request->hasValidSignature(absolute: false));

            return $this->contentMediaService->respond($attachment, $request)
                ?? $this->notFound('sirsoft-page::messages.attachment.not_found');
        } catch (AccessDeniedHttpException $e) {
            return $this->error('auth.scope_denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->notFound('sirsoft-page::messages.attachment.not_found');
        }
    }
}
