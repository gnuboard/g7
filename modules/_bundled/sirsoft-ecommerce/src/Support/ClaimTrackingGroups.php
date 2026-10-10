<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ClaimItemCollectionStateEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;

/**
 * 신청서의 운송장 묶음 파생기 (저장하지 않는다 — 관리자 상세 · 회원·비회원 상세 공용)
 *
 * 송장은 상품 행마다 있다. 「배송 정보」 탭과 구매자 화면은 그것을 **운송장별로 묶어** 보여 준다 —
 * 같은 방향·같은 (택배사, 운송장)을 가진 행이 한 상자다. 송장 없이 도착한 행(고객이 직접 가져온
 * 물건)은 「송장 없이 도착」 묶음으로 따로 선다.
 *
 * 묶음마다 [도착 처리] 가능 여부(`can_deliver`)를 싣는다 — 판정은 도착 처리 서비스가 실제로
 * 옮길 수 있는 행이 있는가와 같다(일반: 회수도착·발송도착 전이 가능, 선교환 회수: 도착 전·미회수 아님).
 * 구매자 응답에는 싣지 않는다.
 */
final class ClaimTrackingGroups
{
    /**
     * 신청서의 방향별 운송장 묶음을 만듭니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (상품 행·행 택배사·주문옵션 로드)
     * @param  bool  $forAdmin  [도착 처리] 가능 여부를 실을지 (관리자 상세)
     * @return array{collection: array<int, array<string, mixed>>, redelivery: array<int, array<string, mixed>>} 방향별 묶음 (반품은 redelivery 빈 배열)
     */
    public static function for(OrderExchange|OrderReturn $claim, bool $forAdmin = false): array
    {
        $items = self::itemsOf($claim);
        $mode = $claim instanceof OrderExchange ? $claim->mode() : ExchangeModeEnum::STANDARD;

        $groups = ['collection' => [], 'redelivery' => []];

        foreach ($claim instanceof OrderExchange
            ? [ClaimTrackingDirectionEnum::COLLECTION, ClaimTrackingDirectionEnum::REDELIVERY]
            : [ClaimTrackingDirectionEnum::COLLECTION] as $direction) {
            $groups[$direction->value] = self::groupsFor($items, $direction, $mode, $forAdmin);
        }

        return $groups;
    }

    /**
     * 알림 본문용 운송장 줄을 만듭니다. (「택배사 · 운송장 · 상품×수량」, 운송장마다 한 줄 — S10)
     *
     * `$itemIds` 를 주면 그 행이 담긴 운송장만 싣는다(송장 등록 알림 — 이번에 새로 적힌 행).
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (상품 행 로드)
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  array<int, int>|null  $itemIds  실을 행 ID (null 이면 전부)
     * @return string 줄바꿈으로 이은 운송장 줄 (운송장이 없으면 빈 문자열)
     */
    public static function lines(OrderExchange|OrderReturn $claim, ClaimTrackingDirectionEnum $direction, ?array $itemIds = null): string
    {
        $mode = $claim instanceof OrderExchange ? $claim->mode() : ExchangeModeEnum::STANDARD;
        $ids = $itemIds === null ? null : array_map('intval', $itemIds);
        $lines = [];

        foreach (self::groupsFor(self::itemsOf($claim), $direction, $mode, false) as $group) {
            if ($group['no_tracking']) {
                continue;
            }

            $items = $ids === null
                ? $group['items']
                : array_values(array_filter($group['items'], fn (array $row) => in_array($row['claim_item_id'], $ids, true)));

            if ($items === []) {
                continue;
            }

            $products = implode(', ', array_map(
                fn (array $row) => trim(($row['product_name'] ?? '').($row['option_name'] ? ' ('.$row['option_name'].')' : '')).' × '.$row['quantity'],
                $items,
            ));

            $lines[] = trim(($group['carrier_name'] ?? '').' · '.$group['tracking_number'].' · '.$products);
        }

        return implode("\n", $lines);
    }

    /**
     * 목록 한 줄용 운송장 요약을 만듭니다. (방향별 첫 운송장과 운송장 개수 — 「외 N개」)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (상품 행 로드)
     * @return array{collection: array{first: string|null, count: int}, redelivery: array{first: string|null, count: int}} 요약
     */
    public static function summary(OrderExchange|OrderReturn $claim): array
    {
        $items = self::itemsOf($claim);
        $summary = [];

        foreach (['collection', 'redelivery'] as $prefix) {
            $numbers = [];

            foreach ($items as $item) {
                $number = $item->getAttribute($prefix.'_tracking_number');

                if ($number !== null && trim((string) $number) !== '') {
                    $key = $item->getAttribute($prefix.'_carrier_id').'|'.$number;
                    $numbers[$key] ??= (string) $number;
                }
            }

            $summary[$prefix] = [
                'first' => $numbers === [] ? null : reset($numbers),
                'count' => count($numbers),
            ];
        }

        return $summary;
    }

