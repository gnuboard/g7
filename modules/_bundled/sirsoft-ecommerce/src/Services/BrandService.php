<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\BrandRepositoryInterface;

/**
 * 브랜드 서비스
 */
class BrandService
{
    public function __construct(
        protected BrandRepositoryInterface $repository
    ) {}

    /**
     * 브랜드 목록 조회
     *
     * @param array $filters 필터 조건
     * @return Collection
     */
    public function getAllBrands(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.brand.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.brand.filter_list_query', $filters);

        $brands = $this->repository->getAll($filters);

        // 필터 훅 - 결과 데이터 변형
        $brands = HookManager::applyFilters('sirsoft-ecommerce.brand.filter_list_result', $brands, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.brand.after_list', $brands, $filters);

        return $brands;
    }

    /**
     * 브랜드 상세 조회
     *
     * @param int $id 브랜드 ID
     * @return Brand|null
     */
    public function getBrand(int $id): ?Brand
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.brand.before_show', $id);

        $brand = $this->repository->findById($id, ['products']);

        if ($brand) {
            // 필터 훅 - 조회 결과 변형
            $brand = HookManager::applyFilters('sirsoft-ecommerce.brand.filter_show_result', $brand);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.brand.after_show', $brand);
        }

        return $brand;
    }

    /**
     * 브랜드 생성
     *
     * @param array $data 브랜드 데이터
     * @return Brand
     */
    public function createBrand(array $data): Brand
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.brand.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.brand.filter_create_data', $data);

        // 생성자/수정자 정보 추가
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $brand = DB::transaction(function () use ($data) {
            // 브랜드 생성
            $brand = $this->repository->create($data);

            return $brand->fresh();
        });

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.brand.after_create', $brand, $data);

        return $brand;
    }

    /**
     * 브랜드 수정
     *
     * @param int $id 브랜드 ID
     * @param array $data 수정할 데이터
     * @return Brand
     */
    public function updateBrand(int $id, array $data): Brand
    {
        $brand = $this->repository->findById($id);

        if (!$brand) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.brand_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.brand.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $brand->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.brand.filter_update_data', $data, $id);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        $brand = DB::transaction(function () use ($brand, $data) {
            // 브랜드 수정
            $brand = $this->repository->update($brand->id, $data);

            return $brand->fresh();
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.brand.after_update', $brand, $data, $snapshot);

        return $brand;
    }

    /**
     * 브랜드 상태 토글
     *
     * @param int $id 브랜드 ID
     * @return Brand
     * @throws \Exception
     */
    public function toggleStatus(int $id): Brand
    {
        $brand = $this->repository->findById($id);

        if (!$brand) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.brand_not_found'));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.brand.before_toggle_status', $brand);

        $brand = DB::transaction(function () use ($brand) {
            return $this->repository->update($brand->id, [
                'is_active' => !$brand->is_active,
            ]);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.brand.after_toggle_status', $brand);

        return $brand->fresh();
    }

    /**
     * 브랜드 삭제
     *
     * @param int $id 브랜드 ID
     * @return array 삭제 결과 정보
     * @throws \Exception
     */
    public function deleteBrand(int $id): array
    {
        $brand = $this->repository->findById($id);

        if (!$brand) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.brand_not_found'));
        }

        // 연결된 상품 수 확인
        $productsCount = $this->repository->getProductCount($id);

        // 연결된 상품이 있으면 삭제 차단
        if ($productsCount > 0) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.brand_has_products', [
                'count' => $productsCount,
            ]));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.brand.before_delete', $brand);

        DB::transaction(function () use ($brand) {
            $this->repository->delete($brand->id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.brand.after_delete', $brand->id);

        return [
            'brand_id' => $brand->id,
            'products_count' => 0,
        ];
    }
}
