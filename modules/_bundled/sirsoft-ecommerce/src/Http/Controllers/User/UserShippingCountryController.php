<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateUserShippingCountryRequest;
use Modules\Sirsoft\Ecommerce\Services\UserShippingCountryService;

/**
 * 사용자 배송국가 컨트롤러 (MP08 후속)
 *
 * 마이페이지 배송국가 설정 / 회원정보 수정에서 유저별 영속 배송국가를 조회·저장합니다.
 */
class UserShippingCountryController extends AuthBaseController
{
    public function __construct(
        private UserShippingCountryService $service
    ) {}

    /**
     * 현재 사용자의 선호 배송국가를 조회합니다.
     *
     * @return JsonResponse 선호 배송국가 코드(미설정 시 null)를 포함한 JSON 응답
     */
    public function show(): JsonResponse
    {
        $userId = (int) Auth::id();

        return ResponseHelper::success('sirsoft-ecommerce::messages.user_shipping_country.fetched', [
            'preferred_shipping_country' => $this->service->getPreferredShippingCountry($userId),
        ]);
    }

    /**
     * 현재 사용자의 선호 배송국가를 저장합니다.
     *
     * @param  UpdateUserShippingCountryRequest  $request  검증된 요청(활성 국가만 허용)
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function update(UpdateUserShippingCountryRequest $request): JsonResponse
    {
        try {
            $userId = (int) Auth::id();
            $country = $request->validated('shipping_country');

            $this->service->setPreferredShippingCountry($userId, $country);

            return ResponseHelper::success('sirsoft-ecommerce::messages.user_shipping_country.updated', [
                'preferred_shipping_country' => strtoupper($country),
            ]);
        } catch (Exception $e) {
            Log::error('User shipping country update failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.user_shipping_country.update_failed',
                500
            );
        }
    }
}
