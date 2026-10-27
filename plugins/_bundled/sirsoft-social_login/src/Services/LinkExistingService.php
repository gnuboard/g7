<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\IdentityVerificationPurpose;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Hash;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Services\Concerns\EnsuresCodeDelivered;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 「이미 가입된 이메일」 — 기존 계정 주인임을 확인한 뒤 소셜 계정을 연결합니다.
 *
 * 확인 수단은 기존 계정의 것만 쓴다: 그 계정의 비밀번호, 또는 그 계정 이메일로 보낸 인증번호(코어 본인인증, 목적
 * `sensitive_action`). 제공자가 알려 준 이메일이 같다는 사실만으로는 연결하지 않는다 — 남이 먼저 그 이메일로 가입해 둔
 * 계정에 피해자의 소셜 계정이 붙는 사전 탈취를 막는다. 틀린 확인이 5번 쌓이면 가입 대기를 지운다.
 *
 * @since 1.0.0
 */
class LinkExistingService
{
    use EnsuresCodeDelivered;

    /** 가입 대기를 지우는 실패 횟수 */
    public const MAX_FAILURES = 5;

    /**
     * @param  SignupFlowService  $signup  가입 흐름 (가입 대기 읽기)
     * @param  PendingSignupStore  $pending  가입 대기
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  AccountLinkService  $links  연결
     * @param  SocialLoginService  $login  로그인 인계
     * @param  IdentityVerificationService  $identity  코어 본인인증
     * @param  AttemptRecorder  $attempts  시도 기록
     */
    public function __construct(
        private readonly SignupFlowService $signup,
        private readonly PendingSignupStore $pending,
        private readonly UserRepositoryInterface $users,
        private readonly AccountLinkService $links,
        private readonly SocialLoginService $login,
        private readonly IdentityVerificationService $identity,
        private readonly AttemptRecorder $attempts,
    ) {}

    /**
     * 비밀번호로 확인하고 연결합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $password  기존 계정 비밀번호
     * @return array{handoff_url: string, handoff_cookie: Cookie} 로그인 인계 경로와 묶음 쿠키(컨트롤러가 응답에 싣는다)
     *
     * @throws SocialFlowException 비밀번호 없는 계정(`password_not_available`), 틀림(`password_mismatch`), 만료
     */
    public function confirmWithPassword(string $token, string $password): array
    {
        [$data, $user] = $this->loadExisting($token);

        if (! $user->hasPassword()) {
            throw new SocialFlowException('password_not_available');
        }

        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            $this->fail($token, $data, 'password_mismatch');
        }

