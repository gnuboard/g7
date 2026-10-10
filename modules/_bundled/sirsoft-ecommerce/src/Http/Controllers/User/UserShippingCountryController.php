<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateUserShippingCountryRequest;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Modules\Sirsoft\Ecommerce\Services\UserShippingCountryService;

/**
 * 사용자 배송국가 컨트롤러 (MP08 후속)
 *
 * 마이페이지 배송국가 설정 / 회원정보 수정에서 유저별 영속 배송국가를 조회·저장합니다.
 */
class UserShippingCountryController extends AuthBaseController
{
    public function __construct(
        private UserShippingCountryService $service,
        private ShippingCountryResolver $resolver,
    ) {}

    /**
     * 현재 사용자의 선호 배송국가를 조회합니다.
     *
     * 저장값과 함께 **실제로 적용되는 국가**를 싣는다. 저장된 국가를 운영자가 배송가능 목록에서
     * 내리면 저장값은 남아 있지만 서버 해석(`ShippingCountryResolver`)은 기본 국가로 떨어진다.
     * 저장값만 내려보내면 화면은 그 국가로 계산을 요청하는데 서버는 다른 국가로 해석해 두 값이
     * 조용히 갈린다 — 예외도 로그도 없이 "배송 불가" 만 뜬다(S17 B12).
     *
     * @return JsonResponse 선호 배송국가(미설정 시 null)·실제 적용 국가·적용 여부
     */
    public function show(): JsonResponse
    {
        $userId = (int) Auth::id();
        $preferred = $this->service->getPreferredShippingCountry($userId);

        return ResponseHelper::success('sirsoft-ecommerce::messages.user_shipping_country.fetched', [
            'preferred_shipping_country' => $preferred,
            'effective_shipping_country' => $this->resolver->resolve($userId, request()->ip()),
            'is_preference_effective' => $preferred !== null && $this->resolver->isAllowed($preferred),
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
