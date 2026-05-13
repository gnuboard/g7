<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreClaimReasonRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimReasonRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimReasonCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimReasonResource;
use Modules\Sirsoft\Ecommerce\Services\ClaimReasonService;

/**
 * 클래임 사유 관리 컨트롤러
 */
class ClaimReasonController extends AdminBaseController
{
    /**
     * @param ClaimReasonService $service 클래임 사유 서비스
     */
    public function __construct(
        protected ClaimReasonService $service,
    ) {}

    /**
     * 클래임 사유 목록 조회
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'is_active', 'fault_type', 'search']);

        if (! isset($filters['type'])) {
            $filters['type'] = 'refund';
        }

        $reasons = $this->service->getAllReasons($filters);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.list_retrieved',
            new ClaimReasonCollection($reasons)
        );
    }

    /**
     * 클래임 사유 생성
     *
     * @param StoreClaimReasonRequest $request
     * @return JsonResponse
     */
    public function store(StoreClaimReasonRequest $request): JsonResponse
    {
        $reason = $this->service->createReason($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.created',
            new ClaimReasonResource($reason),
            201
        );
    }

    /**
     * 활성 클래임 사유 목록 조회 (Select 옵션용)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function active(Request $request): JsonResponse
    {
        $type = $request->query('type', 'refund');
        $reasons = $this->service->getActiveReasons($type);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.list_retrieved',
            new ClaimReasonCollection($reasons)
        );
    }

    /**
     * 클래임 사유 상세 조회
     *
     * @param int $id 사유 ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $reason = $this->service->getReason($id);

        if (! $reason) {
            return ResponseHelper::notFound(
                'messages.claim_reasons.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.retrieved',
            new ClaimReasonResource($reason)
        );
    }

    /**
     * 클래임 사유 수정
     *
     * @param UpdateClaimReasonRequest $request
     * @param int $id 사유 ID
     * @return JsonResponse
     */
    public function update(UpdateClaimReasonRequest $request, int $id): JsonResponse
    {
        try {
            $reason = $this->service->updateReason($id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claim_reasons.updated',
                new ClaimReasonResource($reason)
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
     * 클래임 사유 삭제
     *
     * @param int $id 사유 ID
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $reason = $this->service->getReason($id);

        if (! $reason) {
            return ResponseHelper::notFound(
                'messages.claim_reasons.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $result = $this->service->deleteReason($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claim_reasons.deleted',
                $result
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                $e->getMessage(),
                400
            );
        }
    }

    /**
     * 클래임 사유 상태 토글
     *
     * @param int $id 사유 ID
     * @return JsonResponse
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $reason = $this->service->toggleStatus($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.claim_reasons.toggled',
                new ClaimReasonResource($reason)
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
     * 사용자 선택 가능한 클래임 사유 목록 (User API용)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function userSelectableReasons(Request $request): JsonResponse
    {
        $type = $request->query('type', 'refund');
        $reasons = $this->service->getUserSelectableReasons($type);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.claim_reasons.list_retrieved',
            new ClaimReasonCollection($reasons)
        );
    }
}