    /**
     * 상품 행 회수지의 고유값 목록을 만듭니다. (회수지가 여럿이면 주소별로 묶어 보여 준다 — S4)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (상품 행 로드)
     * @return array<int, array{address_snapshot: array, claim_item_ids: array<int, int>}> 회수지 목록
     */
    public static function returnAddresses(OrderExchange|OrderReturn $claim): array
    {
        $addresses = [];

        foreach (self::itemsOf($claim) as $item) {
            $address = $item->return_address_snapshot;

            if (! is_array($address) || $address === []) {
                continue;
            }

            $key = md5((string) json_encode(array_intersect_key($address, array_flip(['recipient_name', 'phone', 'zipcode', 'address1', 'address2', 'country_code']))));

            $addresses[$key] ??= ['address_snapshot' => $address, 'claim_item_ids' => []];
            $addresses[$key]['claim_item_ids'][] = (int) $item->id;
        }

        return array_values($addresses);
    }

    /**
     * 선교환의 회수 지연 여부와 지연 일수를 판정합니다. (D2 — 파생값)
     *
     * 선교환이고, 회수 기한이 지났고, 종결되지 않은 행 중 헌 물건이 아직 출발하지 않은(pending)
     * 행이 하나 이상 있을 때 지연이다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서 (상품 행 로드)
     * @return array{is_overdue: bool, days: int} 지연 여부·지연 일수
     */
    public static function overdueOf(OrderExchange|OrderReturn $claim): array
    {
        if (! $claim instanceof OrderExchange || $claim->mode() !== ExchangeModeEnum::ADVANCE || $claim->collection_due_at === null) {
            return ['is_overdue' => false, 'days' => 0];
        }

        $due = Carbon::parse($claim->collection_due_at);

        if (! $due->isPast()) {
            return ['is_overdue' => false, 'days' => 0];
        }

        $pending = false;

        foreach (self::itemsOf($claim) as $item) {
            if ($item->option_status->isFinal()) {
                continue;
            }

            if (ClaimItemCollectionState::for($item, ExchangeModeEnum::ADVANCE) === ClaimItemCollectionStateEnum::PENDING) {
                $pending = true;

                break;
            }
        }

        return $pending
            ? ['is_overdue' => true, 'days' => max(1, (int) $due->startOfDay()->diffInDays(now()->startOfDay()))]
            : ['is_overdue' => false, 'days' => 0];
    }

    /**
     * 한 방향의 묶음을 만듭니다.
     *
     * @param  iterable<int, OrderExchangeOption|OrderReturnOption>  $items  상품 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  ExchangeModeEnum  $mode  진행 방식
     * @param  bool  $forAdmin  [도착 처리] 가능 여부를 실을지
     * @return array<int, array<string, mixed>> 묶음 목록 (운송장 묶음 → 송장 없이 도착 묶음 순)
     */
    protected static function groupsFor(iterable $items, ClaimTrackingDirectionEnum $direction, ExchangeModeEnum $mode, bool $forAdmin): array
    {
        $prefix = $direction->columnPrefix();
        $arrivedColumn = $direction->arrivedAtColumn();
        $relation = $prefix.'Carrier';
        $groups = [];
        $withoutTracking = [];

        foreach ($items as $item) {
            $number = $item->getAttribute($prefix.'_tracking_number');
            $arrived = $item->getAttribute($arrivedColumn) !== null;

            if ($number === null || trim((string) $number) === '') {
                // 송장 없이 도착한 행만 따로 묶는다 — 송장도 도착도 없는 행은 아직 보여 줄 것이 없다.
                if ($arrived) {
                    $withoutTracking[] = self::itemRow($item, $arrived);
                }

                continue;
            }

            $carrierId = (int) $item->getAttribute($prefix.'_carrier_id');
            $key = $carrierId.'|'.$number;

            if (! isset($groups[$key])) {
                $carrier = $item->relationLoaded($relation) ? $item->getRelation($relation) : $item->{$relation}()->first();

                $groups[$key] = [
                    'key' => $direction->value.':'.$key,
                    'direction' => $direction->value,
                    'no_tracking' => false,
                    'carrier_id' => $carrierId,
                    'carrier_name' => $carrier?->getLocalizedName(),
                    'tracking_number' => (string) $number,
                    'tracking_url' => $carrier?->buildTrackingUrl((string) $number),
                    'items' => [],
                    'all_arrived' => true,
                    'deliverable_count' => 0,
                ];
            }

            $groups[$key]['items'][] = self::itemRow($item, $arrived);
            $groups[$key]['all_arrived'] = $groups[$key]['all_arrived'] && $arrived;

            if (self::deliverable($item, $direction, $mode)) {
                $groups[$key]['deliverable_count']++;
            }
        }

        $result = [];

        foreach ($groups as $group) {
            $deliverable = $group['deliverable_count'] > 0;
            unset($group['deliverable_count']);

            if ($forAdmin) {
                $group['can_deliver'] = $deliverable;
                $group['blocked_reason'] = $deliverable
                    ? null
                    : __($group['all_arrived']
                        ? 'sirsoft-ecommerce::exceptions.claim_tracking_already_arrived'
                        : 'sirsoft-ecommerce::messages.claims.tracking_deliver_not_ready');
            }

            $result[] = $group;
        }

        if ($withoutTracking !== []) {
            $group = [
                'key' => $direction->value.':no_tracking',
                'direction' => $direction->value,
                'no_tracking' => true,
                'carrier_id' => null,
                'carrier_name' => null,
                'tracking_number' => null,
                'tracking_url' => null,
                'items' => $withoutTracking,
                'all_arrived' => true,
            ];

            if ($forAdmin) {
                $group['can_deliver'] = false;
                $group['blocked_reason'] = null;
            }

            $result[] = $group;
        }

        return $result;
    }

