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
    case SHIPPING_READY = 'shipping_ready';         // 배송준비중
    case SHIPPING = 'shipping';                     // 배송중
    case DELIVERED = 'delivered';                   // 배송완료
    case CONFIRMED = 'confirmed';                   // 구매확정
    case CANCELLED = 'cancelled';                   // 주문취소
    case EXCHANGE_IN_PROGRESS = 'exchange_in_progress'; // 교환진행중 (클레임 코스 상태)
    case RETURN_IN_PROGRESS = 'return_in_progress';     // 반품진행중 (클레임 코스 상태)
    case EXCHANGED = 'exchanged';                       // 교환완료 (터미널, 매출은 신규 옵션이 승계)
    case RETURNED = 'returned';                         // 반품완료 (터미널)

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
            self::EXCHANGE_IN_PROGRESS => 'warning',
            self::RETURN_IN_PROGRESS => 'warning',
            self::EXCHANGED => 'secondary',
            self::RETURNED => 'secondary',
        };
    }

    /**
     * 상태를 상징하는 아이콘 의미 토큰을 반환합니다.
     *
     * 아이콘 라이브러리(Font Awesome 등)의 글리프 이름이 아니라 라이브러리 중립 토큰이다 —
     * 토큰→글리프 표는 각 템플릿이 소유한다. 어휘는 모듈 API 문서 "아이콘 의미 토큰 어휘" 절이
     * 정하며, 클레임 상태 Enum 과 같은 단계는 같은 토큰을 쓴다(결제·발송·배송완료·완료).
     *
     * @return string 아이콘 의미 토큰 (status-*)
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING_ORDER => 'status-request',
            self::PENDING_PAYMENT => 'status-payment-pending',
            self::PAYMENT_COMPLETE => 'status-paid',
            self::SHIPPING_HOLD => 'status-on-hold',
            self::PREPARING => 'status-preparing',
            self::SHIPPING_READY => 'status-dispatch-ready',
            self::SHIPPING => 'status-dispatch',
            self::DELIVERED => 'status-delivered',
            self::CONFIRMED => 'status-completed',
            self::CANCELLED => 'status-cancelled',
            self::EXCHANGE_IN_PROGRESS => 'status-exchange',
            self::RETURN_IN_PROGRESS => 'status-return',
            self::EXCHANGED => 'status-exchanged',
            self::RETURNED => 'status-returned',
        };
    }

    /**
     * 진행단계 스텝퍼에 한 줄로 세우는 순서를 반환합니다. (7단계)
     *
     * `progressOrder()` 가 전이 규칙용 전체 순서(주문대기·배송보류 포함 9단계)라면, 이것은
     * 구매자·운영자에게 **보여 주는** 순서다 — 주문대기는 결제창 진입 전 임시 상태라 목록·통계에서도
     * 숨기고, 배송보류는 흐름을 따라가는 단계가 아니라 멈추는 사건이라 이탈 칸으로 따로 붙는다.
     *
     * @return array<self> 진행 순서
     */
    public static function progressSequence(): array
    {
        return [
            self::PENDING_PAYMENT,
            self::PAYMENT_COMPLETE,
            self::PREPARING,
            self::SHIPPING_READY,
            self::SHIPPING,
            self::DELIVERED,
            self::CONFIRMED,
        ];
    }

    /**
     * 진행 순서 밖으로 벗어난(멈춘) 상태인지 확인합니다.
     *
     * 배송보류·주문취소·교환/반품 진행·교환/반품 완료가 여기 든다. 주문대기는 순서 밖이지만
     * 이탈이 아니라 첫 칸 앞이므로 포함하지 않는다.
     *
     * @return bool 이탈 상태 여부
     */
    public function isOffProgressPath(): bool
    {
        return in_array($this, [
            self::SHIPPING_HOLD,
            self::CANCELLED,
            self::EXCHANGE_IN_PROGRESS,
            self::RETURN_IN_PROGRESS,
            self::EXCHANGED,
            self::RETURNED,
        ], true);
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
     * 결제 전 상태에서 판매 상태(결제완료 ~ 구매확정)로 올라가는 전이인지 판정합니다. (수동 결제 처리 P — Q13)
     *
     * 이 전이는 PG 승인·입금확인을 거치지 않으므로 재고 차감·사용 마일리지 차감·결제금액 기록을
     * 운영자가 확인 창에서 고른 대로 따로 처리해야 한다. 재활성(취소 → 판매)과 클레임 코스 복귀는
     * 걸리지 않는다.
     *
     * @param  self|string|null  $from  변경 전 상태
     * @param  self|string|null  $to  변경 후 상태
     * @return bool 결제 코스 진입 전이면 true
     */
    public static function movesIntoPaidCourse(self|string|null $from, self|string|null $to): bool
    {
        $from = $from instanceof self ? $from : self::tryFrom((string) $from);
        $to = $to instanceof self ? $to : self::tryFrom((string) $to);

        return $from !== null && $to !== null && $from->isBeforePayment()
            && in_array($to, self::salesEligibleStatuses(), true);
    }

    /**
     * 취소 상태에서 판매 상태로 되살리는 전이인지 판정합니다. (재활성 R — Q14·Q26)
     *
     * @param  self|string|null  $from  변경 전 상태
     * @param  self|string|null  $to  변경 후 상태
     * @return bool 재활성 전이면 true
     */
    public static function revivesFromCancelled(self|string|null $from, self|string|null $to): bool
    {
        $from = $from instanceof self ? $from : self::tryFrom((string) $from);
        $to = $to instanceof self ? $to : self::tryFrom((string) $to);

        return $from === self::CANCELLED && $to !== null && in_array($to, self::salesEligibleStatuses(), true);
    }

    /**
     * 활성 상품 상태 값 목록에서 부모 주문 상태를 파생합니다. (순수 함수)
     *
     * 상품 상태 동기화(`OrderOptionService::syncParentOrderStatus`)와 확인 창 미리보기의 부모 상태
     * 시뮬레이션이 같은 규칙을 써야 미리보기가 실행과 갈리지 않는다. 활성 상품이 없으면(전부 취소)
     * 취소, 하나의 상태면 그 상태, 섞이면 진행 순서상 가장 낮은 상태(보수적)다.
     *
     * @param  array<int, string>  $activeStatusValues  취소·클레임 코스를 뺀 활성 상품 상태 값
     * @param  bool  $hasAnyOption  상품 행이 하나라도 있는지 (없으면 파생하지 않음)
     * @return self|null 파생 상태 (상품 행이 없으면 null)
     */
    public static function deriveParentStatus(array $activeStatusValues, bool $hasAnyOption = true): ?self
    {
        $values = array_values(array_unique($activeStatusValues));

        if ($values === []) {
            return $hasAnyOption ? self::CANCELLED : null;
        }

        if (count($values) === 1) {
            return self::from($values[0]);
        }

        $order = self::progressOrderValues();
        $lowest = PHP_INT_MAX;
        foreach ($values as $value) {
            $index = array_search($value, $order, true);
            if ($index !== false && $index < $lowest) {
                $lowest = $index;
            }
        }

        return self::from($order[$lowest]);
    }

    /**
     * 결제 이후 단계(결제완료 ~ 구매확정)인지 확인합니다. 취소는 결제 이후가 아닙니다.
     *
     * 입금 기록, 상태 전이의 결제금액 정합화, 관리자 입금확인 창(리소스 `is_past_payment`)이 같은 판정을 쓴다.
     *
     * @return bool 결제 이후 단계면 true
     */
    public function isPastPayment(): bool
    {
        return ! $this->isBeforePayment() && $this !== self::CANCELLED;
    }

    /**
     * 주문 상태 → 옵션 상태 동기화에서 제외할 상태 목록을 반환합니다.
     *
     * 결제완료·관리자 주문 수정 등으로 주문 전체 상태를 일괄 전이시킬 때,
     * 별도 라이프사이클(취소/환불, 향후 클레임 등)을 가진 옵션은 덮어쓰지 않는다.
     * 취소된 옵션이 결제완료/배송중 등으로 되살아나는 것을 차단하는 SSoT.
     *
     * 클레임(교환/반품) 코스 상태 4종도 같은 이유로 이 목록에 포함된다 — 등록만 하면
     * 모든 동기화 지점(completePayment / OrderService::update /
     * bulkUpdateOptionStatus / syncParentOrderStatus)에 일괄 반영된다.
     *
     * @return array<self> 옵션 상태 동기화에서 제외할 상태 목록
     */
    public static function syncExcludedStatuses(): array
    {
        return [
            self::CANCELLED,
            self::EXCHANGE_IN_PROGRESS,
            self::RETURN_IN_PROGRESS,
            self::EXCHANGED,
            self::RETURNED,
        ];
    }

    /**
     * 클레임(교환/반품) 라이프사이클이 소유하는 코스 상태 목록을 반환합니다.
     *
     * 세밀 상태(ExchangeStatusEnum 14종 / ReturnStatusEnum 11종)는 클레임 테이블에만
     * 두고, 원 주문·주문옵션에는 이 4종만 기록한다. 진행 2종은 회수/재발송이 도는 동안,
     * 완료 2종은 터미널이다.
     *
     * 이 상태들은 클레임 서비스가 전담 기록하므로 관리자 주문 화면의 일반 상태 변경에서는
     * 선택 대상이 아니다(adminAssignableStatuses 참조).
     *
     * @return array<self> 클레임 코스 상태 목록
     */
    public static function claimCourseStatuses(): array
    {
        return [
            self::EXCHANGE_IN_PROGRESS,
            self::RETURN_IN_PROGRESS,
            self::EXCHANGED,
            self::RETURNED,
        ];
    }

    /**
     * 클레임 코스 상태 값 배열을 반환합니다.
     *
     * @return array<string> 클레임 코스 상태 값 배열
     */
    public static function claimCourseValues(): array
    {
        return array_map(fn ($case) => $case->value, self::claimCourseStatuses());
    }

    /**
     * 판매 상태로 되돌릴 수 있는 클레임 코스 상태 목록을 반환합니다.
     *
     * 진행 2종만 대상이다. 교환·반품이 반려·철회되면 그 상품옵션은 신청 직전 상태로
     * 정확히 돌아가야 하는데(배송중에 신청한 건을 배송완료로 되돌리면 도착하지 않은
     * 상품이 도착한 것으로 기록된다), 그 복귀가 canTransitionTo 게이트에 막히면
     * 클레임 서비스가 게이트를 우회해야 한다.
     *
     * 완료 2종(EXCHANGED/RETURNED)은 제외한다 — 완료 시점에 환불 집행·재고 복구·신규
     * 옵션 발송이 이미 끝나 있어 상태만 되돌리면 돈·재고와 기록이 어긋난다. 오조작은
     * 새 클레임 또는 수동 조정으로 정정한다.
     *
     * 게이트는 판매 반영 구간(salesEligibleStatuses)까지만 열고, **정확한 복귀 대상**은
     * 클레임 신청 상품의 original_option_status 가 정한다 — 게이트를 좁히면 신청 직전
     * 상태가 무엇이었느냐에 따라 복귀가 되기도 안 되기도 한다.
     *
     * @return array<self> 복귀 가능한 클레임 진행 코스 상태 목록
     */
    public static function revertibleClaimCourseStatuses(): array
    {
        return [
            self::EXCHANGE_IN_PROGRESS,
            self::RETURN_IN_PROGRESS,
        ];
    }

    /**
     * 관리자가 주문/주문옵션 상태 변경에서 직접 지정할 수 있는 상태 목록을 반환합니다.
     *
     * 클레임 코스 상태는 교환·반품 서비스가 신청서와 짝을 맞춰 기록하는 값이라,
     * 주문 화면에서 직접 지정하면 신청서 없는 코스 상태가 남아 이후 클레임 흐름과
     * 어긋난다(오류 없이 데이터만 어긋나는 결함). 상태 **필터**는 이 목록이 아니라
     * values() 전체를 쓴다 — 조회는 클레임 진행 건도 대상이다.
     *
     * **취소도 같은 이유로 제외한다.** 취소는 상태 변경이 아니라 장부 행위다 — 취소신청서·
     * 환불신청서·주문 합계·결제행 정리가 한 묶음으로 일어나야 한다. 일괄 상태변경으로
     * `cancelled` 를 찍으면 그 경로는 재고만 복원하고 돈은 그대로 두어, 원장 어디에도
     * 근거 없는 취소가 남는다. 결제행을 정리하지 않으므로 그 주문은 이후 입금확인·입금통보로
     * **결제완료로 되살아난다**(P4 가 막은 결함이 그 경로로 부활한다).
     *
     * 취소의 입구는 하나다 — `POST .../orders/{id}/cancel` 이 클레임 접수로 흐른다.
     *
     * @return array<self> 관리자 지정 가능 상태 목록
     */
    public static function adminAssignableStatuses(): array
    {
        $excluded = array_merge(self::claimCourseStatuses(), [self::CANCELLED]);

        return array_values(array_filter(
            self::cases(),
            fn (self $case) => ! in_array($case, $excluded, true),
        ));
    }

    /**
     * 관리자 지정 가능 상태 값 배열을 반환합니다.
     *
     * @return array<string> 관리자 지정 가능 상태 값 배열
     */
    public static function adminAssignableValues(): array
    {
        return array_map(fn ($case) => $case->value, self::adminAssignableStatuses());
    }

    /**
     * 주문 상태 → 옵션 상태 동기화에서 제외할 상태 값 배열을 반환합니다.
     *
     * @return array<string> 옵션 상태 동기화에서 제외할 상태 값 목록
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
     * @return array<self> 목록과 통계에서 기본으로 숨길 상태 목록
     */
    public static function listHiddenStatuses(): array
    {
        return [self::PENDING_ORDER];
    }

    /**
     * 주문 목록·통계에서 기본 숨김 처리할 상태 값 배열을 반환합니다.
     *
     * @return array<string> 목록과 통계에서 기본으로 숨길 상태 값 목록
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
     * @return array<self> 배송 정보가 있어야 하는 상태 목록
     */
    public static function shippingInfoRequiredStatuses(): array
    {
        return [self::SHIPPING_READY, self::SHIPPING];
    }

    /**
     * 배송 정보 필수 상태 값 배열을 반환합니다.
     *
     * @return array<string> 배송 정보가 있어야 하는 상태 값 목록
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
     * @return array<self> 발송 이후 상태 목록
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
     * 결제 전(pending_order/pending_payment)과 취소(cancelled — 부분취소는 별도 상태가 아니라
     * CANCELLED 로 분할된 행으로 표현된다)는 제외됩니다.
     *
     * @return array<self> 매출에 반영되는 상태 목록
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
     * @return array<string> 매출에 반영되는 상태 값 목록
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
     * @return array<self> 진행 순서대로 늘어선 상태 목록(취소 제외)
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
     * @return array<string> 진행 순서대로 늘어선 상태 값 목록
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
     * - 현재 상태가 클레임 진행 코스(교환진행중/반품진행중)면 같은 규칙으로 판매 반영 상태 복귀 허용
     *   (반려·철회 시 신청 직전 상태로 되돌리는 경로 — revertibleClaimCourseStatuses 참조).
     *   완료 2종(EXCHANGED/RETURNED)은 터미널이라 빈 배열을 유지한다.
     *
     * @return array<self> 현재 상태에서 옮겨 갈 수 있는 상태 목록(취소 진입 제외)
     */
    public function allowedTransitions(): array
    {
        if ($this === self::CANCELLED) {
            // 취소 → 판매 상태 복원(reactivation)만 허용. 결제 전 상태 복귀는 차단.
            return self::salesEligibleStatuses();
        }

        if (in_array($this, self::revertibleClaimCourseStatuses(), true)) {
            // 클레임 진행 코스 → 판매 상태 복귀(반려·철회). 취소 reactivation 과 같은 형태이며,
            // 정확한 복귀 대상은 클레임 신청 상품의 original_option_status 가 좁힌다.
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
     *   2) 목표가 별도 라이프사이클 상태(syncExcludedStatuses — 취소 + 클레임 코스 4종)
     *      → true (각각 OrderCancellationService / 교환·반품 서비스 전담)
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
            return true; // 취소·클레임 코스 진입은 전담 서비스 소관
        }

        return in_array($target, $this->allowedTransitions(), true);
    }
}
