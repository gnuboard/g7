<?php

// audit:allow api-doc-coverage 609 후속 — downloadImage 응답 판정을 코어 파일 서빙 단일 지점으로 옮겨 조건부 요청 304 와 ETag, Last-Modified, Cache-Control 헤더가 더해졌다(라우트, 요청은 그대로). 이 관리자 경로는 공개 카테고리 이미지와 같은 서빙 경로를 쓰며, 그 캐시 계약과 304 는 docs/api/category-image.md 가 이미 다룬다.

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Sirsoft\Ecommerce\Exceptions\CategoryOperationException;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 카테고리 관리 컨트롤러
 */
class CategoryController extends AdminBaseController
{
    /**
     * 카테고리 서비스와 카테고리 이미지 서비스를 주입받는다.
     *
     * @param  CategoryService  $categoryService  카테고리 서비스
     * @param  CategoryImageService  $categoryImageService  카테고리 이미지 서비스
     */
    public function __construct(
        private CategoryService $categoryService,
        private CategoryImageService $categoryImageService
    ) {}

    /**
     * 카테고리 목록 조회
     *
     * @param  CategoryListRequest  $request  목록 조회 필터/정렬 조건
     * @return JsonResponse 카테고리 목록 응답
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
     * @return JsonResponse 활성 카테고리 트리 응답
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
     * @param  CreateCategoryRequest  $request  카테고리 생성 데이터
     * @return JsonResponse 생성된 카테고리 응답
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
     * @param  int  $id  카테고리 ID
     * @return JsonResponse 카테고리 상세 응답
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
     * @param  UpdateCategoryRequest  $request  카테고리 수정 데이터
     * @param  int  $category  카테고리 ID
     * @return JsonResponse 수정된 카테고리 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
     * 카테고리 삭제
     *
     * @param  int  $category  카테고리 ID
     * @return JsonResponse 삭제 결과 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
     * 카테고리 이미지 업로드
     *
     * @param  UploadCategoryImageRequest  $request  이미지 업로드 데이터
     * @param  int|null  $categoryId  카테고리 ID (임시 업로드 시 null)
     * @return JsonResponse 업로드된 이미지 정보 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
     * 카테고리 이미지 삭제
     *
     * @param  int  $id  이미지 ID
     * @return JsonResponse 삭제 결과 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
     * 카테고리 이미지 순서 변경
     *
     * @param  ReorderCategoryImagesRequest  $request  이미지 순서 데이터
     * @return JsonResponse 순서 변경 결과 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
     * 카테고리 이미지 다운로드
     *
     * @param  string  $hash  이미지 해시
     * @return StreamedResponse|Response|JsonResponse 이미지 스트림, 304 또는 404 응답
     */
    public function downloadImage(string $hash): StreamedResponse|Response|JsonResponse
    {
        // thumbnail-exempt: 라우트 없는 관리 내부 메서드 — 공개 서빙은 Public\CategoryImageController
        try {
            // 서빙은 서비스의 StorageInterface::response() 스트리밍에 위임한다.
            // (파일 전체를 메모리에 적재하는 streamDownload+Storage::get 안티패턴 금지)
            $info = $this->categoryImageService->getFileInfo($hash);

            if (! $info) {
                return ResponseHelper::notFound(
                    'messages.category_images.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            // 캐시 계약(검증자 비교·환경별 Cache-Control·304 의 200 헤더 동반)과 메타 키 검증은
            // 코어 단일 지점(`streamedFileResponseFromMeta()`)이 소유한다 — 종전의 컨트롤러별 사본을
            // 접었다 (14차 → 16차 G3 라운드 E-7). `immutable` 은 `false` 고정(이 URL 에는 `?v=` 가 없다),
            // 압축은 선언으로 제외한다(사용자 업로드물 — 형제 `PublicStorageController` 와 같은 선언).
            // 만료 기간은 서비스 메타(`max_age`)가 정한다.
            return $this->streamedFileResponseFromMeta(
                $info,
                null,
                immutable: false,
                skipCompression: true,
                logContext: ['hash' => $hash]
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
     * 카테고리 상태 토글
     *
     * @param  int  $id  카테고리 ID
     * @return JsonResponse 상태 변경 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
     * 파일 크기를 사람이 읽기 쉬운 형식으로 변환
     *
     * @param  int  $bytes  바이트 단위 파일 크기
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
     * @param  ReorderCategoriesRequest  $request  카테고리 순서 데이터
     * @return JsonResponse 순서 변경 결과 응답
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
        } catch (CategoryOperationException $e) {
            // 도메인 규칙 위반 — 기존 400 유지. 구체 사유(미존재/하위·상품 연결)를
            // 전용 키로 안내한다 (형제 Brand/ClaimReason destroy 와 동형).
            $messageKey = $e->getMessageKey();

            return ResponseHelper::error(
                $messageKey,
                400,
                null,
                $e->getMessageParams()
            );
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
