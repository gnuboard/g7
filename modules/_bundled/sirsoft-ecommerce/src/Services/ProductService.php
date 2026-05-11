<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\OptionHasOrderHistoryException;
use Modules\Sirsoft\Ecommerce\Exceptions\StockMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLabelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 상품 서비스
 */
class ProductService
{
    /**
     * HTMLPurifier 인스턴스 (지연 생성)
     */
    protected ?\HTMLPurifier $purifier = null;

    /**
     * 시스템 기본통화 코드 조회
     *
     * @return string 통화 코드 (기본값: KRW)
     */
    protected function getCurrencyCode(): string
    {
        return g7_module_settings('sirsoft-ecommerce', 'language_currency')['default_currency'] ?? 'KRW';
    }

    public function __construct(
        protected ProductRepositoryInterface $repository,
        protected ProductImageService $productImageService,
        protected SequenceService $sequenceService,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected ProductLabelRepositoryInterface $productLabelRepository
    ) {}

    /**
     * 관리자 상품 목록을 페이지네이션으로 조회합니다 (filter_list_params 훅 적용).
     *
     * @param  array<string, mixed>  $filters  필터/정렬 조건 (per_page 포함)
     * @return LengthAwarePaginator 페이지네이션된 상품 목록
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.product.filter_list_params', $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 사용자(공개) 페이지용 상품 목록을 페이지네이션으로 조회합니다.
     *
     * before/after_public_list 훅과 filter_public_list_params 훅을 발화합니다.
     *
     * @param  array<string, mixed>  $filters  필터 조건 (category_id, search, sort, min_price, max_price, brand_id)
     * @param  int  $perPage  페이지당 개수
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator 페이지네이션된 공개 상품 목록
     */
    public function getPublicList(array $filters, int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.product.before_public_list', $filters);

        $filters = HookManager::applyFilters('sirsoft-ecommerce.product.filter_public_list_params', $filters);

        $result = $this->repository->getPublicList($filters, $perPage);

        HookManager::doAction('sirsoft-ecommerce.product.after_public_list', $result);

        return $result;
    }

    /**
     * 최근 30일 판매량 기준 인기 상품 컬렉션을 조회합니다.
     *
     * before/after_popular_list 훅과 filter_popular_list_result 훅을 발화합니다.
     *
     * @param  int  $limit  조회 개수
     * @return \Illuminate\Database\Eloquent\Collection<int, Product> 인기 상품 컬렉션
     */
    public function getPopularProducts(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        HookManager::doAction('sirsoft-ecommerce.product.before_popular_list');

        $products = $this->repository->getPopularProducts($limit);

        $products = HookManager::applyFilters('sirsoft-ecommerce.product.filter_popular_list_result', $products);

        HookManager::doAction('sirsoft-ecommerce.product.after_popular_list', $products);

        return $products;
    }

    /**
     * 최신 등록순으로 신상품 컬렉션을 조회합니다.
     *
     * before/after_new_list 훅과 filter_new_list_result 훅을 발화합니다.
     *
     * @param  int  $limit  조회 개수
     * @return \Illuminate\Database\Eloquent\Collection<int, Product> 신상품 컬렉션
     */
    public function getNewProducts(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        HookManager::doAction('sirsoft-ecommerce.product.before_new_list');

        $products = $this->repository->getNewProducts($limit);

        $products = HookManager::applyFilters('sirsoft-ecommerce.product.filter_new_list_result', $products);

        HookManager::doAction('sirsoft-ecommerce.product.after_new_list', $products);

        return $products;
    }

    /**
     * ID 배열로 상품 컬렉션을 조회합니다 ('최근 본 상품' 등에 사용).
     *
     * @param  array<int, int>  $ids  조회할 상품 ID 배열
     * @return \Illuminate\Database\Eloquent\Collection<int, Product> 상품 컬렉션 (빈 입력 시 빈 컬렉션)
     */
    public function getProductsByIds(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return $this->repository->findByIds($ids);
    }

    /**
     * 상품 통계 조회
     *
     * @return array 상품 통계 데이터
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 상품 상세를 옵션과 함께 조회합니다 (after_read 훅 발화).
     *
     * @param  int  $id  상품 ID
     * @param  bool  $includeInactive  비활성 옵션 포함 여부 (관리자 화면에서만 true)
     * @return Product|null 상품 모델 또는 부재 시 null
     */
    public function getDetail(int $id, bool $includeInactive = false): ?Product
    {
        $product = $this->repository->findWithOptions($id, $includeInactive);

        if ($product) {
            HookManager::doAction('sirsoft-ecommerce.product.after_read', $product);
        }

        return $product;
    }

    /**
     * 상품을 생성합니다 (트랜잭션 + 카테고리/옵션/이미지/라벨 동기화 + before/after_create 훅).
     *
     * description XSS sanitize, currency_code 자동 설정, 생성자 ID 기록을 포함합니다.
     *
     * @param  array<string, mixed>  $data  상품 fillable + 관계 데이터 (options/category_ids/images/label_assignments 등)
     * @return Product 생성된 상품 모델
     */
    public function create(array $data): Product
    {
        // 생성 전 훅
        HookManager::doAction('sirsoft-ecommerce.product.before_create', $data);

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_create_data', $data);

        // XSS 방어: HTML 설명 정화
        $data = $this->sanitizeDescription($data);

        // 통화 코드 자동 설정
        $data['currency_code'] = $this->getCurrencyCode();

        // 생성자 정보 추가
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $product = DB::transaction(function () use ($data) {
            $productData = collect($data)->except([
                'options', 'category_ids', 'images', 'label_assignments',
                'notice_items', 'additional_options', 'image_temp_key',
            ])->toArray();
            $product = $this->repository->create($productData);

            // 카테고리 동기화
            if (! empty($data['category_ids']) && is_array($data['category_ids'])) {
                $this->syncCategories($product, $data['category_ids'], $data['primary_category_id'] ?? null);
            }

            // 옵션 생성
            if (! empty($data['options']) && is_array($data['options'])) {
                $this->createOptions($product, $data['options']);
            }

            // 이미지 생성
            if (! empty($data['images']) && is_array($data['images'])) {
                $this->createImages($product, $data['images']);
            }

            // 임시 이미지 연결 (신규 등록 시 FileUploader로 업로드된 이미지)
            if (! empty($data['image_temp_key'])) {
                $this->productImageService->linkTempImages(
                    $data['image_temp_key'],
                    $product->id,
                    $product->product_code
                );
            }

            // 라벨 할당
            if (! empty($data['label_assignments']) && is_array($data['label_assignments'])) {
                $this->syncLabels($product, $data['label_assignments']);
            }

            // 추가옵션 생성
            if (! empty($data['additional_options']) && is_array($data['additional_options'])) {
                $this->createAdditionalOptions($product, $data['additional_options']);
            }

            // 상품정보제공고시 동기화 (템플릿은 UI용, 저장하지 않음)
            $this->syncNotice($product, $data['notice_items'] ?? null);

            return $product->load(['activeOptions', 'categories', 'images', 'additionalOptions']);
        });

        // 생성 후 훅
        HookManager::doAction('sirsoft-ecommerce.product.after_create', $product);

        return $product;
    }

