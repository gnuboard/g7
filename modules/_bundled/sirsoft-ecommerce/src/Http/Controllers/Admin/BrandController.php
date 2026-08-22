<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Exceptions\BrandOperationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BrandListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBrandRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateBrandRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\BrandCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\BrandResource;
use Modules\Sirsoft\Ecommerce\Services\BrandService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
     * @param  BrandListRequest  $request
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
     * @param  StoreBrandRequest  $request
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
     * @param  int  $id
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
     * @param  UpdateBrandRequest  $request
     * @param  int  $brand
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
        } catch (BrandOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/상품 연결)를 전용
            // 키로 안내한다 — generic 키로 접으면 운영자가 원인을 알 수 없다
            // (형제 ClaimReason/ShippingCarrier/ProductLabel destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 브랜드 상태 토글
     *
     * @param  int  $brand
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
        } catch (BrandOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/상품 연결)를 전용
            // 키로 안내한다 — generic 키로 접으면 운영자가 원인을 알 수 없다
            // (형제 ClaimReason/ShippingCarrier/ProductLabel destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }

    /**
     * 브랜드 삭제
     *
     * @param  int  $brand
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
        } catch (BrandOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/상품 연결)를 전용
            // 키로 안내한다 — generic 키로 접으면 운영자가 원인을 알 수 없다
            // (형제 ClaimReason/ShippingCarrier/ProductLabel destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
        } catch (AccessDeniedHttpException $e) {
            return ResponseHelper::forbidden('auth.scope_denied');
        } catch (\Exception $e) {
            // 서버 결함/인프라 장애 — 4xx 로 뭉개면 장애가 입력 오류로 위장된다
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                500
            );
        }
    }
}
