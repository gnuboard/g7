<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use App\Extension\HookManager;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemSpecs;

/**
 * 클레임 송장(택배사 + 운송장 번호)을 **상품 행**에 기록하는 공용 규칙 — 교환·반품 서비스 공용
 *
 * 송장은 신청서 헤더가 아니라 상품 행에 붙는다(PO 2026-09-25 — 주문과 같은 행 쪼개기 방식).
 * 한 상자에 여러 상품이면 같은 운송장을 여러 행에 적고, 같은 상품을 나눠 보내면 수량을 지정해
 * 행을 쪼갠 뒤 쪼갠 행에 적는다. 이 trait 이 계획서 §3-2 의 판정 규칙을 소유한다.
 *
 *  - R1 방향 — 교환 행은 회수·재발송, 반품 행은 회수만.
 *  - R2 택배사·운송장은 둘 다 있거나 둘 다 없다.
 *  - R3 송장은 고른 행에만 쓴다 — 같은 운송장을 가진 다른 행은 바뀌지 않는다.
 *  - R4 수량을 지정하면 행을 쪼개 지정 수량 행에만 쓴다(잔여 행은 원래 값을 잇는다).
 *  - R5 그 방향 송장을 쓸 수 있는 행 상태(서비스가 정한다 — 선교환은 표가 다르다).
 *  - R6 회수중·발송중 전이는 대상 행 전부에 그 방향 송장이 있어야 한다 — 다른 행 송장을 빌려
 *    쓰지 않는다(예전 「헤더 저장값 재사용」 은 두 번째 상자가 첫 상자 번호를 몰래 물려받게 했다).
 *  - R7 「송장만 저장」 은 상태를 바꾸지 않는다. 비우면 지우되, 그 방향 이동 상태 행은 지울 수 없다.
 *  - R8 한 회수 송장은 한 회수지로 간다 — 회수지가 다른 행을 한 송장으로 묶지 않는다.
 *
 * 저장은 서비스에 이미 주입된 상품 행 저장소가 한다(`updateTrackingItem()`). 쪼개기는
 * `ClaimItemSplitter` 가 한다(`splitTrackingItem()`).
 */
trait RecordsClaimTracking
{
    /**
     * 신청서의 상품 행 목록을 반환합니다. (로드된 관계)
     *
     * @param  Model  $claim  신청서
     * @return iterable<int, OrderExchangeOption|OrderReturnOption> 상품 행
     */
    abstract protected function trackingItemsOf(Model $claim): iterable;

    /**
     * 신청서가 받는 송장 방향을 반환합니다. (R1)
     *
     * @param  Model  $claim  신청서
     * @return array<int, ClaimTrackingDirectionEnum> 방향 목록
     */
    abstract protected function trackingDirectionsOf(Model $claim): array;

    /**
     * 그 방향 송장을 쓸 수 있는 행 상태 목록을 반환합니다. (R5)
     *
     * @param  Model  $claim  신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @return array<int, BackedEnum> 행 상태 목록
     */
    abstract protected function trackingWritableStatuses(Model $claim, ClaimTrackingDirectionEnum $direction): array;

    /**
     * 그 방향의 이동 상태(회수중·발송중)를 반환합니다. (R6·R7)
     *
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @return BackedEnum|null 이동 상태 (그 방향에 이동 상태가 없으면 null)
     */
    abstract protected function trackingMovingStatus(ClaimTrackingDirectionEnum $direction): ?BackedEnum;

    /**
     * 상품 행을 갱신합니다. (서비스에 주입된 상품 행 저장소)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @param  array<string, mixed>  $updates  갱신값
     * @return OrderExchangeOption|OrderReturnOption 갱신된 상품 행
     */
    abstract protected function updateTrackingItem(Model $item, array $updates): Model;

    /**
     * 상품 행을 수량으로 쪼갭니다. (R4 — `ClaimItemSplitter`)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @param  int  $quantity  송장을 쓸 수량
     * @return array{target: OrderExchangeOption|OrderReturnOption, remainder: OrderExchangeOption|OrderReturnOption|null} 쪼갠 결과
     */
    abstract protected function splitTrackingItem(Model $item, int $quantity): array;

