<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 반품 클레임 상태 Enum
 *
 * 반품 신청서(`ecommerce_order_returns.return_status`)와 신청 상품
 * (`ecommerce_order_return_options.option_status`)이 공유하는 세밀 상태 11종이다.
 * 교환 상태 14종에서 재발송 3종(redelivery_ready / redelivering / redelivered)을
 * 제외한 형태이며, 회수 도착(COLLECTED)에서 곧바로 완료(COMPLETED)로 이어진다.
 *
 * 라벨은 유형 접두를 포함한 정확 명칭("반품신청" 등)이며, 유저·관리자 화면이
 * `enums.return_status.*` 단일 출처를 공유한다.
 */
enum ReturnStatusEnum: string
{
    case REQUESTED = 'requested';                  // 반품신청
    case PAYMENT_PENDING = 'payment_pending';      // 반품결제대기
    case PAID = 'paid';                            // 반품결제완료
    case APPROVED = 'approved';                    // 반품승인
    case ON_HOLD = 'on_hold';                      // 반품보류
    case REJECTED = 'rejected';                    // 반품반려
    case COLLECTION_READY = 'collection_ready';    // 반품회수준비중
    case COLLECTING = 'collecting';                // 반품회수중
    case COLLECTED = 'collected';                  // 반품회수도착
    case COMPLETED = 'completed';                  // 반품완료
    case WITHDRAWN = 'withdrawn';                  // 반품철회

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.return_status.'.$this->value);
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
        return 'sirsoft-ecommerce::enums.return_status.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::REQUESTED => 'secondary',
            self::PAYMENT_PENDING => 'warning',
            self::PAID => 'success',
            self::APPROVED => 'info',
            self::ON_HOLD => 'dark',
            self::REJECTED => 'danger',
            self::COLLECTION_READY, self::COLLECTING, self::COLLECTED => 'primary',
            self::COMPLETED => 'success',
            self::WITHDRAWN => 'secondary',
        };
    }

    /**
     * 상태를 상징하는 아이콘 의미 토큰을 반환합니다. (아이콘 라이브러리 중립)
     *
     * 교환과 같은 단계는 같은 아이콘이다 — 회수 세 단계는 교환의 회수 단계와 그림이 같아야
     * 운영자가 유형을 바꿔 가며 볼 때 단계를 다시 익히지 않는다. 색은 `variant()` 가 정한다.
     *
     * @return string 의미 토큰 (`status-*`) — 실제 글리프는 화면(템플릿)의 Icon 이 자기 아이콘 세트로 해석한다
     */
    public function icon(): string
    {
        return match ($this) {
            self::REQUESTED => 'status-request',
            self::PAYMENT_PENDING => 'status-payment-pending',
            self::PAID => 'status-paid',
            self::APPROVED => 'status-approved',
            self::ON_HOLD => 'status-on-hold',
            self::REJECTED => 'status-rejected',
            self::COLLECTION_READY => 'status-pickup-ready',
            self::COLLECTING => 'status-pickup',
            self::COLLECTED => 'status-received',
            self::COMPLETED => 'status-completed',
            self::WITHDRAWN => 'status-withdrawn',
        };
    }

    /**
     * 최종(터미널) 상태 여부를 반환합니다.
     *
     * @return bool 최종 상태이면 true
     */
    public function isFinal(): bool
    {
        return in_array($this, self::finalStatuses(), true);
    }

    /**
     * 최종(터미널) 상태 목록을 반환합니다.
     *
     * @return array<self> 최종 상태 목록
     */
    public static function finalStatuses(): array
    {
        return [self::COMPLETED, self::REJECTED, self::WITHDRAWN];
    }

    /**
     * 신청자(유저)가 철회할 수 있는 상태 목록을 반환합니다.
     *
     * @return array<self> 철회 가능 상태 목록
     */
    public static function withdrawableStatuses(): array
    {
        return [self::REQUESTED, self::PAYMENT_PENDING, self::PAID, self::ON_HOLD];
    }

    /**
     * 신청자(유저)가 철회할 수 있는 상태인지 반환합니다.
     *
     * @return bool 철회 가능하면 true
     */
    public function isWithdrawable(): bool
    {
        return in_array($this, self::withdrawableStatuses(), true);
    }

    /**
     * 현재 상태에서 전이 가능한 목표 상태 목록을 반환합니다.
     *
     * 회수 단계는 앞으로 건너뛸 수 있다(#599 후속 운영 결정) — 수거 예약과 송장이 이미 있으면
     * 승인에서 곧바로 회수중이 되고, 고객이 직접 보낸 물건이 먼저 도착하면 회수중을 거치지 않고
     * 회수도착이 된다. 오는 중인 물건도 완료(선환불)할 수 있다 — 완료는 여전히 [완료 처리] 만의
     * 입구다(`transition()`·`transitionOption()` 이 거절). 승인은 건너뛰지 않는다: 추가결제 확인과
     * 승인 알림이 승인에 걸려 있다.
     *
     * @return array<self> 전이 가능 상태 목록
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REQUESTED => [
                self::PAYMENT_PENDING,
                self::APPROVED,
                self::ON_HOLD,
                self::REJECTED,
                self::WITHDRAWN,
            ],
            self::PAYMENT_PENDING => [self::PAID, self::APPROVED, self::REJECTED, self::WITHDRAWN],
            self::PAID => [self::APPROVED, self::ON_HOLD, self::REJECTED, self::WITHDRAWN],
            self::ON_HOLD => [self::APPROVED, self::REJECTED, self::WITHDRAWN],
            self::APPROVED => [self::COLLECTION_READY, self::COLLECTING, self::COLLECTED],
            self::COLLECTION_READY => [self::COLLECTING, self::COLLECTED],
            self::COLLECTING => [self::COLLECTED, self::COMPLETED],
            self::COLLECTED => [self::COMPLETED],
            self::COMPLETED, self::REJECTED, self::WITHDRAWN => [],
        };
    }

    /**
     * 현재 상태에서 목표 상태로의 전이가 허용되는지 판정합니다.
     *
     * @param  self  $target  목표 상태
     * @return bool 전이 허용 여부
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return in_array($target, $this->allowedTransitions(), true);
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
     * 관리자 상태 변경(단건·일괄·상품 단위)에서 직접 지정할 수 있는 상태 목록을 반환합니다.
     *
     * 제외 3종은 각각 소유 경로가 따로 있다 — 신청(REQUESTED)은 접수만이 만들고,
     * 결제완료(PAID)는 결제 확정(`ClaimPaymentService::confirmPaid`)만이 만들며,
     * 철회(WITHDRAWN)는 신청자 행위(`withdraw()`)다. 직접 지정을 열면 결제 없이 결제완료가
     * 되거나 신청자가 하지 않은 철회가 기록된다. 결제대기(PAYMENT_PENDING)는 지정 가능하되
     * 관리자 경로가 `requestPaymentOnApproval()` 로 우회해 결제 건 생성과 짝을 맞춘다.
     * 상태 **필터**는 이 목록이 아니라 values() 전체를 쓴다 — 조회는 전 상태가 대상이다.
     *
     * @return array<self> 관리자 지정 가능 상태 목록
     */
    public static function adminAssignableStatuses(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => ! in_array($case, [self::REQUESTED, self::PAID, self::WITHDRAWN], true),
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
     * 정상 진행 순서(해피 패스)를 반환합니다. (상세 화면 진행단계 스텝퍼 — §6-4)
     *
     * 교환과 달리 재발송 단계가 없다. 보류·반려·철회는 흐름을 멈추는 사건이라 순서에
     * 넣지 않고 스텝퍼가 이탈 단계로 따로 그린다.
     *
     * @return array<self> 진행 순서
     */
    public static function progressSequence(): array
    {
        return [
            self::REQUESTED,
            self::PAYMENT_PENDING,
            self::PAID,
            self::APPROVED,
            self::COLLECTION_READY,
            self::COLLECTING,
            self::COLLECTED,
            self::COMPLETED,
        ];
    }

    /**
     * 진행 순서에 속하지 않는 이탈 상태인지 반환합니다. (보류·반려·철회)
     *
     * @return bool 이탈 상태이면 true
     */
    public function isOffProgressPath(): bool
    {
        return ! in_array($this, self::progressSequence(), true);
    }
}
