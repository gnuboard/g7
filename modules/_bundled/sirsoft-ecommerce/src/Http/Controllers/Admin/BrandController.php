<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BrandListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBrandRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateBrandRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\BrandCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\BrandResource;
use Modules\Sirsoft\Ecommerce\Services\BrandService;

/**
 * 브랜드 관리 컨트롤러
 */
class BrandController extends AdminBaseController
{
    public function __construct(
        private BrandService $brandService
    ) {}

    /**
     * 브랜드 목록 조회
     *
     * @param BrandListRequest $request
     * @return JsonResponse
     */
    public function index(BrandListRequest $request): JsonResponse
    {
        $brands = $this->brandService->getAllBrands($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.brands.list_retrieved',
            new BrandCollection($brands)
        );
    }

    /**
     * 브랜드 생성
     *
     * @param StoreBrandRequest $request
     * @return JsonResponse
     */
    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->brandService->createBrand($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.brands.created',
            new BrandResource($brand),
            201
        );
    }

    /**
     * 브랜드 상세 조회
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $brand = $this->brandService->getBrand($id);

        if (! $brand) {
            return ResponseHelper::notFound(
                'messages.brands.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.brands.retrieved',
            new BrandResource($brand)
        );
    }

    /**
     * 브랜드 수정
     *
     * @param UpdateBrandRequest $request
     * @param int $brand
     * @return JsonResponse
     */
    public function update(UpdateBrandRequest $request, int $brand): JsonResponse
    {
        try {
            $updatedBrand = $this->brandService->updateBrand($brand, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.brands.updated',
                new BrandResource($updatedBrand)
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
     * 브랜드 상태 토글
     *
     * @param int $brand
     * @return JsonResponse
     */
    public function toggleStatus(int $brand): JsonResponse
    {
        try {
            $updatedBrand = $this->brandService->toggleStatus($brand);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.brands.status_changed',
                new BrandResource($updatedBrand)
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
     * 브랜드 삭제
     *
     * @param int $brand
     * @return JsonResponse
     */
    public function destroy(int $brand): JsonResponse
    {
        try {
            $result = $this->brandService->deleteBrand($brand);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.brands.deleted',
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
}