    /**
     * 행 송장이 바뀐 뒤 서비스 고유의 후속 처리를 합니다. (교환 재발송: 후속 배송 행 동기화 — R11)
     *
     * @param  Model  $claim  신청서
     * @param  OrderExchangeOption|OrderReturnOption  $item  송장이 바뀐 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @return void 반환값 없음
     */
    abstract protected function afterItemTrackingWritten(Model $claim, Model $item, ClaimTrackingDirectionEnum $direction): void;

    /**
     * 훅 이름의 유형 낱말을 반환합니다. (`exchange` · `return`)
     *
     * @return string 유형 낱말
     */
    abstract protected function trackingHookType(): string;

    /**
     * 신청서를 잠가 다시 읽습니다. (송장 쓰기는 잠금 안에서 판정한다)
     *
     * @param  Model  $claim  신청서
     * @return Model 잠긴 신청서 (상품 행 로드)
     */
    abstract protected function lockClaimForTracking(Model $claim): Model;

    /**
     * 택배사·운송장 입력을 정규화합니다. (R2 — 둘 다 있거나 둘 다 없어야 한다)
     *
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호 (앞뒤 공백 제거, 빈 문자열은 없음)
     * @return array{0: int|null, 1: string|null} [택배사 ID, 운송장 번호]
     *
     * @throws OrderClaimException 한쪽만 입력한 경우
     */
    protected function normalizeTracking(?int $carrierId, ?string $trackingNumber): array
    {
        $trackingNumber = $trackingNumber !== null && trim($trackingNumber) !== ''
            ? trim($trackingNumber)
            : null;

        if (($carrierId === null) !== ($trackingNumber === null)) {
            throw OrderClaimException::trackingIncomplete();
        }

        return [$carrierId, $trackingNumber];
    }

    /**
     * 전이 부가 정보에 실린 송장을 정규화합니다. (없으면 [null, null])
     *
     * @param  array  $context  전이 부가 정보 (carrier_id / tracking_number)
     * @return array{0: int|null, 1: string|null} [택배사 ID, 운송장 번호]
     *
     * @throws OrderClaimException 한쪽만 입력한 경우
     */
    protected function trackingFromContext(array $context): array
    {
        $carrierId = $context['carrier_id'] ?? null;

        return $this->normalizeTracking(
            $carrierId === null || $carrierId === '' ? null : (int) $carrierId,
            isset($context['tracking_number']) ? (string) $context['tracking_number'] : null,
        );
    }

    /**
     * 방향이 이 신청서에서 허용되는지 검증합니다. (R1)
     *
     * @param  Model  $claim  신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 반품의 재발송 등 허용되지 않는 방향
     */
    protected function assertTrackingDirectionAllowed(Model $claim, ClaimTrackingDirectionEnum $direction): void
    {
        if (! in_array($direction, $this->trackingDirectionsOf($claim), true)) {
            throw OrderClaimException::trackingDirectionNotAllowed();
        }
    }

