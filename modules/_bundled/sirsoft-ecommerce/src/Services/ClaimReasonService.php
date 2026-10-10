<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\ClaimReasonException;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 클레임 사유 서비스
 */
class ClaimReasonService
{
    /**
     * @param  ClaimReasonRepositoryInterface  $repository  클레임 사유 Repository
     * @param  OrderCancelRepositoryInterface  $orderCancelRepository  주문 취소 이력 Repository (사유 사용 중 여부 판정용)
     * @param  OrderExchangeRepositoryInterface  $orderExchangeRepository  교환 신청서 Repository (교환 사유 사용량 집계용)
     * @param  OrderReturnRepositoryInterface  $orderReturnRepository  반품 신청서 Repository (반품 사유 사용량 집계용)
     * @param  OrderRefundRepositoryInterface  $orderRefundRepository  환불 신청서 Repository (환불 사유 사용량 집계용)
     */
    public function __construct(
        protected ClaimReasonRepositoryInterface $repository,
        protected OrderCancelRepositoryInterface $orderCancelRepository,
        protected OrderExchangeRepositoryInterface $orderExchangeRepository,
        protected OrderReturnRepositoryInterface $orderReturnRepository,
        protected OrderRefundRepositoryInterface $orderRefundRepository,
    ) {}

    /**
     * 사유가 실제로 사용 중인 건수를 유형에 맞는 표 전수에서 셉니다.
     *
     * 환불 사유는 환불신청서와 즉시취소가 함께 쓰므로 두 표를 더한다.
     *
     * 시더의 stale 정리도 이 판정을 쓴다(그래서 public 이다) — 사용 중 사유를 지키는 기준이
     * 설정 저장 경로와 갈리면, 한쪽이 400 으로 막는 것을 다른 쪽이 조용히 지운다.
     *
     * @param  ClaimReason  $reason  대상 사유
     * @return int 사용 건수
     */
    public function countUsage(ClaimReason $reason): int
    {
        $type = $reason->type instanceof ClaimReasonTypeEnum ? $reason->type : ClaimReasonTypeEnum::tryFrom((string) $reason->type);

        return match ($type) {
            ClaimReasonTypeEnum::EXCHANGE => $this->orderExchangeRepository->countByReasonCode($reason->code),
            ClaimReasonTypeEnum::RETURN => $this->orderReturnRepository->countByReasonCode($reason->code),
            ClaimReasonTypeEnum::REFUND => $this->orderRefundRepository->countByReasonCode($reason->code)
                + $this->orderCancelRepository->countByCancelReasonType($reason->code),
            default => $this->orderCancelRepository->countByCancelReasonType($reason->code),
        };
    }

    /**
     * 유형 값을 문자열로 정규화합니다.
     *
     * 모델은 Enum 캐스팅, 요청은 문자열로 들어와 `!==` 로 직접 비교하면 값이 같아도 항상
     * 다르다고 판정된다 — 그러면 사용 중이 아닌 사유의 평범한 저장까지 사용량 조회를 타고,
     * 사용 중이면 유형을 바꾸지 않았는데도 거절된다.
     *
     * @param  mixed  $type  Enum 또는 문자열 유형 값
     * @return string 정규화된 유형 문자열
     */
    protected function typeValue(mixed $type): string
    {
        return $type instanceof ClaimReasonTypeEnum ? $type->value : (string) $type;
    }

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
    public function getActiveReasons(string $type): Collection
    {
        return $this->repository->getActiveReasons($type);
    }

    /**
     * 사용자 선택 가능한 클레임 사유 목록 조회
     *
     * @param  string  $type  사유 유형
     * @return Collection 사용자가 선택 가능한 클레임 사유 컬렉션
     */
    public function getUserSelectableReasons(string $type): Collection
    {
        return $this->repository->getUserSelectableReasons($type);
    }

    /**
     * 설정 페이지용 사유 목록을 반환합니다.
     *
     * @param  string  $type  사유 유형
     * @return array 설정 페이지에서 사용할 배열 형태의 사유 목록
     */
    public function getReasonsForSettings(string $type): array
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
     * @throws ClaimReasonException 대상 사유를 찾을 수 없거나, 사용 중인 사유의 코드를 바꾸려 할 때
     */
    public function updateReason(int $id, array $data): ClaimReason
    {
        $reason = $this->repository->findById($id);

        if (! $reason) {
            throw ClaimReasonException::notFound();
        }

        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_update', $id, $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_update_data', $data, $id);

