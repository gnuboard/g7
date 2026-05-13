<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyBulkDeleteRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyBulkToggleActiveRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ShippingPolicyListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingPolicyCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ShippingPolicyResource;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;

/**
 * 배송정책 관리 컨트롤러
 */
class ShippingPolicyController extends AdminBaseController
{
    public function __construct(
        private ShippingPolicyService $shippingPolicyService
    ) {}

    /**
     * 배송정책 목록 조회
     *
     * @param ShippingPolicyListRequest $request
     * @return JsonResponse
     */
    public function index(ShippingPolicyListRequest $request): JsonResponse
    {
        $shippingPolicies = $this->shippingPolicyService->getList($request->validated());
        $statistics = $this->shippingPolicyService->getStatistics();

        $collection = new ShippingPolicyCollection($shippingPolicies);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.list_retrieved',
            $collection->withStatistics($statistics)
        );
    }

    /**
     * 배송정책 생성
     *
     * @param StoreShippingPolicyRequest $request
     * @return JsonResponse
     */
    public function store(StoreShippingPolicyRequest $request): JsonResponse
    {
        try {
            $shippingPolicy = $this->shippingPolicyService->create($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.created',
                new ShippingPolicyResource($shippingPolicy),
                201
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송정책 수정
     *
     * @param UpdateShippingPolicyRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateShippingPolicyRequest $request, int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updated = $this->shippingPolicyService->update($shippingPolicy, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.updated',
                new ShippingPolicyResource($updated)
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 배송정책 상세 조회
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.retrieved',
            new ShippingPolicyResource($shippingPolicy)
        );
    }

    /**
     * 배송정책 삭제
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $this->shippingPolicyService->delete($shippingPolicy);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.deleted'
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
     * 배송정책 사용여부 토글
     *
     * @param int $id
     * @return JsonResponse
     */
    public function toggleActive(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updatedPolicy = $this->shippingPolicyService->toggleActive($shippingPolicy);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.toggled',
                new ShippingPolicyResource($updatedPolicy)
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
     * 배송정책 일괄 삭제
     *
     * @param ShippingPolicyBulkDeleteRequest $request
     * @return JsonResponse
     */
    public function bulkDestroy(ShippingPolicyBulkDeleteRequest $request): JsonResponse
    {
        try {
            $count = $this->shippingPolicyService->bulkDelete($request->validated()['ids']);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.bulk_deleted',
                ['deleted_count' => $count]
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
     * 배송정책 일괄 사용여부 변경
     *
     * @param ShippingPolicyBulkToggleActiveRequest $request
     * @return JsonResponse
     */
    public function bulkToggleActive(ShippingPolicyBulkToggleActiveRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $count = $this->shippingPolicyService->bulkToggleActive(
                $validated['ids'],
                $validated['is_active']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.bulk_toggled',
                ['updated_count' => $count]
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
     * 활성화된 배송정책 목록 조회 (Select 옵션용)
     *
     * @return JsonResponse
     */
    public function activeList(): JsonResponse
    {
        $shippingPolicies = $this->shippingPolicyService->getActiveList();

        $options = $shippingPolicies->map(fn ($policy) => [
            'value' => $policy->id,
            'label' => $policy->getLocalizedName(),
            'countries_display' => $policy->getCountriesWithFlags(),
            'fee_summary' => $policy->getFeeSummary(),
            'is_default' => $policy->is_default,
        ]);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.shipping_policy.active_list_retrieved',
            $options
        );
    }

    /**
     * 기본 배송정책 설정
     *
     * @param int $id
     * @return JsonResponse
     */
    public function setDefault(int $id): JsonResponse
    {
        $shippingPolicy = $this->shippingPolicyService->getDetail($id);

        if (! $shippingPolicy) {
            return ResponseHelper::notFound(
                'messages.shipping_policy.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        try {
            $updatedPolicy = $this->shippingPolicyService->setDefault($shippingPolicy);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.shipping_policy.set_default_success',
                new ShippingPolicyResource($updatedPolicy)
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
