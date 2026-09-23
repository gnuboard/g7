<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 클레임 수량 잠금 가드 (D15)
 *
 * "활성 클레임(철회·반려·완료 이전)에 묶인 수량은 다른 클레임·취소 신청 대상이 아니다" 를
 * 한 곳에서 판정한다. 교환·반품·직접 환불신청이 같은 주문옵션을 두고 경합하므로 유형별로
 * 나누지 않고 세 축을 함께 센다 — 한쪽만 세면 교환 진행 중인 수량으로 반품이 접수된다.
 *
 * ## 잠금이 두 겹인 이유
 *
 * 교환·반품 신청이 접수되면 그 수량은 곧바로 코스 상태(`exchange_in_progress`/`return_in_progress`)로
 * 분할되므로, 남은 행의 수량 자체가 이미 줄어든다. 그런데 그것만으로는 충분하지 않다:
 *
 *  1. 신청 가능 상태 목록(`claim.*_requestable_statuses`)은 운영자 설정이라 취소 가능 상태와
 *     겹칠 수 있고, 겹치면 같은 상품에 취소와 반품이 동시에 걸린다.
 *  2. 취소 경로(`OrderCancellationService`)는 옵션 상태를 `CANCELLED` 인지만 보고 코스 상태를
 *     보지 않는다 — 가드가 없으면 반품 진행 중인 수량이 취소로 빠져나간다.
 *  3. 직접 환불신청(`refund_source_type=direct`)은 원 주문옵션을 판매 상태 그대로 둔 채
 *     수량만 선점한다 — 코스 상태가 없으므로 수량 합산 가드만이 이중 신청을 막는다.
 *
 * 그래서 **코스 상태 가드**(진행 중인 상품은 다른 클레임·취소 대상 아님)와 **수량 합산
 * 가드**(활성 클레임 수량만큼 잔여를 깎음)를 함께 적용한다. 어느 쪽도 예외나 로그를 남기지
 * 않는 종류의 결함(같은 상품이 두 번 환불됨)을 막는 것이 목적이다.
 *
 * ## 코스 상태 가드가 걸리는 두 경로
 *
 * 취소(`assertNotLockedForCancellation`)와 관리자 상태 변경(`assertNotInClaimCourse`, D15
 * 후단) 둘이며, 둘 다 같은 판정(`resolveClaimCourseStatus`)을 읽는다 — 경로마다 따로
 * 판정하면 한쪽만 고쳐져 규칙이 갈리고, 갈린 쪽이 우회로가 된다.
 *
 * **신청 경로(`resolveClaimItems`)는 이 판정을 읽지 않는다.** 그쪽의 상태 판정은 운영자
 * 설정인 신청 가능 상태 목록(`claim.*_requestable_statuses`)과의 대조이고, 코스 상태는 그
 * 목록에 없으므로 자연히 걸러진다. 설정에 코스 상태가 들어가더라도 수량 합산 가드가
 * 잔여를 0 으로 만들어 막는다 — 즉 신청 경로의 방어는 다른 축이 담당한다. 이 차이를
 * 모르고 "가드가 알아서 막는다" 로 읽으면 신청 검증을 얇게 만들게 된다.
 */
class ClaimQuantityGuard
{
    /**
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 신청서 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 신청서 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository (직접 환불신청 축)
     */
    public function __construct(
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
    ) {}

    /**
     * 해당 주문옵션에 활성 클레임이 잡아 둔 수량을 반환합니다.
     *
     * @param  int  $orderOptionId  주문옵션 ID
     * @param  int|null  $excludeExchangeId  집계에서 뺄 교환 신청서 ID (수정 중인 자기 자신)
     * @param  int|null  $excludeReturnId  집계에서 뺄 반품 신청서 ID (수정 중인 자기 자신)
     * @param  int|null  $excludeRefundId  집계에서 뺄 직접 환불신청서 ID (수정 중인 자기 자신)
     * @return int 활성 클레임 점유 수량
     */
    public function lockedQuantity(
        int $orderOptionId,
        ?int $excludeExchangeId = null,
        ?int $excludeReturnId = null,
        ?int $excludeRefundId = null,
    ): int {
        return $this->exchangeRepository->sumActiveQuantityByOrderOption($orderOptionId, $excludeExchangeId)
            + $this->returnRepository->sumActiveQuantityByOrderOption($orderOptionId, $excludeReturnId)
            + $this->refundRepository->sumActiveDirectQuantityByOrderOption($orderOptionId, $excludeRefundId);
    }

