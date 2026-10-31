<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\AuthService;
use App\Services\IdentityVerificationService;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\SocialLogin\DTO\AuthorizationRequest;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptIntent;
use Plugins\Sirsoft\SocialLogin\Enums\AttemptOutcome;
use Plugins\Sirsoft\SocialLogin\Enums\EmailConflictPolicy;
use Plugins\Sirsoft\SocialLogin\Enums\EmailMissingPolicy;
use Plugins\Sirsoft\SocialLogin\Enums\EmailTrust;
use Plugins\Sirsoft\SocialLogin\Enums\ExtraInfoMode;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Services\Concerns\EnsuresCodeDelivered;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 연결된 회원이 없는 소셜 계정의 가입 흐름.
 *
 * 1. 콜백 → `begin()` — 가입 허용·이메일 정책·「이미 가입된 이메일」을 판정해 가입 대기를 만들고 가입 화면으로 보낸다.
 *    이메일이 같아도 기본은 자동으로 합치지 않는다(본인확인 뒤 연결). 자동 연결은 운영자가 고르고, 제공자가 검증했다고
 *    알린 이메일일 때만 한다.
 * 2. 가입 화면 모달 → `view()` 로 부족한 항목을 그리고, 이메일이 필요하면 `requestEmailCode()`·`verifyEmailCode()`
 *    (코어 본인인증 메일, 목적 signup)로 소유를 확인한다.
 * 3. `complete()` — 코어 `AuthService::register()`(비밀번호 없음, 토큰 없음)로 회원을 만들고 연결을 붙인 뒤 로그인 인계
 *    코드를 돌려준다. 동의 기록·역할·가입 훅·본인인증 가입 정책은 코어 가입과 같다.
 *
 * @since 1.0.0
 */
class SignupFlowService
{
    use EnsuresCodeDelivered;

    /** 가입 데이터 필터 (인자: array $data, NormalizedProfile $profile) */
    public const FILTER_REGISTRATION_DATA = 'sirsoft-social_login.signup.filter_registration_data';

    /** 소셜 가입 직후 훅 (인자: User, SocialAccount) */
    public const ACTION_AFTER_SOCIAL_SIGNUP = 'sirsoft-social_login.auth.after_social_signup';

    /** 대체 이메일 도메인 (RFC 2606 예약 최상위 도메인 — 메일이 나가지 않는다) */
    public const PLACEHOLDER_DOMAIN = 'social.invalid';

    /** 이메일 처리 방식 — 제공자 이메일 그대로(확인됨) */
    public const EMAIL_LOCKED = 'locked';

    /** 이메일 처리 방식 — 회원이 입력하고 인증번호로 확인 */
    public const EMAIL_INPUT = 'input';

    /** 이메일 처리 방식 — 대체 이메일 발급 */
    public const EMAIL_PLACEHOLDER = 'placeholder';

    /** 가입 화면 단계 — 가입 완료 모달 */
    public const STEP_COMPLETE = 'complete';

    /** 가입 화면 단계 — 이미 가입된 이메일 모달 */
    public const STEP_EXISTING = 'existing';

    /**
     * @param  PendingSignupStore  $pending  가입 대기
     * @param  ProviderSettings  $settings  제공자 정책
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  AuthService  $auth  코어 인증 서비스 (가입)
     * @param  AccountLinkService  $links  연결
     * @param  SocialLoginService  $login  로그인 인계
     * @param  IdentityVerificationService  $identity  코어 본인인증 (이메일 인증번호)
     * @param  SignupSurgeMonitor  $surge  가입 급증 감시
     * @param  AttemptRecorder  $attempts  시도 기록
     */
    public function __construct(
        private readonly PendingSignupStore $pending,
        private readonly ProviderSettings $settings,
        private readonly UserRepositoryInterface $users,
        private readonly AuthService $auth,
        private readonly AccountLinkService $links,
        private readonly SocialLoginService $login,
        private readonly IdentityVerificationService $identity,
        private readonly SignupSurgeMonitor $surge,
        private readonly AttemptRecorder $attempts,
    ) {}

