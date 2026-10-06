<?php

namespace App\Extension\Consent;

/**
 * 동의 주체
 *
 * 회원이면 회원 ID, 게스트면 동의 제공자가 요청에서 구한 식별자를 담습니다. 둘 다 없으면 아직
 * 식별되지 않은 게스트(쿠키 없는 첫 방문)입니다.
 *
 * @since 7.1.0
 */
final class ConsentSubject
{
    /**
     * 동의 주체를 생성합니다.
     *
     * @param  int|null  $userId  회원 ID (게스트면 null)
     * @param  string|null  $subjectKey  게스트 동의 식별자 (회원이거나 미식별이면 null)
     */
    public function __construct(
        public readonly ?int $userId,
        public readonly ?string $subjectKey,
    ) {}

    /**
     * 회원 주체를 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @return self 동의 주체
     */
    public static function member(int $userId): self
    {
        return new self($userId, null);
    }

    /**
     * 게스트 주체를 만듭니다.
     *
     * @param  string|null  $subjectKey  게스트 동의 식별자
     * @return self 동의 주체
     */
    public static function guest(?string $subjectKey): self
    {
        return new self(null, $subjectKey !== '' ? $subjectKey : null);
    }

    /**
     * 회원 주체인지 반환합니다.
     *
     * @return bool 회원이면 true
     */
    public function isMember(): bool
    {
        return $this->userId !== null;
    }
}