        return $this->linkAndHandoff($token, $data, $user);
    }

    /**
     * 기존 계정 이메일로 인증번호를 보냅니다.
     *
     * @param  string  $token  대기 토큰
     * @return array{challenge_id: string, expires_at: string} 인증 요청
     *
     * @throws SocialFlowException 만료·이메일을 받을 수 없는 계정, 메일 발송 실패(`mail_failed`, 503)
     */
    public function requestEmailCode(string $token): array
    {
        [$data, $user] = $this->loadExisting($token);

        $challenge = $this->identity->start(IdentityVerificationPurpose::SensitiveAction->value, $user, [
            'origin_type' => 'custom',
            'origin_identifier' => 'sirsoft-social_login.link_existing',
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ], 'g7:core.mail');
        $this->ensureCodeDelivered($this->identity, $this->attempts, $challenge->id, (string) $data['provider_key'], (int) $user->getKey());

        $data['existing_challenge_id'] = $challenge->id;
        $this->pending->put($token, $data);

        return ['challenge_id' => $challenge->id, 'expires_at' => $challenge->expiresAt->toIso8601String()];
    }

    /**
     * 인증번호로 확인하고 연결합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $challengeId  인증 요청 ID
     * @param  string  $code  인증번호
     * @return array{handoff_url: string, handoff_cookie: Cookie} 로그인 인계 경로와 묶음 쿠키(컨트롤러가 응답에 싣는다)
     *
     * @throws SocialFlowException 다른 요청·틀림·만료
     */
    public function confirmWithEmailCode(string $token, string $challengeId, string $code): array
    {
        [$data, $user] = $this->loadExisting($token);

        if (! hash_equals((string) ($data['existing_challenge_id'] ?? ''), $challengeId)) {
            throw new SocialFlowException('challenge_mismatch');
        }

        $result = $this->identity->verify($challengeId, ['code' => $code], [
            'origin_type' => 'custom',
            'origin_identifier' => 'sirsoft-social_login.link_existing',
        ]);

        $verified = $result->success
            ? $this->identity->resolveVerifiedUser($challengeId, IdentityVerificationPurpose::SensitiveAction->value)
            : null;

        if (! $verified instanceof User || (int) $verified->getKey() !== (int) $user->getKey()) {
            $this->fail($token, $data, 'invalid_code');
        }

        // 연결 확인에 쓴 인증은 여기서 끝낸다 — 같은 확인으로 다른 민감 작업을 하지 못하게.
        $verificationToken = $result->claims['verification_token'] ?? null;
        if (is_string($verificationToken) && $verificationToken !== '') {
            $this->identity->consumeToken($verificationToken);
        }

        return $this->linkAndHandoff($token, $data, $user);
    }

    /**
     * 연결을 붙이고 로그인 인계 코드를 만듭니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $data  가입 대기
     * @param  User  $user  기존 회원
     * @return array{handoff_url: string, handoff_cookie: Cookie} 로그인 인계 경로와 묶음 쿠키(컨트롤러가 응답에 싣는다)
     *
     * @throws SocialFlowException 관리자 차단·이미 연결됨
     */
    private function linkAndHandoff(string $token, array $data, User $user): array
    {
        $key = (string) $data['provider_key'];
        $profile = NormalizedProfile::fromArray((array) $data['profile']);
        $tokens = is_array($data['tokens'] ?? null) ? TokenSet::fromArray($data['tokens']) : null;

        $this->login->assertMayLogin($user);

        $account = $this->links->attach($user, $key, $profile, $tokens, 'existing', loggedIn: true);
        $this->pending->forget($token);

        // API 응답이라 묶음 쿠키를 함께 돌려준다(컨트롤러가 응답에 싣는다 — 응답 본문에는 나가지 않는다).
        $ticket = $this->login->issueHandoffTicket($user, $key);
        HookManager::doAction(SocialLoginService::ACTION_AFTER_SOCIAL_LOGIN, $user, $account);
        $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Link, (int) $user->getKey());

        return [
            'handoff_url' => SocialLoginService::handoffPath($ticket->code, (string) ($data['redirect'] ?? '/')),
            'handoff_cookie' => $ticket->cookie,
        ];
    }

    /**
     * 「이미 가입된 이메일」 단계의 가입 대기와 기존 회원을 읽습니다.
     *
     * @param  string  $token  대기 토큰
     * @return array{0: array<string, mixed>, 1: User} 가입 대기와 회원
     *
     * @throws SocialFlowException 만료·단계가 아님
     */
    private function loadExisting(string $token): array
    {
        $data = $this->signup->load($token);

        if (($data['force_new'] ?? false) || empty($data['existing_user_id'])) {
            throw new SocialFlowException('not_existing_step', 409);
        }

        $user = $this->users->findById((int) $data['existing_user_id']);
        if (! $user instanceof User || $user->isWithdrawn()) {
            $this->pending->forget($token);

            throw new SocialFlowException('expired', 404);
        }

        return [$data, $user];
    }

    /**
     * 확인 실패를 세고, 다섯 번째면 가입 대기를 지웁니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $data  가입 대기
     * @param  string  $code  오류 코드
     * @return never
     *
     * @throws SocialFlowException 항상 — 한도 도달이면 `too_many_attempts`(404), 아니면 `$code`
     */
    private function fail(string $token, array $data, string $code): never
    {
        $data['link_failures'] = (int) ($data['link_failures'] ?? 0) + 1;
        $this->attempts->record((string) $data['provider_key'], AttemptIntent::Login->value, AttemptOutcome::Failed, null, $code);

        if ($data['link_failures'] >= self::MAX_FAILURES) {
            $this->pending->forget($token);

            throw new SocialFlowException('too_many_attempts', 404);
        }

        $this->pending->put($token, $data);

        throw new SocialFlowException($code);
    }
}
