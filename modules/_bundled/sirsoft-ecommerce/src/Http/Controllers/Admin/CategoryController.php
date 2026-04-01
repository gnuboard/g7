<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CategoryListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\CreateCategoryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ReorderCategoriesRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ReorderCategoryImagesRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateCategoryRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadCategoryImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\CategoryCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\CategoryResource;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;

/**
 * 카테고리 관리 컨트롤러
 */
class CategoryController extends AdminBaseController
{
    public function __construct(
        private CategoryService $categoryService,
        private CategoryImageService $categoryImageService
    ) {}

    /**
     * 카테고리 목록 조회
     *
     * @param CategoryListRequest $request
     * @return JsonResponse
     */
    public function index(CategoryListRequest $request): JsonResponse
    {
        $categories = $this->categoryService->getHierarchicalCategories($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.categories.list_retrieved',
            new CategoryCollection($categories)
        );
    }

    /**
     * 카테고리 트리 조회 (상품 등록 폼용)
     *
     * 활성화된 카테고리만 트리 형태로 반환합니다.
     *
     * @return JsonResponse
     */
    public function tree(): JsonResponse
    {
        $categories = $this->categoryService->getHierarchicalCategories([
            'hierarchical' => true,
            'is_active' => true,
        ]);

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.categories.list_retrieved',
            new CategoryCollection($categories)
        );
    }

    /**
     * 카테고리 생성
     *
     * @param CreateCategoryRequest $request
     * @return JsonResponse
     */
    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->createCategory($request->validated());

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.categories.created',
            new CategoryResource($category),
            201
        );
    }

    /**
     * 카테고리 상세 조회
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $category = $this->categoryService->getCategory($id);

        if (! $category) {
            return ResponseHelper::notFound(
                'messages.categories.not_found',
                [],
                'sirsoft-ecommerce'
            );
        }

        return ResponseHelper::moduleSuccess(
            'sirsoft-ecommerce',
            'messages.categories.retrieved',
            new CategoryResource($category)
        );
    }

    /**
     * 카테고리 수정
     *
     * @param UpdateCategoryRequest $request
     * @param int $category
     * @return JsonResponse
     */
    public function update(UpdateCategoryRequest $request, int $category): JsonResponse
    {
        try {
            $updatedCategory = $this->categoryService->updateCategory($category, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.categories.updated',
                new CategoryResource($updatedCategory)
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
     * 카테고리 삭제
     *
     * @param int $category
     * @return JsonResponse
     */
    public function destroy(int $category): JsonResponse
    {
        try {
            $result = $this->categoryService->deleteCategory($category);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.categories.deleted',
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

    /**
     * 카테고리 이미지 업로드
     *
     * @param UploadCategoryImageRequest $request
     * @param int|null $categoryId
     * @return JsonResponse
     */
    public function uploadImage(UploadCategoryImageRequest $request, ?int $categoryId = null): JsonResponse
    {
        try {
            $validated = $request->validated();

            $image = $this->categoryImageService->upload(
                file: $validated['file'],
                categoryId: $categoryId,
                collection: $validated['collection'] ?? 'main',
                tempKey: $validated['temp_key'] ?? null,
                altText: $validated['alt_text'] ?? null
            );

            // FileUploader 컴포넌트가 response.data?.data 형식을 기대하므로
            // data 키 안에 한 번 더 감싸서 반환
            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.category_images.uploaded',
                [
                    'data' => [
                        'id' => $image->id,
                        'hash' => $image->hash,
                        'original_filename' => $image->original_filename,
                        'mime_type' => $image->mime_type,
                        'size' => $image->file_size,
                        'size_formatted' => $this->formatFileSize($image->file_size),
                        'download_url' => $image->download_url,
                        'order' => $image->sort_order ?? 1,
                        'is_image' => str_starts_with($image->mime_type, 'image/'),
                    ],
                ],
                201
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
     * 카테고리 이미지 삭제
     *
     * @param int $id
     * @return JsonResponse
     */
    public function deleteImage(int $id): JsonResponse
    {
        try {
            $result = $this->categoryImageService->delete($id);

            if (! $result) {
                return ResponseHelper::notFound(
                    'messages.category_images.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.category_images.deleted'
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
     * 카테고리 이미지 순서 변경
     *
     * @param ReorderCategoryImagesRequest $request
     * @return JsonResponse
     */
    public function reorderImages(ReorderCategoryImagesRequest $request): JsonResponse
    {
        try {
            $this->categoryImageService->reorder(
                collect($request->validated('order'))->pluck('order', 'id')->toArray()
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.category_images.reordered'
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
     * 카테고리 이미지 다운로드
     *
     * @param string $hash
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
     */
    public function downloadImage(string $hash)
    {
        try {
            $image = $this->categoryImageService->getByHash($hash);

            if (! $image) {
                return ResponseHelper::notFound(
                    'messages.category_images.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return response()->streamDownload(function () use ($image) {
                echo Storage::disk($image->disk)->get($image->path);
            }, $image->original_filename, [
                'Content-Type' => $image->mime_type,
            ]);
        } catch (\Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 카테고리 상태 토글
     *
     * @param int $id
     * @return JsonResponse
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $category = $this->categoryService->toggleStatus($id);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.categories.status_changed',
                new CategoryResource($category)
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
     * 파일 크기를 사람이 읽기 쉬운 형식으로 변환
     *
     * @param int $bytes 바이트 단위 파일 크기
     * @return string 포맷된 파일 크기 (예: "1.5 MB")
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }

    /**
     * 카테고리 순서 변경
     *
     * SortableMenuList 컴포넌트에서 전송하는 데이터 형식:
     * {
     *   "parent_menus": [{ "id": 1, "order": 1 }, ...],
     *   "child_menus": { "1": [{ "id": 2, "order": 1 }, ...] }
     * }
     *
     * @param ReorderCategoriesRequest $request
     * @return JsonResponse
     */
    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $parentMenus = $validated['parent_menus'] ?? [];
            $childMenus = $validated['child_menus'] ?? [];

            // SortableMenuList 형식을 Service가 기대하는 형식으로 변환
            $orders = [];

            // 부모 메뉴 순서 처리
            foreach ($parentMenus as $menu) {
                $orders[] = [
                    'id' => $menu['id'],
                    'parent_id' => null,
                    'sort_order' => $menu['order'],
                ];
            }

            // 자식 메뉴 순서 처리
            foreach ($childMenus as $parentId => $children) {
                foreach ($children as $child) {
                    $orders[] = [
                        'id' => $child['id'],
                        'parent_id' => (int) $parentId,
                        'sort_order' => $child['order'],
                    ];
                }
            }

            $this->categoryService->reorder($orders);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.categories.order_updated'
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
