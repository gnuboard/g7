<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;

/**
 * 클레임 사유 서비스
 */
class ClaimReasonService
{
    /**
     * @param  ClaimReasonRepositoryInterface  $repository  클레임 사유 Repository
     * @param  OrderCancelRepositoryInterface  $orderCancelRepository  주문 취소 이력 Repository (사유 사용 중 여부 판정용)
     */
    public function __construct(
        protected ClaimReasonRepositoryInterface $repository,
        protected OrderCancelRepositoryInterface $orderCancelRepository,
    ) {}

    /**
     * 클레임 사유 목록 조회
     *
     * @param  array  $filters  필터 조건
     * @return Collection 조회된 클레임 사유 컬렉션
     */
    public function getAllReasons(array $filters = []): Collection
    {
        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_list', $filters);

        $filters = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_list_query', $filters);

        $reasons = $this->repository->getAll($filters);

        $reasons = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_list_result', $reasons, $filters);

        HookManager::doAction('sirsoft-ecommerce.claim_reason.after_list', $reasons, $filters);

        return $reasons;
    }

    /**
     * 클레임 사유 상세 조회
     *
     * @param  int  $id  사유 ID
     * @return ClaimReason|null 조회된 클레임 사유 (없으면 null)
     */
    public function getReason(int $id): ?ClaimReason
    {
        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_show', $id);

        $reason = $this->repository->findById($id);

        if ($reason) {
            $reason = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_show_result', $reason);
            HookManager::doAction('sirsoft-ecommerce.claim_reason.after_show', $reason);
        }

        return $reason;
    }

    /**
     * 활성 클레임 사유 목록 조회
     *
     * @param  string  $type  사유 유형
     * @return Collection 활성 클레임 사유 컬렉션
     */
    public function getActiveReasons(string $type = 'refund'): Collection
    {
        return $this->repository->getActiveReasons($type);
    }

    /**
     * 사용자 선택 가능한 클레임 사유 목록 조회
     *
     * @param  string  $type  사유 유형
     * @return Collection 사용자가 선택 가능한 클레임 사유 컬렉션
     */
    public function getUserSelectableReasons(string $type = 'refund'): Collection
    {
        return $this->repository->getUserSelectableReasons($type);
    }

    /**
     * 설정 페이지용 사유 목록을 반환합니다.
     *
     * @param  string  $type  사유 유형
     * @return array 설정 페이지에서 사용할 배열 형태의 사유 목록
     */
    public function getReasonsForSettings(string $type = 'refund'): array
    {
        $reasons = $this->getAllReasons(['type' => $type]);

        return $reasons->map(fn ($r) => [
            'id' => $r->id,
            'type' => $r->type->value,
            'code' => $r->code,
            'name' => $r->name,
            'localized_name' => $r->getLocalizedName(),
            'fault_type' => $r->fault_type->value,
            'is_user_selectable' => $r->is_user_selectable,
            'is_active' => $r->is_active,
            'sort_order' => $r->sort_order,
        ])->values()->toArray();
    }

    /**
     * 클레임 사유 생성
     *
     * @param  array  $data  사유 데이터
     * @return ClaimReason 생성된 클레임 사유
     */
    public function createReason(array $data): ClaimReason
    {
        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_create', $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_create_data', $data);

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $reason = DB::transaction(function () use ($data) {
            $reason = $this->repository->create($data);

            return $reason->fresh();
        });

        HookManager::doAction('sirsoft-ecommerce.claim_reason.after_create', $reason, $data);

