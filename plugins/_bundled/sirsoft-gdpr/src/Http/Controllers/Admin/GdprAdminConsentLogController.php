<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Http\Requests\IndexConsentLogRequest;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprConsentLogResource;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentLogService;

/**
 * GDPR 관리자 동의 로그 컨트롤러
 *
 * GET /api/plugins/sirsoft-gdpr/admin/consent-log
 *
 * 권한: consent-log.read (라우트 미들웨어에서 검증)
 *
 * `gdpr_user_consent_histories` 테이블의 페이지네이션·필터 조회를 제공합니다.
 * DPO 감사용 IP/User-Agent까지 노출됩니다.
 */
class GdprAdminConsentLogController extends AdminBaseController
{
    /**
     * GdprAdminConsentLogController 생성자
     *
     * @param  GdprConsentLogService  $consentLogService  동의 로그 서비스
     */
    public function __construct(
        private readonly GdprConsentLogService $consentLogService,
    ) {
        parent::__construct();
    }

    /**
     * 동의 로그 페이지네이션 응답을 반환합니다.
     *
     * 쿼리 파라미터:
     * - email: 회원 이메일 부분 일치
     * - session_id: 게스트 세션 ID 부분 일치 (DataGrid 가 앞 8자만 표시하므로 prefix/중간 모두 허용)
     * - consent_keys[]: 동의 항목 키 배열 (whereIn)
     * - actions[]: granted|revoked 배열
     * - sources[]: 출처 배열 (허용 어휘는 ConsentSource enum — banner|preference_center|register|mypage|mypage_renew_all)
     * - per_page: 페이지 크기 (1~100, 기본 20)
     *
     * @param  IndexConsentLogRequest  $request  HTTP 요청
     * @return JsonResponse
     */
    public function index(IndexConsentLogRequest $request): JsonResponse
    {
        $paginator = $this->consentLogService->paginateForAdmin($request->filters(), $request->perPage());

        return ResponseHelper::success('common.success', [
            'data' => GdprConsentLogResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }
}
