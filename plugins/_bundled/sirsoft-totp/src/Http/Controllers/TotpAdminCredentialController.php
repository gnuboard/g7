<?php

namespace Plugins\Sirsoft\Totp\Http\Controllers;

use App\Extension\HookManager;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;

/**
 * 관리자가 회원의 인증 앱 등록을 초기화하는 컨트롤러.
 *
 * - DELETE /api/plugins/sirsoft-totp/admin/credentials/{user}
 *
 * 단말과 백업 코드를 모두 잃은 회원의 유일한 구제 경로다. 코드를 요구하지 않는 강제
 * 해제이므로 전용 권한(`sirsoft-totp.credentials.delete`)을 라우트에서 강제하고, 실행
 * 사실은 `sirsoft-totp.credential.admin_reset` 훅으로 알린다 — 그 훅을 구독하는
 * `TotpAdminResetActivityLogListener` 가 활동 로그에 남긴다. 누가 누구의 2단계 인증을
 * 풀었는지 추적할 수 없으면 이 경로 자체가 권한 상승 수단이 된다.
 *
 * @since 1.0.0
 */
class TotpAdminCredentialController extends AdminBaseController
{
    /**
     * TotpAdminCredentialController 생성자.
     *
     * @param  TotpEnrollmentService  $enrollmentService  등록 서비스
     */
    public function __construct(
        private readonly TotpEnrollmentService $enrollmentService,
    ) {
        parent::__construct();
    }

    /**
     * 대상 회원의 자격증명을 강제 해제합니다.
     *
     * 식별자는 uuid 다 — 라우트-모델 바인딩이 `User::getRouteKeyName()` 을 따르므로
     * 없는 회원이면 프레임워크가 404 를 낸다(컨트롤러가 다시 조회하지 않는다).
     *
     * @param  User  $user  대상 회원 (uuid 바인딩)
     * @return JsonResponse 초기화 결과
     */
    public function destroy(User $user): JsonResponse
    {
        $target = $user;

        $removed = $this->enrollmentService->reset($target);

        if ($removed) {
            HookManager::doAction('sirsoft-totp.credential.admin_reset', $target, [
                'actor_id' => $this->getCurrentUser()?->id,
                'ip_address' => request()->ip(),
            ]);
        }

        return ResponseHelper::success('sirsoft-totp::messages.enrollment.admin_reset', [
            'user_id' => $target->id,
            'removed' => $removed,
        ]);
    }
}
