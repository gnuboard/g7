<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponNotIssuableException;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\BulkDownloadCouponRequest;
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
    /**
     * 회원 쿠폰 서비스를 주입받습니다.
     *
     * @param  UserCouponService  $userCouponService  회원 쿠폰 서비스
     */
    public function __construct(
        private UserCouponService $userCouponService
    ) {}

    /**
     * 사용자 쿠폰함 목록 조회
     *
     * @param  Request  $request  요청 데이터
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
     * @param  Request  $request  요청 데이터
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
     * @param  Request  $request  요청 데이터
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
     * @param  DownloadCouponRequest  $request  검증된 요청
     * @param  int  $couponId  쿠폰 ID
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
        } catch (CouponNotIssuableException $e) {
            // 발급 불가 사유는 예외가 식별자로 들고 있다 — 번역된 메시지를 다시 키로
            // 넘기면(종전 동작) 키 해석에 실패해 원문이 그대로 노출된다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.coupon.'.$e->getReason(),
                400
            );
        } catch (Exception $e) {
            Log::error('쿠폰 다운로드 실패', [
                'coupon_id' => $couponId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 쿠폰 일괄 받기 (받을 수 있는 쿠폰을 한 번에 발급)
     *
     * 받은 장이 없어도 200 으로 답한다 — 쿠폰별 결과와 사유 코드가 응답 본문에 있다.
     *
     * @param  BulkDownloadCouponRequest  $request  검증된 요청
     * @return JsonResponse 쿠폰별 결과와 합계
     */
    public function bulkDownload(BulkDownloadCouponRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('user.coupons.bulk-download');

            $couponIds = $request->validated('coupon_ids');
            $result = $this->userCouponService->bulkDownloadCoupons(
                (int) Auth::id(),
                $couponIds === null ? null : array_map('intval', $couponIds)
            );

            $messageKey = $result['issued_count'] === 0
                ? 'messages.coupon.bulk_download_none'
                : ($result['skipped_count'] === 0 ? 'messages.coupon.bulk_download_all' : 'messages.coupon.bulk_download_result');

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $messageKey,
                $result,
                200,
                ['issued' => $result['issued_count'], 'skipped' => $result['skipped_count']]
            );
        } catch (Exception $e) {
            Log::error('쿠폰 일괄 받기 실패', [
                'user_id' => Auth::id(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }
}
