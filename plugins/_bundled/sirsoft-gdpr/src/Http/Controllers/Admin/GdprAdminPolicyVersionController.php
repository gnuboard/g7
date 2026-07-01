<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Http\Requests\PublishPolicyVersionRequest;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprPolicyVersionDetailResource;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprPolicyVersionResource;
use Plugins\Sirsoft\Gdpr\Services\GdprPolicyVersionService;
use Plugins\Sirsoft\Gdpr\Services\GdprSettingsService;

/**
 * GDPR 관리자 정책 버전 컨트롤러
 *
 * - GET  /api/plugins/sirsoft-gdpr/admin/policy-versions          — 이력 페이지네이션
 * - GET  /api/plugins/sirsoft-gdpr/admin/policy-versions/current  — 현재 발행된 최신 버전
 * - POST /api/plugins/sirsoft-gdpr/admin/policy-versions          — 운영자 수동 정책 버전 발행
 *
 * 권한: sirsoft-gdpr.privacy.view (조회) / sirsoft-gdpr.privacy.update (발행)
 *
 * GDPR Art.7(1) 동의 입증 책임 + Art.30 처리 기록 의무를 충족합니다.
 */
class GdprAdminPolicyVersionController extends AdminBaseController
{
    /**
     * GdprAdminPolicyVersionController 생성자
     *
     * @param GdprPolicyVersionService $service 정책 버전 서비스
     * @param GdprSettingsService $settingsService 현재 settings snapshot 캡처용
     */
    public function __construct(
        private readonly GdprPolicyVersionService $service,
        private readonly GdprSettingsService $settingsService,
    ) {
        parent::__construct();
    }

    /**
     * 정책 버전 이력을 페이지네이션 형태로 반환합니다 (version DESC).
     *
     * 쿼리 파라미터:
     * - per_page: 페이지 크기 (1~100, 기본 20)
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));

        $paginator = $this->service->paginate($perPage);

        return ResponseHelper::success('messages.success', [
            'data' => GdprPolicyVersionResource::collection($paginator->items()),
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
     * 운영자 수동 정책 버전 발행.
     *
     * 자동 감지 영역 (카테고리 key/description 변경, slug 변경) 밖의 변경 — 예:
     * - 정책 본문 페이지(sirsoft-page) 외부 수정
     * - 법인명 변경 후 의도적 재동의 트리거
     * - 운영 위탁자 변경 등 Art.13 정보 변경
     *
     * 호출 시 현재 settings snapshot 을 자동 캡처하여 새 정책 버전 발행
     * (settings 자체는 변경되지 않음 — 발행만 수행).
     *
     * @param PublishPolicyVersionRequest $request memo 필수 검증
     * @return JsonResponse
     */
    public function store(PublishPolicyVersionRequest $request): JsonResponse
    {
        $memo = (string) $request->validated('memo');
        $snapshot = $this->settingsService->getCurrentSnapshot();

        $version = $this->service->publishManually($snapshot, $memo, $request->user()?->id);

        return ResponseHelper::success('sirsoft-gdpr::messages.settings.policy_version.publish_success', [
            'data' => new GdprPolicyVersionResource($version->load('createdBy')),
        ]);
    }

    /**
     * 현재 발행된 최신 정책 버전을 반환합니다.
     *
     * 발행 row 가 없으면 data 가 null.
     *
     * @return JsonResponse
     */
    public function current(): JsonResponse
    {
        $current = $this->service->getCurrent(loadCreatedBy: true);

        return ResponseHelper::success('messages.success', [
            'data' => $current !== null ? new GdprPolicyVersionResource($current) : null,
        ]);
    }

    /**
     * 특정 정책 버전의 detail (snapshot 본문 포함) 을 반환합니다.
     *
     * admin 동의 이력 / 정책 버전 이력 화면에서 행 클릭 시 호출되어
     * 그 시점의 settings snapshot (cookie_categories / privacy_policy_slug / blocked_domains)
     * 을 모달로 표시. DPO 가 회원 분쟁 시 즉시 그 시점 정책 본문 확인 가능
     * (GDPR Art.7(1) 입증 책임 충족).
     *
     * 권한: sirsoft-gdpr.privacy.view (라우트 미들웨어에서 검증).
     *
     * @param int $version 조회할 정책 버전 정수 (URL path param)
     * @return JsonResponse 200 + detail / 404 (버전 부재)
     */
    public function show(int $version): JsonResponse
    {
        $policyVersion = $this->service->getByVersion($version);

        if ($policyVersion === null) {
            return ResponseHelper::error('sirsoft-gdpr::messages.settings.policy_version.not_found', 404);
        }

        return ResponseHelper::success('messages.success', [
            'data' => new GdprPolicyVersionDetailResource($policyVersion),
        ]);
    }
}
