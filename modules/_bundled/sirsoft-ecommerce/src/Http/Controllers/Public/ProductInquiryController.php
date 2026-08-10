<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\ProductInquiryListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\StoreInquiryRequest;
use Modules\Sirsoft\Ecommerce\Models\Product;
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
     * @param  ProductInquiryListRequest  $request  목록 조회 요청 (페이지네이션 상·하한 검증)
     * @param  Product  $product  라우트 바인딩된 상품 (product_code 또는 id)
     * @return JsonResponse 문의 목록 및 board_settings 메타 JSON 응답
     */
    public function index(ProductInquiryListRequest $request, Product $product): JsonResponse
    {
        try {
            $this->logApiUsage('inquiry.index');
            $validated = $request->validated();
            $perPage = (int) ($validated['per_page'] ?? 10);
            $page = (int) ($validated['page'] ?? 1);
            $excludeSecret = filter_var($validated['exclude_secret'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $result = $this->inquiryService->getProductInquiries($product->id, $perPage, $page, $excludeSecret);

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
     * @param  Product  $product  라우트 바인딩된 상품 (product_code 또는 id)
     * @return JsonResponse 작성된 문의 JSON 응답
     */
    public function store(StoreInquiryRequest $request, Product $product): JsonResponse
    {
        try {
            $this->logApiUsage('inquiry.store');
            $inquiry = $this->inquiryService->createInquiry($product->id, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.inquiries.created',
                ['id' => $inquiry->id],
                201
            );
        } catch (\RuntimeException $e) {
            // 실패 사유(문의 게시판 미설정·게시판 모듈 불가 등)를 그대로 보여준다.
            // 일반 문구만 남기면 서버 기록을 봐야만 원인을 알 수 있다 — 같은 기능의
            // 수정·답변 경로는 이미 사유를 노출하고 있어 안내 수준을 맞춘다.
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.inquiries.operation_failed_reason',
                422,
                null,
                ['reason' => $e->getMessage()]
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
