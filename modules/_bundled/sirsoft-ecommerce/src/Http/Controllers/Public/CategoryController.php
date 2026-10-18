<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Public;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicCategoryDetailResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\PublicCategoryResource;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;

/**
 * 공개 카테고리 컨트롤러
 *
 * 비로그인 사용자도 접근할 수 있는 카테고리 트리 조회 API를 제공합니다.
 */
class CategoryController extends PublicBaseController
{
    public function __construct(
        private CategoryService $categoryService
    ) {}

    /**
     * 공개 카테고리 트리를 조회합니다.
     *
     * 활성 카테고리만 트리 구조로 반환하며, 각 카테고리의 공개 상품 수를 포함합니다.
     *
     * @return JsonResponse 카테고리 트리를 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $this->logApiUsage('categories.index');

            $categories = $this->categoryService->getPublicCategoryTree();

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.categories.list_retrieved',
                PublicCategoryResource::collection($categories)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.categories.fetch_failed',
                500
            );
        }
    }

    /**
     * 단일 카테고리 정보와 직계 자식을 조회합니다.
     *
     * slug로 카테고리를 조회하며, 활성 자식 카테고리와 브레드크럼을 포함합니다.
     *
     * @param string $slug 카테고리 slug
     * @return JsonResponse 카테고리 정보를 포함한 JSON 응답
     */
    public function show(string $slug): JsonResponse
    {
        try {
            $this->logApiUsage('categories.show', ['slug' => $slug]);

            $category = $this->categoryService->getPublicCategoryBySlug($slug);

            if (! $category) {
                return ResponseHelper::notFound(
                    'messages.categories.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.categories.fetch_success',
                new PublicCategoryDetailResource($category)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.categories.fetch_failed',
                500
            );
        }
    }
}