    /**
     * 「송장만 저장」 — 고른 행(수량 지정 시 쪼갠 행)에 송장을 적습니다. (R1~R5·R7·R8 · 상태 불변)
     *
     * 잠금 전에 `claim.{t}.before_tracking_update`(막기) → `claim.{t}.filter_tracking_update_data`
     * (`carrier_id`·`tracking_number` 만 바꿀 수 있다)를 동기로 낸다. 바뀐 행이 있으면 커밋 뒤
     * `claim.{t}.after_tracking_update` 를 낸다.
     *
     * @param  Model  $claim  신청서 (교환·반품)
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  array<int, int|array{id: int, quantity?: int|null}>  $items  대상 행 지시
     * @param  int|null  $carrierId  택배사 ID (비우면 삭제)
     * @param  string|null  $trackingNumber  운송장 번호 (비우면 삭제)
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{claim: Model, split: array<int, array{id: int, quantity: int, remainder_id: int, remainder_quantity: int}>, changes: array<int, array<string, mixed>>} 결과
     *
     * @throws OrderClaimException R1·R2·R5·R7·R8 위반 / 행 없음
     */
    public function writeItemTracking(
        Model $claim,
        ClaimTrackingDirectionEnum $direction,
        array $items,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): array {
        $type = $this->trackingHookType();
        $specs = ClaimItemSpecs::normalize($items);

        if ($specs === []) {
            throw OrderClaimException::itemsRequired();
        }

        $this->assertTrackingDirectionAllowed($claim, $direction);

        HookManager::doAction("sirsoft-ecommerce.claim.{$type}.before_tracking_update", $claim, $direction->value, $specs, [
            'carrier_id' => $carrierId,
            'tracking_number' => $trackingNumber,
        ]);

        $input = ['carrier_id' => $carrierId, 'tracking_number' => $trackingNumber];
        $filtered = $this->acceptClaimFilterResult(
            HookManager::applyFilters("sirsoft-ecommerce.claim.{$type}.filter_tracking_update_data", $input, $claim, $direction->value),
            $input,
            ['carrier_id', 'tracking_number'],
        );

        [$carrierId, $trackingNumber] = $this->normalizeTracking(
            ($filtered['carrier_id'] ?? null) === null || $filtered['carrier_id'] === '' ? null : (int) $filtered['carrier_id'],
            isset($filtered['tracking_number']) ? (string) $filtered['tracking_number'] : null,
        );

        $split = [];
        $changes = [];

        $fresh = DB::transaction(function () use ($claim, $direction, $specs, $carrierId, $trackingNumber, $processedBy, &$split, &$changes) {
            $locked = $this->lockClaimForTracking($claim);
            $targets = $this->resolveTrackingTargets($locked, $specs);

            foreach ($targets as $item) {
                $this->assertItemTrackingWritable($locked, $item, $direction, $trackingNumber === null);
            }

            if ($direction === ClaimTrackingDirectionEnum::COLLECTION && $trackingNumber !== null) {
                $this->assertSameReturnAddress($locked, $targets, $carrierId, $trackingNumber);
            }

            $quantities = ClaimItemSpecs::quantities($specs);

            foreach ($targets as $item) {
                $quantity = $quantities[$item->id] ?? null;
                $previousQuantity = $this->trackingItemQuantity($item);

                if ($quantity !== null && $quantity < $previousQuantity) {
                    ['target' => $item, 'remainder' => $remainder] = $this->splitTrackingItem($item, $quantity);

                    if ($remainder !== null) {
                        $split[] = [
                            'id' => (int) $item->id,
                            'quantity' => $quantity,
                            'remainder_id' => (int) $remainder->id,
                            'remainder_quantity' => $previousQuantity - $quantity,
                        ];
                    }
                }

                $change = $this->applyItemTracking($locked, $item, $direction, $carrierId, $trackingNumber, $processedBy);

                if ($change !== null) {
                    $changes[] = $change;
                }
            }

            return $locked->fresh();
        });

        $this->announceTrackingUpdated($fresh, $direction, $changes, ['source' => 'tracking_only', 'split' => $split]);

        return ['claim' => $fresh, 'split' => $split, 'changes' => $changes];
    }

    /**
     * 한 행에 한 방향 송장을 적습니다. (값이 같으면 쓰지 않는다)
     *
     * 등록 시각(`*_shipped_at`)은 송장이 새로 생기거나 바뀔 때 찍고, 지우면 함께 비운다.
     *
     * @param  Model  $claim  신청서 (잠금 인스턴스)
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  int|null  $carrierId  택배사 ID
     * @param  string|null  $trackingNumber  운송장 번호
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return array{item_id: int, direction: string, quantity: int, previous: array{carrier_id: int|null, tracking_number: string|null}, new: array{carrier_id: int|null, tracking_number: string|null}}|null 바뀐 내용 (같으면 null)
     */
    protected function applyItemTracking(
        Model $claim,
        Model $item,
        ClaimTrackingDirectionEnum $direction,
        ?int $carrierId,
        ?string $trackingNumber,
        ?int $processedBy = null,
    ): ?array {
        $prefix = $direction->columnPrefix();
        $previousCarrier = $item->{$prefix.'_carrier_id'} === null ? null : (int) $item->{$prefix.'_carrier_id'};
        $previousTracking = $item->{$prefix.'_tracking_number'};

        if ($previousCarrier === $carrierId && $previousTracking === $trackingNumber) {
            return null;
        }

        $updates = [
            $prefix.'_carrier_id' => $carrierId,
            $prefix.'_tracking_number' => $trackingNumber,
            $prefix.'_shipped_at' => $trackingNumber === null ? null : now(),
        ];

        if ($processedBy !== null) {
            $updates['processed_by'] = $processedBy;
        }

        $updated = $this->updateTrackingItem($item, $updates);

        $this->afterItemTrackingWritten($claim, $updated, $direction);

        return [
            'item_id' => (int) $updated->id,
            'direction' => $direction->value,
            'quantity' => $this->trackingItemQuantity($updated),
            'previous' => ['carrier_id' => $previousCarrier, 'tracking_number' => $previousTracking],
            'new' => ['carrier_id' => $carrierId, 'tracking_number' => $trackingNumber],
        ];
    }