    /**
     * 콜백에서 가입 흐름을 시작하고 브라우저가 갈 주소를 돌려줍니다.
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  NormalizedProfile  $profile  프로필
     * @param  TokenSet|null  $tokens  토큰
     * @return string 이동할 주소 (가입 화면, 자동 연결이면 로그인 인계, 거절이면 로그인 화면 오류)
     */
    public function begin(AuthorizationRequest $state, NormalizedProfile $profile, ?TokenSet $tokens): string
    {
        $key = $state->providerKey;

        if (! $this->settings->signupAllowed($key)) {
            $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Denied, null, 'signup_disabled');

            return OAuthFlowService::loginErrorUrl('signup_disabled', $state->redirect, $key);
        }

        $existing = $profile->email !== null ? $this->findActiveByEmail($profile->email) : null;

        if ($existing !== null) {
            $policy = EmailConflictPolicy::tryFrom((string) plugin_setting('sirsoft-social_login', 'email_conflict_policy', EmailConflictPolicy::VerifyLink->value))
                ?? EmailConflictPolicy::VerifyLink;

            if ($policy === EmailConflictPolicy::AutoLinkVerified
                && $this->settings->emailTrust($key) === EmailTrust::Flag
                && $profile->emailVerified === true) {
                return $this->autoLink($existing, $state, $profile, $tokens);
            }

            if ($policy !== EmailConflictPolicy::NeverLink) {
                // 확인 뒤 연결(기본) — 자동 연결 정책이라도 검증 표시가 없으면 여기로 온다.
                $token = $this->pending->create($this->pendingPayload($state, $profile, $tokens) + [
                    'step' => self::STEP_EXISTING,
                    'existing_user_id' => (int) $existing->getKey(),
                    'email_mode' => self::EMAIL_INPUT,
                    'locked_email' => null,
                    'prefill_email' => null,
                ]);
                $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Pending, null, 'existing_email');

                return self::registerUrl($token, self::STEP_EXISTING);
            }
            // never_link — 이 이메일은 쓸 수 없으니 다른 이메일을 받는다(아래 입력 경로).
        }

        // 여기부터는 새 계정이다. 사이트가 「일반 회원가입 받기」를 끄면(코어 7.2.0) 소셜 신규 가입도 닫는다 — 위의 기존 회원 연결과
        // 연결된 회원의 로그인(이 흐름 앞)은 그대로 둔다.
        if (! $this->auth->isSelfRegistrationOpen()) {
            $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Denied, null, 'registration_closed');

            return OAuthFlowService::loginErrorUrl('registration_closed', $state->redirect, $key);
        }

        $email = $this->decideEmail($key, $profile, $existing !== null);
        if ($email === null) {
            $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Denied, null, 'email_required');

            return OAuthFlowService::loginErrorUrl('email_required', $state->redirect, $key);
        }

        $token = $this->pending->create($this->pendingPayload($state, $profile, $tokens) + [
            'step' => self::STEP_COMPLETE,
            'existing_user_id' => null,
            ...$email,
        ]);
        $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Pending);

        return self::registerUrl($token, self::STEP_COMPLETE);
    }

    /**
     * 가입 화면이 그릴 가입 대기 정보를 돌려줍니다 (토큰·원본 프로필은 싣지 않는다).
     *
     * @param  string  $token  대기 토큰
     * @return array<string, mixed> 화면 데이터
     *
     * @throws SocialFlowException 없거나 만료(`expired`, 404)
     */
    public function view(string $token): array
    {
        $data = $this->load($token);
        $profile = NormalizedProfile::fromArray((array) $data['profile']);
        $key = (string) $data['provider_key'];
        $mode = (string) $data['email_mode'];
        $alwaysExtra = plugin_setting('sirsoft-social_login', 'extra_info_mode', ExtraInfoMode::WhenMissing->value) === ExtraInfoMode::Always->value;
        $nameMissing = ($profile->name ?? $profile->nickname ?? '') === '';

        $emailValue = match ($mode) {
            self::EMAIL_LOCKED, self::EMAIL_PLACEHOLDER => $data['locked_email'] ?? null,
            default => $data['verified_email'] ?? $data['prefill_email'] ?? null,
        };

        $existing = null;
        if ($data['step'] === self::STEP_EXISTING && ! ($data['force_new'] ?? false)) {
            $user = $this->users->findById((int) $data['existing_user_id']);
            $existing = $user instanceof User ? [
                'email_masked' => OAuthFlowService::maskEmail((string) $user->email),
                'has_password' => $user->hasPassword(),
            ] : null;
        }

        return [
            'provider' => ['key' => $key, 'label' => $this->settings->label($key), 'brand' => $this->settings->brand($key)],
            'step' => $existing !== null ? self::STEP_EXISTING : self::STEP_COMPLETE,
            'name' => $profile->name ?? $profile->nickname,
            'nickname' => $profile->nickname,
            'email' => [
                'mode' => $mode,
                'value' => $emailValue,
                'verified' => $mode !== self::EMAIL_INPUT || isset($data['verified_email']),
                'requires_code' => $mode === self::EMAIL_INPUT,
                'challenge_id' => $data['email_challenge']['id'] ?? null,
            ],
            'missing' => array_values(array_filter([
                $mode === self::EMAIL_INPUT ? 'email' : null,
                $nameMissing ? 'name' : null,
            ])),
            'show_profile_fields' => $alwaysExtra || $nameMissing,
            'existing_account' => $existing,
            'expires_at' => date(DATE_ATOM, (int) $data['expires_at']),
        ];
    }

    /**
     * 가입 이메일 인증번호를 보냅니다 (코어 본인인증 메일, 목적 signup, 대상 = 입력 이메일).
     *
     * @param  string  $token  대기 토큰
     * @param  string  $email  입력 이메일
     * @return array{challenge_id: string, expires_at: string} 인증 요청
     *
     * @throws SocialFlowException 만료(`expired`), 입력할 수 없는 단계(`email_locked`), 이미 쓰는 이메일(`email_taken`),
     *                             메일 발송 실패(`mail_failed`, 503)
     */
    public function requestEmailCode(string $token, string $email): array
    {
        $data = $this->load($token);

        if ($data['email_mode'] !== self::EMAIL_INPUT) {
            throw new SocialFlowException('email_locked');
        }

        if ($this->findAnyByEmail($email)) {
            throw new SocialFlowException('email_taken');
        }

        $challenge = $this->identity->start('signup', ['email' => $email], [
            'origin_type' => 'custom',
            'origin_identifier' => 'sirsoft-social_login.signup',
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ], 'g7:core.mail');
        $this->ensureCodeDelivered($this->identity, $this->attempts, $challenge->id, (string) $data['provider_key']);

        $data['email_challenge'] = ['id' => $challenge->id, 'email' => $email];
        unset($data['verified_email']);
        $this->pending->put($token, $data);

        return ['challenge_id' => $challenge->id, 'expires_at' => $challenge->expiresAt->toIso8601String()];
    }

    /**
     * 가입 이메일 인증번호를 확인합니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $challengeId  인증 요청 ID
     * @param  string  $code  인증번호
     * @return array{verified_email: string} 확인된 이메일
     *
     * @throws SocialFlowException 만료(`expired`), 다른 요청(`challenge_mismatch`), 틀림(`invalid_code`)
     */
    public function verifyEmailCode(string $token, string $challengeId, string $code): array
    {
        $data = $this->load($token);
        $challenge = $data['email_challenge'] ?? null;

        if (! is_array($challenge) || ! hash_equals((string) $challenge['id'], $challengeId)) {
            throw new SocialFlowException('challenge_mismatch');
        }

        $result = $this->identity->verify($challengeId, ['code' => $code], [
            'origin_type' => 'custom',
            'origin_identifier' => 'sirsoft-social_login.signup',
        ]);

        if (! $result->success) {
            throw new SocialFlowException('invalid_code');
        }

        $data['verified_email'] = (string) $challenge['email'];
        $this->pending->put($token, $data);

        return ['verified_email' => $data['verified_email']];
    }

    /**
     * 가입을 마칩니다.
     *
     * @param  string  $token  대기 토큰
     * @param  array<string, mixed>  $input  검증된 입력 (name·nickname·email·agree_terms·agree_privacy·verification_token)
     * @return array{handoff_url?: string, handoff_cookie?: Cookie, redirect_url?: string} 다음 주소 (같은 출처 상대 경로)와 인계 코드의 묶음 쿠키(컨트롤러가 응답에 싣는다)
     *
     * @throws SocialFlowException 만료·이메일 미확인·이미 쓰는 이메일·이미 연결됨·가입 닫힘(`registration_closed`, 403)
     */
    public function complete(string $token, array $input): array
    {
        $data = $this->load($token);

        if ($data['step'] === self::STEP_EXISTING && ! ($data['force_new'] ?? false)) {
            throw new SocialFlowException('existing_account_pending', 409);
        }

        // 대기를 만든 뒤 가입이 닫혔으면 여기서 막는다 — 기존 계정 대신 새 계정을 고른 경우(`force_new`)도 이 길로 온다.
        if (! $this->auth->isSelfRegistrationOpen()) {
            throw new SocialFlowException('registration_closed', 403);
        }

        $key = (string) $data['provider_key'];
        $profile = NormalizedProfile::fromArray((array) $data['profile']);
        $tokens = is_array($data['tokens'] ?? null) ? TokenSet::fromArray($data['tokens']) : null;

        $email = $this->resolveSignupEmail($data, isset($input['email']) ? (string) $input['email'] : null);
        // 인증번호로 확인한 이메일, 제공자가 검증했다고 한 이메일만 인증으로 기록한다 — 신뢰만 한 이메일·대체 이메일은 아니다.
        $emailConfirmed = $data['email_mode'] === self::EMAIL_INPUT
            || ($data['email_mode'] === self::EMAIL_LOCKED && $profile->emailVerified === true);

        $name = trim((string) ($input['name'] ?? '')) ?: ($profile->name ?? $profile->nickname ?? '');
        if ($name === '') {
            throw new SocialFlowException('name_required');
        }

        if ($this->findAnyByEmail($email)) {
            throw new SocialFlowException('email_taken');
        }

        $registration = [
            'name' => mb_substr($name, 0, 255),
            'nickname' => ($nickname = trim((string) ($input['nickname'] ?? '')) ?: $profile->nickname) !== null ? mb_substr($nickname, 0, 50) : null,
            'email' => $email, // audit:allow resource-member-email-ungated 코어 가입에 넘기는 저장 데이터(응답 아님)
            'agree_terms' => (bool) ($input['agree_terms'] ?? false),
            'agree_privacy' => (bool) ($input['agree_privacy'] ?? false),
            'language' => app()->getLocale(),
            'social_provider' => $key,
        ];
        if (isset($input['verification_token']) && is_string($input['verification_token']) && $input['verification_token'] !== '') {
            $registration['verification_token'] = $input['verification_token'];
        }

        $filtered = HookManager::applyFilters(self::FILTER_REGISTRATION_DATA, $registration, $profile);
        if (is_array($filtered)) {
            // 확장이 지운 필수 키는 되살린다 — 가입 데이터 모양이 깨지면 코어 가입이 실패한다.
            $registration = array_merge($registration, $filtered);
        }

        [$user, $account] = DB::transaction(function () use ($registration, $key, $profile, $tokens, $emailConfirmed) {
            $user = $this->auth->register($registration, ['issue_token' => false])['user'];
            if ($emailConfirmed && $user->email === $registration['email']) {
                $this->users->markEmailVerified($user);
            }
            $account = $this->links->attach($user, $key, $profile, $tokens, 'signup', loggedIn: true);

            return [$user, $account];
        });

        $this->pending->forget($token);

        HookManager::doAction(self::ACTION_AFTER_SOCIAL_SIGNUP, $user, $account);

        $this->surge->record($key);
        $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Signup, (int) $user->getKey());

        $status = $user->status instanceof UserStatus ? $user->status : UserStatus::tryFrom((string) $user->status);
        if ($status === UserStatus::PendingVerification) {
            return ['redirect_url' => '/login?social_notice=pending_verification'];
        }

        // 가입 직후 로그인은 코어 일반 가입과 같게 2단계 인증 없이 들어간다. API 응답이라 묶음 쿠키를 함께 돌려준다
        // (컨트롤러가 응답에 싣는다 — 응답 본문에는 나가지 않는다).
        $ticket = $this->login->issueHandoffTicket($user, $key, skipTwoFactor: true);

        return [
            'handoff_url' => SocialLoginService::handoffPath($ticket->code, (string) ($data['redirect'] ?? '/')),
            'handoff_cookie' => $ticket->cookie,
        ];
    }

    /**
     * 「이미 가입된 이메일」에서 [새 계정으로 가입]을 고릅니다 — 그 이메일은 쓸 수 없으니 다른 이메일을 받는다.
     *
     * @param  string  $token  대기 토큰
     * @return array<string, mixed> 새 화면 데이터
     *
     * @throws SocialFlowException 만료(`expired`)
     */
    public function discardExisting(string $token): array
    {
        $data = $this->load($token);

        $data['force_new'] = true;
        $data['step'] = self::STEP_COMPLETE;
        $data['existing_user_id'] = null;

        // 이메일이 겹친 사람은 제공자의 「이메일 미제공」 정책과 무관하게 다른 주소를 받는다(대체 이메일로 넘기지 않는다) —
        // 처음부터 겹친 이메일로 들어온 경우(`decideEmail` 의 겹침 분기)와 같은 규칙.
        $data = array_merge($data, ['email_mode' => self::EMAIL_INPUT, 'locked_email' => null, 'prefill_email' => null]);

        $this->pending->put($token, $data);

        return $this->view($token);
    }

    /**
     * 가입 대기를 읽고, 없으면 만료로 거절합니다.
     *
     * @param  string  $token  대기 토큰
     * @return array<string, mixed> 값
     *
     * @throws SocialFlowException 없거나 만료(`expired`, 404)
     */
    public function load(string $token): array
    {
        $data = $this->pending->get($token);
        if ($data === null) {
            throw new SocialFlowException('expired', 404);
        }

        return $data;
    }

    /**
     * 대체 이메일을 만듭니다 (`{제공자}_{식별자 해시 12자}@social.invalid`).
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $providerUserId  제공자 사용자 ID
     * @return string 대체 이메일
     */
    public static function placeholderEmail(string $providerKey, string $providerUserId): string
    {
        return $providerKey.'_'.substr(sha1($providerUserId), 0, 12).'@'.self::PLACEHOLDER_DOMAIN;
    }

    /**
     * 가입 화면 주소를 만듭니다.
     *
     * @param  string  $token  대기 토큰
     * @param  string  $step  단계
     * @return string 절대 주소
     */
    public static function registerUrl(string $token, string $step): string
    {
        return SiteUrl::absolute('/register?'.http_build_query(['social_pending' => $token, 'social_step' => $step]));
    }

    /**
     * 검증된 이메일 자동 연결 — 관리자 차단 규칙을 지키고 로그인 인계로 넘깁니다.
     *
     * @param  User  $user  기존 회원
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  NormalizedProfile  $profile  프로필
     * @param  TokenSet|null  $tokens  토큰
     * @return string 이동할 주소
     */
    private function autoLink(User $user, AuthorizationRequest $state, NormalizedProfile $profile, ?TokenSet $tokens): string
    {
        $key = $state->providerKey;

        try {
            $this->login->assertMayLogin($user);
            $account = $this->links->attach($user, $key, $profile, $tokens, 'auto', loggedIn: true);
        } catch (SocialFlowException $e) {
            $outcome = $e->errorCode === 'admin_blocked' ? AttemptOutcome::Denied : AttemptOutcome::Failed;
            $this->attempts->record($key, AttemptIntent::Login->value, $outcome, (int) $user->getKey(), $e->errorCode);

            return OAuthFlowService::loginErrorUrl($e->errorCode, $state->redirect);
        }

        $code = $this->login->issueHandoff($user, $key);
        HookManager::doAction(SocialLoginService::ACTION_AFTER_SOCIAL_LOGIN, $user, $account);
        $this->attempts->record($key, AttemptIntent::Login->value, AttemptOutcome::Handoff, (int) $user->getKey());

        return SocialLoginService::handoffUrl($code, $state->redirect);
    }

    /**
     * 새 계정의 이메일을 어떻게 받을지 정합니다.
     *
     * 제공자 이메일이 있고 신뢰 정책을 통과하고 다른 회원과 겹치지 않으면 그대로 쓴다. 아니면(없음·마스킹·미검증·겹침)
     * 제공자별 「이메일 미제공」 정책을 따른다 — 겹친 경우는 다른 주소를 받아야 하므로 늘 입력이다.
     *
     * @param  string  $key  제공자 키
     * @param  NormalizedProfile  $profile  프로필
     * @param  bool  $conflict  같은 이메일의 회원이 있는지
     * @return array<string, mixed>|null 가입 대기 이메일 값 (거부면 null)
     */
    private function decideEmail(string $key, NormalizedProfile $profile, bool $conflict): ?array
    {
        if (! $conflict && $profile->email !== null && $profile->isEmailTrusted($this->settings->emailTrust($key))) {
            return ['email_mode' => self::EMAIL_LOCKED, 'locked_email' => $profile->email, 'prefill_email' => null];
        }

        if ($conflict) {
            return ['email_mode' => self::EMAIL_INPUT, 'locked_email' => null, 'prefill_email' => null];
        }

        return match ($this->settings->emailMissingPolicy($key)) {
            EmailMissingPolicy::Deny => null,
            EmailMissingPolicy::Placeholder => [
                'email_mode' => self::EMAIL_PLACEHOLDER,
                'locked_email' => self::placeholderEmail($key, $profile->providerUserId),
                'prefill_email' => null,
            ],
            EmailMissingPolicy::Ask => ['email_mode' => self::EMAIL_INPUT, 'locked_email' => null, 'prefill_email' => $profile->email],
        };
    }

    /**
     * 가입에 쓸 이메일을 정합니다.
     *
     * @param  array<string, mixed>  $data  가입 대기
     * @param  string|null  $input  입력 이메일
     * @return string 이메일
     *
     * @throws SocialFlowException 입력 이메일이 인증번호로 확인한 이메일과 다르면 `email_not_verified`
     */
    private function resolveSignupEmail(array $data, ?string $input): string
    {
        if ($data['email_mode'] !== self::EMAIL_INPUT) {
            return (string) $data['locked_email'];
        }

        $verified = $data['verified_email'] ?? null;
        if (! is_string($verified) || $input === null || mb_strtolower(trim($input)) !== mb_strtolower($verified)) {
            throw new SocialFlowException('email_not_verified');
        }

        return $verified;
    }

    /**
     * 가입 대기 공통 값을 만듭니다.
     *
     * @param  AuthorizationRequest  $state  시작 정보
     * @param  NormalizedProfile  $profile  프로필
     * @param  TokenSet|null  $tokens  토큰
     * @return array<string, mixed> 값
     */
    private function pendingPayload(AuthorizationRequest $state, NormalizedProfile $profile, ?TokenSet $tokens): array
    {
        return [
            'provider_key' => $state->providerKey,
            'redirect' => $state->redirect,
            'profile' => $profile->toArray(),
            'tokens' => $tokens?->toArray(),
            'force_new' => false,
            'link_failures' => 0,
        ];
    }

    /**
     * 탈퇴하지 않은 같은 이메일 회원을 찾습니다 (대소문자 무시 — 이메일 컬럼 정렬 규칙).
     *
     * @param  string  $email  이메일
     * @return User|null 회원
     */
    private function findActiveByEmail(string $email): ?User
    {
        $user = $this->users->findByEmail($email);

        return $user instanceof User && ! $user->isWithdrawn() ? $user : null;
    }

    /**
     * 같은 이메일 회원이 하나라도 있는지 확인합니다 (가입 이메일 중복 — 탈퇴 회원은 접미사로 이메일이 바뀌어 있다).
     *
     * @param  string  $email  이메일
     * @return bool 있으면 true
     */
    private function findAnyByEmail(string $email): bool
    {
        return $this->users->findByEmail($email) !== null;
    }
}
