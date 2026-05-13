<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\DownloadCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserCouponAvailableRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserCouponDownloadableRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UserCouponListRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponIssueCollection;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;

/**
 * 사용자 쿠폰 컨트롤러
 *
 * 마이페이지 쿠폰함 API를 제공합니다.
 */
class UserCouponController extends AuthBaseController
{
    public function __construct(
        private UserCouponService $userCouponService
    ) {}

    /**
     * 사용자 쿠폰함 목록 조회
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 쿠폰 목록을 포함한 JSON 응답
     */
    public function index(UserCouponListRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.coupons.index');

            $userId = Auth::id();
            $validated = $request->validated();
            $status = $validated['status'] ?? null;
            $perPage = (int) ($validated['per_page'] ?? 10);

            $coupons = $this->userCouponService->getUserCoupons($userId, $status, $perPage);

            return ResponseHelper::success('sirsoft-ecommerce::messages.coupon.list_fetched', [
                'coupons' => new CouponIssueCollection($coupons),
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.list_fetch_failed',
                500
            );
        }
    }

    /**
     * 체크아웃에서 사용 가능한 쿠폰 목록 조회
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 사용 가능한 쿠폰 목록을 포함한 JSON 응답
     */
    public function available(UserCouponAvailableRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.coupons.available');

            $userId = Auth::id();
            $productIds = $request->validated('product_ids') ?? [];

            $coupons = $this->userCouponService->getAvailableCoupons($userId, $productIds);

            return ResponseHelper::success('sirsoft-ecommerce::messages.coupon.available_fetched', [
                'coupons' => $coupons,
            ]);
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.available_fetch_failed',
                500
            );
        }
    }

    /**
     * 다운로드 가능한 쿠폰 목록 조회
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 다운로드 가능 쿠폰 목록
     */
    public function downloadable(UserCouponDownloadableRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.coupons.downloadable');

            $userId = Auth::id();
            $perPage = (int) ($request->validated('per_page') ?? 8);
            $coupons = $this->userCouponService->getDownloadableCoupons($userId, $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupon.downloadable_fetched',
                $coupons
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.downloadable_fetch_failed',
                500
            );
        }
    }

    /**
     * 쿠폰 다운로드 (발급)
     *
     * @param DownloadCouponRequest $request 검증된 요청
     * @param int $couponId 쿠폰 ID
     * @return JsonResponse 생성된 발급 정보
     */
    public function download(DownloadCouponRequest $request, int $couponId): JsonResponse
    {
        try {
            $this->logApiUsage('user.coupons.download');

            $userId = Auth::id();
            $couponIssue = $this->userCouponService->downloadCoupon($userId, $couponId);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupon.download_success',
                $couponIssue,
                201
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                $e->getMessage(),
                $e->getCode() ?: 400
            );
        }
    }
}
