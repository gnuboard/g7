<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserMileageMaxUsableRequest;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 사용자 마일리지 컨트롤러
 *
 * 마이페이지 마일리지 조회 API를 제공합니다.
 */
class UserMileageController extends AuthBaseController
{
    public function __construct(
        private UserMileageService $userMileageService
    ) {}

    /**
     * 사용자 마일리지 잔액 조회
     *
     * @return JsonResponse 마일리지 정보를 포함한 JSON 응답
     */
    public function balance(): JsonResponse
    {
        try {
            $this->logApiUsage('user.mileage.balance');

            $userId = Auth::id();
            $balance = $this->userMileageService->getBalance($userId);

            return ResponseHelper::success('sirsoft-ecommerce::messages.mileage.balance_fetched', [
                'mileage' => $balance,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mileage.balance_fetch_failed',
                500
            );
        }
    }

    /**
     * 체크아웃에서 사용 가능한 최대 마일리지 조회
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 사용 가능한 최대 마일리지를 포함한 JSON 응답
     */
    public function maxUsable(UserMileageMaxUsableRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.mileage.max-usable');

            $userId = Auth::id();
            $orderAmount = (int) $request->validated('order_amount');

            $maxUsable = $this->userMileageService->getMaxUsable($userId, $orderAmount);
            $balance = $this->userMileageService->getBalance($userId);

            return ResponseHelper::success('sirsoft-ecommerce::messages.mileage.max_usable_fetched', [
                'max_usable' => $maxUsable,
                'available' => $balance['available'],
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.mileage.max_usable_fetch_failed',
                500
            );
        }
    }
}
