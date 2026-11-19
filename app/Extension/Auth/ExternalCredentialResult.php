<?php

namespace App\Extension\Auth;

use App\Models\User;
use App\Support\SameOriginPath;
use InvalidArgumentException;

/**
 * 외부 자격 증명 검증 결과 DTO.
 *
 * {@see \App\Contracts\Extension\ExternalCredentialVerifierInterface::verify()} 의 반환값. 세 형태만 있다.
 *
 * - `authenticated(User, context)` — 인증 성공. 코어가 `loginVerifiedUser($user, $context, $guard)` 로 로그인시킨다.
 *   `context` 는 `loginVerifiedUser()` 의 문맥(`login_method` 필수, `skip_two_factor`, `max_session_minutes`, `external_ref`).
 * - `rejected(reasonKey, countsAsFailure)` — 거절. `countsAsFailure` 면 회원의 로그인 실패 횟수에 센다
 *   (비밀번호 없는 회원도 — 디렉터리 비밀번호는 실제 비밀이다). 디렉터리 장애는 세지 않는다(`false`).
 *   응답 문구는 계정 열거를 막으려고 비밀번호 로그인 실패와 같다 — `reasonKey` 는 기록용이다.
 * - `stepRequired(redirectUrl, reasonKey)` — 동의·연결 확인 같은 화면이 더 필요하다. 코어가 409 + 이동 경로로 답한다.
 *
 * @since 7.2.0
 */
final class ExternalCredentialResult
{
    /** 인증 성공 */
    public const AUTHENTICATED = 'authenticated';

    /** 거절 */
    public const REJECTED = 'rejected';

    /** 추가 단계 필요 */
    public const STEP_REQUIRED = 'step_required';

    /**
     * @param  string  $status  결과 형태 (authenticated | rejected | step_required)
     * @param  User|null  $user  인증된 회원 (authenticated)
     * @param  array<string, mixed>  $context  로그인 문맥 (authenticated)
     * @param  string|null  $reasonKey  거절·단계 사유 문구 키
     * @param  bool  $countsAsFailure  로그인 실패 횟수에 셀지 (rejected)
     * @param  string|null  $redirectUrl  다음 화면의 같은 출처 경로 (step_required)
     */
    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly array $context = [],
        public readonly ?string $reasonKey = null,
        public readonly bool $countsAsFailure = false,
        public readonly ?string $redirectUrl = null,
    ) {}

    /**
     * 인증 성공 결과를 만듭니다.
     *
     * @param  User  $user  인증된 회원
     * @param  array<string, mixed>  $context  `loginVerifiedUser()` 문맥 (`login_method` 필수)
     * @return self 성공 결과
     */
    public static function authenticated(User $user, array $context): self
    {
        return new self(status: self::AUTHENTICATED, user: $user, context: $context);
    }

    /**
     * 거절 결과를 만듭니다.
     *
     * @param  string  $reasonKey  사유 문구 키 (기록용)
     * @param  bool  $countsAsFailure  로그인 실패 횟수에 셀지
     * @return self 거절 결과
     */
    public static function rejected(string $reasonKey, bool $countsAsFailure = true): self
    {
        return new self(status: self::REJECTED, reasonKey: $reasonKey, countsAsFailure: $countsAsFailure);
    }

    /**
     * 추가 단계 필요 결과를 만듭니다.
     *
     * @param  string  $redirectUrl  다음 화면의 같은 출처 경로
     * @param  string  $reasonKey  사유 문구 키
     * @return self 단계 결과
     *
     * @throws InvalidArgumentException 경로가 같은 출처가 아닐 때
     */
    public static function stepRequired(string $redirectUrl, string $reasonKey): self
    {
        $path = SameOriginPath::normalize($redirectUrl);

        if ($path === null) {
            throw new InvalidArgumentException(__('exceptions.external_step_redirect_not_same_origin'));
        }

        return new self(status: self::STEP_REQUIRED, reasonKey: $reasonKey, redirectUrl: $path);
    }

    /**
     * 인증 성공인지 돌려줍니다.
     *
     * @return bool 성공이면 true
     */
    public function isAuthenticated(): bool
    {
        return $this->status === self::AUTHENTICATED;
    }

    /**
     * 거절인지 돌려줍니다.
     *
     * @return bool 거절이면 true
     */
    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    /**
     * 추가 단계 필요인지 돌려줍니다.
     *
     * @return bool 단계 필요면 true
     */
    public function isStepRequired(): bool
    {
        return $this->status === self::STEP_REQUIRED;
    }
}
