<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Admin\PrivacyTargetListRequest;
use App\Http\Resources\PrivacyTargetCollection;
use App\Privacy\DormantAccountService;
use App\Privacy\PrivacyRetentionManager;
use App\Services\PasswordExpiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 개인정보 조치 대상 목록 컨트롤러
 *
 * 관리자 화면의 「대상 N건」 배지 옆 「더보기」 모달이 부른다. 목록은 건수·배치와 **같은
 * 쿼리**에서 나오므로 모달에서 본 명단이 곧 배치가 처리할 명단이다.
 *
 * 보유기간 목록은 코어가 확장 핸들러를 모른 채 식별자로 찾는다 — 확장이
 * `core.privacy.retention_handlers` 로 핸들러를 등록하면 이 API 가 그 확장의 목록도 내려준다.
 * 목록에는 개인정보가 그대로 나오므로 권한은 공급자가 선언한 「설정 읽기 + 도메인 읽기」를
 * 요청 시점에 검사한다(라우트가 확장별 권한을 정적으로 알 수 없다).
 */
class PrivacyTargetController extends AdminBaseController
{
    /**
     * @param  PrivacyRetentionManager  $retentionManager  보유기간 핸들러 수집기
     * @param  DormantAccountService  $dormantAccountService  휴면 생명주기 서비스
     * @param  PasswordExpiryService  $passwordExpiryService  비밀번호 만료 정책 서비스
     */
    public function __construct(
        private PrivacyRetentionManager $retentionManager,
        private DormantAccountService $dormantAccountService,
        private PasswordExpiryService $passwordExpiryService,
    ) {
        parent::__construct();
    }

    /**
     * 보유기간 핸들러 하나의 대상 목록을 한 페이지 조회합니다.
     *
     * @param  PrivacyTargetListRequest  $request  페이지 요청
     * @param  string  $handler  핸들러 식별자 (예: `user-withdrawn`, `sirsoft-ecommerce.order`)
     * @return JsonResponse 대상 목록 응답 (미등록 404 · 권한 부족 403)
     */
    public function retention(PrivacyTargetListRequest $request, string $handler): JsonResponse
    {
        $target = $this->retentionManager->handler($handler);

        if ($target === null) {
            return $this->notFound('privacy.targets.unknown_handler');
        }

        if (! $this->retentionManager->canViewTargets($target, $request->user())) {
            return $this->forbidden('auth.permission_denied');
        }

        try {
            $page = $target->targets($request->page(), $request->perPage());
            $columns = $target->targetColumns();
        } catch (Throwable $e) {
            Log::error('개인정보 보유기간 대상 목록 조회 실패', [
                'handler' => $handler,
                'error' => $e->getMessage(),
            ]);

            return $this->error('common.failed', 500);
        }

        return $this->success('common.success', (new PrivacyTargetCollection($page))->withColumns($columns));
    }

    /**
     * 휴면 생명주기 한 단계의 대상 목록을 한 페이지 조회합니다.
     *
     * @param  PrivacyTargetListRequest  $request  페이지 요청
     * @param  string  $stage  단계 (`pre_notice`·`convert`·`withdraw_notice`·`withdraw`)
     * @return JsonResponse 대상 목록 응답 (알 수 없는 단계 404 · 권한 부족 403)
     */
    public function dormant(PrivacyTargetListRequest $request, string $stage): JsonResponse
    {
        if (! in_array($stage, DormantAccountService::STAGES, true)) {
            return $this->notFound('privacy.targets.unknown_stage');
        }

        if (! $this->retentionManager->canViewTargetList(DormantAccountService::TARGET_PERMISSIONS, $request->user())) {
            return $this->forbidden('auth.permission_denied');
        }

        $page = $this->dormantAccountService->stageTargets($stage, $request->page(), $request->perPage());

        return $this->success(
            'common.success',
            (new PrivacyTargetCollection($page))->withColumns($this->dormantAccountService->stageColumns($stage)),
        );
    }

    /**
     * 비밀번호 만료 한 단계의 대상 목록을 한 페이지 조회합니다 (#736 — 만료가 가까운 순).
     *
     * 보안 탭이 개인정보 탭과 같은 명단 창을 쓴다. 권한은 휴면 명단과 같은 「설정 읽기 + 회원 읽기」이고,
     * 회원 읽기가 부분 범위면 전체 명단을 볼 수 없으므로 403 이다.
     *
     * @param  PrivacyTargetListRequest  $request  페이지 요청
     * @param  string  $stage  단계 (`notice`·`expire`)
     * @return JsonResponse 대상 목록 응답 (알 수 없는 단계 404 · 권한 부족 403)
     */
    public function passwordExpiry(PrivacyTargetListRequest $request, string $stage): JsonResponse
    {
        if (! in_array($stage, PasswordExpiryService::STAGES, true)) {
            return $this->notFound('privacy.targets.unknown_stage');
        }

        if (! $this->retentionManager->canViewTargetList(PasswordExpiryService::TARGET_PERMISSIONS, $request->user())) {
            return $this->forbidden('auth.permission_denied');
        }

        $page = $this->passwordExpiryService->stageTargets($stage, $request->page(), $request->perPage());

        return $this->success(
            'common.success',
            (new PrivacyTargetCollection($page))->withColumns($this->passwordExpiryService->stageColumns($stage)),
        );
    }
}
