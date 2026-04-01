<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateCouponStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CouponIssuesListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CouponListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponIssueCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponResource;
use Modules\Sirsoft\Ecommerce\Services\CouponService;

/**
 * 쿠폰 관리 컨트롤러
 */
class CouponController extends AdminBaseController
{
    public function __construct(
        private CouponService $couponService
    ) {}

    /**
     * 쿠폰 목록 조회
     *
     * @param CouponListRequest $request
     * @return JsonResponse
     */
    public function index(CouponListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 10;

        $coupons = $this->couponService->getCoupons($filters, $perPage);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.coupons.list_retrieved',
            new CouponCollection($coupons)
        );
    }

    /**
     * 쿠폰 생성
     *
     * @param StoreCouponRequest $request
     * @return JsonResponse
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $coupon = $this->couponService->createCoupon($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.coupons.created',
            new CouponResource($coupon),
            201
        );
    }

    /**
     * 쿠폰 상세 조회
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $coupon = $this->couponService->getCoupon($id);

        if (! $coupon) {
            return ResponseHelper::notFound(
                'messages.coupons.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.coupons.retrieved',
            new CouponResource($coupon)
        );
    }

    /**
     * 쿠폰 수정
     *
     * @param UpdateCouponRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateCouponRequest $request, int $id): JsonResponse
    {
        try {
            $coupon = $this->couponService->updateCoupon($id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupons.updated',
                new CouponResource($coupon)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 쿠폰 삭제
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $result = $this->couponService->deleteCoupon($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupons.deleted',
                $result
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 일괄 발급상태 변경
     *
     * @param BulkUpdateCouponStatusRequest $request
     * @return JsonResponse
     */
    public function bulkUpdateStatus(BulkUpdateCouponStatusRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $count = $this->couponService->bulkUpdateIssueStatus(
            $validated['ids'],
            $validated['issue_status']
        );

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.coupons.status_changed',
            ['updated_count' => $count],
            200,
            ['count' => $count]
        );
    }

    /**
     * 쿠폰 발급 내역 조회
     *
     * @param CouponIssuesListRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function issues(CouponIssuesListRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $filters = array_filter([
                'user_id' => $validated['user_id'] ?? null,
                'status' => $validated['status'] ?? null,
            ]);
            $perPage = $validated['per_page'] ?? 10;

            $issues = $this->couponService->getCouponIssues($id, $filters, $perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupons.issues_retrieved',
                new CouponIssueCollection($issues)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }
}
