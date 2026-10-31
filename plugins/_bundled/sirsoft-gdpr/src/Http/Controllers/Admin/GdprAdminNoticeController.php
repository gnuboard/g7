<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\PrivacyTargetListRequest;
use App\Http\Resources\PrivacyTargetCollection;
use App\Privacy\PrivacyRetentionManager;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Http\Requests\AnnualNoticePreviewRequest;
use Plugins\Sirsoft\Gdpr\Http\Requests\IndexNoticeLogRequest;
use Plugins\Sirsoft\Gdpr\Http\Requests\SendTestNoticeRequest;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprNoticeLogResource;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;

/** 개인정보 통지 이력 조회와 관리자 본인 시험 발송을 제공합니다. */
class GdprAdminNoticeController extends AdminBaseController
{
    /**
     * 통지 대상 명단을 볼 수 있는 권한 — 통지 조회 + 회원 읽기 (모두 필요).
     *
     * 명단에 회원 이름·이메일이 그대로 나오므로 설정 화면 권한만으로 회원 명단을 보는 우회 경로를
     * 막는다. 라우트 미들웨어도 같은 목록을 쓴다.
     *
     * @var array<int, string>
     */
    public const TARGET_PERMISSIONS = ['sirsoft-gdpr.privacy.view', 'core.users.read'];

    /**
     * 필요한 서비스를 주입합니다.
     *
     * @param  GdprAnnualNoticeService  $notices  통지 서비스 또는 이력 저장소
     * @param  PrivacyRetentionManager  $retention  명단 열람 판정(권한 + 전체 범위)
     */
    public function __construct(
        private readonly GdprAnnualNoticeService $notices,
        private readonly PrivacyRetentionManager $retention,
    ) {
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
     * 이번 주기 연간 통지 대상 건수를 반환합니다 (이슈 #636).
     *
     * **꺼져 있어도 셉니다** — 켜기 전에 규모를 확인하는 자리다(2026-09-23 확정). 건수는 발송
     * 판정(활성 · 가입 1년 경과 · 설정 채널 중 최근 1년 미발송)과 같은 쿼리에서 나온다.
     * `can_view_targets` 는 요청 관리자가 「더보기」 명단을 볼 권한(통지 조회 + 회원 읽기)이 있는지다.
     *
     * @param  AnnualNoticePreviewRequest  $request  검증된 HTTP 요청 (입력 없음)
     * @return JsonResponse 처리 결과
     */
    public function preview(AnnualNoticePreviewRequest $request): JsonResponse
    {
        return ResponseHelper::success('common.success', [
            'enabled' => $this->notices->isEnabled(),
            'count' => $this->notices->dueCount(),
            'can_view_targets' => $this->retention->canViewTargetList(self::TARGET_PERMISSIONS, $request->user()),
        ]);
    }

    /**
     * 이번 주기 연간 통지 대상 명단을 한 페이지 반환합니다 (이슈 #636).
     *
     * 행 형태는 코어 개인정보 조치 대상 목록과 같다 — 관리자 화면의 대상 목록 모달 하나가 그대로 그린다.
     * 라우트 미들웨어는 권한 보유만 보므로 적용 범위(전체만 허용)는 여기서 코어 판정으로 거른다.
     *
     * @param  PrivacyTargetListRequest  $request  페이지 요청
     * @return JsonResponse 처리 결과 (범위가 좁은 관리자 403)
     */
    public function targets(PrivacyTargetListRequest $request): JsonResponse
    {
        if (! $this->retention->canViewTargetList(self::TARGET_PERMISSIONS, $request->user())) {
            return ResponseHelper::forbidden('auth.permission_denied');
        }

        $page = $this->notices->dueTargets($request->page(), $request->perPage());

        return ResponseHelper::success('common.success', (new PrivacyTargetCollection($page))->withColumns($this->notices->targetColumns()));
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
