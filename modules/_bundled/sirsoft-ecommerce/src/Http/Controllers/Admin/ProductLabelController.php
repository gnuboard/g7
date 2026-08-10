<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductLabelListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductLabelStoreRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductLabelUpdateRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductLabelCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductLabelResource;
use Modules\Sirsoft\Ecommerce\Services\ProductLabelService;

/**
 * 상품 라벨 관리 컨트롤러
 */
class ProductLabelController extends AdminBaseController
{
    public function __construct(
        private ProductLabelService $service
    ) {}

    /**
     * 라벨 목록 조회
     *
     * @param ProductLabelListRequest $request
     * @return JsonResponse
     */
    public function index(ProductLabelListRequest $request): JsonResponse
    {
        $filters = $request->validated();

        // active_only 필터 처리 (기존 호환성 유지)
        if (!empty($filters['active_only'])) {
            $filters['is_active'] = true;
        }

        $labels = $this->service->getAllLabels($filters);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.labels.fetch_success',
            new ProductLabelCollection($labels)
        );
    }

    /**
     * 라벨 상세 조회
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $label = $this->service->getLabel($id);

        if (!$label) {
            return ResponseHelper::notFound(
                'messages.labels.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.labels.retrieved',
            new ProductLabelResource($label)
        );
    }

    /**
     * 라벨 생성
     *
     * @param ProductLabelStoreRequest $request
     * @return JsonResponse
     */
    public function store(ProductLabelStoreRequest $request): JsonResponse
    {
        $label = $this->service->createLabel($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.labels.create_success',
            new ProductLabelResource($label),
            201
        );
    }

    /**
     * 라벨 수정
     *
     * @param ProductLabelUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(ProductLabelUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $label = $this->service->updateLabel($id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.labels.update_success',
                new ProductLabelResource($label)
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::notFound(
                'messages.labels.not_found',
                [],
                'sirsoft-ecommerce'
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.labels.update_failed',
                400
            );
        }
    }

    /**
     * 라벨 상태 토글
     *
     * @param int $id
     * @return JsonResponse
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $label = $this->service->toggleStatus($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.labels.status_changed',
                new ProductLabelResource($label)
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::notFound(
                'messages.labels.not_found',
                [],
                'sirsoft-ecommerce'
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.labels.update_failed',
                400
            );
        }
    }

    /**
     * 라벨 삭제
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $result = $this->service->deleteLabel($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.labels.delete_success',
                $result
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::notFound(
                'messages.labels.not_found',
                [],
                'sirsoft-ecommerce'
            );
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.labels.delete_failed',
                400
            );
        }
    }
}