        // 상세 수정 경로도 설정 저장 경로와 같은 강도로 막는다 — 두 경로의 가드가 갈리면
        // 약한 쪽이 우회로가 된다.
        //
        // 사용량은 **변경 전** 사유로 센다. `countUsage()` 는 유형으로 표를 고르므로 변경 후
        // 값으로 세면 엉뚱한 표를 보게 되고, 그 표가 비어 있으면 가드가 통과해 버린다.
        $usageCount = null;

        if (array_key_exists('code', $data) && $data['code'] !== $reason->code) {
            $usageCount = $this->countUsage($reason);

            if ($usageCount > 0) {
                throw ClaimReasonException::codeLockedInUse($usageCount);
            }
        }

        // 유형도 같은 강도로 잠근다 — 라벨 해석이 `(code, type)` 쌍이라 유형만 옮겨도 피해가
        // 코드 변경과 같고, 옮겨진 뒤에는 사용량 판정이 새 유형의 표를 보므로 삭제 가드까지
        // 연쇄로 뚫린다. 잠그는 것은 코드와 유형 둘뿐이며 이름·귀책·노출 여부는 계속 고칠 수
        // 있다(가드가 넓으면 사용 중인 사유의 오타조차 고칠 수 없다).
        if (array_key_exists('type', $data) && $this->typeValue($data['type']) !== $this->typeValue($reason->type)) {
            $usageCount ??= $this->countUsage($reason);

            if ($usageCount > 0) {
                throw ClaimReasonException::typeLockedInUse($usageCount);
            }
        }

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
     * 일괄 동기화가 지울 사유들이 실제로 지워도 되는지만 판정합니다. (쓰기 없음)
     *
     * 설정 저장은 JSON 파일 쓰기가 먼저이고 카탈로그 동기화가 뒤라, 동기화가 예외를 던지면
     * 스칼라 설정만 반영된 반쪽 상태가 남는다. 파일 쓰기는 트랜잭션으로 되돌릴 수 없으므로
     * 호출자가 **쓰기 전에** 이 가드를 먼저 부른다. `syncReasons()` 안의 같은 가드는
     * 그대로 둔다 — 다른 호출 경로가 이 선행 판정을 건너뛸 수 있다.
     *
     * @param  string  $type  사유 유형 (exchange|return|refund)
     * @param  array  $reasonsData  저장하려는 reasons 배열
     * @param  array<int, int>  $deleteIds  운영자가 휴지통으로 지목한 사유 id 목록
     * @return void 반환값 없음 (판정 결과는 예외로만 통지)
     *
     * @throws ClaimReasonException 사용 중인 사유를 지우거나 그 코드를 바꾸려 할 때
     */
    public function assertSyncDeletable(string $type, array $reasonsData, array $deleteIds = []): void
    {
        $existingIds = $this->repository->getIdsByType($type);

        foreach ($this->deletableIds($existingIds, $deleteIds) as $id) {
            $reason = $this->repository->findById($id);

            if (! $reason) {
                continue;
            }

            $usageCount = $this->countUsage($reason);

            if ($usageCount > 0) {
                throw ClaimReasonException::inUse($usageCount);
            }
        }

        // 코드 변경도 같은 강도로 막는다. 접수된 신청서는 사유를 `reason_code` 문자열로만
        // 기억하므로(FK 없음), 코드를 바꾸면 삭제와 똑같이 그 사유로 접수된 전건이 카탈로그를
        // 잃는다 — 삭제만 막으면 평범한 오타 교정 한 번이 같은 피해를 조용히 낸다.
        foreach ($reasonsData as $data) {
            if (empty($data['id']) || ! in_array($data['id'], $existingIds)) {
                continue;
            }

            $reason = $this->repository->findById((int) $data['id']);

            if (! $reason || ! array_key_exists('code', $data) || $data['code'] === $reason->code) {
                continue;
            }

            $usageCount = $this->countUsage($reason);

            if ($usageCount > 0) {
                throw ClaimReasonException::codeLockedInUse($usageCount);
            }
        }
    }

