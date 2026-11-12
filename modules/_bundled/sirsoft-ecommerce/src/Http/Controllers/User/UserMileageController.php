<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserMileageHistoryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserMileageMaxUsableRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\MileageTransactionCollection;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;

/**
 * 사용자 마일리지 컨트롤러
 *
 * 마이페이지 마일리지 조회 API를 제공합니다.
 */
class UserMileageController extends AuthBaseController
{
    /**
     * 회원 마일리지 서비스를 주입받습니다.
     *
     * @param  UserMileageService  $userMileageService  회원 마일리지 서비스
     */
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
     * @param  Request  $request  요청 데이터
     * @return JsonResponse 사용 가능한 최대 마일리지를 포함한 JSON 응답
     */
    public function maxUsable(UserMileageMaxUsableRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.mileage.max-usable');

            $userId = Auth::id();
            $orderAmount = (int) $request->validated('order_amount');

            // 상품 합계는 선택 값 — 주면 「사용 가능 최소 상품 합계」도 함께 판정한다
            $productTotal = $request->validated('product_total');

            $maxUsable = $this->userMileageService->getMaxUsable(
                $userId,
                $orderAmount,
                null,
                $productTotal === null ? null : (int) $productTotal
            );
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

    /**
     * 마이페이지 마일리지 내역 조회 (4분류 필터)
     *
     * @param  Request  $request  요청 (category, per_page)
     * @return JsonResponse 마일리지 내역
     */
    public function history(UserMileageHistoryRequest $request): JsonResponse
    {
        $this->logApiUsage('user.mileage.history');

        $userId = Auth::id();
        $validated = $request->validated();
        $filters = [
            'category' => $validated['category'] ?? null,
            'currency' => $validated['currency'] ?? null,
        ];
        $perPage = (int) ($validated['per_page'] ?? 20);

        $transactions = $this->userMileageService->paginateUserHistory($userId, $filters, $perPage);

        return ResponseHelper::success('sirsoft-ecommerce::messages.mileage.list_retrieved', [
            'transactions' => new MileageTransactionCollection($transactions),
        ]);
    }
}