    /**
     * 상품을 수정합니다 (트랜잭션 + 카테고리/옵션/이미지/라벨 재동기화 + before/after_update 훅).
     *
     * 변경 감지를 위한 toArray 스냅샷을 캡처하여 활동 로그용 후속 처리에 활용합니다.
     *
     * @param  Product  $product  수정 대상 상품 모델
     * @param  array<string, mixed>  $data  수정할 fillable + 관계 데이터
     * @return Product 갱신된 상품 모델
     */
    public function update(Product $product, array $data): Product
    {
        // 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.product.before_update', $product, $data);

        // 스냅샷 캡처 (활동 로그 변경 감지용)
        $snapshot = $product->toArray();

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_update_data', $data, $product);

        // XSS 방어: HTML 설명 정화
        $data = $this->sanitizeDescription($data);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        $product = DB::transaction(function () use ($product, $data) {
            $productData = collect($data)->except([
                'options', 'category_ids', 'images', 'label_assignments',
                'notice_items', 'additional_options',
            ])->toArray();
            $product = $this->repository->update($product, $productData);

            // 카테고리 동기화
            if (isset($data['category_ids']) && is_array($data['category_ids'])) {
                $this->syncCategories($product, $data['category_ids'], $data['primary_category_id'] ?? null);
            }

            // 옵션 동기화
            if (isset($data['options']) && is_array($data['options'])) {
                $this->syncOptions($product, $data['options']);
            }

            // 이미지 동기화
            if (isset($data['images']) && is_array($data['images'])) {
                $this->syncImages($product, $data['images'], $data['thumbnail_hash'] ?? null);
            }

            // 라벨 동기화
            if (array_key_exists('label_assignments', $data)) {
                $this->syncLabels($product, $data['label_assignments'] ?? []);
            }

            // 추가옵션 동기화
            if (array_key_exists('additional_options', $data)) {
                $this->syncAdditionalOptions($product, $data['additional_options'] ?? []);
            }

            // 상품정보제공고시 동기화 (템플릿은 UI용, 저장하지 않음)
            if (array_key_exists('notice_items', $data)) {
                $this->syncNotice($product, $data['notice_items'] ?? null);
            }

            return $product->load(['activeOptions', 'categories', 'images', 'additionalOptions']);
        });

        // 수정 후 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.product.after_update', $product, $snapshot);

        return $product;
    }

    /**
     * 상품 삭제 가능 여부를 확인합니다.
     *
     * 주문 이력이 있는 상품은 삭제할 수 없습니다.
     *
     * @param  Product  $product  확인할 상품
     * @return array{canDelete: bool, reason: string|null, relatedData: array}
     */
    public function checkCanDelete(Product $product): array
    {
        $ordersCount = $this->orderOptionRepository->countByProductId($product->id);

        return [
            'canDelete' => $ordersCount === 0,
            'reason' => $ordersCount > 0
                ? __('sirsoft-ecommerce::messages.products.has_order_history', ['count' => $ordersCount])
                : null,
            'relatedData' => [
                'orders' => $ordersCount,
                'images' => $product->images()->count(),
                'options' => $product->options()->count(),
                'additionalOptions' => $product->additionalOptions()->count(),
                'labelAssignments' => $product->labelAssignments()->count(),
            ],
        ];
    }

    /**
     * 상품을 삭제합니다.
     *
     * 그누보드7 규정에 따라 DB CASCADE에 의존하지 않고 어플리케이션에서 명시적으로 삭제합니다.
     * 이는 삭제 순서 제어, 훅 실행, 로깅, 파일 삭제 등을 보장하기 위함입니다.
     *
     * @param  Product  $product  삭제할 상품
     * @return bool 삭제 성공 여부
     *
     * @throws \Exception 삭제 실패 시
     */
    public function delete(Product $product): bool
    {
        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.product.before_delete', $product);

        return DB::transaction(function () use ($product) {
            // 1. 이미지 파일 물리적 삭제 (Storage)
            $this->deleteProductImageFiles($product);

            // 2. 이미지 레코드 삭제
            $product->images()->delete();

            // 3. 옵션 삭제
            $product->options()->delete();

            // 4. 추가 옵션 삭제
            $product->additionalOptions()->delete();

            // 5. 라벨 할당 삭제
            $product->labelAssignments()->delete();

            // 6. 공지사항 삭제
            $product->notice()->delete();

            // 7. 카테고리 연결 해제 (중간 테이블)
            $product->categories()->detach();

            // === 미구현 연관 데이터 (테이블 생성 후 활성화) ===

            // TODO: 장바구니 삭제 (테이블: ecommerce_cart_items)
            // CartItem::where('product_id', $product->id)->delete();

            // TODO: 위시리스트 삭제 (테이블: ecommerce_wishlists)
            // Wishlist::where('product_id', $product->id)->delete();

            // TODO: 리뷰 삭제 (테이블: ecommerce_reviews)
            // Review::where('product_id', $product->id)->delete();

            // TODO: 상품문의 삭제 (테이블: ecommerce_product_inquiries)
            // ProductInquiry::where('product_id', $product->id)->delete();

            // 8. 상품 레코드 완전 삭제 (SoftDeletes 무시)
            // 모든 연관 데이터가 완전 삭제되었으므로 상품도 완전 삭제합니다.
            $result = $product->forceDelete();

            // 삭제 후 훅
            HookManager::doAction('sirsoft-ecommerce.product.after_delete', $product);

            return $result;
        });
    }