    /**
     * 묶음 안의 상품 한 줄을 만듭니다.
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @param  bool  $arrived  도착 여부
     * @return array{claim_item_id: int, product_name: string|null, option_name: string|null, quantity: int, status_label: string|null, arrived: bool} 상품 줄
     */
    protected static function itemRow(OrderExchangeOption|OrderReturnOption $item, bool $arrived): array
    {
        $orderOption = $item->relationLoaded('orderOption') ? $item->orderOption : null;
        $locale = app()->getLocale();

        return [
            'claim_item_id' => (int) $item->id,
            'product_name' => self::localize($orderOption?->product_name, $locale),
            'option_name' => self::localize($orderOption?->option_name, $locale),
            'quantity' => (int) ($item instanceof OrderExchangeOption ? $item->exchange_quantity : $item->return_quantity),
            'status_label' => $item->option_status?->label(),
            'arrived' => $arrived,
        ];
    }

    /**
     * 운송장 단위 도착 처리가 이 행을 옮길 수 있는지 판정합니다. (도착 처리 서비스와 같은 판정)
     *
     * @param  OrderExchangeOption|OrderReturnOption  $item  상품 행
     * @param  ClaimTrackingDirectionEnum  $direction  방향
     * @param  ExchangeModeEnum  $mode  진행 방식
     * @return bool 옮길 수 있으면 true
     */
    protected static function deliverable(OrderExchangeOption|OrderReturnOption $item, ClaimTrackingDirectionEnum $direction, ExchangeModeEnum $mode): bool
    {
        if ($item->option_status->isFinal()) {
            return false;
        }

        if ($item instanceof OrderReturnOption) {
            return $item->option_status !== ReturnStatusEnum::COLLECTED
                && $item->option_status->canTransitionTo(ReturnStatusEnum::COLLECTED);
        }

        if ($mode === ExchangeModeEnum::ADVANCE && $direction === ClaimTrackingDirectionEnum::COLLECTION) {
            return $item->collected_at === null && $item->unreturned_at === null;
        }

        $target = $direction === ClaimTrackingDirectionEnum::COLLECTION ? ExchangeStatusEnum::COLLECTED : ExchangeStatusEnum::REDELIVERED;

        return $item->option_status !== $target && $item->option_status->canTransitionTo($target, $mode);
    }

    /**
     * 신청서의 상품 행을 반환합니다. (로드된 관계 — 미로드면 불러온다)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return iterable<int, OrderExchangeOption|OrderReturnOption> 상품 행
     */
    protected static function itemsOf(OrderExchange|OrderReturn $claim): iterable
    {
        // 상품명·옵션명은 주문옵션에 있다 — 알림 경로는 신청서를 관계 없이 다시 읽으므로 여기서 함께 부른다.
        // 빠뜨리면 운송장 줄이 「택배사 · 운송장 ·  × 수량」 으로 상품명 없이 나간다(2026-10-07 실측).
        return $claim instanceof OrderExchange
            ? $claim->loadMissing('exchangeOptions.orderOption')->exchangeOptions
            : $claim->loadMissing('returnOptions.orderOption')->returnOptions;
    }

    /**
     * 다국어 배열 값을 현재 로케일 문자열로 풀어냅니다.
     *
     * @param  mixed  $value  다국어 배열 또는 문자열
     * @param  string  $locale  로케일
     * @return string|null 현지화 문자열
     */
    protected static function localize(mixed $value, string $locale): ?string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? (reset($value) ?: null);
        }

        return $value === null ? null : (string) $value;
    }
}
