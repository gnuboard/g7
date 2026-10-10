<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingTypeOperationException;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;

/**
 * 배송유형 서비스
 */
class ShippingTypeService
{
    /**
     * @param  ShippingTypeRepositoryInterface  $repository  배송유형 Repository
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 Repository
     */
    public function __construct(
        protected ShippingTypeRepositoryInterface $repository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
    ) {}

    /**
     * 배송유형 목록 조회
     *
     * @param  array  $filters  필터 조건
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
     * @param  int  $id  배송유형 ID
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
     * @param  string|null  $category  카테고리 필터
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
     * @param  array  $data  배송유형 데이터
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
     * @param  int  $id  배송유형 ID
     * @param  array  $data  수정할 데이터
     * @return ShippingType
     *
     * @throws ShippingTypeOperationException 대상 배송유형이 없을 때
     */
    public function updateType(int $id, array $data): ShippingType
    {
        $type = $this->repository->findById($id);

        if (! $type) {
            throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_not_found');
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
     * 저장 payload 가 사용 중인 배송유형을 지우려 하는지 **쓰기 없이** 판정합니다
     *
     * 설정 저장은 JSON 파일 쓰기라 트랜잭션으로 되돌릴 수 없다. `syncShippingTypes()` 안의
     * 가드는 파일이 이미 기록된 뒤에 돌아, 거절되면 배송 스칼라만 반영된 반쪽 상태가 남는다.
     * 그래서 컨트롤러가 파일 쓰기 **앞에서** 이 선행 판정을 부른다
     * (`ClaimReasonService::assertSyncDeletable()` 과 같은 형태).
     *
     * `syncShippingTypes()` 안의 가드는 그대로 둔다 — 이 서비스는 확장에 열려 있어 다른 호출
     * 경로가 이 선행 판정을 건너뛸 수 있다.
     *
     * @param  array  $typesData  저장하려는 types 배열
     * @return void 반환값 없음 (판정 결과는 예외로만 통지)
     *
     * @throws ShippingTypeOperationException 사용 중인 배송유형을 지우려 할 때
     */
    public function assertSyncDeletable(array $typesData): void
    {
        $existingIds = $this->repository->pluckIds();
        $incomingIds = array_filter(array_column($typesData, 'id'));

        foreach (array_diff($existingIds, $incomingIds) as $id) {
            $type = $this->repository->findById((int) $id);

            if (! $type) {
                continue;
            }

            $usageCount = $this->orderShippingRepository->countByShippingType($type->code);

            if ($usageCount > 0) {
                throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                    'name' => $type->getLocalizedName(),
                    'count' => $usageCount,
                ]);
            }
        }

        // 코드 변경도 같은 강도로 막는다 — 사유 카탈로그(`ClaimReasonService::assertSyncDeletable`)와
        // 같은 이유다. 주문 배송행은 배송유형을 `shipping_type` **코드 문자열**로만 기억하므로
        // (FK 없음), 코드를 바꾸면 삭제와 똑같이 그 유형으로 접수된 전건이 카탈로그를 잃는다:
        // 목록의 배송방법 라벨이 빈칸이 되고, 그 코드가 실린 북마크·프리셋 URL 은 목록 전체를
        // 422 로 만들며, 새 코드 기준 사용량이 0 이라 **삭제 가드마저 통과**한다.
        // 삭제만 막으면 평범한 오타 교정 한 번이 그 셋을 한꺼번에 낸다.
        foreach ($typesData as $data) {
            if (empty($data['id']) || ! in_array($data['id'], $existingIds)) {
                continue;
            }

            $type = $this->repository->findById((int) $data['id']);

            if (! $type || ! array_key_exists('code', $data) || $data['code'] === $type->code) {
                continue;
            }

            $usageCount = $this->orderShippingRepository->countByShippingType($type->code);

            if ($usageCount > 0) {
                throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_code_locked_in_use', [
                    'name' => $type->getLocalizedName(),
                    'count' => $usageCount,
                ]);
            }
        }
    }

    /**
     * 배송유형 일괄 동기화 (설정 저장 시 사용)
     *
     * payload에 있는 types를 DB와 동기화합니다.
     * - id 있음 → 기존 type 업데이트 (**실제로 바뀐 행만**)
     * - id 없음 → 새 type 생성
     * - DB에 있지만 payload에 없음 → 삭제 (주문에서 사용 중이면 예외)
     *
     * 생성·수정·삭제마다 상세 REST 경로(`createType`·`updateType`·`deleteType`)와 같은 훅
     * (`shipping_type.before_*` → `filter_*_data` → `after_*`)을 쏜다. 종전에는 이 경로만 훅 없이
     * 리포지토리를 직접 불러, 확장이 REST 경로에서만 배송유형 변경에 개입할 수 있었다
     * (`ClaimReasonService::syncReasons()` 가 같은 결함을 먼저 고쳤다).
     *
     * - 바뀌지 않은 행은 쓰지도, 훅을 쏘지도 않는다. 설정 탭은 저장마다 유형 전부를 보내므로,
     *   전부 갱신하면 아무것도 고치지 않은 저장이 다른 운영자의 `updated_by` 로 행을 덮는다.
     * - after 훅은 커밋 뒤에 쏜다 — 뒤 행에서 예외가 나 전체가 되돌아가도 이미 나간 after 훅은
     *   되돌릴 수 없다.
     *
     * @param  array  $typesData  types 배열
     * @return void
     *
     * @throws ShippingTypeOperationException 사용 중인 배송유형 삭제 시도 시
     */
    public function syncShippingTypes(array $typesData): void
    {
        DB::transaction(function () use ($typesData) {
            $existingIds = $this->repository->pluckIds();
            $incomingIds = array_filter(array_column($typesData, 'id'));

            // 삭제: DB에 있지만 payload에 없는 항목
            $toDeleteIds = array_diff($existingIds, $incomingIds);
            foreach ($toDeleteIds as $id) {
                $type = $this->repository->findById((int) $id);
                if ($type) {
                    $usageCount = $this->orderShippingRepository->countByShippingType($type->code);
                    if ($usageCount > 0) {
                        throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                            'name' => $type->getLocalizedName(),
                            'count' => $usageCount,
                        ]);
                    }
                    HookManager::doAction('sirsoft-ecommerce.shipping_type.before_delete', $type);
                    $this->repository->delete((int) $type->id);
                    $deletedId = (int) $type->id;
                    DB::afterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.shipping_type.after_delete', $deletedId));
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
                ];

                if (! empty($data['id']) && in_array($data['id'], $existingIds)) {
                    $this->syncExistingType((int) $data['id'], $typeData);
                } else {
                    HookManager::doAction('sirsoft-ecommerce.shipping_type.before_create', $typeData);
                    $typeData = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_create_data', $typeData);
                    $typeData['created_by'] = Auth::id();
                    $typeData['updated_by'] = Auth::id();
                    $created = $this->repository->create($typeData);
                    DB::afterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.shipping_type.after_create', $created, $typeData));
                }
            }
        });

        // 캐시 초기화
        ShippingType::clearCodeCache();
    }

    /**
     * 동기화 대상 중 기존 배송유형 한 건을 반영합니다. (바뀐 경우에만)
     *
     * @param  int  $id  배송유형 ID
     * @param  array<string, mixed>  $typeData  반영할 값 (updated_by 제외)
     * @return void
     *
     * @throws ShippingTypeOperationException 사용 중인 배송유형의 코드를 바꾸려 할 때
     */
    protected function syncExistingType(int $id, array $typeData): void
    {
        $existing = $this->repository->findById($id);

        if (! $existing || ! $this->wouldChange($existing, $typeData)) {
            return;
        }

        // 선행 판정(assertSyncDeletable)을 거치지 않는 호출 경로가 있을 수 있으므로
        // 여기서도 코드 변경을 막는다 — 삭제 가드와 같은 이유다
        // (`ClaimReasonService::syncReasons()` 와 같은 형태).
        if ($existing->code !== $typeData['code']) {
            $usageCount = $this->orderShippingRepository->countByShippingType($existing->code);

            if ($usageCount > 0) {
                throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_code_locked_in_use', [
                    'name' => $existing->getLocalizedName(),
                    'count' => $usageCount,
                ]);
            }
        }

        HookManager::doAction('sirsoft-ecommerce.shipping_type.before_update', $id, $typeData);
        $snapshot = $existing->toArray();
        $typeData = HookManager::applyFilters('sirsoft-ecommerce.shipping_type.filter_update_data', $typeData, $id);
        $typeData['updated_by'] = Auth::id();

        $updated = $this->repository->update($id, $typeData);
        DB::afterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.shipping_type.after_update', $updated, $typeData, $snapshot));
    }

    /**
     * 값을 반영하면 배송유형이 실제로 바뀌는지 판정합니다. (쓰기 없음)
     *
     * 복제본에 채워 캐스트 기준 dirty 를 본다. 다국어 이름은 로케일 순서·빈 번역만 다른 것을 같은
     * 값으로 본다(화면이 키 순서를 보장하지 않는다).
     *
     * @param  ShippingType  $type  기존 배송유형
     * @param  array<string, mixed>  $data  반영할 값
     * @return bool 바뀌면 true
     */
    protected function wouldChange(ShippingType $type, array $data): bool
    {
        $probe = clone $type;
        $probe->fill($data);

        foreach (array_keys($probe->getDirty()) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($key === 'name' && $this->normalizeTranslations($type->name) === $this->normalizeTranslations($data['name'])) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * 다국어 이름을 비교용으로 정규화합니다. (빈 번역 제거 + 로케일 정렬)
     *
     * @param  mixed  $name  다국어 이름 (배열 또는 그 밖의 값)
     * @return array<string, string>|string 정규화된 이름
     */
    protected function normalizeTranslations(mixed $name): array|string
    {
        if (! is_array($name)) {
            return (string) $name;
        }

        $normalized = [];

        foreach ($name as $locale => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[(string) $locale] = (string) $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * 배송유형 삭제
     *
     * @param  int  $id  배송유형 ID
     * @return array 삭제 결과 정보
     *
     * @throws ShippingTypeOperationException 대상이 없거나 주문에서 사용 중일 때
     */
    public function deleteType(int $id): array
    {
        $type = $this->repository->findById($id);

        if (! $type) {
            throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_not_found');
        }

        // 주문에서 사용 중인지 확인
        $usageCount = $this->orderShippingRepository->countByShippingType($type->code);

        if ($usageCount > 0) {
            throw new ShippingTypeOperationException('sirsoft-ecommerce::exceptions.shipping_type_in_use', [
                'name' => $type->getLocalizedName(),
                'count' => $usageCount,
            ]);
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
