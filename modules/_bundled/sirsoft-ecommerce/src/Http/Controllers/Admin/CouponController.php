<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateCouponStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CouponIssuesListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CouponListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\IssueCouponDirectRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateCouponRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponIssueCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\CouponIssueResource;
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
     * @param  CouponListRequest  $request  목록 검색/필터 요청
     * @return JsonResponse 쿠폰 목록 응답
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
     * @param  StoreCouponRequest  $request  쿠폰 생성 요청
     * @return JsonResponse 생성된 쿠폰 응답
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
     * @param  int  $id  쿠폰 ID
     * @return JsonResponse 쿠폰 상세 응답
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
     * @param  UpdateCouponRequest  $request  쿠폰 수정 요청
     * @param  int  $id  쿠폰 ID
     * @return JsonResponse 수정된 쿠폰 응답
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
     * @param  int  $id  쿠폰 ID
     * @return JsonResponse 삭제 결과 응답
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
     * @param  BulkUpdateCouponStatusRequest  $request  일괄 상태 변경 요청
     * @return JsonResponse 변경 결과 응답
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
     * 쿠폰 직접 발급 (관리자가 지정한 회원들에게 즉시 발급)
     *
     * @param  IssueCouponDirectRequest  $request  직접 발급 요청(user_uuids)
     * @param  int  $id  쿠폰 ID
     * @return JsonResponse 발급 결과 응답(발급/스킵 건수)
     */
    public function issueDirect(IssueCouponDirectRequest $request, int $id): JsonResponse
    {
        try {
            // 회원 식별은 uuid 로 받아 FormRequest 에서 내부 정수 ID 로 해석 (관리자 UI 는 uuid 만 노출)
            $result = $this->couponService->issueDirectly($id, $request->resolvedUserIds());

            $messageKey = empty($result['skipped'])
                ? 'messages.coupons.direct_issued'
                : 'messages.coupons.direct_issued_with_skip';

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $messageKey,
                $result,
                200,
                ['issued' => $result['issued'], 'skipped' => count($result['skipped'])]
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
     * 쿠폰 발급 취소 (미사용 발급 건을 취소 처리)
     *
     * @param  int  $id  쿠폰 ID
     * @param  int  $issueId  발급 내역 ID
     * @return JsonResponse 취소 결과 응답
     */
    public function cancelIssue(int $id, int $issueId): JsonResponse
    {
        try {
            $issue = $this->couponService->cancelIssue($id, $issueId);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.coupons.issue_cancelled',
                new CouponIssueResource($issue)
            );
        } catch (\Exception $e) {
            // 취소 불가 사유(미사용 아님 등)를 관리자에게 그대로 노출
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400,
                ['detail' => $e->getMessage()]
            );
        }
    }

    /**
     * 쿠폰 발급 내역 조회
     *
     * @param  CouponIssuesListRequest  $request  발급 내역 조회 요청
     * @param  int  $id  쿠폰 ID
     * @return JsonResponse 발급 내역 응답
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