    /**
     * 클레임 사유 일괄 동기화 (설정 저장 시 사용)
     *
     * payload에 있는 reasons를 DB와 동기화합니다.
     * - id 있음 → 기존 reason 업데이트
     * - id 없음 → 새 reason 생성
     * - `$deleteIds` 에 지목된 id → 삭제 (그 유형에서 사용 중이면 예외)
     * - **DB 에 있지만 payload 에 없는 행은 손대지 않는다.** 종전에는 그 행을 삭제로 읽었다 —
     *   다른 운영자(또는 다른 탭)가 화면을 연 뒤에 만든 사유는 그 화면의 페이로드에 없으므로,
     *   아무 사유도 건드리지 않은 저장 한 번이 남의 사유를 조용히 지웠다(#599 S0-D2 — 통합 실측
     *   세션0 이 만든 사유 3건이 그렇게 사라진 것으로 보이나 흔적이 없어 확정하지 못했다).
     *   삭제는 운영자가 휴지통을 눌러 지목한 id 로만 일어난다.
     *
     * 생성·수정·삭제마다 상세 REST 경로와 같은 훅(`claim_reason.before_*` → `filter_*_data` →
     * `after_*`)을 쏜다 — 활동로그가 after 훅을 듣고, 확장은 before/filter 로 개입한다. 종전에는
     * 설정 저장 경로만 훅 없이 리포지토리를 직접 불러, 카탈로그 변경이 「설정 저장」 한 줄 뒤에 숨었다.
     *
     * - **실제로 바뀐 행만 갱신한다.** 설정 탭은 저장할 때마다 그 유형의 사유 전부를 보낸다 —
     *   전부 update 하면 아무것도 고치지 않은 저장 한 번이 사유 수만큼 「사유 수정」 로그를 쌓는다.
     * - **after 훅은 커밋 뒤에 쏜다.** 뒤따르는 행에서 예외가 나 전체가 되돌아가도 이미 쏜 after
     *   훅은 되돌릴 수 없어, 저장되지 않은 변경이 이력에 남는다.
     *
     * @param  string  $type  사유 유형 (exchange|return|refund)
     * @param  array  $reasonsData  reasons 배열
     * @param  array<int, int>  $deleteIds  운영자가 휴지통으로 지목한 사유 id 목록
     * @return void 반환값 없음 (동기화 결과는 예외로만 통지)
     *
     * @throws ClaimReasonException 사용 중인 사유를 삭제하거나 그 코드를 바꾸려 할 때
     */
    public function syncReasons(string $type, array $reasonsData, array $deleteIds = []): void
    {
        DB::transaction(function () use ($type, $reasonsData, $deleteIds) {
            $existingIds = $this->repository->getIdsByType($type);

            // 삭제: 운영자가 지목한 id 만 (페이로드 부재는 삭제가 아니다)
            foreach ($this->deletableIds($existingIds, $deleteIds) as $id) {
                $reason = $this->repository->findById($id);
                if ($reason) {
                    // 사용량은 그 사유의 **유형에 맞는 표 전수**를 센다 (countUsage 단일 출처).
                    // 취소 한 표만 세면 설정 화면의 삭제 경로가 상세 삭제(deleteReason)보다
                    // 약한 가드가 되어, 교환·반품에서 사용 중인 사유가 이 경로로만 지워진다.
                    // 그 순간 해당 신청서 전건의 사유 라벨이 카탈로그를 잃어 원시 코드로 노출된다.
                    $usageCount = $this->countUsage($reason);
                    if ($usageCount > 0) {
                        throw ClaimReasonException::inUse($usageCount);
                    }
                    HookManager::doAction('sirsoft-ecommerce.claim_reason.before_delete', $reason);
                    $this->repository->delete($id);
                    DB::afterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim_reason.after_delete', $reason->id, $reason));
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
                ];

                if (! empty($data['id']) && in_array($data['id'], $existingIds)) {
                    $this->syncExistingReason((int) $data['id'], $reasonData);
                } else {
                    HookManager::doAction('sirsoft-ecommerce.claim_reason.before_create', $reasonData);
                    $reasonData = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_create_data', $reasonData);
                    $reasonData['created_by'] = Auth::id();
                    $reasonData['updated_by'] = Auth::id();
                    $created = $this->repository->create($reasonData);
                    DB::afterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim_reason.after_create', $created, $reasonData));
                }
            }
        });
    }

