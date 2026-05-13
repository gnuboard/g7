<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductCommonInfoRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductCommonInfoRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductCommonInfoCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductCommonInfoResource;
use Modules\Sirsoft\Ecommerce\Services\ProductCommonInfoService;

/**
 * 공통정보 컨트롤러
 *
 * 관리자가 상품 공통정보를 관리할 수 있는 기능을 제공합니다.
 */
class ProductCommonInfoController extends AdminBaseController
{
    public function __construct(
        private ProductCommonInfoService $commonInfoService
    ) {}

    /**
     * 공통정보 목록을 조회합니다.
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 공통정보 목록 JSON 응답
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->get('search'),
            'is_active' => $request->boolean('active_only', false) ? true : null,
            'is_default' => $request->has('default_only') && $request->boolean('default_only') ? true : null,
        ];

        // null 값 제거
        $filters = array_filter($filters, fn ($v) => $v !== null);

        // 페이지네이션 파라미터
        $perPage = (int) $request->get('per_page', 20);

        // per_page가 0 이하이거나 all이면 전체 조회
        if ($perPage <= 0 || $request->get('per_page') === 'all') {
            $commonInfos = $this->commonInfoService->getAllCommonInfos($filters);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.common_infos.fetch_success',
                new ProductCommonInfoCollection($commonInfos)
            );
        }

        // 페이지네이션 조회
        $commonInfos = $this->commonInfoService->getPaginatedCommonInfos($filters, $perPage);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.common_infos.fetch_success',
            new ProductCommonInfoCollection($commonInfos)
        );
    }

    /**
     * 공통정보 상세를 조회합니다.
     *
     * @param int $id 공통정보 ID
     * @return JsonResponse 공통정보 상세 JSON 응답
     */
    public function show(int $id): JsonResponse
    {
        $commonInfo = $this->commonInfoService->getCommonInfo($id);

        if (! $commonInfo) {
            return ResponseHelper::notFound(
                'messages.common_infos.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.common_infos.fetch_success',
            new ProductCommonInfoResource($commonInfo)
        );
    }

    /**
     * 공통정보를 생성합니다.
     *
     * @param StoreProductCommonInfoRequest $request 생성 요청
     * @return JsonResponse 생성된 공통정보 JSON 응답
     */
    public function store(StoreProductCommonInfoRequest $request): JsonResponse
    {
        try {
            $commonInfo = $this->commonInfoService->createCommonInfo($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.common_infos.created',
                new ProductCommonInfoResource($commonInfo),
                201
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 공통정보를 수정합니다.
     *
     * @param UpdateProductCommonInfoRequest $request 수정 요청
     * @param int $id 공통정보 ID
     * @return JsonResponse 수정된 공통정보 JSON 응답
     */
    public function update(UpdateProductCommonInfoRequest $request, int $id): JsonResponse
    {
        try {
            $commonInfo = $this->commonInfoService->updateCommonInfo($id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.common_infos.updated',
                new ProductCommonInfoResource($commonInfo)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 공통정보를 삭제합니다.
     *
     * @param int $id 공통정보 ID
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $result = $this->commonInfoService->deleteCommonInfo($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.common_infos.deleted',
                $result
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 공통정보 사용 여부를 토글합니다.
     *
     * @param int $id 공통정보 ID
     * @return JsonResponse 토글된 공통정보 JSON 응답
     */
    public function toggleActive(int $id): JsonResponse
    {
        try {
            $commonInfo = $this->commonInfoService->toggleActive($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                $commonInfo->is_active
                    ? 'messages.common_infos.activated'
                    : 'messages.common_infos.deactivated',
                new ProductCommonInfoResource($commonInfo)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }
}