    /**
     * 상품 이미지 파일을 Storage에서 물리적으로 삭제합니다.
     *
     * ProductImageService에 위임하여 상품 이미지 폴더 전체를 삭제합니다.
     *
     * @param  Product  $product  상품
     */
    private function deleteProductImageFiles(Product $product): void
    {
        $this->productImageService->deleteByProductId($product->id);
    }

    /**
     * 다수 상품의 단일 상태 필드를 일괄 변경합니다 (활동 로그용 스냅샷 캡처 포함).
     *
     * before/after_bulk_update 훅을 발화하여 활동 로그 리스너가 변경 감지를 수행할 수 있게 합니다.
     *
     * @param  array<int, int>  $ids  대상 상품 ID 배열
     * @param  string  $field  변경할 상태 필드명 (sales_status/display_status/tax_status 등)
     * @param  string  $value  새 값
     * @return array{updated_count: int, requested_count: int} 갱신/요청 건수
     */
    public function bulkUpdateStatus(array $ids, string $field, string $value): array
    {
        // 스냅샷 캡처 (활동 로그 변경 감지용)
        $snapshots = Product::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // 일괄 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.product.before_bulk_update', $ids, [
            'field' => $field,
            'value' => $value,
        ]);

        $updatedCount = $this->repository->bulkUpdateStatus($ids, $field, $value);

        // 일괄 수정 후 훅 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.product.after_bulk_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 다수 상품의 가격을 일괄 변경합니다 (활동 로그용 스냅샷 캡처 포함).
     *
     * before/after_bulk_price_update 훅을 발화합니다.
     *
     * @param  array<int, int>  $ids  대상 상품 ID 배열
     * @param  string  $method  변경 방식 ('set' | 'increase' | 'decrease')
     * @param  int  $value  변경 값 (단위 의존)
     * @param  string  $unit  변경 단위 ('amount' | 'percent')
     * @return array{updated_count: int, requested_count: int} 갱신/요청 건수
     */
    public function bulkUpdatePrice(array $ids, string $method, int $value, string $unit): array
    {
        // 스냅샷 캡처 (활동 로그 변경 감지용)
        $snapshots = Product::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        HookManager::doAction('sirsoft-ecommerce.product.before_bulk_price_update', $ids, [
            'method' => $method,
            'value' => $value,
            'unit' => $unit,
        ]);

        $updatedCount = $this->repository->bulkUpdatePrice($ids, $method, $value, $unit);

        // 스냅샷 전달
        HookManager::doAction('sirsoft-ecommerce.product.after_bulk_price_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 다수 상품의 재고를 일괄 변경합니다 (활동 로그용 스냅샷 캡처 포함).
     *
     * before/after_bulk_stock_update 훅을 발화합니다.
     *
     * @param  array<int, int>  $ids  대상 상품 ID 배열
     * @param  string  $method  변경 방식 ('set' | 'increase' | 'decrease')
     * @param  int  $value  변경 수량
     * @return array{updated_count: int, requested_count: int} 갱신/요청 건수
     */
    public function bulkUpdateStock(array $ids, string $method, int $value): array
    {
        // 스냅샷 캡처 (활동 로그 변경 감지용)
        $snapshots = Product::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        HookManager::doAction('sirsoft-ecommerce.product.before_bulk_stock_update', $ids, [
            'method' => $method,
            'value' => $value,
        ]);

        $updatedCount = $this->repository->bulkUpdateStock($ids, $method, $value);

        // 스냅샷 전달
        HookManager::doAction('sirsoft-ecommerce.product.after_bulk_stock_update', $ids, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_count' => count($ids),
        ];
    }

    /**
     * 상품 통합 일괄 업데이트 (일괄 변경 + 개별 인라인 수정 동시 처리)
     *
     * 일괄 변경 조건이 설정된 필드는 우선 적용되며, 나머지는 개별 수정이 적용됩니다.
     * 옵션 처리는 ProductOptionService에 위임합니다.
     *
     * @param  array  $data  업데이트 데이터 (ids, bulk_changes, items, option_bulk_changes, option_items)
     * @return array 업데이트 결과 (products_updated, options_updated)
     */
    public function bulkUpdate(array $data): array
    {
        // 스냅샷 캡처 (활동 로그 변경 감지용)
        $ids = $data['ids'] ?? [];
        $snapshots = ! empty($ids)
            ? Product::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all()
            : [];

        // 1. before 훅 실행
        HookManager::doAction('sirsoft-ecommerce.product.before_bulk_update', $data);

        // 2. filter 훅으로 데이터 변형 허용
        $data = HookManager::applyFilters('sirsoft-ecommerce.product.filter_bulk_update_data', $data);

        $productsUpdated = 0;
        $optionsUpdated = 0;

        $result = DB::transaction(function () use ($data, &$productsUpdated, &$optionsUpdated) {
            $ids = $data['ids'] ?? [];
            $bulkChanges = $data['bulk_changes'] ?? [];
            $items = $data['items'] ?? [];
            $optionBulkChanges = $data['option_bulk_changes'] ?? [];
            $optionItems = $data['option_items'] ?? [];

            // 3. 상품 일괄 변경 처리 (bulk_changes)
            if (! empty($bulkChanges)) {
                $validBulkChanges = array_filter($bulkChanges, fn ($v) => $v !== null);
                if (! empty($validBulkChanges)) {
                    $this->repository->bulkUpdateFields($ids, $validBulkChanges);
                    $productsUpdated = count($ids);
                }
            }

            // 4. 상품 개별 인라인 수정 처리 (bulk_changes 필드 제외)
            if (! empty($items)) {
                foreach ($items as $item) {
                    $productId = $item['id'] ?? null;
                    if (! $productId || ! in_array($productId, $ids)) {
                        continue;
                    }

                    $updateData = $this->filterBulkFields($item, $bulkChanges);
                    if (! empty($updateData)) {
                        $product = $this->repository->find($productId);
                        if ($product) {
                            $updateData['updated_by'] = Auth::id();
                            $this->repository->update($product, $updateData);
                            $productsUpdated++;
                        }
                    }
                }
            }

            // 5. 옵션 처리 (ProductOptionService에 위임)
            if (! empty($optionBulkChanges) || ! empty($optionItems)) {
                $optionService = app(ProductOptionService::class);
                $optionResult = $optionService->bulkUpdate([
                    'product_ids' => $ids,
                    'bulk_changes' => $optionBulkChanges,
                    'items' => $optionItems,
                ]);
                $optionsUpdated = $optionResult['options_updated'] ?? 0;
            }

            return [
                'products_updated' => $productsUpdated,
                'options_updated' => $optionsUpdated,
            ];
        });

        // 6. after 훅 실행 (스냅샷 전달)
        HookManager::doAction('sirsoft-ecommerce.product.after_bulk_update', $result, $data, $snapshots);

        return $result;
    }

    /**
     * bulk_changes에 설정된 필드를 제외한 데이터 반환
     *
     * @param  array  $item  개별 수정 데이터
     * @param  array  $bulkChanges  일괄 변경 조건
     * @return array bulk_changes 필드가 제외된 데이터
     */
    private function filterBulkFields(array $item, array $bulkChanges): array
    {
        $filtered = $item;
        unset($filtered['id']);

        // bulk_changes에 null이 아닌 값으로 설정된 필드 제외
        foreach ($bulkChanges as $field => $value) {
            if ($value !== null) {
                unset($filtered[$field]);
            }
        }

        return $filtered;
    }

    /**
     * 재고 일치 검증
     *
     * @param  Product  $product  상품 모델
     *
     * @throws StockMismatchException
     */
    public function validateStockConsistency(Product $product): void
    {
        if (! $product->isStockConsistent()) {
            throw new StockMismatchException(
                $product->id,
                $product->stock_quantity,
                $product->calculateOptionStockSum()
            );
        }
    }

    /**
     * 옵션 생성
     *
     * @param  Product  $product  상품 모델
     * @param  array  $options  옵션 데이터 배열
     */
    protected function createOptions(Product $product, array $options): void
    {
        $currencyCode = $this->getCurrencyCode();

        foreach ($options as $index => $optionData) {
            $optionData['product_id'] = $product->id;
            $optionData['sort_order'] = $optionData['sort_order'] ?? $index;
            $optionData['option_name'] = $optionData['option_name']
                ?? $this->generateMultilingualOptionName($optionData['option_values'] ?? []);
            $optionData['currency_code'] = $currencyCode;

            $product->options()->create($optionData);
        }

        // 옵션 재고 합계로 상품 재고 업데이트
        $this->syncProductStock($product);
    }

    /**
     * 옵션 동기화
     *
     * @param  Product  $product  상품 모델
     * @param  array  $options  옵션 데이터 배열
     */
    protected function syncOptions(Product $product, array $options): void
    {
        $existingIds = $product->options()->pluck('id')->toArray();
        $newIds = [];
        $currencyCode = $this->getCurrencyCode();
        $createdCount = 0;
        $updatedCount = 0;

        foreach ($options as $index => $optionData) {
            if (! empty($optionData['id'])) {
                // 기존 옵션 수정
                $option = $product->options()->find($optionData['id']);
                if ($option) {
                    $option->update($optionData);
                    $newIds[] = $option->id;
                    $updatedCount++;
                }
            } else {
                // 새 옵션 생성
                $optionData['product_id'] = $product->id;
                $optionData['sort_order'] = $optionData['sort_order'] ?? $index;
                $optionData['option_name'] = $optionData['option_name']
                    ?? $this->generateMultilingualOptionName($optionData['option_values'] ?? []);
                $optionData['currency_code'] = $currencyCode;
                $option = $product->options()->create($optionData);
                $newIds[] = $option->id;
                $createdCount++;
            }
        }

        // 삭제된 옵션 제거
        $deleteIds = array_diff($existingIds, $newIds);
        $deletedCount = 0;
        if (! empty($deleteIds)) {
            // 주문 이력이 있는 옵션은 삭제 불가
            $this->validateOptionsDeletion($deleteIds);
            $deletedCount = count($deleteIds);
            $product->options()->whereIn('id', $deleteIds)->delete();
        }

        // 옵션 재고 합계로 상품 재고 업데이트
        $this->syncProductStock($product);

        // 옵션 동기화 완료 훅 호출 (option_groups 동기화용)
        HookManager::doAction(
            'sirsoft-ecommerce.product.after_options_sync',
            $product,
            $createdCount,
            $updatedCount,
            $deletedCount
        );
    }

    /**
     * 상품 재고를 옵션 재고 합계로 동기화
     *
     * @param  Product  $product  상품 모델
     */
    protected function syncProductStock(Product $product): void
    {
        if ($product->has_options) {
            $stockSum = $product->calculateOptionStockSum();
            $product->update(['stock_quantity' => $stockSum]);
        }
    }

    /**
     * 옵션 데이터를 기반으로 option_groups 재생성
     *
     * 옵션의 option_values에서 그룹명과 값 목록을 추출하여 option_groups 형식으로 변환합니다.
     * 옵션 삭제/수정/추가 후 option_groups와 실제 옵션 간 동기화에 사용됩니다.
     *
     * @param  Product  $product  상품 모델
     */
    public function rebuildOptionGroups(Product $product): void
    {
        $options = $product->options()->where('is_active', true)->get();

        if ($options->isEmpty()) {
            $product->update(['option_groups' => []]);

            return;
        }

        // 옵션 값에서 그룹 추출
        $groupedValues = [];

        foreach ($options as $option) {
            $optionValues = $option->option_values ?? [];

            // option_values가 배열 형식인지 확인 (다국어 대응 시 배열 형식)
            if ($this->isOptionValuesArrayFormat($optionValues)) {
                // 배열 형식: [{"key": "색상", "value": "빨강"}, ...]
                foreach ($optionValues as $item) {
                    $key = $item['key'] ?? null;
                    $value = $item['value'] ?? null;

                    if ($key !== null && $value !== null) {
                        $normalizedKey = $this->normalizeOptionKey($key);
                        if (! isset($groupedValues[$normalizedKey])) {
                            $groupedValues[$normalizedKey] = [
                                'name' => $key,
                                'values' => [],
                            ];
                        }

                        $normalizedValue = $this->normalizeOptionValue($value);
                        $existingNormalized = array_map([$this, 'normalizeOptionValue'], $groupedValues[$normalizedKey]['values']);
                        if (! in_array($normalizedValue, $existingNormalized)) {
                            $groupedValues[$normalizedKey]['values'][] = $value;
                        }
                    }
                }
            } else {
                // 객체 형식: {"색상": "빨강", "사이즈": "M"}
                foreach ($optionValues as $key => $value) {
                    if (! isset($groupedValues[$key])) {
                        $groupedValues[$key] = [
                            'name' => $key,
                            'values' => [],
                        ];
                    }

                    if (! in_array($value, $groupedValues[$key]['values'])) {
                        $groupedValues[$key]['values'][] = $value;
                    }
                }
            }
        }

        // option_groups 형식으로 변환
        $optionGroups = array_values($groupedValues);

        $product->update(['option_groups' => $optionGroups]);
    }

    /**
     * option_values가 배열 형식인지 확인
     *
     * @param  mixed  $optionValues  옵션 값
     */
    protected function isOptionValuesArrayFormat($optionValues): bool
    {
        if (! is_array($optionValues) || empty($optionValues)) {
            return false;
        }

        $firstItem = reset($optionValues);

        return is_array($firstItem) && (isset($firstItem['key']) || isset($firstItem['value']));
    }

    /**
     * option_values에서 다국어 옵션명 생성
     *
     * 새 배열 형식: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}] → {"ko": "빨강/M", "en": "Red/M"}
     * 레거시 형식: {"색상": "빨강"} → {"ko": "빨강/M"} (ko만)
     *
     * @param  array  $optionValues  옵션 값 배열
     * @return array 다국어 옵션명
     */
    protected function generateMultilingualOptionName(array $optionValues): array
    {
        if (empty($optionValues)) {
            return [];
        }

        // 배열 형식 확인 (다국어 대응)
        if ($this->isOptionValuesArrayFormat($optionValues)) {
            // 새 형식: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}, ...]
            $locales = config('app.supported_locales', ['ko', 'en']);
            $result = [];

            foreach ($locales as $locale) {
                $parts = [];
                foreach ($optionValues as $item) {
                    $value = $item['value'] ?? [];
                    $localizedValue = is_array($value) ? ($value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? '') : $value;
                    if ($localizedValue !== '') {
                        $parts[] = $localizedValue;
                    }
                }
                $result[$locale] = implode('/', $parts);
            }

            return $result;
        }

        // 레거시 형식: {"색상": "빨강"} → {"ko": "빨강/M"} (ko로만)
        return ['ko' => implode('/', array_values($optionValues))];
    }

    /**
     * 다국어 객체 또는 문자열을 정규화된 키로 변환
     *
     * @param  mixed  $key  키 (문자열 또는 다국어 객체)
     */
    protected function normalizeOptionKey($key): string
    {
        if (is_array($key)) {
            return $key['ko'] ?? reset($key) ?? '';
        }

        return (string) $key;
    }

    /**
     * 다국어 객체 또는 문자열을 정규화된 값으로 변환
     *
     * @param  mixed  $value  값 (문자열 또는 다국어 객체)
     */
    protected function normalizeOptionValue($value): string
    {
        if (is_array($value)) {
            return $value['ko'] ?? reset($value) ?? '';
        }

        return (string) $value;
    }

    /**
     * 추가옵션 생성
     *
     * @param  Product  $product  상품 모델
     * @param  array  $additionalOptions  추가옵션 데이터 배열
     */
    protected function createAdditionalOptions(Product $product, array $additionalOptions): void
    {
        foreach ($additionalOptions as $index => $optionData) {
            $product->additionalOptions()->create([
                'name' => $optionData['name'],
                'is_required' => $optionData['is_required'] ?? false,
                'sort_order' => $optionData['sort_order'] ?? $index,
            ]);
        }
    }

    /**
     * 추가옵션 동기화
     *
     * 기존 추가옵션과 새 추가옵션을 비교하여 추가/수정/삭제합니다.
     * 향후 추가옵션에 주문 참조가 생기면 삭제 검증 로직 추가 예정입니다.
     *
     * @param  Product  $product  상품 모델
     * @param  array  $additionalOptions  추가옵션 데이터 배열
     */
    protected function syncAdditionalOptions(Product $product, array $additionalOptions): void
    {
        $existingIds = $product->additionalOptions()->pluck('id')->toArray();
        $newIds = collect($additionalOptions)->pluck('id')->filter()->toArray();

        // 삭제 대상 ID 계산
        $deleteIds = array_diff($existingIds, $newIds);

        // 주문 이력 검증 (향후 추가옵션 주문 참조 시 활성화)
        // if (! empty($deleteIds)) {
        //     $this->validateAdditionalOptionsDeletion($deleteIds);
        // }

        // 삭제 대상 옵션 제거
        if (! empty($deleteIds)) {
            $product->additionalOptions()->whereIn('id', $deleteIds)->delete();
        }

        // 새 옵션 추가 및 기존 옵션 업데이트
        foreach ($additionalOptions as $index => $optionData) {
            if (isset($optionData['id']) && in_array($optionData['id'], $existingIds)) {
                // 업데이트
                $product->additionalOptions()->where('id', $optionData['id'])->update([
                    'name' => $optionData['name'],
                    'is_required' => $optionData['is_required'] ?? false,
                    'sort_order' => $optionData['sort_order'] ?? $index,
                ]);
            } else {
                // 생성
                $product->additionalOptions()->create([
                    'name' => $optionData['name'],
                    'is_required' => $optionData['is_required'] ?? false,
                    'sort_order' => $optionData['sort_order'] ?? $index,
                ]);
            }
        }
    }

    /**
     * 상품 옵션 삭제 가능 여부를 검증합니다.
     *
     * 주문 이력이 있는 옵션은 삭제할 수 없습니다.
     *
     * @param  array  $optionIds  삭제 대상 옵션 ID 배열
     *
     * @throws OptionHasOrderHistoryException 주문 이력이 있는 경우
     */
    protected function validateOptionsDeletion(array $optionIds): void
    {
        $hasOrders = OrderOption::whereIn('product_option_id', $optionIds)->exists();

        if ($hasOrders) {
            throw new OptionHasOrderHistoryException(
                __('sirsoft-ecommerce::messages.options.has_order_history')
            );
        }
    }

    /**
     * 이미지 생성
     *
     * @param  Product  $product  상품 모델
     * @param  array  $images  이미지 데이터 배열
     */
    protected function createImages(Product $product, array $images): void
    {
        foreach ($images as $index => $imageData) {
            $sortOrder = $imageData['sort_order'] ?? $index;
            $isThumbnail = $imageData['is_thumbnail'] ?? ($index === 0);

            // hash가 있으면 복사 모드 — 원본 이미지 파일을 복사하여 새 레코드 생성
            if (! empty($imageData['hash'])) {
                $this->productImageService->copyFromSource(
                    $imageData['hash'],
                    $product->id,
                    $product->product_code,
                    (bool) $isThumbnail,
                    $sortOrder
                );

                continue;
            }

            // hash가 없으면 기존 동작 (직접 레코드 생성)
            $imageData['product_id'] = $product->id;
            $imageData['sort_order'] = $sortOrder;

            if ($index === 0 && ! isset($imageData['is_thumbnail'])) {
                $imageData['is_thumbnail'] = true;
            }

            $product->images()->create($imageData);
        }
    }

    /**
     * 이미지 동기화
     *
     * @param  Product  $product  상품 모델
     * @param  array  $images  이미지 데이터 배열
     */
    protected function syncImages(Product $product, array $images, ?string $thumbnailHash = null): void
    {
        $existingIds = $product->images()->pluck('id')->toArray();
        $newIds = [];

        foreach ($images as $index => $imageData) {
            // 배열 순서를 sort_order의 SSoT로 사용 (이미지 데이터의 sort_order 값은 stale일 수 있음)
            $imageData['sort_order'] = $index + 1;

            if (! empty($imageData['id'])) {
                // 기존 이미지 수정
                $image = $product->images()->find($imageData['id']);
                if ($image) {
                    $image->update($imageData);
                    $newIds[] = $image->id;
                }
            } else {
                // 새 이미지 생성
                $imageData['product_id'] = $product->id;
                $image = $product->images()->create($imageData);
                $newIds[] = $image->id;
            }
        }

        // 삭제된 이미지 제거
        $deleteIds = array_diff($existingIds, $newIds);
        if (! empty($deleteIds)) {
            $product->images()->whereIn('id', $deleteIds)->delete();
        }

        // 대표 이미지 설정 (thumbnail_hash 기준)
        if ($thumbnailHash) {
            // 전체 해제 후 해당 hash 이미지를 대표로 설정
            $product->images()->update(['is_thumbnail' => false]);
            $product->images()->where('hash', $thumbnailHash)->update(['is_thumbnail' => true]);
        }

        // 대표 이미지가 없으면 첫 번째를 대표로 설정
        if (! $product->images()->where('is_thumbnail', true)->exists()) {
            $product->images()->orderBy('sort_order')->first()?->update(['is_thumbnail' => true]);
        }
    }

    /**
     * 라벨 할당 동기화
     *
     * 기존 할당을 삭제하고 새 할당을 생성합니다.
     * name/color는 모달에서 이미 API로 저장되므로 여기서는 할당 관계만 처리합니다.
     *
     * @param  Product  $product  상품 모델
     * @param  array  $labelAssignments  라벨 할당 데이터 배열
     */
    protected function syncLabels(Product $product, array $labelAssignments): void
    {
        $product->labelAssignments()->delete();

        foreach ($labelAssignments as $assignment) {
            $labelId = $assignment['label_id'] ?? null;
            if (! $labelId) {
                continue;
            }

            // label_id 유효성 확인
            if (! $this->productLabelRepository->exists($labelId)) {
                continue;
            }

            $product->labelAssignments()->create([
                'label_id' => $labelId,
                'start_date' => $assignment['start_date'] ?? null,
                'end_date' => $assignment['end_date'] ?? null,
            ]);
        }
    }

    /**
     * 상품 설명 HTML을 정화합니다.
     *
     * XSS 공격을 방지하기 위해 HTMLPurifier를 사용하여
     * 허용된 HTML 태그만 남기고 위험한 내용을 제거합니다.
     *
     * @param  array  $data  상품 데이터
     * @return array 정화된 상품 데이터
     */
    protected function sanitizeDescription(array $data): array
    {
        if (! isset($data['description']) || ($data['description_mode'] ?? 'text') !== 'html') {
            return $data;
        }

        if (! is_array($data['description'])) {
            return $data;
        }

        if ($this->purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'p,br,strong,em,b,i,u,s,ul,ol,li,a[href|target],img[src|alt|width|height],h1,h2,h3,h4,h5,h6,table,tr,td,th,thead,tbody,tfoot,caption,colgroup,col,blockquote,pre,code,div,span[style],hr');
            $config->set('CSS.AllowedProperties', 'color,background-color,font-size,font-weight,text-align,text-decoration,margin,padding,border,width,height');
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $this->purifier = new \HTMLPurifier($config);
        }

        foreach ($data['description'] as $locale => $content) {
            if (is_string($content) && ! empty($content)) {
                $data['description'][$locale] = $this->purifier->purify($content);
            }
        }

        return $data;
    }

    /**
     * 상품정보제공고시 동기화
     *
     * 기존 데이터를 삭제 후 재등록합니다.
     * 템플릿은 UI용 도구일 뿐, 저장하지 않습니다.
     *
     * @param  Product  $product  상품 모델
     * @param  array|null  $noticeItems  고시 항목 데이터
     */
    protected function syncNotice(Product $product, ?array $noticeItems): void
    {
        // 기존 데이터 삭제
        $product->notice()->delete();

        // 데이터가 없으면 종료
        if (empty($noticeItems)) {
            return;
        }

        // 새로 생성 (template_id 없이)
        $product->notice()->create([
            'values' => $noticeItems,
        ]);
    }

    /**
     * 카테고리 동기화 (다대다)
     *
     * @param  Product  $product  상품 모델
     * @param  array  $categoryIds  카테고리 ID 배열
     * @param  int|null  $primaryCategoryId  대표 카테고리 ID
     */
    protected function syncCategories(Product $product, array $categoryIds, ?int $primaryCategoryId = null): void
    {
        // 기존 카테고리 연결 해제
        $product->categories()->detach();

        if (empty($categoryIds)) {
            return;
        }

        // 새 카테고리 연결
        $syncData = [];
        foreach ($categoryIds as $categoryId) {
            $syncData[$categoryId] = [
                'is_primary' => ($categoryId === $primaryCategoryId),
            ];
        }

        // 대표 카테고리가 지정되지 않았으면 첫 번째를 대표로 설정
        if ($primaryCategoryId === null && ! empty($categoryIds)) {
            $firstCategoryId = reset($categoryIds);
            $syncData[$firstCategoryId]['is_primary'] = true;
        }

        $product->categories()->attach($syncData);
    }

    /**
     * `product_code` 컬럼으로 상품을 조회합니다.
     *
     * @param  string  $code  상품코드 (vendor_code/sku 등 외부 식별자)
     * @return Product|null 일치 상품 또는 부재 시 null
     */
    public function findByCode(string $code): ?Product
    {
        return $this->repository->findByProductCode($code);
    }

    /**
     * 문자열 식별자로 상품을 조회합니다 — 숫자면 ID 우선, 미발견/비숫자는 product_code 폴백.
     *
     * URL 라우트에서 `id` 와 `product_code` 어느 것이 들어와도 동일하게 처리하기 위한 헬퍼.
     *
     * @param  string  $identifier  상품 ID 숫자 문자열 또는 product_code
     * @return Product|null 일치 상품 또는 부재 시 null
     */
    public function findByIdOrCode(string $identifier): ?Product
    {
        // 숫자인 경우 먼저 ID로 조회 시도
        if (ctype_digit($identifier)) {
            $product = $this->repository->find((int) $identifier);
            if ($product) {
                return $product;
            }
        }

        // ID로 찾지 못하면 product_code로 조회
        return $this->repository->findByProductCode($identifier);
    }

    /**
     * 상품 수정 폼의 초기 데이터를 위한 모든 관계 포함 배열을 반환합니다.
     *
     * `findWithAllRelations` 결과를 폼 모델에 맞게 직렬화한 형태입니다.
     *
     * @param  int  $id  상품 ID
     * @return array<string, mixed>|null 폼용 직렬화 데이터 또는 부재 시 null
     */
    public function getDetailForForm(int $id): ?array
    {
        $product = $this->repository->findWithAllRelations($id);

        if (! $product) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'product_code' => $product->product_code,
            'sales_product_code' => $product->sales_product_code,
            'sku' => $product->sku,
            'brand_id' => $product->brand_id,
            'category_ids' => $product->categories->pluck('id')->toArray(),
            'primary_category_id' => $product->categories->where('pivot.is_primary', true)->first()?->id,
            'created_at' => $product->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),

            'list_price' => $product->list_price,
            'selling_price' => $product->selling_price,
            'stock_quantity' => $product->stock_quantity,
            'safe_stock_quantity' => $product->safe_stock_quantity,
            'tax_status' => $product->tax_status->value ?? $product->tax_status,
            'sales_status' => $product->sales_status->value ?? $product->sales_status,
            'display_status' => $product->display_status->value ?? $product->display_status,

            'options' => $product->options->map(fn ($opt) => [
                'id' => $opt->id,
                'option_code' => $opt->option_code,
                'option_name' => $opt->option_name,
                'option_values' => $opt->option_values,
                'list_price' => $opt->list_price ?? $product->list_price,
                'selling_price' => $opt->selling_price ?? $product->selling_price,
                'stock_quantity' => $opt->stock_quantity,
                'safe_stock_quantity' => $opt->safe_stock_quantity,
                'sku' => $opt->sku,
                'weight' => $opt->weight,
                'volume' => $opt->volume,
                'mileage_value' => $opt->mileage_value,
                'mileage_type' => $opt->mileage_type,
                'is_default' => $opt->is_default,
                'is_active' => $opt->is_active,
                'sort_order' => $opt->sort_order,
            ])->toArray(),

            'additional_options' => $product->additionalOptions?->map(fn ($opt) => [
                'id' => $opt->id,
                'name' => $opt->name,
                'is_required' => $opt->is_required,
            ])->toArray() ?? [],

            'images' => $product->images->map(fn ($img) => [
                'id' => $img->id,
                'hash' => $img->hash,
                'url' => $img->url,
                'original_filename' => $img->original_filename,
                'download_url' => '/api/modules/sirsoft-ecommerce/product-image/'.$img->hash,
                'file_size' => $img->file_size,
                'size' => $img->file_size,
                'size_formatted' => $this->formatFileSize($img->file_size),
                'mime_type' => $img->mime_type,
                'is_image' => str_starts_with($img->mime_type ?? '', 'image/'),
                'is_thumbnail' => $img->is_thumbnail,
                'sort_order' => $img->sort_order,
                'order' => $img->sort_order,
                'width' => $img->width,
                'height' => $img->height,
            ])->toArray(),

            'thumbnail_hash' => $product->images->firstWhere('is_thumbnail', true)?->hash,

            'description' => $product->description,
            'description_mode' => $product->description_mode ?? 'text',

            // 상품정보제공고시 (템플릿은 UI용 도구일 뿐 저장하지 않음)
            'notice_items' => $product->notice?->values,

            'shipping_policy_id' => $product->shipping_policy_id,
            'common_info_id' => $product->common_info_id,

            'label_assignments' => $product->labelAssignments?->map(fn ($la) => [
                'label_id' => $la->label_id,
                'start_date' => $la->start_date?->format('Y-m-d'),
                'end_date' => $la->end_date?->format('Y-m-d'),
            ])->toArray() ?? [],

            'min_purchase_qty' => $product->min_purchase_qty ?? 1,
            'max_purchase_qty' => $product->max_purchase_qty ?? 0,
            'purchase_restriction' => $product->purchase_restriction ?? 'none',
            'allowed_roles' => $product->allowed_roles ?? [],

            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'seo_tags' => $product->meta_keywords ?? [],

            'barcode' => $product->barcode,
            'hs_code' => $product->hs_code,
        ];
    }

    /**
     * 상품 복사용 데이터 조회 (ID 제외, 옵션별 필터링)
     *
     * @param int $id 상품 ID
     * @param array $copyOptions 복사 옵션 (각 섹션별 true/false)
     * @return array|null 복사용 데이터 또는 null
     */
    public function getDetailForCopy(int $id, array $copyOptions = []): ?array
    {
        $data = $this->getDetailForForm($id);

        if (! $data) {
            return null;
        }

        // ID 필드들 제거
        unset($data['id']);
        $data['product_code'] = $this->generateUniqueCode();

        // 이미지 — id 제거 (원본 이미지 삭제 방지, hash 기반으로 식별)
        if ($copyOptions['images'] ?? true) {
            foreach ($data['images'] as &$image) {
                unset($image['id']);
            }
            unset($image);
        } else {
            $data['images'] = [];
        }

        // 상품 옵션
        if ($copyOptions['options'] ?? true) {
            foreach ($data['options'] as &$option) {
                unset($option['id']);
            }
            unset($option);
            foreach ($data['additional_options'] as &$option) {
                unset($option['id']);
            }
            unset($option);
        } else {
            $data['options'] = [];
            $data['additional_options'] = [];
        }

        // 카테고리
        if (! ($copyOptions['categories'] ?? true)) {
            $data['category_ids'] = [];
            $data['primary_category_id'] = null;
        }

        // 판매 정보
        if (! ($copyOptions['sales_info'] ?? true)) {
            $data['list_price'] = 0;
            $data['selling_price'] = 0;
            $data['stock_quantity'] = 0;
            $data['safe_stock_quantity'] = 0;
            $data['tax_status'] = 'taxable';
            $data['sales_status'] = 'active';
            $data['display_status'] = 'visible';
        }

        // 상품 설명
        if (! ($copyOptions['description'] ?? true)) {
            $data['description'] = null;
        }

        // 상품정보제공고시
        if (! ($copyOptions['notice'] ?? true)) {
            $data['notice_items'] = null;
        }

        // 공통 정보
        if (! ($copyOptions['common_info'] ?? true)) {
            $data['common_info_id'] = null;
        }

        // 기타 정보 (라벨, 구매제한)
        if (! ($copyOptions['other_info'] ?? true)) {
            $data['label_assignments'] = [];
            $data['min_purchase_qty'] = 1;
            $data['max_purchase_qty'] = 0;
            $data['purchase_restriction'] = 'none';
            $data['allowed_roles'] = [];
        }

        // 배송 정책
        if (! ($copyOptions['shipping'] ?? true)) {
            $data['shipping_policy_id'] = null;
        }

        // SEO 설정
        if (! ($copyOptions['seo'] ?? false)) {
            $data['meta_title'] = null;
            $data['meta_description'] = null;
            $data['seo_tags'] = [];
        }

        // 식별 코드 (SKU, 바코드 등)
        if (! ($copyOptions['identification'] ?? true)) {
            $data['sku'] = null;
            $data['barcode'] = null;
            $data['hs_code'] = null;
            $data['sales_product_code'] = null;
        }

        // 날짜 필드 제거 (새 상품이므로)
        unset($data['created_at'], $data['updated_at']);

        return $data;
    }

    /**
     * 파일 크기를 읽기 쉬운 형식으로 변환
     *
     * @param  int|null  $bytes  바이트 크기
     * @return string
     */
    private function formatFileSize(?int $bytes): string
    {
        if ($bytes === null || $bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * 고유 상품코드 생성
     *
     * 시퀀스 서비스를 사용하여 원자적으로 고유 코드를 생성합니다.
     *
     * @return string 10자리 숫자 상품코드
     */
    public function generateUniqueCode(): string
    {
        return $this->sequenceService->generateCode(SequenceType::PRODUCT);
    }

    // =========================================================================
    // 통합 검색 메서드
    // =========================================================================

    /**
     * 정렬 옵션을 DB 컬럼/방향으로 변환합니다.
     *
     * @param  string  $sort  정렬 옵션 (latest, oldest, price_asc, price_desc, relevance 등)
     * @return array{0: string, 1: string} [컬럼명, 방향]
     */
    public function resolveSortColumn(string $sort): array
    {
        return match ($sort) {
            'latest' => ['created_at', 'desc'],
            'oldest' => ['created_at', 'asc'],
            'price_asc' => ['selling_price', 'asc'],
            'price_desc' => ['selling_price', 'desc'],
            default => ['created_at', 'desc'],
        };
    }

    /**
     * 키워드로 공개 상품을 검색합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  string  $sort  정렬 옵션
     * @param  int|null  $categoryId  카테고리 필터
     * @param  int  $offset  오프셋
     * @param  int  $limit  조회할 최대 항목 수
     * @return array{total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function searchByKeyword(string $keyword, string $sort = 'latest', ?int $categoryId = null, int $offset = 0, int $limit = 10): array
    {
        [$orderBy, $direction] = $this->resolveSortColumn($sort);

        return $this->repository->searchByKeyword($keyword, $orderBy, $direction, $categoryId, $offset, $limit);
    }

    /**
     * 키워드와 일치하는 공개 상품 수를 조회합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @param  int|null  $categoryId  카테고리 필터
     * @return int 일치하는 상품 수
     */
    public function countByKeyword(string $keyword, ?int $categoryId = null): int
    {
        return $this->repository->countByKeyword($keyword, $categoryId);
    }
}
