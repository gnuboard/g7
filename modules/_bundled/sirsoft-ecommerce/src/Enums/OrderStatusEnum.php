<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 주문 상태 Enum
 */
enum OrderStatusEnum: string
{
    case PENDING_ORDER = 'pending_order';           // 주문대기
    case PENDING_PAYMENT = 'pending_payment';       // 결제대기
    case PAYMENT_COMPLETE = 'payment_complete';     // 결제완료
    case SHIPPING_HOLD = 'shipping_hold';           // 배송보류
    case PREPARING = 'preparing';                   // 상품준비중
    case SHIPPING_READY = 'shipping_ready';         // 배송준비완료
    case SHIPPING = 'shipping';                     // 배송중
    case DELIVERED = 'delivered';                   // 배송완료
    case CONFIRMED = 'confirmed';                   // 구매확정
    case CANCELLED = 'cancelled';                   // 주문취소

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.order_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.order_status.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string 뱃지 variant (success/warning/danger/info/primary/secondary)
     */
    public function variant(): string
    {
        return match ($this) {
            self::PENDING_ORDER => 'secondary',
            self::PENDING_PAYMENT => 'warning',
            self::PAYMENT_COMPLETE => 'info',
            self::SHIPPING_HOLD => 'warning',
            self::PREPARING => 'info',
            self::SHIPPING_READY => 'info',
            self::SHIPPING => 'primary',
            self::DELIVERED => 'success',
            self::CONFIRMED => 'success',
            self::CANCELLED => 'danger',
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<string> 상태 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<array{value: string, label: string}> 셀렉트 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }

    /**
     * 결제 전 상태인지 확인합니다.
     *
     * @return bool 결제 전 상태 여부
     */
    public function isBeforePayment(): bool
    {
        return in_array($this, [self::PENDING_ORDER, self::PENDING_PAYMENT]);
    }

    /**
     * 주문 상태 → 옵션 상태 동기화에서 제외할 상태 목록을 반환합니다.
     *
     * 결제완료·관리자 주문 수정 등으로 주문 전체 상태를 일괄 전이시킬 때,
     * 별도 라이프사이클(취소/환불, 향후 클레임 등)을 가진 옵션은 덮어쓰지 않는다.
     * 취소된 옵션이 결제완료/배송중 등으로 되살아나는 것을 차단하는 SSoT.
     *
     * 향후 클레임(반품/교환) 상태가 추가되면 이 목록에 함께 등록하면
     * 모든 동기화 지점(completePayment / OrderService::update /
     * bulkUpdateOptionStatus / syncParentOrderStatus)에 일괄 반영된다.
     *
     * @return array<self>
     */
    public static function syncExcludedStatuses(): array
    {
        return [self::CANCELLED];
    }

    /**
     * 주문 상태 → 옵션 상태 동기화에서 제외할 상태 값 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function syncExcludedValues(): array
    {
        return array_map(fn ($case) => $case->value, self::syncExcludedStatuses());
    }

    /**
     * 주문 목록·통계에서 기본 숨김 처리할 상태 목록을 반환합니다.
     *
     * PENDING_ORDER(주문대기)는 PG 결제창 진입 전/미완료의 임시 상태로,
     * 결제가 완료된 적 없는 주문이라 관리자·마이페이지 목록과 통계에서 기본 제외한다.
     * (관리자가 order_status 필터를 명시하면 노출 가능 — Repository 분기 참조)
     *
     * @return array<self>
     */
    public static function listHiddenStatuses(): array
    {
        return [self::PENDING_ORDER];
    }

    /**
     * 주문 목록·통계에서 기본 숨김 처리할 상태 값 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function listHiddenValues(): array
    {
        return array_map(fn ($case) => $case->value, self::listHiddenStatuses());
    }

    /**
     * 배송 전 상태인지 확인합니다.
     *
     * @return bool 배송 전 상태 여부
     */
    public function isBeforeShipping(): bool
    {
        return in_array($this, [
            self::PENDING_ORDER,
            self::PENDING_PAYMENT,
            self::PAYMENT_COMPLETE,
            self::SHIPPING_HOLD,
            self::PREPARING,
            self::SHIPPING_READY,
        ]);
    }

    /**
     * 배송 정보(택배사/송장번호)가 필수인 상태인지 확인합니다.
     *
     * @return bool 배송 정보 필수 여부
     */
    public function requiresShippingInfo(): bool
    {
        return in_array($this, self::shippingInfoRequiredStatuses());
    }

    /**
     * 배송 정보 필수 상태 목록을 반환합니다.
     *
     * @return array<self>
     */
    public static function shippingInfoRequiredStatuses(): array
    {
        return [self::SHIPPING_READY, self::SHIPPING];
    }

    /**
     * 배송 정보 필수 상태 값 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function shippingInfoRequiredValues(): array
    {
        return array_map(fn ($case) => $case->value, self::shippingInfoRequiredStatuses());
    }

    /**
     * 발송 이후 상태인지 확인합니다. (배송중, 배송완료, 구매확정)
     *
     * @return bool 발송 이후 상태 여부
     */
    public function isShipped(): bool
    {
        return in_array($this, self::shippedStatuses());
    }

    /**
     * 발송 이후 상태 목록을 반환합니다.
     *
     * @return array<self>
     */
    public static function shippedStatuses(): array
    {
        return [self::SHIPPING, self::DELIVERED, self::CONFIRMED];
    }

    /**
     * 완료 상태인지 확인합니다.
     *
     * @return bool 완료 상태 여부
     */
    public function isCompleted(): bool
    {
        return in_array($this, [self::DELIVERED, self::CONFIRMED]);
    }

    /**
     * 매출에 반영되는 상태 목록을 반환합니다.
     *
     * "결제됨 & 미취소" 구간 — 결제완료 이후 구매확정까지(배송보류 포함).
     * 대시보드 판매 현황 집계(순매출/판매수량)에서 이 상태의 주문상품만 합산합니다.
     * 결제 전(pending_order/pending_payment)과 취소(partial_cancelled/cancelled)는 제외됩니다.
     *
     * @return array<self>
     */
    public static function salesEligibleStatuses(): array
    {
        return [
            self::PAYMENT_COMPLETE,
            self::SHIPPING_HOLD,
            self::PREPARING,
            self::SHIPPING_READY,
            self::SHIPPING,
            self::DELIVERED,
            self::CONFIRMED,
        ];
    }

    /**
     * 매출 반영 상태 값(문자열) 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function salesEligibleValues(): array
    {
        return array_map(fn ($case) => $case->value, self::salesEligibleStatuses());
    }

    /**
     * 마이페이지 주문내역 카운터 키 → 실제 필터 상태 값 집합 매핑을 반환합니다.
     *
     * 일부 카운터(상품준비중)는 여러 내부 상태를 합산해 집계하므로, 그 카운터를 클릭해
     * 필터링할 때도 동일한 상태 집합으로 조회해야 카운터 수와 목록 수가 일치한다.
     * 카운터 정의(getUserStatistics)와 필터 확장(Repository)이 이 매핑을 공유하는 SSoT다.
     *
     * @return array<string, array<string>> 카운터 키 => 필터 대상 상태 값 배열
     */
    public static function statisticsFilterGroups(): array
    {
        return [
            self::PREPARING->value => [
                self::PREPARING->value,
                self::SHIPPING_READY->value,
            ],
        ];
    }

    /**
     * 카운터 키(또는 단일 상태 값)를 실제 필터 대상 상태 값 배열로 확장합니다.
     *
     * 합산 카운터 키면 그룹(여러 상태)으로, 일반 상태 값이면 자기 자신만 반환한다.
     *
     * @param  string  $statusValue  카운터 키 또는 상태 값
     * @return array<string> 필터 대상 상태 값 배열
     */
    public static function expandStatisticsFilter(string $statusValue): array
    {
        return self::statisticsFilterGroups()[$statusValue] ?? [$statusValue];
    }

    /**
     * 주문 진행 순서 SSoT 를 반환합니다. (취소 상태 제외 9단계)
     *
     * 인덱스 순서가 곧 정상 진행 방향이며, 전이 규칙(allowedTransitions)·
     * 잔여옵션 파생 상태(OrderOptionService::syncParentOrderStatus)가 이 배열을 공유한다.
     *
     * @return array<self>
     */
    public static function progressOrder(): array
    {
        return [
            self::PENDING_ORDER,
            self::PENDING_PAYMENT,
            self::PAYMENT_COMPLETE,
            self::SHIPPING_HOLD,
            self::PREPARING,
            self::SHIPPING_READY,
            self::SHIPPING,
            self::DELIVERED,
            self::CONFIRMED,
        ];
    }

    /**
     * 주문 진행 순서 값(문자열) 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function progressOrderValues(): array
    {
        return array_map(fn ($case) => $case->value, self::progressOrder());
    }

    /**
     * 현재 상태에서 전이 가능한 목표 상태 목록을 반환합니다. (취소 진입 제외)
     *
     * - forward 점프: 진행 순서상 현재 인덱스보다 큰 모든 상태(중간 건너뛰기 허용).
     * - 역방향 화이트리스트: 운영 정정용으로 아래 쌍만 추가 허용.
     *   · SHIPPING_HOLD ↔ PREPARING (배송보류는 준비중과 양방향)
     *   · SHIPPING → {SHIPPING_READY, PREPARING}
     *   · DELIVERED → SHIPPING
     * - 취소(CANCELLED) 진입은 이 목록에 포함하지 않는다(canTransitionTo 가 별도 통과 처리).
     * - 현재 상태가 CANCELLED 면 판매 반영 상태(salesEligibleStatuses)로만 복원 허용
     *   (취소된 주문/옵션을 다시 판매 진행 상태로 되살리는 reactivation 운영 기능 — 재고 재차감 동반.
     *    결제 전 상태(pending_order/pending_payment)로의 복원은 차단. 2026-06-23).
     *
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        if ($this === self::CANCELLED) {
            // 취소 → 판매 상태 복원(reactivation)만 허용. 결제 전 상태 복귀는 차단.
            return self::salesEligibleStatuses();
        }

        $order = self::progressOrder();
        $index = array_search($this, $order, true);

        // forward 점프: 현재 인덱스보다 큰 모든 진행 상태
        $forward = $index === false ? [] : array_slice($order, $index + 1);

        // 역방향 화이트리스트 union (운영 정정용)
        $reverse = match ($this) {
            self::PREPARING => [self::SHIPPING_HOLD],                 // 배송보류 ↔ 준비중(양방향)
            self::SHIPPING => [self::SHIPPING_READY, self::PREPARING],
            self::DELIVERED => [self::SHIPPING],
            default => [],
        };

        // 중복 제거 후 반환 (forward 에 이미 있는 항목과 reverse 가 겹치지 않으나 방어적 union)
        return array_values(array_unique(array_merge($forward, $reverse), SORT_REGULAR));
    }

    /**
     * 현재 상태에서 목표 상태로의 전이가 허용되는지 판정합니다.
     *
     * 판정 순서:
     *   1) 동일 상태(self-transition) → true (일괄에서 이미 그 상태인 건 혼입 방어)
     *   2) 목표가 취소(CANCELLED) → true (취소는 이 게이트 책임 밖, OrderCancellationService 전담)
     *   3) 그 외 → allowedTransitions() 포함 여부
     *
     * @param  self  $target  목표 상태
     * @return bool 전이 허용 여부
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true; // self-transition no-op 허용
        }

        if (in_array($target, self::syncExcludedStatuses(), true)) {
            return true; // 취소 진입은 OrderCancellationService 전담
        }

        return in_array($target, $this->allowedTransitions(), true);
    }
}
