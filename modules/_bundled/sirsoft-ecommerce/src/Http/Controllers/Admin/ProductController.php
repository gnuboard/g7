<?php

namespace Modules\Sirsoft\Ecommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdatePriceRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateProductsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkUpdateStockRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ProductListRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreProductRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateProductRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ReorderProductImagesRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UploadProductImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductCollection;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductOptionResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductImageService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;

/**
 * 상품 관리 컨트롤러
 *
 * 관리자가 상품을 관리할 수 있는 기능을 제공합니다.
 */
class ProductController extends AdminBaseController
{
    public function __construct(
        private ProductService $productService,
        private ProductImageService $productImageService
    ) {}

    /**
     * 필터링된 상품 목록을 조회합니다.
     *
     * @param ProductListRequest $request 상품 목록 요청 데이터
     * @return JsonResponse 상품 목록과 통계 정보를 포함한 JSON 응답
     */
    public function index(ProductListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $products = $this->productService->getList($filters);
            $statistics = $this->productService->getStatistics();

            $collection = new ProductCollection($products);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                $collection->withStatistics($statistics)
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
     * 특정 상품의 상세 정보를 조회합니다.
     *
     * ID 또는 product_code로 조회할 수 있습니다.
     * - 숫자 ID: 먼저 ID로 조회 시도, 없으면 product_code로 조회
     * - product_code: product_code로 조회
     *
     * @param string $identifier 상품 ID 또는 product_code
     * @return JsonResponse 상품 상세 정보를 포함한 JSON 응답
     */
    public function show(string $identifier): JsonResponse
    {
        try {
            $product = $this->productService->findByIdOrCode($identifier);

            if (! $product) {
                return ResponseHelper::notFound(
                    'messages.products.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            $product = $this->productService->getDetail($product->id, includeInactive: true);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                new ProductResource($product)
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
     * 새로운 상품을 생성합니다.
     *
     * @param StoreProductRequest $request 상품 생성 요청 데이터
     * @return JsonResponse 생성된 상품 정보를 포함한 JSON 응답
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            $product = $this->productService->create($request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.created',
                new ProductResource($product),
                201
            );
        } catch (ValidationException $e) {
            Log::error('상품 등록 검증 실패', ['errors' => $e->errors()]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.create_failed',
                422,
                $e->errors()
            );
        } catch (Exception $e) {
            Log::error('상품 등록 실패', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.create_failed',
                500,
                config('app.debug') ? ['exception' => $e->getMessage()] : null
            );
        }
    }

    /**
     * 기존 상품 정보를 수정합니다.
     *
     * @param UpdateProductRequest $request 상품 수정 요청 데이터
     * @param Product $product 수정할 상품 모델
     * @return JsonResponse 수정된 상품 정보를 포함한 JSON 응답
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        try {
            $updatedProduct = $this->productService->update($product, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.updated',
                new ProductResource($updatedProduct)
            );
        } catch (ValidationException $e) {
            Log::error('상품 수정 검증 실패', ['product_id' => $product->id, 'errors' => $e->errors()]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.update_failed',
                422,
                $e->errors()
            );
        } catch (Exception $e) {
            Log::error('상품 수정 실패', [
                'product_id' => $product->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.update_failed',
                500,
                config('app.debug') ? ['exception' => $e->getMessage()] : null
            );
        }
    }

    /**
     * 상품 삭제 가능 여부를 확인합니다.
     *
     * 주문 이력이 있는 상품은 삭제할 수 없습니다.
     *
     * @param Product $product 확인할 상품 모델
     * @return JsonResponse 삭제 가능 여부 JSON 응답
     */
    public function canDelete(Product $product): JsonResponse
    {
        try {
            $result = $this->productService->checkCanDelete($product);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.can_delete_checked',
                $result
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.can_delete_check_failed',
                500
            );
        }
    }

    /**
     * 상품을 삭제합니다.
     *
     * @param Product $product 삭제할 상품 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(Product $product): JsonResponse
    {
        try {
            $productId = $product->id;
            $this->productService->delete($product);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.deleted',
                ['deleted' => true]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.delete_failed',
                500
            );
        }
    }

    /**
     * 여러 상품의 상태를 일괄 변경합니다.
     *
     * @param BulkUpdateStatusRequest $request 일괄 상태 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdateStatus(BulkUpdateStatusRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->productService->bulkUpdateStatus(
                $validated['ids'],
                $validated['field'],
                $validated['value']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.bulk_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_update_failed',
                500
            );
        }
    }

    /**
     * 여러 상품의 가격을 일괄 변경합니다.
     *
     * @param BulkUpdatePriceRequest $request 일괄 가격 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdatePrice(BulkUpdatePriceRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->productService->bulkUpdatePrice(
                $validated['ids'],
                $validated['method'],
                $validated['value'],
                $validated['unit']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.bulk_price_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_price_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_price_update_failed',
                500
            );
        }
    }

    /**
     * 여러 상품의 재고를 일괄 변경합니다.
     *
     * @param BulkUpdateStockRequest $request 일괄 재고 변경 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdateStock(BulkUpdateStockRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->productService->bulkUpdateStock(
                $validated['ids'],
                $validated['method'],
                $validated['value']
            );

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.bulk_stock_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_stock_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_stock_update_failed',
                500
            );
        }
    }

    /**
     * 상품 및 옵션을 통합 일괄 업데이트합니다.
     *
     * 일괄 변경(bulk_changes)과 개별 인라인 수정(items)을 동시 처리합니다.
     * 일괄 변경 조건이 설정된 필드는 우선 적용되며, 나머지는 개별 수정이 적용됩니다.
     *
     * @param BulkUpdateProductsRequest $request 통합 업데이트 요청 데이터
     * @return JsonResponse 변경 결과 JSON 응답
     */
    public function bulkUpdate(BulkUpdateProductsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->productService->bulkUpdate($validated);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.bulk_updated',
                $result
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.bulk_update_failed',
                500
            );
        }
    }

