<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;

/**
 * 공개 쿠폰 컨트롤러
 *
 * 비로그인 사용자도 접근할 수 있는 쿠폰 관련 API를 제공합니다.
 */
class PublicCouponController extends PublicBaseController
{
    public function __construct(
        private UserCouponService $userCouponService
    ) {}

    /**
     * 상품별 다운로드 가능 쿠폰 목록
     *
     * 선택적 인증: 라우트에 optional.sanctum 미들웨어 적용
     * 로그인 시 is_downloaded 정보가 추가됩니다.
     *
     * @param Request $request 요청 데이터
     * @param int $productId 상품 ID
     * @return JsonResponse 쿠폰 목록
     */
    public function downloadableCoupons(Request $request, int $productId): JsonResponse
    {
        try {
            $this->logApiUsage('products.downloadable_coupons');

            $userId = $request->user('sanctum')?->id;
            $coupons = $this->userCouponService->getProductDownloadableCoupons($productId, $userId);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupon.downloadable_fetched',
                ['data' => $coupons]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.downloadable_fetch_failed',
                500
            );
        }
    }
}
