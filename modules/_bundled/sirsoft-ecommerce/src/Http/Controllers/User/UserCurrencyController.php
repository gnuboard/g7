<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UpdateUserCurrencyRequest;
use Modules\Sirsoft\Ecommerce\Services\UserCurrencyService;

/**
 * 사용자 결제 통화 컨트롤러 (A3)
 *
 * 마이페이지 통화 설정 / 회원정보 수정에서 유저별 영속 통화를 조회·저장합니다.
 */
class UserCurrencyController extends AuthBaseController
{
    public function __construct(
        private UserCurrencyService $userCurrencyService
    ) {}

    /**
     * 현재 사용자의 선호 통화를 조회합니다.
     *
     * @return JsonResponse 선호 통화 코드(미설정 시 null)를 포함한 JSON 응답
     */
    public function show(): JsonResponse
    {
        $userId = (int) Auth::id();

        return ResponseHelper::success('sirsoft-ecommerce::messages.user_currency.fetched', [
            'preferred_currency' => $this->userCurrencyService->getPreferredCurrency($userId),
        ]);
    }

    /**
     * 현재 사용자의 선호 통화를 저장합니다.
     *
     * @param  UpdateUserCurrencyRequest  $request  검증된 요청(등록 통화만 허용)
     * @return JsonResponse 저장 결과 JSON 응답
     */
    public function update(UpdateUserCurrencyRequest $request): JsonResponse
    {
        try {
            $userId = (int) Auth::id();
            $currency = $request->validated('currency');

            $this->userCurrencyService->setPreferredCurrency($userId, $currency);

            return ResponseHelper::success('sirsoft-ecommerce::messages.user_currency.updated', [
                'preferred_currency' => $currency,
            ]);
        } catch (Exception $e) {
            Log::error('User currency update failed', [
                'message' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.user_currency.update_failed',
                500
            );
        }
    }
}