    /**
     * 신규 상품코드를 생성합니다.
     *
     * @return JsonResponse 생성된 상품코드 JSON 응답
     */
    public function generateCode(): JsonResponse
    {
        try {
            $code = $this->productService->generateUniqueCode();

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.code_generated',
                ['product_code' => $code]
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.code_generation_failed',
                500
            );
        }
    }

    /**
     * 상품코드로 상품을 조회합니다.
     *
     * @param string $itemCode 상품코드
     * @return JsonResponse 상품 정보 JSON 응답
     */
    public function showByCode(string $itemCode): JsonResponse
    {
        try {
            $product = $this->productService->findByCode($itemCode);

            if (! $product) {
                return ResponseHelper::notFound(
                    'messages.products.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                new ProductResource($product)
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
     * 상품코드로 상품을 수정합니다.
     *
     * @param UpdateProductRequest $request 상품 수정 요청 데이터
     * @param string $itemCode 상품코드
     * @return JsonResponse 수정된 상품 정보 JSON 응답
     */
    public function updateByCode(UpdateProductRequest $request, string $itemCode): JsonResponse
    {
        try {
            $product = $this->productService->findByCode($itemCode);

            if (! $product) {
                return ResponseHelper::notFound(
                    'messages.products.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            $updatedProduct = $this->productService->update($product, $request->validated());

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.updated',
                new ProductResource($updatedProduct)
            );
        } catch (ValidationException $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.update_failed',
                422
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.update_failed',
                500
            );
        }
    }

    /**
     * 폼 상세 데이터를 조회합니다.
     *
     * @param Product $product 조회할 상품 모델
     * @return JsonResponse 폼 상세 데이터 JSON 응답
     */
    public function showForForm(Product $product): JsonResponse
    {
        try {
            $formData = $this->productService->getDetailForForm($product->id);

            if (! $formData) {
                return ResponseHelper::notFound(
                    'messages.products.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                $formData
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
     * 상품 복사용 데이터를 조회합니다.
     *
     * @param Request $request HTTP 요청 (복사 옵션 포함)
     * @param Product $product 복사할 상품 모델
     * @return JsonResponse 복사용 데이터 JSON 응답
     */
    public function showForCopy(Request $request, Product $product): JsonResponse
    {
        try {
            $copyOptions = [
                'images' => $request->boolean('copy_images', true),
                'options' => $request->boolean('copy_options', true),
                'categories' => $request->boolean('copy_categories', true),
                'sales_info' => $request->boolean('copy_sales_info', true),
                'description' => $request->boolean('copy_description', true),
                'notice' => $request->boolean('copy_notice', true),
                'common_info' => $request->boolean('copy_common_info', true),
                'other_info' => $request->boolean('copy_other_info', true),
                'shipping' => $request->boolean('copy_shipping', true),
                'seo' => $request->boolean('copy_seo', false),
                'identification' => $request->boolean('copy_identification', true),
            ];

            $copyData = $this->productService->getDetailForCopy($product->id, $copyOptions);

            if (! $copyData) {
                return ResponseHelper::notFound(
                    'messages.products.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            // thumbnail_url 추가 (복제 모드 안내 박스용)
            $thumbnailImage = collect($copyData['images'] ?? [])->firstWhere('is_thumbnail', true)
                ?? collect($copyData['images'] ?? [])->first();
            $copyData['thumbnail_url'] = $thumbnailImage['download_url'] ?? null;

            // 카테고리 정보 추가 (breadcrumb 포함, UI 표시용)
            if (! empty($copyData['category_ids']) && ($copyOptions['categories'] ?? true)) {
                $product->load('categories');
                $copyData['categories'] = $product->categories->map(fn ($cat) => [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'name_localized' => $cat->getLocalizedName(),
                    'breadcrumb' => $cat->breadcrumb,
                    'path' => $cat->path,
                    'is_primary' => $cat->pivot->is_primary,
                ])->toArray();
            }

            // 옵션에 다중통화 가격 추가 (ProductOptionResource 활용)
            if (! empty($copyData['options']) && ($copyOptions['options'] ?? true)) {
                $productOptions = $product->options()->orderBy('sort_order')->get();
                $optionResources = ProductOptionResource::collection($productOptions)->resolve();

                foreach ($copyData['options'] as &$opt) {
                    $matchingResource = collect($optionResources)->firstWhere('option_code', $opt['option_code']);
                    if ($matchingResource) {
                        $opt['multi_currency_selling_price'] = $matchingResource['multi_currency_selling_price'] ?? [];
                        $opt['multi_currency_list_price'] = $matchingResource['multi_currency_list_price'] ?? [];
                    }
                }
                unset($opt);
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.fetch_success',
                $copyData
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
     * 상품 이미지 업로드
     *
     * @param  UploadProductImageRequest  $request  이미지 업로드 요청
     * @param  int|null  $productId  상품 ID (신규 등록 시 null)
     * @return JsonResponse
     */
    public function uploadImage(UploadProductImageRequest $request, ?int $productId = null): JsonResponse
    {
        try {
            $validated = $request->validated();

            $image = $this->productImageService->upload(
                file: $request->file('file'),
                productId: $productId,
                collection: $validated['collection'] ?? 'main',
                tempKey: $validated['temp_key'] ?? null,
                altText: $validated['alt_text'] ?? null
            );

            // FileUploader 컴포넌트가 response.data?.data 형식을 기대하므로
            // data 키 안에 한 번 더 감싸서 반환
            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.product_images.uploaded',
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
                        'is_thumbnail' => $image->is_thumbnail,
                    ],
                ],
                201
            );
        } catch (\Exception $e) {
            Log::error('상품 이미지 업로드 실패', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'exceptions.operation_failed',
                400
            );
        }
    }

    /**
     * 상품 이미지 삭제
     *
     * @param  int  $id  이미지 ID
     * @return JsonResponse
     */
    public function deleteImage(int $id): JsonResponse
    {
        try {
            $result = $this->productImageService->delete($id);

            if (! $result) {
                return ResponseHelper::notFound(
                    'messages.product_images.not_found',
                    [],
                    'sirsoft-ecommerce'
                );
            }

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.product_images.deleted'
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
     * 상품 이미지 순서 변경
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function reorderImages(ReorderProductImagesRequest $request): JsonResponse
    {
        try {
            $orders = collect($request->validated('order'))->pluck('order', 'id')->toArray();
            $this->productImageService->reorder($orders);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.product_images.reordered'
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
     * 대표 이미지 설정
     *
     * @param  int  $productId  상품 ID
     * @param  int  $imageId  이미지 ID
     * @return JsonResponse
     */
    public function setThumbnail(int $productId, int $imageId): JsonResponse
    {
        try {
            $this->productImageService->setThumbnail($productId, $imageId);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.product_images.thumbnail_set'
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
     * 파일 크기를 읽기 쉬운 형식으로 변환합니다.
     *
     * @param  int  $bytes  바이트 크기
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * 상품 처리로그(활동 로그) 목록을 조회합니다.
     *
     * @param Request $request 요청
     * @param Product $product 상품
     * @return JsonResponse 활동 로그 목록
     */
    public function logs(Request $request, Product $product): JsonResponse
    {
        try {
            $perPage = (int) ($request->query('per_page', 10));
            $sortOrder = $request->query('sort_order', 'desc');

            // 상품 + 상품옵션 로그를 합쳐서 조회
            $optionIds = $product->options()->pluck('id')->toArray();

            $query = ActivityLog::where(function ($q) use ($product, $optionIds) {
                // 상품 자체 로그
                $q->where(function ($sub) use ($product) {
                    $sub->where('loggable_type', $product->getMorphClass())
                        ->where('loggable_id', $product->getKey());
                });

                // 해당 상품의 옵션 로그
                if (! empty($optionIds)) {
                    $q->orWhere(function ($sub) use ($optionIds) {
                        $sub->where('loggable_type', (new ProductOption)->getMorphClass())
                            ->whereIn('loggable_id', $optionIds);
                    });
                }
            })->orderBy('created_at', $sortOrder);

            $logs = $query->paginate($perPage);

            return ResponseHelper::moduleSuccess(
                'sirsoft-ecommerce',
                'messages.products.logs_fetch_success',
                ActivityLogResource::collection($logs)->response()->getData(true)
            );
        } catch (Exception $e) {
            return ResponseHelper::moduleError(
                'sirsoft-ecommerce',
                'messages.products.logs_fetch_failed',
                500
            );
        }
    }
}
