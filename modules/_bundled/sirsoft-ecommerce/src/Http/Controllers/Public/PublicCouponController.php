<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Concerns\EnsuresPublicProduct;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\DownloadableCouponsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicCouponZoneRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\DownloadableCouponResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;

/**
 * 공개 쿠폰 컨트롤러
 *
 * 비로그인 사용자도 접근할 수 있는 쿠폰 관련 API를 제공합니다.
 */
class PublicCouponController extends PublicBaseController
{
    use EnsuresPublicProduct;

    /**
     * 회원 쿠폰 서비스를 주입받습니다.
     *
     * @param  UserCouponService  $userCouponService  회원 쿠폰 서비스
     */
    public function __construct(
        private UserCouponService $userCouponService
    ) {}

    /**
     * 쿠폰존 — 받을 수 있는 쿠폰 목록 (비로그인 공개)
     *
     * 회원용 `user/coupons/downloadable` 과 같은 쿠폰·같은 paginator 모양이다. 비로그인이면 받은 여부를 싣지 않고, 토큰을 실은
     * 로그인 회원이면 받은 여부(`is_downloaded`)를 싣는다 — 쿠폰존 화면은 이 한 주소만 부른다(로그인 확인 전에 주소가 갈리던 결함).
     *
     * @param  PublicCouponZoneRequest  $request  요청 데이터
     * @return JsonResponse 쿠폰 목록 (paginator)
     */
    public function zone(PublicCouponZoneRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('coupons.downloadable');

            $perPage = (int) ($request->validated('per_page') ?? 12);
            $userId = Auth::id();
            $coupons = $this->userCouponService->getPublicDownloadableCoupons($perPage, $userId !== null ? (int) $userId : null);
            $coupons->setCollection(
                $coupons->getCollection()->map(fn ($coupon) => (new DownloadableCouponResource($coupon))->resolve($request))
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupon.zone_fetched',
                $coupons
            );
        } catch (Exception $e) {
            Log::error('coupons.downloadable failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.zone_fetch_failed',
                500
            );
        }
    }

    /**
     * 상품별 다운로드 가능 쿠폰 목록
     *
     * 선택적 인증: 라우트에 optional.sanctum 미들웨어 적용
     * 로그인 시 is_downloaded 정보가 추가됩니다.
     *
     * @param  DownloadableCouponsRequest  $request  요청 데이터
     * @param  Product  $product  라우트 바인딩된 상품 (product_code 또는 id)
     * @return JsonResponse 쿠폰 목록
     */
    public function downloadableCoupons(DownloadableCouponsRequest $request, Product $product): JsonResponse
    {
        // 숨김·삭제 상품의 쿠폰 목록은 열지 않는다 (상품 상세와 같은 판정)
        $this->ensurePublicProduct($product);

        try {
            $this->logApiUsage('products.downloadable_coupons');

            $userId = $request->user('sanctum')?->id;
            $coupons = $this->userCouponService->getProductDownloadableCoupons($product->id, $userId);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupon.downloadable_fetched',
                ['data' => $coupons]
            );
        } catch (Exception $e) {
            Log::error('products.downloadable_coupons failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'product_id' => $product->id,
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.downloadable_fetch_failed',
                500
            );
        }
    }
}
