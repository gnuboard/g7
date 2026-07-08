<?php

namespace Modules\Sirsoft\Page\Http\Controllers\User;

use App\Enums\PermissionType;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Page\Http\Resources\PublicPageResource;
use Modules\Sirsoft\Page\Services\PageService;

/**
 * 공개용 페이지 조회 컨트롤러
 *
 * 발행된 페이지를 슬러그로 조회합니다.
 * 첨부파일 다운로드/미리보기는 WP3에서 PageAttachmentService를 통해 구현됩니다.
 */
class PublicPageController extends PublicBaseController
{
    /**
     * PublicPageController 생성자
     *
     * @param  PageService  $pageService  페이지 서비스
     */
    public function __construct(
        private PageService $pageService,
    ) {
        parent::__construct();
    }

    /**
     * 슬러그로 발행된 페이지를 조회합니다.
     *
     * 페이지 조회 권한(sirsoft-page.pages.read)을 가진 관리자는 미발행 페이지도
     * 사용자 화면에서 미리볼 수 있습니다(비로그인·일반 회원은 미발행 시 404 유지).
     *
     * @param  Request  $request  HTTP 요청
     * @param  string  $slug  페이지 슬러그
     * @return JsonResponse 페이지 상세 응답
     */
    // audit:allow controller-base-request-injection reason: GET 상세 조회. 인증 사용자만 read-only 참조($request->user())해 미리보기 권한 판정. 검증할 body 없음(slug 는 라우트 파라미터)
    public function show(Request $request, string $slug): JsonResponse
    {
        try {
            $user = $request->user();
            $canPreview = $user
                && $user->hasPermission('sirsoft-page.pages.read', PermissionType::Admin);

            $page = $this->pageService->getPublishedPageBySlug($slug, $canPreview);

            if (! $page) {
                return $this->notFound('sirsoft-page::messages.page.not_found');
            }

            $page->load('attachments');

            $isPreview = $canPreview && ! $page->published;

            return $this->successWithResource(
                'sirsoft-page::messages.page.fetch_success',
                (new PublicPageResource($page))->withPreview($isPreview)
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.fetch_failed', 500, $e->getMessage());
        }
    }
}