    /**
     * 지시한 행을 신청서 안에서 찾습니다. (다른 신청서의 행이면 거절)
     *
     * @param  Model  $claim  신청서 (상품 행 로드)
     * @param  array<int, array{id: int, quantity: int|null}>  $specs  정규화된 지시
     * @return array<int, OrderExchangeOption|OrderReturnOption> 대상 행
     *
     * @throws OrderClaimException 신청서에 없는 행
     */
    protected function resolveTrackingTargets(Model $claim, array $specs): array
    {
        $byId = [];

        foreach ($this->trackingItemsOf($claim) as $item) {
            $byId[(int) $item->id] = $item;
        }

        $targets = [];

        foreach ($specs as $spec) {
            if (! isset($byId[$spec['id']])) {
                throw OrderClaimException::claimItemNotFound();
            }

            $item = $byId[$spec['id']];

            if ($spec['quantity'] !== null && ($spec['quantity'] < 1 || $spec['quantity'] > $this->trackingItemQuantity($item))) {
                throw OrderClaimException::quantityInvalid();
            }

            $targets[] = $item;
        }

        return $targets;
    }

    /**
     * 행이 지금 그 방향 송장을 받을 수 있는지 검증합니다. (R5 · R7)
     *
     * @param  Model  $claim  신청서
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  bool  $clearing  송장을 지우는 요청인지
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 쓸 수 없는 상태 / 이동 상태 행의 송장 삭제
     */
    protected function assertItemTrackingWritable(Model $claim, Model $item, ClaimTrackingDirectionEnum $direction, bool $clearing): void
    {
        if (! in_array($item->option_status, $this->trackingWritableStatuses($claim, $direction), true)) {
            throw OrderClaimException::trackingItemNotEligible();
        }

        if ($clearing && $item->option_status === $this->trackingMovingStatus($direction)) {
            throw OrderClaimException::trackingRequired();
        }
    }

    /**
     * 한 회수 송장에 묶이는 행들의 회수지가 같은지 검증합니다. (R8)
     *
     * 이번에 쓰는 행끼리는 물론, 같은 신청서에서 이미 같은 (택배사, 운송장)을 가진 다른 행과도
     * 회수지가 같아야 한다 — 한 상자는 한 회수지로 간다.
     *
     * @param  Model  $claim  신청서 (상품 행 로드)
     * @param  array<int, OrderExchangeOption|OrderReturnOption>  $targets  이번에 쓰는 행
     * @param  int|null  $carrierId  택배사 ID
     * @param  string  $trackingNumber  운송장 번호
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 회수지가 섞임
     */
    protected function assertSameReturnAddress(Model $claim, array $targets, ?int $carrierId, string $trackingNumber): void
    {
        $addresses = [];

        foreach ($targets as $item) {
            $addresses[] = $this->returnAddressKey($item);
        }

        $targetIds = array_map(fn ($item) => (int) $item->id, $targets);

        foreach ($this->trackingItemsOf($claim) as $item) {
            if (in_array((int) $item->id, $targetIds, true)) {
                continue;
            }

            if ((int) $item->collection_carrier_id === (int) $carrierId && $item->collection_tracking_number === $trackingNumber) {
                $addresses[] = $this->returnAddressKey($item);
            }
        }

        if (count(array_unique($addresses)) > 1) {
            throw OrderClaimException::trackingMixedReturnAddress();
        }
    }

