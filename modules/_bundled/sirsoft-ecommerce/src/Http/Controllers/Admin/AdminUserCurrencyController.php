<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateAdminUserCurrencyRequest;
use Modules\Sirsoft\Ecommerce\Services\UserCurrencyService;

/**
 * 관리자 회원 결제 통화 컨트롤러 (A3)
 *
 * 관리자가 특정 회원의 결제 통화를 변경합니다. permission:admin,sirsoft-ecommerce.user-currency.manage
 * 로 가드되며, 변경 시 활동 로그 훅을 발화합니다.
 *
 * @deprecated 1.3.0 — 번들 관리자 회원 수정 화면은 코어 회원 저장(PUT /api/admin/users/{user})으로 함께 보낸다
 *             (UserPreferenceCoreSaveListener). 이전 관리자 템플릿과의 호환을 위해 이번 버전까지 남기고 다음 버전에서 제거한다.
 */
class AdminUserCurrencyController extends AdminBaseController
{
    /**
     * 의존 서비스를 주입받습니다.
     *
     * @param  UserCurrencyService  $userCurrencyService  회원 결제 통화 서비스
     */
    public function __construct(
        private UserCurrencyService $userCurrencyService
    ) {}

    /**
     * 특정 회원의 결제 통화를 변경합니다.
     *
     * 회원은 UUID 라우트 모델 바인딩으로 주입됩니다(관리자 회원 URL 규약, getRouteKeyName='uuid').
     *
     * @param  UpdateAdminUserCurrencyRequest  $request  검증된 요청(등록 통화만 허용)
     * @param  User  $user  대상 회원(UUID 바인딩)
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function update(UpdateAdminUserCurrencyRequest $request, User $user): JsonResponse
    {
        try {
            $currency = $request->validated('currency');

            // 통화 저장 + 활동 로그 훅을 서비스가 한 단위로 처리(컨트롤러 모델 직접 접근 회피)
            $this->userCurrencyService->changeUserCurrencyByAdmin($user->id, $currency);

            return ResponseHelper::success('sirsoft-ecommerce::messages.user_currency.updated', [
                'preferred_currency' => $currency,
            ]);
        } catch (Exception $e) {
            Log::error('Admin user currency update failed', [
                'message' => $e->getMessage(),
                'target_user_id' => $user->id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.user_currency.update_failed',
                500
            );
        }
    }
}
