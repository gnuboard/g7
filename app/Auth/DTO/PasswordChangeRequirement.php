<?php

namespace App\Auth\DTO;

use App\Enums\PasswordChangeReason;
use Carbon\CarbonInterface;

/**
 * 로그인 관문의 비밀번호 변경 요구
 *
 * 비밀번호·2단계 인증을 통과한 회원에게 토큰 대신 변경 단계를 돌려줘야 하는지를 담는다.
 * `PasswordExpiryService::loginRequirement()` 가 만들어 `AuthService` 의 관문으로 넘긴다.
 *
 * @since 7.1.0
 */
final readonly class PasswordChangeRequirement
{
    /**
     * @param  PasswordChangeReason  $reason  변경을 요구하는 사유 (만료 / 관리자가 정해 준 비밀번호)
     * @param  bool  $canPostpone  '다음에 변경' 으로 미룰 수 있는지 (만료 + 다음에 변경 허용 모드만)
     * @param  int|null  $postponeDays  미룰 수 있으면 다시 안내할 때까지의 일수
     * @param  CarbonInterface|null  $expiredAt  만료 일시 (관리자 지정이면 null)
     */
    public function __construct(
        public PasswordChangeReason $reason,
        public bool $canPostpone = false,
        public ?int $postponeDays = null,
        public ?CarbonInterface $expiredAt = null,
    ) {}

    /**
     * 배열로 변환합니다 (로그·훅 문맥용).
     *
     * @return array{reason: string, can_postpone: bool, postpone_days: int|null, expired_at: string|null} 변경 요구 배열
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason->value,
            'can_postpone' => $this->canPostpone,
            'postpone_days' => $this->postponeDays,
            'expired_at' => $this->expiredAt?->toIso8601String(),
        ];
    }
}
