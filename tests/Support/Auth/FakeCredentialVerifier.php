<?php

namespace Tests\Support\Auth;

use App\Contracts\Extension\ExternalCredentialVerifierInterface;
use App\Extension\Auth\ExternalCredentialMatch;
use App\Extension\Auth\ExternalCredentialResult;
use App\Models\User;
use Closure;

/**
 * 시험용 외부 자격 증명 검증자
 *
 * 정해 둔 접미어(도메인)나 아이디 접두어의 식별자를 담당하고, `verify()` 결과는 시험이 고른다. 호출 기록을 남긴다.
 */
class FakeCredentialVerifier implements ExternalCredentialVerifierInterface
{
    /** `verify()` 호출 기록 */
    public array $verifyCalls = [];

    /**
     * 담당 식별자와 검증 결과를 정해 시험용 검증자를 만듭니다.
     *
     * @param  string  $suffix  담당할 식별자 접미어 (예: `@dir.test`) — 접미어 없는 아이디(`dir:` 접두어)도 담당
     * @param  Closure(ExternalCredentialMatch, string, string): ExternalCredentialResult  $result  검증 결과 생성기
     * @param  User|null  $user  `match()` 가 돌려줄 회원
     * @param  bool  $available  사용 가능 여부
     * @param  bool  $usernames  아이디 허용 여부
     */
    public function __construct(
        private string $suffix,
        private Closure $result,
        private ?User $user = null,
        private bool $available = true,
        private bool $usernames = false,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return 'test:directory'.$this->suffix;
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * {@inheritDoc}
     */
    public function acceptsUsernames(string $guard): bool
    {
        return $this->usernames;
    }

    /**
     * {@inheritDoc}
     */
    public function match(string $identifier, string $guard): ?ExternalCredentialMatch
    {
        $lower = strtolower($identifier);

        if (! str_ends_with($lower, $this->suffix) && ! str_starts_with($lower, 'dir:')) {
            return null;
        }

        return new ExternalCredentialMatch($this->user, $lower, $lower);
    }

    /**
     * {@inheritDoc}
     */
    public function verify(ExternalCredentialMatch $match, string $password, string $guard): ExternalCredentialResult
    {
        $this->verifyCalls[] = [$match->handle, $guard];

        return ($this->result)($match, $password, $guard);
    }
}
