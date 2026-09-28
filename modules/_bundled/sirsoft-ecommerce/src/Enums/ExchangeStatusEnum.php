<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 교환 클레임 상태 Enum
 *
 * 교환 신청서(`ecommerce_order_exchanges.exchange_status`)와 신청 상품
 * (`ecommerce_order_exchange_options.option_status`)이 공유하는 세밀 상태 14종이다.
 * 원 주문옵션(`ecommerce_order_options.option_status`)에는 코스 상태
 * (`OrderStatusEnum::EXCHANGE_IN_PROGRESS` / `EXCHANGED`)만 기록되며,
 * 세밀 상태는 클레임 테이블에만 존재한다.
 *
 * 라벨은 유형 접두를 포함한 정확 명칭("교환신청" 등)이며, 유저·관리자 화면이
 * `enums.exchange_status.*` 단일 출처를 공유한다. 화면마다 어휘를 따로 두면
 * 같은 건이 서로 다르게 불린다.
 */
enum ExchangeStatusEnum: string
{
    case REQUESTED = 'requested';                  // 교환신청
    case PAYMENT_PENDING = 'payment_pending';      // 교환결제대기
    case PAID = 'paid';                            // 교환결제완료
    case APPROVED = 'approved';                    // 교환승인
    case ON_HOLD = 'on_hold';                      // 교환보류
    case REJECTED = 'rejected';                    // 교환반려
    case COLLECTION_READY = 'collection_ready';    // 교환회수준비중
    case COLLECTING = 'collecting';                // 교환회수중
    case COLLECTED = 'collected';                  // 교환회수도착
    case REDELIVERY_READY = 'redelivery_ready';    // 교환발송준비중
    case REDELIVERING = 'redelivering';            // 교환발송중
    case REDELIVERED = 'redelivered';              // 교환발송도착
    case COMPLETED = 'completed';                  // 교환완료
    case WITHDRAWN = 'withdrawn';                  // 교환철회

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.exchange_status.'.$this->value);
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
        return 'sirsoft-ecommerce::enums.exchange_status.'.$this->value;
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
            self::REDELIVERY_READY, self::REDELIVERING, self::REDELIVERED => 'primary',
            self::COMPLETED => 'success',
            self::WITHDRAWN => 'secondary',
        };
    }

    /**
     * 상태를 상징하는 아이콘 의미 토큰을 반환합니다. (아이콘 라이브러리 중립)
     *
     * 진행단계 스텝퍼와 상태 뱃지가 같은 값을 읽는다 — 화면마다 아이콘을 따로 고르면
     * 같은 상태가 화면에 따라 다른 그림으로 보인다. 색은 `variant()` 가 정한다.
     *
     * 값은 특정 아이콘 라이브러리의 글리프 이름이 아니라 **의미 토큰**이다(`status-request` ·
     * `status-pickup` …). 백엔드가 글리프 이름을 박으면 그 라이브러리에 묶인다 — 토큰을
     * 글리프로 바꾸는 표는 아이콘 세트를 소유한 템플릿의 Icon 컴포넌트가 가진다. 같은 단계는
     * 유형(교환·반품·환불·취소)이 달라도 같은 토큰이다.
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
            self::REDELIVERY_READY => 'status-dispatch-ready',
            self::REDELIVERING => 'status-dispatch',
            self::REDELIVERED => 'status-delivered',
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
     * 승인(APPROVED) 이후는 회수 절차가 시작되므로 철회 경계 밖이다.
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
     * 회수·재발송 단계는 앞으로 건너뛸 수 있다(#599 후속 운영 결정) — 송장이 있으면 승인에서 곧바로
     * 회수중, 회수도착에서 곧바로 발송중이 되고, 고객이 직접 보낸 물건은 회수도착으로, 직접 건넨
     * 교환품은 발송도착으로 곧바로 간다. 발송중인 교환품도 완료할 수 있다 — 완료는 여전히
     * [완료 처리] 만의 입구다. 승인은 건너뛰지 않는다: 추가결제 확인·교환품 재고 차감이 승인에 걸려 있다.
     *
     * 일반 교환의 표다 — 선교환 표는 `allowedTransitionsIn(ExchangeModeEnum::ADVANCE)` 가 낸다.
     *
     * @return array<self> 전이 가능 상태 목록
     */
    public function allowedTransitions(): array
    {
        return $this->allowedTransitionsIn(ExchangeModeEnum::STANDARD);
    }

    /**
     * 진행 방식별로 현재 상태에서 전이 가능한 목표 상태 목록을 반환합니다.
     *
     * 선교환(새 물건 먼저 발송)은 심사 단계가 일반 교환과 같고, 승인 뒤로는 **발송 단계만** 쓴다 —
     * 승인 → 발송준비중·발송중·발송도착, 발송준비중 → 발송중·발송도착, 발송중 → 발송도착·완료,
     * 발송도착 → 완료. 회수준비중·회수중·회수도착은 선교환에서 쓰지 않는다 — 헌 물건이 어디쯤
     * 왔는지는 상품 행의 회수 송장·도착 시각이 따로 표현한다(`ClaimItemCollectionState`).
     *
     * @param  ExchangeModeEnum  $mode  진행 방식
     * @return array<self> 전이 가능 상태 목록
     */
    public function allowedTransitionsIn(ExchangeModeEnum $mode): array
    {
        if ($mode === ExchangeModeEnum::ADVANCE) {
            return match ($this) {
                self::APPROVED => [self::REDELIVERY_READY, self::REDELIVERING, self::REDELIVERED],
                self::COLLECTION_READY, self::COLLECTING, self::COLLECTED => [],
                default => $this->standardTransitions(),
            };
        }

        return $this->standardTransitions();
    }

    /**
     * 일반 교환의 전이표를 반환합니다.
     *
     * @return array<self> 전이 가능 상태 목록
     */
    protected function standardTransitions(): array
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
            self::COLLECTING => [self::COLLECTED],
            self::COLLECTED => [self::REDELIVERY_READY, self::REDELIVERING, self::REDELIVERED],
            self::REDELIVERY_READY => [self::REDELIVERING, self::REDELIVERED],
            self::REDELIVERING => [self::REDELIVERED, self::COMPLETED],
            self::REDELIVERED => [self::COMPLETED],
            self::COMPLETED, self::REJECTED, self::WITHDRAWN => [],
        };
    }

    /**
     * 현재 상태에서 목표 상태로의 전이가 허용되는지 판정합니다.
     *
     * 동일 상태(self-transition)는 일괄 전이에서 이미 그 상태인 건이 섞이는 것을
     * 방어하기 위해 허용한다(no-op).
     *
     * @param  self  $target  목표 상태
     * @param  ExchangeModeEnum  $mode  진행 방식 (기본 일반 교환)
     * @return bool 전이 허용 여부
     */
    public function canTransitionTo(self $target, ExchangeModeEnum $mode = ExchangeModeEnum::STANDARD): bool
    {
        if ($this === $target) {
            return true;
        }

        return in_array($target, $this->allowedTransitionsIn($mode), true);
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
     * 보류·반려·철회는 이 순서에 없다 — 흐름을 따라가는 단계가 아니라 흐름을 멈추는
     * 사건이라 스텝퍼는 그 셋을 이탈 단계로 따로 그린다. 결제 두 단계는 청구액이 있는
     * 건에서만 지나가므로, 실제로 그릴지는 신청서를 아는 쪽(`AdminClaimService`)이 정한다.
     *
     * @return array<self> 진행 순서
     */
    public static function progressSequence(): array
    {
        return self::progressSequenceIn(ExchangeModeEnum::STANDARD);
    }

    /**
     * 진행 방식별 정상 진행 순서를 반환합니다.
     *
     * 선교환은 회수 3단계가 없다 — 신청·결제대기·결제완료·승인·발송준비중·발송중·발송도착·완료.
     * 헌 물건의 회수 진행은 진행단계 카드가 발송 줄 아래 「회수」 줄로 따로 그린다.
     *
     * @param  ExchangeModeEnum  $mode  진행 방식
     * @return array<self> 진행 순서
     */
    public static function progressSequenceIn(ExchangeModeEnum $mode): array
    {
        if ($mode === ExchangeModeEnum::ADVANCE) {
            return [
                self::REQUESTED,
                self::PAYMENT_PENDING,
                self::PAID,
                self::APPROVED,
                self::REDELIVERY_READY,
                self::REDELIVERING,
                self::REDELIVERED,
                self::COMPLETED,
            ];
        }

        return [
            self::REQUESTED,
            self::PAYMENT_PENDING,
            self::PAID,
            self::APPROVED,
            self::COLLECTION_READY,
            self::COLLECTING,
            self::COLLECTED,
            self::REDELIVERY_READY,
            self::REDELIVERING,
            self::REDELIVERED,
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
