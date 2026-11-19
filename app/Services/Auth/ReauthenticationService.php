<?php

namespace App\Services\Auth;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationPurpose;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * 민감한 작업 직전의 본인 재확인
 *
 * 비밀번호 확인 관문(마이페이지 정보 수정 진입·앱 키 재생성·관리자 본인 정보 수정)이 함께 쓴다. 비밀번호가 있는 회원은
 * 종전대로 비밀번호를 확인하고, 비밀번호가 없는 회원(외부 인증 전용)은 이메일 인증번호로 대신한다 — 코어 본인인증
 * 요청(`POST /api/identity/challenges`, 목적 `sensitive_action`)으로 받은 인증번호를 확인하면 돌려받는
 * `verification_token` 을 관문 요청에 실어 보낸다. 토큰은 이 회원 본인의 확인이어야 하고 한 번만 쓸 수 있다.
 *
 * 관문은 모두 「확인과 실행을 한 요청에서」 하는 구조라 이 서비스도 별도 상태를 남기지 않는다.
 *
 * 비밀번호 없는 회원을 모르는 옛 화면(7.1.x 템플릿)은 비밀번호 칸에 무엇이든 넣어 보낸다. 관문은 그 값을 버리고
 * 코어 본인인증 요구(428, `challengeFor()`)로 답한다 — 엔진이 인증 창을 열고 확인이 끝나면 `verification_token` 을
 * 붙여 원 요청을 다시 보내므로, 옛 화면도 같은 토큰 확인(본인·목적·1회)을 거쳐 통과한다.
 *
 * @since 7.2.0
 */
class ReauthenticationService
{
    /**
     * 재확인 요구(428)에 싣는 정책 키 — 정책 표의 행이 아니다(화면이 challenge 요청에 되돌려 보내도 서버가 무시한다).
     */
    public const CHALLENGE_POLICY_KEY = 'core.reauthentication';

    /** 확인 통과 */
    public const PASSED = 'passed';

    /** 비밀번호가 틀림 */
    public const PASSWORD_MISMATCH = 'password_mismatch';

    /** 비밀번호가 없는 회원이 비밀번호로 확인하려 함 */
    public const PASSWORD_NOT_SET = 'password_not_set';

    /** 인증 토큰이 없거나·만료됐거나·다른 회원 것이거나·이미 쓰였음 */
    public const TOKEN_INVALID = 'token_invalid';

    /** 확인 수단을 하나도 보내지 않음 */
    public const MISSING = 'missing';

    /**
     * 재확인 서비스를 생성합니다.
     *
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 저장소
     */
    public function __construct(
        private IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    /**
     * 입력으로 본인을 확인하고, 통과하면 인증 토큰을 소비합니다.
     *
     * 입력에 `verification_token` 이 있으면 그것을 먼저 본다(비밀번호가 있는 회원도 인증번호로 확인할 수 있다).
     * 없으면 `password` 로 확인한다.
     *
     * @param  User  $user  확인 대상 (요청 회원 본인)
     * @param  array<string, mixed>  $input  `password` 또는 `verification_token`
     * @return string 결과 상수 (PASSED · PASSWORD_MISMATCH · PASSWORD_NOT_SET · TOKEN_INVALID · MISSING)
     */
    public function verify(User $user, array $input): string
    {
        $result = $this->check($user, $input);

        if ($result === self::PASSED) {
            $this->consume($user, $input);
        }

        return $result;
    }

    /**
     * 인증 토큰을 소비하지 않고 확인만 합니다 (검증 단계 — 실행 전에 다른 입력 오류가 날 수 있을 때).
     *
     * @param  User  $user  확인 대상
     * @param  array<string, mixed>  $input  `password` 또는 `verification_token`
     * @return string 결과 상수
     */
    public function check(User $user, array $input): string
    {
        $token = $input['verification_token'] ?? null;

        if (is_string($token) && $token !== '') {
            return $this->findTokenLog($user, $token) !== null ? self::PASSED : self::TOKEN_INVALID;
        }

        $password = $input['password'] ?? null;

        if (! is_string($password) || $password === '') {
            return self::MISSING;
        }

        if (! $user->hasPassword()) {
            return self::PASSWORD_NOT_SET;
        }

        return Hash::check($password, (string) $user->getAuthPassword()) ? self::PASSED : self::PASSWORD_MISMATCH;
    }

    /**
     * 확인에 쓴 인증 토큰을 소비합니다 (비밀번호 확인이면 아무 일도 하지 않음).
     *
     * @param  User  $user  확인 대상
     * @param  array<string, mixed>  $input  확인 입력
     * @return void
     */
    public function consume(User $user, array $input): void
    {
        $token = $input['verification_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return;
        }

        $log = $this->findTokenLog($user, $token);

        if ($log !== null) {
            $this->logRepository->updateById($log->id, ['consumed_at' => now()]);
        }
    }

    /**
     * 비밀번호 없는 회원에게 본인인증을 요구하는 428 예외를 만듭니다 (원 요청을 다시 보낼 주소 포함).
     *
     * 목적은 이 서비스가 토큰을 찾는 목적(`sensitive_action`)과 같다. 수단(`provider_id`)은 비워 두어 challenge 를
     * 시작할 때 서버가 그 회원에게 쓸 수 있는 수단을 고르게 한다. 재요청 주소는 경로+쿼리만 싣는다(교차 출처 재요청 차단).
     *
     * @param  Request  $request  재확인을 요구할 원 요청
     * @return IdentityVerificationRequiredException 호출자가 던질 예외
     */
    public function challengeFor(Request $request): IdentityVerificationRequiredException
    {
        return new IdentityVerificationRequiredException(
            policyKey: self::CHALLENGE_POLICY_KEY,
            purpose: IdentityVerificationPurpose::SensitiveAction->value,
            returnRequest: [
                'method' => $request->getMethod(),
                'url' => $request->getRequestUri(),
            ],
        );
    }

    /**
     * 이 회원 본인의 아직 쓰지 않은 재확인 인증 이력을 찾습니다.
     *
     * @param  User  $user  확인 대상
     * @param  string  $token  인증 토큰
     * @return IdentityVerificationLog|null 이력 (없으면 null)
     */
    private function findTokenLog(User $user, string $token): ?IdentityVerificationLog
    {
        $log = $this->logRepository->findVerifiedForToken($token, IdentityVerificationPurpose::SensitiveAction->value);

        if ($log === null || (int) $log->user_id !== (int) $user->getKey()) {
            return null;
        }

        return $log;
    }
}
