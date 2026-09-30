<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateAdminUserShippingCountryRequest;
use Modules\Sirsoft\Ecommerce\Services\UserShippingCountryService;

/**
 * 관리자 회원 배송국가 컨트롤러 (MP08 후속)
 *
 * 관리자가 특정 회원의 배송국가를 변경합니다. permission:admin,sirsoft-ecommerce.user-shipping-country.manage
 * 로 가드되며, 변경 시 활동 로그 훅을 발화합니다.
 *
 * @deprecated 1.3.0 — 번들 관리자 회원 수정 화면은 코어 회원 저장(PUT /api/admin/users/{user})으로 함께 보낸다
 *             (UserPreferenceCoreSaveListener). 이전 관리자 템플릿과의 호환을 위해 이번 버전까지 남기고 다음 버전에서 제거한다.
 */
class AdminUserShippingCountryController extends AdminBaseController
{
    /**
     * 의존 서비스를 주입받습니다.
     *
     * @param  UserShippingCountryService  $service  회원 배송국가 서비스
     */
    public function __construct(
        private UserShippingCountryService $service
    ) {}

    /**
     * 특정 회원의 배송국가를 변경합니다.
     *
     * 회원은 UUID 라우트 모델 바인딩으로 주입됩니다(관리자 회원 URL 규약, getRouteKeyName='uuid').
     *
     * @param  UpdateAdminUserShippingCountryRequest  $request  검증된 요청(활성 국가만 허용)
     * @param  User  $user  대상 회원(UUID 바인딩)
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function update(UpdateAdminUserShippingCountryRequest $request, User $user): JsonResponse
    {
        try {
            $country = $request->validated('shipping_country');

            // 배송국가 저장 + 활동 로그 훅을 서비스가 한 단위로 처리(컨트롤러 모델 직접 접근 회피)
            $this->service->changeUserShippingCountryByAdmin($user->id, $country);

            return ResponseHelper::success('sirsoft-ecommerce::messages.user_shipping_country.updated', [
                'preferred_shipping_country' => strtoupper($country),
            ]);
        } catch (Exception $e) {
            Log::error('Admin user shipping country update failed', [
                'message' => $e->getMessage(),
                'target_user_id' => $user->id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.user_shipping_country.update_failed',
                500
            );
        }
    }
}
