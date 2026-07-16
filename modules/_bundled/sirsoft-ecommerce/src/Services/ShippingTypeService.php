<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;

/**
 * 배송유형 서비스
 */
class ShippingTypeService
{
    /**
     * @param ShippingTypeRepositoryInterface $repository 배송유형 Repository
     * @param OrderShippingRepositoryInterface $orderShippingRepository 주문 배송 Repository
     */
    public function __construct(
        protected ShippingTypeRepositoryInterface $repository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
    ) {}

    /**
     * 배송유형 목록 조회
     *
     * @param array $filters 필터 조건
     * @return Collection
     */
    public function getAllTypes(array $filters = []): Collection
    {
        HookManager::doAction('sirsoft-ecommerce.shipping_type.before_list', $filters);

        $filters = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_list_query', $filters);

        $types = $this->repository->getAll($filters);

        $types = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_list_result', $types, $filters);

        HookManager::doAction('sirsoft-ecommerce.shipping_type.after_list', $types, $filters);

        return $types;
    }

    /**
     * 배송유형 상세 조회
     *
     * @param int $id 배송유형 ID
     * @return ShippingType|null
     */
    public function getType(int $id): ?ShippingType
    {
        HookManager::doAction('sirsoft-ecommerce.shipping_type.before_show', $id);

        $type = $this->repository->findById($id);

        if ($type) {
            $type = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_show_result', $type);
            HookManager::doAction('sirsoft-ecommerce.shipping_type.after_show', $type);
        }

        return $type;
    }

    /**
     * 활성 배송유형 목록 조회
     *
     * @param string|null $category 카테고리 필터
     * @return Collection
     */
    public function getActiveTypes(?string $category = null): Collection
    {
        return $this->repository->getActiveTypes($category);
    }

    /**
     * 설정 페이지용 배송유형 목록을 반환합니다.
     *
     * @return array 설정 페이지에서 사용할 배열 형태의 배송유형 목록
     */
    public function getTypesForSettings(): array
    {
        $types = $this->getAllTypes();

        return $types->map(fn ($t) => [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'category' => $t->category,
            'is_active' => $t->is_active,
            'sort_order' => $t->sort_order,
        ])->values()->toArray();
    }

    /**
     * 배송유형 생성
     *
     * @param array $data 배송유형 데이터
     * @return ShippingType
     */
    public function createType(array $data): ShippingType
    {
        HookManager::doAction('sirsoft-ecommerce.shipping_type.before_create', $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_create_data', $data);

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $type = DB::transaction(function () use ($data) {
            $type = $this->repository->create($data);

            return $type->fresh();
        });

        // 캐시 초기화
        ShippingType::clearCodeCache();

        HookManager::doAction('sirsoft-ecommerce.shipping_type.after_create', $type, $data);

        return $type;
    }

    /**
     * 배송유형 수정
     *
     * @param int $id 배송유형 ID
     * @param array $data 수정할 데이터
     * @return ShippingType
     * @throws \Exception
     */
    public function updateType(int $id, array $data): ShippingType
    {
        $type = $this->repository->findById($id);

        if (! $type) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_type_not_found'));
        }

        HookManager::doAction('sirsoft-ecommerce.shipping_type.before_update', $id, $data);

        $snapshot = $type->toArray();

        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_update_data', $data, $id);

        $data['updated_by'] = Auth::id();

        $type = DB::transaction(function () use ($type, $data) {
            $type = $this->repository->update($type->id, $data);

            return $type->fresh();
        });

        // 캐시 초기화
        ShippingType::clearCodeCache();

        HookManager::doAction('sirsoft-ecommerce.shipping_type.after_update', $type, $data, $snapshot);

        return $type;
    }

    /**
     * 배송유형 일괄 동기화 (설정 저장 시 사용)
     *
     * payload에 있는 types를 DB와 동기화합니다.
     * - id 있음 → 기존 type 업데이트
     * - id 없음 → 새 type 생성
     * - DB에 있지만 payload에 없음 → 삭제 (주문에서 사용 중이면 예외)
     *
     * @param array $typesData types 배열
     * @return void
     * @throws \Exception 사용 중인 배송유형 삭제 시도 시
     */
    public function syncShippingTypes(array $typesData): void
    {
        DB::transaction(function () use ($typesData) {
            $existingIds = ShippingType::pluck('id')->toArray();
            $incomingIds = array_filter(array_column($typesData, 'id'));

            // 삭제: DB에 있지만 payload에 없는 항목
            $toDeleteIds = array_diff($existingIds, $incomingIds);
            foreach ($toDeleteIds as $id) {
                $type = ShippingType::find($id);
                if ($type) {
                    $usageCount = $this->orderShippingRepository->countByShippingType($type->code);
                    if ($usageCount > 0) {
                        throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                            'name' => $type->getLocalizedName(),
                            'count' => $usageCount,
                        ]));
                    }
                    $type->delete();
                }
            }

            // 생성/수정
            foreach ($typesData as $index => $data) {
                $typeData = [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'category' => $data['category'],
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $index,
                    'updated_by' => Auth::id(),
                ];

                if (! empty($data['id']) && in_array($data['id'], $existingIds)) {
                    ShippingType::where('id', $data['id'])->update($typeData);
                } else {
                    $typeData['created_by'] = Auth::id();
                    ShippingType::create($typeData);
                }
            }
        });

        // 캐시 초기화
        ShippingType::clearCodeCache();
    }

    /**
     * 배송유형 삭제
     *
     * @param int $id 배송유형 ID
     * @return array 삭제 결과 정보
     * @throws \Exception
     */
    public function deleteType(int $id): array
    {
        $type = $this->repository->findById($id);

        if (! $type) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_type_not_found'));
        }

        // 주문에서 사용 중인지 확인
        $usageCount = $this->orderShippingRepository->countByShippingType($type->code);

        if ($usageCount > 0) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                'name' => $type->getLocalizedName(),
                'count' => $usageCount,
            ]));
        }

        HookManager::doAction('sirsoft-ecommerce.shipping_type.before_delete', $type);

        DB::transaction(function () use ($type) {
            $this->repository->delete($type->id);
        });

        // 캐시 초기화
        ShippingType::clearCodeCache();

        HookManager::doAction('sirsoft-ecommerce.shipping_type.after_delete', $type->id);

        return [
            'type_id' => $type->id,
        ];
    }
}