    /**
     * 동기화 대상 중 기존 사유 한 건을 반영합니다. (바뀐 경우에만)
     *
     * 바뀌었는지는 모델 캐스트 기준으로 본다 — 다국어 이름(JSON)·불리언·Enum 을 문자열로 비교하면
     * 같은 값도 다르다고 판정되어 무변경 저장이 로그를 쌓는다. `updated_by` 는 판정에서 뺀다
     * (저장자만 다른 것은 사유가 바뀐 것이 아니다).
     *
     * @param  int  $id  사유 ID
     * @param  array<string, mixed>  $reasonData  반영할 값 (updated_by 제외)
     * @return void
     *
     * @throws ClaimReasonException 사용 중인 사유의 코드를 바꾸려 할 때
     */
    protected function syncExistingReason(int $id, array $reasonData): void
    {
        $existing = $this->repository->findById($id);

        if (! $existing) {
            return;
        }

        if (! $this->wouldChange($existing, $reasonData)) {
            return;
        }

        // 선행 판정(assertSyncDeletable)을 거치지 않는 호출 경로가 있을 수 있으므로
        // 여기서도 코드 변경을 막는다 — 삭제 가드와 같은 이유다.
        if ($existing->code !== $reasonData['code']) {
            $usageCount = $this->countUsage($existing);

            if ($usageCount > 0) {
                throw ClaimReasonException::codeLockedInUse($usageCount);
            }
        }

        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_update', $id, $reasonData);
        $reasonData = HookManager::applyFilters('sirsoft-ecommerce.claim_reason.filter_update_data', $reasonData, $id);
        $reasonData['updated_by'] = Auth::id();

        $updated = $this->repository->update($id, $reasonData);
        DB::afterCommit(fn () => HookManager::doAction('sirsoft-ecommerce.claim_reason.after_update', $updated, $reasonData));
    }

    /**
     * 값을 반영하면 사유가 실제로 바뀌는지 판정합니다. (쓰기 없음)
     *
     * 복제본에 채워 캐스트 기준 dirty 를 본다 — 원본 모델을 건드리지 않아 판정이 이후 흐름에 새지
     * 않는다. 다국어 이름은 로케일 순서·빈 번역만 다른 것을 같은 값으로 본다(화면이 키 순서를
     * 보장하지 않는다).
     *
     * @param  ClaimReason  $reason  기존 사유
     * @param  array<string, mixed>  $data  반영할 값
     * @return bool 바뀌면 true
     */
    protected function wouldChange(ClaimReason $reason, array $data): bool
    {
        $probe = clone $reason;
        $probe->fill($data);

        foreach (array_keys($probe->getDirty()) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            if ($key === 'name' && $this->normalizeTranslations($reason->name) === $this->normalizeTranslations($data['name'])) {
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
     * 지목된 삭제 id 가운데 실제로 그 유형에 존재하는 것만 고릅니다.
     *
     * 화면은 저장 뒤에도 지목 목록을 들고 있을 수 있어 이미 지워진 id 가 다시 올 수 있다 —
     * 그것을 오류로 만들면 두 번째 저장이 영영 막힌다. 다른 유형의 id 도 여기서 걸러진다.
     *
     * @param  array<int, int>  $existingIds  그 유형의 현재 사유 id
     * @param  array<int, int>  $deleteIds  지목된 id
     * @return array<int, int> 삭제할 id
     */
    protected function deletableIds(array $existingIds, array $deleteIds): array
    {
        $deleteIds = array_map('intval', array_filter($deleteIds, 'is_numeric'));

        return array_values(array_intersect($existingIds, $deleteIds));
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

        // 사용 중인지 확인 — 그 사유가 실제로 쓰이는 **모든** 표를 그 유형에 맞게 센다.
        // 취소 한 표만 세면 교환·반품·환불 사유는 사용 중에도 삭제되고, 그 순간 해당 신청서 전건의
        // 사유 라벨이 카탈로그를 잃어 원시 코드로 노출된다(오류도 경고도 없다).
        $usageCount = $this->countUsage($reason);

        if ($usageCount > 0) {
            throw ClaimReasonException::inUse($usageCount);
        }

        HookManager::doAction('sirsoft-ecommerce.claim_reason.before_delete', $reason);

        DB::transaction(function () use ($reason) {
            $this->repository->delete($reason->id);
        });

        HookManager::doAction('sirsoft-ecommerce.claim_reason.after_delete', $reason->id, $reason);

        return [
            'reason_id' => $reason->id,
        ];
    }
}