    /**
     * 여러 주문옵션의 활성 클레임 점유 수량을 한 번에 반환합니다. (목록·주문 상세 N+1 방지)
     *
     * `lockedQuantity()` 와 **같은 세 축**(교환·반품·직접 환불신청)을 유형당 한 쿼리로 묶는다.
     * 주문 상세처럼 옵션을 여러 개 그리는 응답이 옵션마다 `lockedQuantity()` 를 부르면 옵션 수 × 3
     * 쿼리가 나간다. 수정 중인 자기 신청서 제외(`exclude*`)는 단건 경로에만 있다 — 일괄판을 쓰는
     * 응답은 신청서 문맥이 없다.
     *
     * @param  array<int, int>  $orderOptionIds  주문옵션 ID 목록
     * @return array<int, int> 주문옵션 ID => 점유 수량 (요청한 모든 ID 가 키로 있다)
     */
    public function lockedQuantities(array $orderOptionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $orderOptionIds)));
        $exchange = $this->exchangeRepository->sumActiveQuantitiesByOrderOptions($ids);
        $return = $this->returnRepository->sumActiveQuantitiesByOrderOptions($ids);
        $refund = $this->refundRepository->sumActiveDirectQuantitiesByOrderOptions($ids);

        $locked = [];

        foreach ($ids as $id) {
            $locked[$id] = ($exchange[$id] ?? 0) + ($return[$id] ?? 0) + ($refund[$id] ?? 0);
        }

        return $locked;
    }

    /**
     * 해당 주문옵션에서 지금 신청할 수 있는 잔여 수량을 반환합니다.
     *
     * @param  OrderOption  $option  주문옵션
     * @param  int|null  $excludeExchangeId  집계에서 뺄 교환 신청서 ID
     * @param  int|null  $excludeReturnId  집계에서 뺄 반품 신청서 ID
     * @param  int|null  $excludeRefundId  집계에서 뺄 직접 환불신청서 ID
     * @return int 신청 가능 잔여 수량 (0 이상)
     */
    public function availableQuantity(
        OrderOption $option,
        ?int $excludeExchangeId = null,
        ?int $excludeReturnId = null,
        ?int $excludeRefundId = null,
    ): int {
        $locked = $this->lockedQuantity($option->id, $excludeExchangeId, $excludeReturnId, $excludeRefundId);

        return max(0, (int) $option->quantity - $locked);
    }

    /**
     * 클레임 신청 대상 아이템을 검증하고 주문옵션을 해석합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $items  신청 대상 [{order_option_id, quantity}]
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  array<string>  $requestableStatusValues  신청 가능 주문옵션 상태 값 목록 (설정)
     * @param  int|null  $excludeExchangeId  집계에서 뺄 교환 신청서 ID
     * @param  int|null  $excludeReturnId  집계에서 뺄 반품 신청서 ID
     * @param  int|null  $excludeRefundId  집계에서 뺄 직접 환불신청서 ID
     * @return array<int, array{option: OrderOption, quantity: int}> 주문옵션 ID => 해석 결과
     *
     * @throws OrderClaimException 대상 부재·상태 불가·수량 오류·수량 잠금
     */
    public function resolveClaimItems(
        Order $order,
        array $items,
        ClaimTypeEnum $claimType,
        array $requestableStatusValues,
        ?int $excludeExchangeId = null,
        ?int $excludeReturnId = null,
        ?int $excludeRefundId = null,
    ): array {
        if (empty($items)) {
            throw OrderClaimException::itemsRequired();
        }

        $order->loadMissing('options');

        $resolved = [];

        foreach ($items as $item) {
            $optionId = (int) ($item['order_option_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            $option = $order->options->firstWhere('id', $optionId);

            if (! $option) {
                throw OrderClaimException::optionNotFound();
            }

            // 같은 옵션을 두 줄로 보내면 각 줄이 따로 잔여를 보고 통과한 뒤 합계가 잔여를 넘는다.
            if (isset($resolved[$optionId])) {
                throw OrderClaimException::quantityInvalid();
            }

            $statusValue = $this->statusValueForGate($option, $excludeExchangeId, $excludeReturnId);

            if (! in_array($statusValue, $requestableStatusValues, true)) {
                throw OrderClaimException::optionNotClaimable(
                    $claimType,
                    OrderStatusEnum::tryFrom($statusValue)?->label() ?? $statusValue,
                );
            }

            if ($quantity < 1 || $quantity > (int) $option->quantity) {
                throw OrderClaimException::quantityInvalid();
            }

            $available = $this->availableQuantity($option, $excludeExchangeId, $excludeReturnId, $excludeRefundId);

            if ($quantity > $available) {
                throw OrderClaimException::quantityLocked($quantity, $available);
            }

            $resolved[$optionId] = ['option' => $option, 'quantity' => $quantity];
        }

        return $resolved;
    }

    /**
     * 취소 대상 아이템이 진행 중인 클레임에 묶여 있지 않은지 검증합니다.
     *
     * 취소 경로는 옵션 상태가 `CANCELLED` 인지만 보고 클레임 코스 상태를 보지 않으므로,
     * 이 가드가 없으면 반품·교환 진행 중인 수량이 취소로 빠져나가 같은 상품이 두 번
     * 환불된다. 오류도 경고도 남지 않고 금액만 어긋나는 형태다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 코스 상태 상품이거나 잔여 수량을 넘을 때
     */
    public function assertNotLockedForCancellation(Order $order, array $cancelItems): void
    {
        $order->loadMissing('options');

        foreach ($cancelItems as $item) {
            $option = $order->options->firstWhere('id', (int) ($item['order_option_id'] ?? 0));

            if (! $option) {
                // 판정 대상을 못 찾으면 통과시키지 않는다 — 이 가드는 진행 중인 교환·반품 수량이
                // 취소로 빠져나가 이중 환불되는 것을 막는 자리라, 대상 부재를 "잠기지 않음" 으로 읽으면
                // 막으려던 것이 그대로 통과한다. 형제 `resolveClaimItems()` 도 같은 상황에서 차단한다.
                // (정상 경로에서는 호출부가 먼저 대상 존재를 검증하므로 여기 도달하지 않는다.)
                throw OrderClaimException::optionNotFound();
            }

            if ($course = $this->resolveClaimCourseStatus($option->option_status)) {
                throw OrderClaimException::optionInClaimCourse($course->label());
            }

            $quantity = (int) ($item['cancel_quantity'] ?? 0);
            $available = $this->availableQuantity($option);

            if ($quantity > $available) {
                throw OrderClaimException::quantityLocked($quantity, $available);
            }
        }
    }

    /**
     * 관리자 상태 변경 대상에 클레임 진행 중인 상품이 섞여 있지 않은지 검증합니다. (D15 후단)
     *
     * 코스 진행 2종은 반려·철회 복귀를 위해 `OrderStatusEnum::allowedTransitions()` 가 판매
     * 상태로의 전이를 허용한다. 그 허용은 클레임 서비스의 복귀 경로를 위한 것인데 **같은 게이트를
     * 관리자 주문화면의 일괄 상태 변경도 읽으므로**, 가드가 없으면 운영자가 진행 중인 클레임의
     * 상품을 코스 밖으로 빼낼 수 있다. 그렇게 빠져나간 행은 이후 `OrderReturnService` 의 복귀
     * 처리에서 코스 상태 조건에 걸리지 않아 **조용히 복귀 대상에서 제외**되고, 신청서와 주문옵션이
     * 오류·로그 없이 어긋난다.
     *
     * 상태 값은 모델 캐스트를 거친 Enum 일 수도, 스냅샷 배열에서 온 원시 문자열일 수도 있어
     * 두 형태를 모두 받는다.
     *
     * @param  array<array-key, OrderStatusEnum|string|null>  $optionStatuses  대상 주문옵션의 현재 상태 목록
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 코스 상태 상품이 섞여 있을 때
     */
    public function assertNotInClaimCourse(array $optionStatuses): void
    {
        foreach ($optionStatuses as $status) {
            if ($course = $this->resolveClaimCourseStatus($status)) {
                throw OrderClaimException::optionInClaimCourseStatusChange($course->label());
            }
        }
    }

    /**
     * 관리자 상태 변경의 목표 상태가 클레임 코스 상태가 아닌지 검증합니다. (D15 후단 — 목표 축)
     *
     * `assertNotInClaimCourse()` 가 **현재** 상태(소스)를 막는다면 이 메서드는 **목표** 상태를
     * 막는다. 코스 상태는 교환·반품 서비스가 신청서와 함께 기록하는 값이라, 신청서 없이 주문
     * 옵션만 코스 상태가 되면 복귀·완료할 신청서가 없는 고아 행이 된다. FormRequest 는
     * `OrderStatusEnum::adminAssignableValues()` 로 같은 축을 막지만, 그 목록을 거치지 않는
     * 내부·훅 호출도 같은 규칙을 따라야 한다.
     *
     * @param  OrderStatusEnum  $target  목표 상태
     * @return void 반환값 없음
     *
     * @throws OrderClaimException 목표가 코스 상태일 때
     */
    public function assertNotClaimCourseTarget(OrderStatusEnum $target): void
    {
        if ($course = $this->resolveClaimCourseStatus($target)) {
            throw OrderClaimException::courseStatusNotAssignable($course->label());
        }
    }

    /**
     * 주어진 상태가 클레임 코스 상태이면 그 Enum 을, 아니면 null 을 반환합니다.
     *
     * 코스 판정을 이 메서드 하나로 모은다 — 취소 경로와 관리자 상태 변경 경로가 각자 판정하면
     * 한쪽만 고쳐져 두 경로의 규칙이 갈린다.
     *
     * @param  OrderStatusEnum|string|null  $status  판정할 상태 (Enum 또는 원시 값)
     * @return OrderStatusEnum|null 코스 상태일 때 그 Enum, 아니면 null
     */
    public function resolveClaimCourseStatus(OrderStatusEnum|string|null $status): ?OrderStatusEnum
    {
        $enum = $status instanceof OrderStatusEnum
            ? $status
            : ($status === null || $status === '' ? null : OrderStatusEnum::tryFrom($status));

        if ($enum === null || ! in_array($enum, OrderStatusEnum::claimCourseStatuses(), true)) {
            return null;
        }

        return $enum;
    }

    /**
     * 상태 가드에 쓸 주문옵션 상태 값을 결정합니다.
     *
     * 승인 전 수정 화면의 미리보기(`$excludeExchangeId`/`$excludeReturnId`)에서는 그 신청서가
     * 이 옵션을 이미 코스 상태(`exchange_in_progress`/`return_in_progress`)로 옮겨 두었다 —
     * 현재 상태로 대조하면 자기 신청서에 막혀 "신청 불가" 가 되므로, 신청서가 접수 시점에
     * 박제한 원 상태(`original_option_status`)로 판정한다. 수량 합산 가드가 같은 신청서를
     * 집계에서 빼는 것과 같은 축의 제외다. 직접 환불신청은 코스 상태를 만들지 않으므로
     * 이 완화가 필요 없다.
     *
     * @param  OrderOption  $option  주문옵션
     * @param  int|null  $excludeExchangeId  수정 중인 교환 신청서 ID
     * @param  int|null  $excludeReturnId  수정 중인 반품 신청서 ID
     * @return string 판정에 쓸 상태 값
     */
    protected function statusValueForGate(OrderOption $option, ?int $excludeExchangeId, ?int $excludeReturnId): string
    {
        $current = $option->option_status instanceof OrderStatusEnum
            ? $option->option_status->value
            : (string) $option->option_status;

        if ($excludeExchangeId !== null && $current === OrderStatusEnum::EXCHANGE_IN_PROGRESS->value) {
            $original = $this->exchangeRepository->findOriginalOptionStatus($excludeExchangeId, (int) $option->id);

            if ($original !== null) {
                return $original;
            }
        }

        if ($excludeReturnId !== null && $current === OrderStatusEnum::RETURN_IN_PROGRESS->value) {
            $original = $this->returnRepository->findOriginalOptionStatus($excludeReturnId, (int) $option->id);

            if ($original !== null) {
                return $original;
            }
        }

        return $current;
    }
}
