<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * - sources[]: banner|preference_center|mypage 배열
     * - per_page: 페이지 크기 (1~100, 기본 20)
     *
     * @param  Request  $request  HTTP 요청
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'email' => $request->query('email'),
            'session_id' => $request->query('session_id'),
            'consent_keys' => $this->normalizeArrayParam($request->query('consent_keys')),
            'actions' => $this->normalizeArrayParam($request->query('actions')),
            'sources' => $this->normalizeArrayParam($request->query('sources')),
        ];

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $paginator = $this->consentLogService->paginateForAdmin($filters, $perPage);

        return ResponseHelper::success('messages.success', [
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

    /**
     * 쿼리 파라미터 배열을 정규화합니다.
     *
     * `?consent_keys[]=a&consent_keys[]=b` → ['a', 'b']
     * `?consent_keys=a` → ['a'] (단일 값도 배열로 정규화)
     * `null` 또는 빈 값 → []
     *
     * @param  mixed  $value  쿼리 값
     * @return array<int, string>
     */
    private function normalizeArrayParam(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn ($v) => $v !== ''));
        }

        return [(string) $value];
    }
}
