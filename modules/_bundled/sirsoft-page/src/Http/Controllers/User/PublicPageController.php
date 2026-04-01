<?php

namespace Modules\Sirsoft\Page\Http\Controllers\User;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
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
     * @param  string  $slug  페이지 슬러그
     * @return JsonResponse 페이지 상세 응답
     */
    public function show(string $slug): JsonResponse
    {
        try {
            $page = $this->pageService->getPublishedPageBySlug($slug);

            if (! $page) {
                return $this->notFound('sirsoft-page::messages.page.not_found');
            }

            $page->load('attachments');

            return $this->successWithResource(
                'sirsoft-page::messages.page.fetch_success',
                new PublicPageResource($page)
            );
        } catch (\Exception $e) {
            return $this->error('sirsoft-page::messages.page.fetch_failed', 500, $e->getMessage());
        }
    }
}
