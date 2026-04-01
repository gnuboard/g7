<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLabelRepositoryInterface;

/**
 * 상품 라벨 서비스
 */
class ProductLabelService
{
    public function __construct(
        protected ProductLabelRepositoryInterface $repository
    ) {}

    /**
     * 라벨 목록 조회
     *
     * @param array $filters 필터 조건
     * @return Collection
     */
    public function getAllLabels(array $filters = []): Collection
    {
        // Before 훅 - 검색 조건 전처리
        HookManager::doAction('sirsoft-ecommerce.label.before_list', $filters);

        // 필터 훅 - 검색 조건 변형
        $filters = HookManager::applyFilters('sirsoft-ecommerce.label.filter_list_query', $filters);

        $labels = $this->repository->getAll($filters);

        // 필터 훅 - 결과 데이터 변형
        $labels = HookManager::applyFilters('sirsoft-ecommerce.label.filter_list_result', $labels, $filters);

        // After 훅 - 조회 후처리
        HookManager::doAction('sirsoft-ecommerce.label.after_list', $labels, $filters);

        return $labels;
    }

    /**
     * 라벨 상세 조회
     *
     * @param int $id 라벨 ID
     * @return ProductLabel|null
     */
    public function getLabel(int $id): ?ProductLabel
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.label.before_show', $id);

        $label = $this->repository->findById($id, ['assignments']);

        if ($label) {
            // 필터 훅 - 조회 결과 변형
            $label = HookManager::applyFilters('sirsoft-ecommerce.label.filter_show_result', $label);

            // After 훅
            HookManager::doAction('sirsoft-ecommerce.label.after_show', $label);
        }

        return $label;
    }

    /**
     * 활성 라벨 목록 조회
     *
     * @return Collection
     */
    public function getActiveLabels(): Collection
    {
        return $this->repository->getActiveLabels();
    }

    /**
     * 라벨 생성
     *
     * @param array $data 라벨 데이터
     * @return ProductLabel
     */
    public function createLabel(array $data): ProductLabel
    {
        // Before 훅 - 데이터 검증, 전처리
        HookManager::doAction('sirsoft-ecommerce.label.before_create', $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.label.filter_create_data', $data);

        $label = DB::transaction(function () use ($data) {
            // 라벨 생성
            $label = $this->repository->create($data);

            return $label->fresh();
        });

        // After 훅 - 후처리, 알림, 캐시 등
        HookManager::doAction('sirsoft-ecommerce.label.after_create', $label, $data);

        return $label;
    }

    /**
     * 라벨 수정
     *
     * @param int $id 라벨 ID
     * @param array $data 수정할 데이터
     * @return ProductLabel
     * @throws \Exception
     */
    public function updateLabel(int $id, array $data): ProductLabel
    {
        $label = $this->repository->findById($id);

        if (!$label) {
            throw (new ModelNotFoundException)->setModel(ProductLabel::class, $id);
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.label.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $label->toArray();

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.label.filter_update_data', $data, $id);

        $label = DB::transaction(function () use ($label, $data) {
            // 라벨 수정
            $label = $this->repository->update($label->id, $data);

            return $label->fresh();
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.label.after_update', $label, $data, $snapshot);

        return $label;
    }

    /**
     * 라벨 상태 토글
     *
     * @param int $id 라벨 ID
     * @return ProductLabel
     * @throws \Exception
     */
    public function toggleStatus(int $id): ProductLabel
    {
        $label = $this->repository->findById($id);

        if (!$label) {
            throw (new ModelNotFoundException)->setModel(ProductLabel::class, $id);
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.label.before_toggle_status', $label);

        $label = DB::transaction(function () use ($label) {
            return $this->repository->update($label->id, [
                'is_active' => !$label->is_active,
            ]);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.label.after_toggle_status', $label);

        return $label->fresh();
    }

    /**
     * 라벨 삭제
     *
     * @param int $id 라벨 ID
     * @return array 삭제 결과 정보
     * @throws \Exception
     */
    public function deleteLabel(int $id): array
    {
        $label = $this->repository->findById($id);

        if (!$label) {
            throw (new ModelNotFoundException)->setModel(ProductLabel::class, $id);
        }

        // 연결된 상품 수 확인
        $productsCount = $this->repository->getProductCount($id);

        // 연결된 상품이 있으면 삭제 차단
        if ($productsCount > 0) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.label_has_products', [
                'count' => $productsCount,
            ]));
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.label.before_delete', $label);

        DB::transaction(function () use ($label) {
            $this->repository->delete($label->id);
        });

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.label.after_delete', $label->id);

        return [
            'label_id' => $label->id,
            'products_count' => 0,
        ];
    }
}