    /**
     * 행 회수지의 비교 키를 만듭니다. (주소 필드만 — 정책 ID·표기 차이는 무시하지 않는다)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @return string 비교 키 (회수지 없음은 빈 문자열)
     */
    protected function returnAddressKey(Model $item): string
    {
        $address = $item->return_address_snapshot;

        if (! is_array($address) || $address === []) {
            return '';
        }

        $fields = [];

        foreach (['recipient_name', 'phone', 'zipcode', 'address1', 'address2', 'country_code'] as $field) {
            $fields[$field] = trim((string) ($address[$field] ?? ''));
        }

        return json_encode($fields, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * 회수중·발송중 전이의 대상 행이 모두 그 방향 송장을 가질 수 있는지 검증합니다. (R6)
     *
     * 요청이 송장을 실었으면 그 송장을 대상 행에 쓰므로 통과한다(회수 송장이면 R8 을 함께 본다).
     * 싣지 않았으면 **대상 행이 이미 자기 송장을 가진 경우만** 통과한다 — 다른 행의 송장을 빌려 쓰지 않는다.
     *
     * @param  Model  $claim  신청서 (상품 행 로드)
     * @param  array<int, OrderExchangeOption|OrderReturnOption>  $targets  이동할 대상 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  int|null  $carrierId  요청 택배사 ID
     * @param  string|null  $trackingNumber  요청 운송장 번호
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 송장 없음 / 회수지 섞임
     */
    protected function assertTransitionTracking(
        Model $claim,
        array $targets,
        ClaimTrackingDirectionEnum $direction,
        ?int $carrierId,
        ?string $trackingNumber,
    ): void {
        if ($targets === []) {
            return;
        }

        if ($trackingNumber !== null) {
            if ($direction === ClaimTrackingDirectionEnum::COLLECTION) {
                $this->assertSameReturnAddress($claim, $targets, $carrierId, $trackingNumber);
            }

            return;
        }

        $prefix = $direction->columnPrefix();

        foreach ($targets as $item) {
            if ($item->{$prefix.'_carrier_id'} === null || trim((string) $item->{$prefix.'_tracking_number'}) === '') {
                throw OrderClaimException::trackingRequired();
            }
        }
    }

    /**
     * 같은 방향·같은 (택배사, 운송장)을 가진 행을 모읍니다. (운송장 단위 도착 — R9)
     *
     * @param  Model  $claim  신청서 (상품 행 로드)
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  int  $carrierId  택배사 ID
     * @param  string  $trackingNumber  운송장 번호
     * @return array<int, OrderExchangeOption|OrderReturnOption> 그 운송장의 행
     */
    protected function itemsWithTracking(Model $claim, ClaimTrackingDirectionEnum $direction, int $carrierId, string $trackingNumber): array
    {
        $prefix = $direction->columnPrefix();
        $matches = [];

        foreach ($this->trackingItemsOf($claim) as $item) {
            if ((int) $item->{$prefix.'_carrier_id'} === $carrierId
                && trim((string) $item->{$prefix.'_tracking_number'}) === trim($trackingNumber)) {
                $matches[] = $item;
            }
        }

        return $matches;
    }

    /**
     * 상품 행의 수량을 반환합니다. (교환·반품 컬럼명 차이 흡수)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @return int 수량
     */
    protected function trackingItemQuantity(Model $item): int
    {
        return (int) ($item instanceof OrderExchangeOption ? $item->exchange_quantity : $item->return_quantity);
    }

    /**
     * 송장이 바뀐 사실을 커밋 뒤에 알립니다. (`claim.{t}.after_tracking_update`)
     *
     * 전이와 함께 적은 송장도 같은 훅으로 나간다 — 송장 변경 사실은 하나의 훅으로(§3-7). 고객 알림은
     * 이 훅에서 나가며, 같은 요청에서 신청서가 회수중·발송중으로 옮겨져 상태 알림이 나가면 그쪽이
     * 운송장 줄을 싣고 이 알림은 보내지 않는다(`context.header_transitioned_to`).
     *
     * @param  Model  $claim  신청서
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  array<int, array<string, mixed>>  $changes  바뀐 행
     * @param  array<string, mixed>  $context  부가 정보 (source · split · header_transitioned_to)
     * @return void 반환값 없음
     */
    protected function announceTrackingUpdated(Model $claim, ClaimTrackingDirectionEnum $direction, array $changes, array $context = []): void
    {
        if ($changes === []) {
            return;
        }

        $type = $this->trackingHookType();

        $this->fireAfterCommit(fn () => HookManager::doAction(
            "sirsoft-ecommerce.claim.{$type}.after_tracking_update",
            $claim->fresh(),
            $direction->value,
            $changes,
            $context,
        ));
    }
}
