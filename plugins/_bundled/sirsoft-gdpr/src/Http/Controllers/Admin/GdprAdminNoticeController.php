<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Http\Requests\IndexNoticeLogRequest;
use Plugins\Sirsoft\Gdpr\Http\Requests\SendTestNoticeRequest;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprNoticeLogResource;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;

/** 개인정보 통지 이력 조회와 관리자 본인 시험 발송을 제공합니다. */
class GdprAdminNoticeController extends AdminBaseController
{
    /**
     * 필요한 서비스를 주입합니다.
     *
     * @param  GdprAnnualNoticeService  $notices  통지 서비스 또는 이력 저장소
     */
    public function __construct(private readonly GdprAnnualNoticeService $notices)
    {
        parent::__construct();
    }

    /**
     * 통지 이력을 페이지 단위로 반환합니다.
     *
     * @param  IndexNoticeLogRequest  $request  검증된 HTTP 요청
     * @return JsonResponse 처리 결과
     */
    public function index(IndexNoticeLogRequest $request): JsonResponse
    {
        // audit:allow repository-paginate-column-pruning reason: 서비스 위임이며 Repository의 paginateWithDeferredJoin에서 목록 컬럼을 명시합니다.
        $page = $this->notices->paginate((int) $request->validated('per_page', 20));

        return ResponseHelper::success('common.success', [
            'data' => GdprNoticeLogResource::collection($page->items()),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /**
     * 통지 처리 계약을 수행합니다.
     *
     * @param  SendTestNoticeRequest  $request  검증된 HTTP 요청
     * @return JsonResponse 처리 결과
     */
    public function sendTest(SendTestNoticeRequest $request): JsonResponse
    {
        $attempt = $this->notices->sendTest($request->user());
        if ($attempt === null) {
            return ResponseHelper::error('sirsoft-gdpr::messages.annual_notice.unavailable', 422);
        }

        return ResponseHelper::success('sirsoft-gdpr::messages.annual_notice.test_queued', ['attempt_id' => $attempt]);
    }
}
