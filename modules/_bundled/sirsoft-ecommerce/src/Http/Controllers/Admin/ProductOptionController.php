<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOptionPriceRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOptionsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateOptionStockRequest;
use Modules\Sirsoft\Ecommerce\Services\ProductOptionService;

/**
 * 상품 옵션 관리 컨트롤러
 *
 * 관리자가 상품 옵션을 관리할 수 있는 기능을 제공합니다.
 */
class ProductOptionController extends AdminBaseController
{
    public function __construct(
        private ProductOptionService $optionService
    ) {}

    /**
     * 선택한 상품/옵션의 판매가를 일괄 변경합니다.
     *
     * product_ids: 상품 ID 배열 (해당 상품의 모든 옵션 대상)
     * option_ids: "productId-optionId" 형식의 문자열 배열 (개별 선택된 옵션)
     *
     * @param BulkUpdateOptionPriceRequest $request 일괄 가격 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdatePrice(BulkUpdateOptionPriceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->optionService->bulkUpdatePriceByMixedIds(
                $validated['product_ids'] ?? [],
                $validated['option_ids'] ?? [],
                $validated['method'],
                $validated['value'],
                $validated['unit']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.options.bulk_price_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.options.bulk_price_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.options.bulk_price_update_failed',
                500
            );
        }
    }

    /**
     * 선택한 상품/옵션의 재고를 일괄 변경합니다.
     *
     * product_ids: 상품 ID 배열 (해당 상품의 모든 옵션 대상)
     * option_ids: "productId-optionId" 형식의 문자열 배열 (개별 선택된 옵션)
     *
     * @param BulkUpdateOptionStockRequest $request 일괄 재고 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdateStock(BulkUpdateOptionStockRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->optionService->bulkUpdateStockByMixedIds(
                $validated['product_ids'] ?? [],
                $validated['option_ids'] ?? [],
                $validated['method'],
                $validated['value']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.options.bulk_stock_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.options.bulk_stock_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.options.bulk_stock_update_failed',
                500
            );
        }
    }

    /**
     * 옵션을 통합 일괄 업데이트합니다.
     *
     * 상품 미체크 + 옵션만 체크된 경우에 사용됩니다.
     * 일괄 변경(bulk_changes)과 개별 인라인 수정(items)을 동시 처리합니다.
     * 일괄 변경 조건이 설정된 필드는 우선 적용되며, 나머지는 개별 수정이 적용됩니다.
     *
     * @param BulkUpdateOptionsRequest $request 통합 업데이트 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdate(BulkUpdateOptionsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->optionService->bulkUpdate($validated);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.options.bulk_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.options.bulk_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.options.bulk_update_failed',
                500
            );
        }
    }
}