        return $reason;
    }

    /**
     * 클레임 사유 수정
     *
     * @param  int  $id  사유 ID
     * @param  array  $data  수정할 데이터
     * @return ClaimReason 수정된 클레임 사유
     *
     * @throws ClaimReasonException 대상 사유를 찾을 수 없을 때
     */
    public function updateReason(int $id, array $data): ClaimReason
    {
        $reason = $this->repository->findById($id);

        if (! $reason) {
            throw ClaimReasonException::notFound();
        }

        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_update', $id, $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_update_data', $data, $id);

        $data['updated_by'] = Auth::id();

        $reason = DB::transaction(function () use ($reason, $data) {
            $reason = $this->repository->update($reason->id, $data);

            return $reason->fresh();
        });

        HookManager::doAction('sirsoft-ecommerce.claim_reason.after_update', $reason, $data);

        return $reason;
    }

    /**
     * 클레임 사유 상태 토글
     *
     * @param  int  $id  사유 ID
     * @return ClaimReason 상태가 토글된 클레임 사유
     *
     * @throws ClaimReasonException 대상 사유를 찾을 수 없을 때
     */
    public function toggleStatus(int $id): ClaimReason
    {
        $reason = $this->repository->findById($id);

        if (! $reason) {
            throw ClaimReasonException::notFound();
        }

        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_toggle_status', $reason);

        $reason = DB::transaction(function () use ($reason) {
            return $this->repository->update($reason->id, [
                'is_active' => ! $reason->is_active,
            ]);
        });

        HookManager::doAction('sirsoft-ecommerce.claim_reason.after_toggle_status', $reason);

        return $reason->fresh();
    }

    /**
     * 클레임 사유 일괄 동기화 (설정 저장 시 사용)
     *
     * payload에 있는 reasons를 DB와 동기화합니다.
     * - id 있음 → 기존 reason 업데이트
     * - id 없음 → 새 reason 생성
     * - DB에 있지만 payload에 없음 → 삭제 (주문에서 사용 중이면 예외)
     *
     * @param  string  $type  사유 유형 (refund 등)
     * @param  array  $reasonsData  reasons 배열
     * @return void 반환값 없음 (동기화 결과는 예외로만 통지)
     *
     * @throws ClaimReasonException 사용 중인 사유 삭제 시도 시
     */
    public function syncReasons(string $type, array $reasonsData): void
    {
        DB::transaction(function () use ($type, $reasonsData) {
            $existingIds = $this->repository->getIdsByType($type);
            $incomingIds = array_filter(array_column($reasonsData, 'id'));

            // 삭제: DB에 있지만 payload에 없는 항목
            $toDeleteIds = array_diff($existingIds, $incomingIds);
            foreach ($toDeleteIds as $id) {
                $reason = $this->repository->findById($id);
                if ($reason) {
                    $usageCount = $this->orderCancelRepository->countByCancelReasonType($reason->code);
                    if ($usageCount > 0) {
                        throw ClaimReasonException::inUse($usageCount);
                    }
                    $this->repository->delete($id);
                }
            }

            // 생성/수정
            foreach ($reasonsData as $index => $data) {
                $reasonData = [
                    'type' => $type,
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'fault_type' => $data['fault_type'],
                    'is_user_selectable' => $data['is_user_selectable'] ?? true,
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $index,
                    'updated_by' => Auth::id(),
                ];

                if (! empty($data['id']) && in_array($data['id'], $existingIds)) {
                    $this->repository->update((int) $data['id'], $reasonData);
                } else {
                    $reasonData['created_by'] = Auth::id();
                    $this->repository->create($reasonData);
                }
            }
        });
    }

    /**
     * 클레임 사유 삭제
     *
     * @param  int  $id  사유 ID
     * @return array 삭제 결과 정보
     *
     * @throws ClaimReasonException 대상 사유가 없거나 주문에서 사용 중일 때
     */
    public function deleteReason(int $id): array
    {
        $reason = $this->repository->findById($id);

        if (! $reason) {
            throw ClaimReasonException::notFound();
        }

        // 주문에서 사용 중인지 확인
        $usageCount = $this->orderCancelRepository->countByCancelReasonType($reason->code);

        if ($usageCount > 0) {
            throw ClaimReasonException::inUse($usageCount);
        }

        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_delete', $reason);

        DB::transaction(function () use ($reason) {
            $this->repository->delete($reason->id);
        });

        HookManager::doAction('sirsoft-ecommerce.claim_reason.after_delete', $reason->id);

        return [
            'reason_id' => $reason->id,
        ];
    }
}
