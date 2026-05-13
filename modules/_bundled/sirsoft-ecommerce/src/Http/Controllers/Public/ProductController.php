<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicProductListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicProductNewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicProductPopularRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Public\PublicProductRecentRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicProductResource;
use Modules\Sirsoft\Ecommerce\Services\ProductService;

/**
 * 공개 상품 컨트롤러
 *
 * 비로그인 사용자도 접근할 수 있는 상품 목록 및 상세 조회 API를 제공합니다.
 */
class ProductController extends PublicBaseController
{
    public function __construct(
        private ProductService $productService
    ) {}

    /**
     * 공개 상품 목록을 조회합니다.
     *
     * 전시상태가 visible이고, 판매상태가 on_sale 또는 coming_soon인 상품만 반환합니다.
     * 정렬: latest(최신순), sales(판매순), price_asc(가격낮은순), price_desc(가격높은순)
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 상품 목록을 포함한 JSON 응답
     */
    public function index(PublicProductListRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('products.index');

            $validated = $request->validated();
            $filters = array_filter($validated, fn ($v, $k) => $k !== 'per_page', ARRAY_FILTER_USE_BOTH);

            $perPage = (int) ($validated['per_page'] ?? 20);
            $paginator = $this->productService->getPublicList($filters, $perPage);

            $collection = new ProductCollection($paginator);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                $collection->toArray($request)
            );
        } catch (Exception $e) {

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.fetch_failed',
                500
            );
        }
    }

    /**
     * 인기 상품 목록을 조회합니다.
     *
     * 최근 30일 판매량 기준으로 정렬합니다.
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 인기 상품 목록을 포함한 JSON 응답
     */
    public function popular(PublicProductPopularRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('products.popular');

            $limit = (int) ($request->validated('limit') ?? 10);
            $products = $this->productService->getPopularProducts($limit);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                ProductListResource::collection($products)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.fetch_failed',
                500
            );
        }
    }

    /**
     * 신상품 목록을 조회합니다.
     *
     * 최신 등록순으로 정렬합니다.
     *
     * @param Request $request 요청 데이터
     * @return JsonResponse 신상품 목록을 포함한 JSON 응답
     */
    public function new(PublicProductNewRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('products.new');

            $limit = (int) ($request->validated('limit') ?? 10);
            $products = $this->productService->getNewProducts($limit);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                ProductListResource::collection($products)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.fetch_failed',
                500
            );
        }
    }

    /**
     * 최근 본 상품 목록을 조회합니다.
     *
     * 클라이언트가 전달한 상품 ID 목록으로 조회합니다.
     *
     * @param Request $request 요청 데이터 (ids: 쉼표 구분 상품 ID)
     * @return JsonResponse 상품 목록을 포함한 JSON 응답
     */
    public function recent(PublicProductRecentRequest $request): JsonResponse
    {
        try {
            $this->logApiUsage('products.recent');

            $idsParam = $request->validated('ids') ?? '';
            $ids = array_filter(array_map('intval', explode(',', $idsParam)));

            if (empty($ids)) {
                return ResponseHelper::moduleSuccess(
                    'sirsoft-ecommerce',
                    'messages.products.fetch_success',
                    []
                );
            }

            $products = $this->productService->getProductsByIds($ids);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                ProductListResource::collection($products)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.fetch_failed',
                500
            );
        }
    }

    /**
     * 공개 상품 상세 정보를 조회합니다.
     *
     * 전시상태가 visible인 상품만 조회할 수 있습니다.
     *
     * @param int $id 상품 ID
     * @return JsonResponse 상품 상세 정보를 포함한 JSON 응답
     */
    public function show(int $id): JsonResponse
    {
        try {
            $this->logApiUsage('products.show', ['product_id' => $id]);

            $product = $this->productService->getDetail($id);

            if (! $product || $product->display_status->value !== 'visible') {
                return ResponseHelper::notFound(
                    'messages.products.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            // 공개 상세 페이지용 추가 관계 로드
            $product->loadMissing([
                'shippingPolicy',
                'notice',
                'commonInfo',
                'brand',
                'activeLabelAssignments.label',
                'additionalOptions',
                'currentUserWishlist',
            ]);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                new PublicProductResource($product)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.fetch_failed',
                500
            );
        }
    }
}
