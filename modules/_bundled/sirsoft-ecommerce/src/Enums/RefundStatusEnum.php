<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 환불 상태 Enum
 */
enum RefundStatusEnum: string
{
    case REQUESTED = 'requested';      // 환불 신청
    case APPROVED = 'approved';        // 환불 승인
    case PROCESSING = 'processing';    // 환불 처리 중 (PG 요청 중)
    case ON_HOLD = 'on_hold';          // 환불 보류
    case COMPLETED = 'completed';      // 환불 완료
    case REJECTED = 'rejected';        // 환불 반려
    case WITHDRAWN = 'withdrawn';      // 환불 철회 (신청자 주체)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.refund_status.'.$this->value);
    }

    /**
     * 다국어 라벨 **키**를 반환합니다. (활동 로그의 전/후 라벨 해석용)
     *
     * 활동 로그는 값을 원값으로 저장하고 라벨은 **표시 시점 로케일**로 해석한다 — 그래서
     * `label()`(이미 번역된 문자열)이 아니라 키가 필요하다. 이 메서드가 없으면
     * `BuildsEnumFieldChange::enumFieldChange()` 가 정의되지 않은 메서드를 불러 리스너 잡이
     * 통째로 죽는다. 리스너는 큐 잡으로 돌아가므로 그 실패는 화면 어디에도 드러나지 않고
     * **해당 전이의 활동로그만 조용히 사라진다**(환불신청서 상태 축이 그 상태였다).
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.refund_status.'.$this->value;
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::REQUESTED => 'secondary',
            self::APPROVED => 'info',
            self::PROCESSING => 'warning',
            self::ON_HOLD => 'dark',
            self::COMPLETED => 'success',
            self::REJECTED => 'danger',
            self::WITHDRAWN => 'secondary',
        };
    }

    /**
     * 상태를 상징하는 아이콘 의미 토큰을 반환합니다. (아이콘 라이브러리 중립)
     *
     * 교환·반품과 같은 단계(신청·승인·완료·보류·반려·철회)는 같은 아이콘이다. 색은
     * `variant()` 가 정한다.
     *
     * @return string 의미 토큰 (`status-*`) — 실제 글리프는 화면(템플릿)의 Icon 이 자기 아이콘 세트로 해석한다
     */
    public function icon(): string
    {
        return match ($this) {
            self::REQUESTED => 'status-request',
            self::APPROVED => 'status-approved',
            self::PROCESSING => 'status-processing',
            self::ON_HOLD => 'status-on-hold',
            self::COMPLETED => 'status-completed',
            self::REJECTED => 'status-rejected',
            self::WITHDRAWN => 'status-withdrawn',
        };
    }

    /**
     * 최종 상태 여부를 반환합니다.
     *
     * @return bool
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
     * 신청서 상품과의 연결을 끊는 상태 목록을 반환합니다. (반려·철회)
     *
     * 이 상태의 환불신청서는 연결된 반품 상품·교환 차액을 환불하지 않는다 — 그 몫은 다시 「환불 미접수」 이고
     * 재접수·선점 대상이다. 「살아 있는 환불 연결」 판정(반품 서비스·환불 미접수·환불 가능액 선점)의 유일한 기준이다.
     *
     * @return array<self> 연결을 끊는 상태 목록
     */
    public static function linkReleasingStatuses(): array
    {
        return [self::REJECTED, self::WITHDRAWN];
    }

    /**
     * 신청서 상품과의 연결을 끊는 상태 값 목록을 반환합니다. (쿼리용)
     *
     * @return array<int, string> 상태 값 목록
     */
    public static function linkReleasingValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::linkReleasingStatuses());
    }

    /**
     * 신청자(유저)가 철회할 수 있는 상태 목록을 반환합니다.
     *
     * 승인(APPROVED) 이후는 환불 실행 절차가 시작되므로 철회 경계 밖이다.
     *
     * @return array<self> 철회 가능 상태 목록
     */
    public static function withdrawableStatuses(): array
    {
        return [self::REQUESTED, self::ON_HOLD];
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
     * 교환(ExchangeStatusEnum)·반품(ReturnStatusEnum)과 동형인 전이 게이트로,
     * 관리자 일괄 전환이 세 유형을 같은 방식으로 검증한다.
     *
     * @return array<self> 전이 가능 상태 목록
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REQUESTED => [self::APPROVED, self::ON_HOLD, self::REJECTED, self::WITHDRAWN],
            self::ON_HOLD => [self::APPROVED, self::REJECTED, self::WITHDRAWN],
            self::APPROVED => [self::PROCESSING, self::COMPLETED, self::ON_HOLD],
            self::PROCESSING => [self::COMPLETED, self::ON_HOLD],
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
     * @return array 상태 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 관리자 상태 변경에서 직접 지정할 수 있는 상태 목록을 반환합니다.
     *
     * 승인·보류·반려 3종만 열린다. 신청(REQUESTED)은 접수만이 만들고, 철회(WITHDRAWN)는
     * 신청자 행위(`withdraw()`)이며, 처리중(PROCESSING)·완료(COMPLETED)는 환불 **실행**
     * (`RefundExecutionService`)의 소유라 상태 변경 경로로 열면 돈이 움직이지 않은 채
     * 환불완료가 기록된다. 상태 **필터**는 values() 전체를 쓴다.
     *
     * @return array<self> 관리자 지정 가능 상태 목록
     */
    public static function adminAssignableStatuses(): array
    {
        return [self::APPROVED, self::ON_HOLD, self::REJECTED];
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
     * @return array
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
     * 환불은 회수·재발송 단계가 없고 결제 단계도 없다 — 청구가 아니라 지급이라
     * 신청 → 승인 → 처리 중 → 완료 넷뿐이다. 보류·반려·철회는 이탈 상태다.
     *
     * @return array<self> 진행 순서
     */
    public static function progressSequence(): array
    {
        return [
            self::REQUESTED,
            self::APPROVED,
            self::PROCESSING,
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
