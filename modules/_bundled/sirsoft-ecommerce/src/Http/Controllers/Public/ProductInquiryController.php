<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\StoreInquiryRequest;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;

/**
 * 상품 1:1 문의 컨트롤러 (공개)
 *
 * 문의 목록 조회는 비회원도 접근 가능하며, 문의 작성은 인증 필요합니다.
 * 문의 작성 시 게시판 모듈과의 연동은 Service 계층에서 처리합니다.
 */
class ProductInquiryController extends PublicBaseController
{
    public function __construct(
        private ProductInquiryService $inquiryService
    ) {}

    /**
     * 상품 문의 목록 조회
     *
     * @param  int  $productId  상품 ID
     * @return JsonResponse 문의 목록 및 board_settings 메타 JSON 응답
     */
    public function index(int $productId): JsonResponse
    {
        try {
            $this->logApiUsage('inquiry.index');
            $perPage = (int) (request()->query('per_page', 10));
            $page = (int) (request()->query('page', 1));
            $excludeSecret = filter_var(request()->query('exclude_secret', false), FILTER_VALIDATE_BOOLEAN);

            $result = $this->inquiryService->getProductInquiries($productId, $perPage, $page, $excludeSecret);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.inquiries.fetch_success',
                $result
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.fetch_failed',
                500
            );
        }
    }

    /**
     * 상품 문의 작성
     *
     * @param  StoreInquiryRequest  $request  문의 작성 요청
     * @param  int  $productId  상품 ID
     * @return JsonResponse 작성된 문의 JSON 응답
     */
    public function store(StoreInquiryRequest $request, int $productId): JsonResponse
    {
        try {
            $this->logApiUsage('inquiry.store');
            $inquiry = $this->inquiryService->createInquiry($productId, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.inquiries.created',
                ['id' => $inquiry->id],
                201
            );
        } catch (\RuntimeException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.create_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.create_failed',
                500
            );
        }
    }
}
