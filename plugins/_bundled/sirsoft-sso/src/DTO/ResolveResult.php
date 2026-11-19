<?php

namespace Plugins\Sirsoft\Sso\DTO;

use App\Models\User;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * IdP 신원 → 회원 결정 결과 (`AccountResolver::resolve()`).
 *
 * @since 1.0.0
 */
final class ResolveResult
{
    /** 이미 연결된 회원으로 로그인 */
    public const LOGIN = 'login';

    /** 처음 온 사람을 자동 생성 */
    public const JIT = 'jit';

    /** 소유 도메인 자동 연결 */
    public const DOMAIN = 'domain';

    /** 연결 확인 화면에서 기존 회원으로 연결 */
    public const VERIFY = 'verify';

    /** 로그인한 회원에게 연결 추가 (마이페이지) */
    public const LINKED = 'link';

    /** 재확인 성공 */
    public const REAUTH = 'reauth';

    /** 약관 동의 대기 */
    public const PENDING_CONSENT = 'pending_consent';

    /** 기존 회원 연결 확인 대기 */
    public const PENDING_LINK = 'pending_link';

    /** 거절 */
    public const REJECTED = 'rejected';

    /**
     * @param  string  $outcome  결과 종류
     * @param  User|null  $user  회원 (로그인, 연결 결과)
     * @param  SsoAccount|null  $account  연결
     * @param  string|null  $pendingToken  대기 토큰 (대기 결과)
     * @param  string|null  $errorCode  거절 사유 코드
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?User $user = null,
        public readonly ?SsoAccount $account = null,
        public readonly ?string $pendingToken = null,
        public readonly ?string $errorCode = null,
    ) {}

    /**
     * 거절 결과를 만듭니다.
     *
     * @param  string  $errorCode  사유 코드
     * @return self 결과
     */
    public static function rejected(string $errorCode): self
    {
        return new self(self::REJECTED, errorCode: $errorCode);
    }

    /**
     * 로그인 인계로 이어지는 결과인지 확인합니다.
     *
     * @return bool 인계로 이어지면 true
     */
    public function logsIn(): bool
    {
        return in_array($this->outcome, [self::LOGIN, self::JIT, self::DOMAIN, self::VERIFY], true);
    }
}
