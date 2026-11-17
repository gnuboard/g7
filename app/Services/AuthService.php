<?php

namespace App\Services;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\ChallengeDeliveryAwareProviderInterface;
use App\Contracts\Extension\ExternalCredentialVerifierInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\MailFallbackAwareProviderInterface;
use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\DisplayName\DisplayNameChoicePolicy;
use App\Enums\ActivityLogType;
use App\Enums\ConsentType;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Enums\PasswordChangeSource;
use App\Enums\UserStatus;
use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\ExternalStepRequiredException;
use App\Exceptions\Auth\LoginMethodRequiredException;
use App\Exceptions\Auth\PasswordAuthDisallowedException;
use App\Exceptions\Auth\PasswordChangeUnavailableException;
use App\Exceptions\Auth\TwoFactorDeliveryFailedException;
use App\Exceptions\Auth\TwoFactorEnrollmentRequiredException;
use App\Exceptions\Auth\TwoFactorMeansRequiredException;
use App\Exceptions\Auth\TwoFactorResendCooldownException;
use App\Exceptions\Auth\TwoFactorResendNotSupportedException;
use App\Extension\Auth\CredentialVerifierManager;
use App\Extension\Auth\ExternalCredentialMatch;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Fields\FieldValueService;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\Auth\PasswordAuthPolicyService;
use App\Services\Screen\ScreenAuth;
use App\Support\LocaleTag;
use App\Support\SameOriginPath;
use App\Support\ScreenUrl;
use App\Support\UndeliverableEmail;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    use ResolvesActivityLogType;

    /**
     * 코어 메일 프로바이더 id — 2단계 인증의 최후 폴백 수단.
     */
    private const MAIL_PROVIDER_ID = 'g7:core.mail';

    /**
     * 단계(2단계 인증·비밀번호 변경) 식별자에 묶은 로그인 수단 이름의 캐시 키 접두어.
     */
    private const LOGIN_METHOD_CACHE_PREFIX = 'core:login_method:';

    /**
     * 인증 서비스를 생성합니다.
     *
     * @param  UserRepositoryInterface  $userRepository  사용자 저장소
     * @param  RoleRepositoryInterface  $roleRepository  역할 저장소
     * @param  UserConsentRepositoryInterface  $userConsentRepository  약관 동의 저장소
     * @param  PasswordResetTokenRepositoryInterface  $passwordResetTokenRepository  비밀번호 재설정 토큰 저장소
     * @param  IdentityPolicyService  $policyService  본인인증 정책 서비스
     * @param  IdentityVerificationLogRepositoryInterface  $identityLogRepository  본인인증 로그 저장소
     * @param  PasswordChangeService  $passwordChangeService  비밀번호 변경 기록 서비스
     * @param  PasswordExpiryService  $passwordExpiryService  비밀번호 만료 정책 서비스
     * @param  PasswordChangeChallengeService  $passwordChangeChallengeService  로그인 변경 단계 식별자 서비스
     * @param  PasswordResetService  $passwordResetService  비밀번호 재설정 실행 (메일·터미널 공통)
     */
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private RoleRepositoryInterface $roleRepository,
        private UserConsentRepositoryInterface $userConsentRepository,
        private PasswordResetTokenRepositoryInterface $passwordResetTokenRepository,
        private IdentityPolicyService $policyService,
        private IdentityVerificationLogRepositoryInterface $identityLogRepository,
        private PasswordChangeService $passwordChangeService,
        private PasswordExpiryService $passwordExpiryService,
        private PasswordChangeChallengeService $passwordChangeChallengeService,
        private PasswordResetService $passwordResetService,
    ) {}

    /**
     * 환경설정의 토큰 유지시간 기반으로 만료 시간을 계산합니다.
     *
     * @return \DateTimeInterface|null 만료 시간 (0이면 null로 무한대)
     */
    private function getTokenExpiresAt(): ?\DateTimeInterface
    {
        // 환경설정에서 토큰 유지시간 조회 (분 단위, 기본값 30분)
        $lifetime = (int) g7_core_settings('security.auth_token_lifetime', 30);

        // 0이면 무한대 (만료 없음)
        if ($lifetime === 0) {
            return null;
        }

        return now()->addMinutes($lifetime);
    }

    /**
     * Accept-Language 헤더에서 국가 코드를 추출합니다.
     *
     * 첫 항목을 언어팩 규칙(BCP 47)으로 읽는다(#148): 지역 하위 태그가 국가면 그것
     * (`fil-PH`→PH, `zh-Hant-TW`→TW), 숫자 지역(`es-419`)·문자 표기만 있는 코드(`zh-Hant`)는
     * 나라를 하나로 정할 수 없어 비운다. 언어만 있으면 기본 국가 매핑(config)을 쓴다 —
     * 매핑에 없는 `fil` 은 비운다.
     *
     * @return string|null ISO 3166-1 alpha-2 국가 코드 (2자리 대문자)
     */
    private function detectCountryFromAcceptLanguage(): ?string
    {
        $tag = LocaleTag::firstAcceptLanguageTag(request()->header('Accept-Language'));
        if ($tag === null) {
            return null;
        }

        $country = LocaleTag::countryCode($tag);
        if ($country !== null) {
            return $country;
        }

        // 숫자 지역이나 문자 표기가 붙어 있으면 언어만으로 나라를 추정하지 않는다
        if (LocaleTag::region($tag) !== null || str_contains($tag, '-')) {
            return null;
        }

        $languageToCountry = config('app.locale_country_fallback', []);

        return $languageToCountry[LocaleTag::primaryLanguage($tag)] ?? null;
    }

    /**
     * 사용자를 로그인시키고 인증 토큰을 발급합니다.
     *
     * 보안 환경설정 `security.login_attempt_enabled` 가 켜져 있으면
     * `Auth::attempt()` 직전에 계정 잠금 상태를 검사합니다. 실제 카운트
     * 증감/리셋은 Laravel 의 `Auth\Events\Failed` / `Auth\Events\Login`
     * 이벤트를 구독하는 Listener (`HandleFailedLoginListener` /
     * `HandleSuccessfulLoginListener`) 가 Repository 를 통해 처리합니다.
     *
     * 순서 불변식: 자격증명 → 상태 → 잠금 → 2단계 인증 → 비밀번호 변경 관문 → (휴면 해제 + 토큰 발급).
     * 변경 관문이 2단계 인증보다 앞에 오면 비밀번호만 훔친 공격자가 2단계 인증 없이 비밀번호를 바꿔
     * 계정을 가져간다.
     *
     * @param  string  $email  사용자 이메일
     * @param  string  $password  사용자 비밀번호
     * @param  string  $guard  로그인 경로 (user | admin — 관리자 로그인만 'admin', 변경 단계 식별자를 이 경로에 묶는다)
     * @return array 사용자 정보와 토큰을 포함한 배열 (2단계 인증·비밀번호 변경 단계면 그 안내)
     *
     * 외부 인증 확장점(7.2.0):
     * 1. 외부 자격 증명 검증자(`core.auth.registered_credential_verifiers`)가 식별자를 담당하면 비밀번호를 G7 이 아니라 그 검증자가
     *    확인한다 — 비밀번호 인증 정책보다 **먼저** 본다(디렉터리 비밀번호가 정책 거절로 디렉터리에 닿지 못하는 일이 없게).
     * 2. 비밀번호 인증 정책(`core.auth.password_auth_decision`)을 두 번 묻는다 — 식별자 기준은 시도 **전**(실패 횟수 0),
     *    회원 기준은 비밀번호가 맞은 **뒤**(틀린 비밀번호로는 「이 이메일은 막힌 회원」이 드러나지 않는다).
     *
     * @throws PasswordAuthDisallowedException 비밀번호 인증 정책이 거절할 때
     * @throws ExternalStepRequiredException 외부 자격 증명 로그인에 화면 단계가 더 필요할 때
     * @throws TwoFactorMeansRequiredException 정책이 2단계 인증을 요구하는데 수단이 없을 때
     * @throws ValidationException 인증 정보가 올바르지 않을 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     * @throws PasswordChangeUnavailableException 변경 단계를 열 수 없을 때
     */
    public function login(string $email, string $password, string $guard = 'user'): array
    {
        $external = app(CredentialVerifierManager::class)->match($email, $guard);

        if ($external !== null) {
            return $this->loginWithExternalCredential($external['verifier'], $external['match'], $password, $guard);
        }

        $policy = app(PasswordAuthPolicyService::class);

        // 식별자 기준 판정 — 시도 전에 막아 강제 대상 회원이 남의 오입력으로 잠기지도 않는다.
        $identifierDecision = $policy->assertAllowed(['identifier' => $email, 'user' => null, 'guard' => $guard, 'purpose' => 'login']);

        // 사전 잠금 체크 — 잠긴 계정은 Auth::attempt 자체를 시도하지 않는다.
        // (실패 카운트가 0 으로 리셋된 잠금 상태에서 Failed 이벤트가 다시
        //  카운트를 올려 재잠금 시각을 갱신하는 부작용 방지)
        $account = $this->userRepository->findByEmail($email);
        $this->assertNotLocked($account);

        // 가입되지 않은 주소도 같은 횟수 실패 뒤에는 같은 잠금 응답을 받는다 (가입 여부 비노출).
        if ($account === null) {
            $this->assertUnknownEmailNotLocked($email);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            // 실패 카운트 증가/잠금 처리는 HandleFailedLoginListener 에서 담당
            HookManager::doAction('core.auth.login_failed', $email, [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'attempted_at' => now(),
            ]);

            throw ValidationException::withMessages([
                'email' => [__('auth.invalid_credentials')],
            ]);
        }

        $user = Auth::user();

        // 회원 기준 판정 — 비밀번호가 맞은 뒤에만 묻는다(열거 방지). 거절 전에 `Auth::attempt()` 가 연 세션을 닫는다 —
        // 판정 뒤에 닫으면 거절 응답에도 세션 쿠키가 발급된다.
        $userDecision = $policy->decide(['identifier' => $email, 'user' => $user, 'guard' => $guard, 'purpose' => 'login']);

        if (! $userDecision->allowed) {
            Auth::logout();

            throw new PasswordAuthDisallowedException($userDecision);
        }

        $requireTwoFactor = $identifierDecision->requireTwoFactor || $userDecision->requireTwoFactor;

        // 사용자 상태 체크 — Active 와 Dormant 만 로그인 허용
        //
        // 휴면은 **막는 상태가 아니라 되돌리는 상태**다. 로그인 성공(비밀번호 확인, 2FA 사이트는
        // 코드 확인까지) 자체가 본인확인이므로 통과시키고, 세션을 여는 자리에서 Active 로
        // 복귀시킨다({@see issueLoginSession()}). 여기서 막으면 휴면 회원에게 해제 경로가
        // 아예 없고, 열거 방지 문구가 사유를 삼켜 "비밀번호가 틀렸다" 로만 안내된다.
        if (! $this->isLoginAllowedStatus($user->status)) {
            Auth::logout();

            $this->assertLoginAllowedStatus($user);
        }

        // 2단계 인증이 켜져 있으면 여기서 토큰을 발급하지 않는다.
        // 비밀번호 확인만으로 세션이 열리면 2단계가 없는 것과 같으므로, 인증 코드를 확인할
        // 때까지 로그인 상태를 만들지 않고 challenge 만 돌려준다.
        // 판정보다 **먼저** 회수한다 — `resolveTwoFactorProviderId()` 는 수단이 없고 메일
        // 폴백이 꺼진 계정에 `TwoFactorEnrollmentRequiredException` 을 던진다. 회수를 판정
        // 뒤에 두면 그 경로만 `Auth::attempt()` 가 연 세션을 닫지 못하고, 컨트롤러가 그
        // 예외를 정상 응답으로 바꾸므로 세션 쿠키가 그대로 발급된다.
        // `issueLoginSession()` 이 자기 자리에서 다시 로그인시키므로 성공 경로는 불변이다.
        Auth::logout();

        // 관리자 로그인에 관리자가 아닌 회원 — 휴면 해제·로그인 완료 훅·2단계 인증 메일보다 **먼저** 거절한다.
        $this->assertGuardAllowed($user, $guard);

        // 정책이 2단계 인증을 요구하면(예: 회사 계정 강제 중 비상 계정) 사이트 2단계 설정과 무관하게 그 단계를 탄다.
        $twoFactorProviderId = $requireTwoFactor
            ? $this->resolveRequiredTwoFactorProviderId($user)
            : $this->resolveTwoFactorProviderId($user);

        if ($twoFactorProviderId !== null) {
            return $this->startTwoFactorChallenge($user, $email, $twoFactorProviderId);
        }

        // 비밀번호 변경 관문 — 2단계 인증 뒤, 토큰 발급 앞.
        $passwordChange = $this->passwordChangeGate($user, $guard);

        if ($passwordChange !== null) {
            return $passwordChange;
        }

        return $this->issueLoginSession($user, $email);
    }

    /**
     * 외부 자격 증명 검증자로 로그인합니다.
     *
     * 잠긴 회원은 디렉터리에 비밀번호를 보내기 전에 거절한다. 인증 성공은 `loginVerifiedUser()` 로 합류해 비밀번호 로그인과 같은
     * 관문을 지난다. 거절 응답은 계정 열거를 막으려고 비밀번호 로그인 실패와 같다 — 검증자가 실패로 센다고 하면 그 회원의 로그인
     * 실패 횟수에 더한다(비밀번호 없는 회원도 — 디렉터리 비밀번호는 실제 비밀이다).
     *
     * @param  ExternalCredentialVerifierInterface  $verifier  일치한 검증자
     * @param  ExternalCredentialMatch  $match  일치 결과
     * @param  string  $password  입력한 비밀번호
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array 로그인 결과 (`login()` 과 같은 모양)
     *
     * @throws ExternalStepRequiredException 화면 단계가 더 필요할 때
     * @throws ValidationException 거절일 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     */
    private function loginWithExternalCredential(
        ExternalCredentialVerifierInterface $verifier,
        ExternalCredentialMatch $match,
        string $password,
        string $guard,
    ): array {
        if ($match->user !== null) {
            $this->assertNotLocked($match->user);
        }

        $result = $verifier->verify($match, $password, $guard);

        if ($result->isAuthenticated() && $result->user !== null) {
            return $this->loginVerifiedUser($result->user, $result->context, $guard);
        }

        if ($result->isStepRequired() && $result->redirectUrl !== null) {
            throw new ExternalStepRequiredException($result->redirectUrl, $result->reasonKey);
        }

        if ($result->countsAsFailure && $match->user !== null) {
            HookManager::doAction('core.auth.login_failed', (string) $match->user->email, [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'attempted_at' => now(),
                // 외부 자격 증명 실패 — 비밀번호 없는 회원도 실패 횟수에 센다(HandleFailedLoginListener)
                'external_credential' => true,
                'verifier' => $verifier->getId(),
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [__('auth.invalid_credentials')],
        ]);
    }

    /**
     * 정책이 요구한 2단계 인증의 수단을 고릅니다 — 사이트 2단계 설정(켜짐·적용 범위)과 무관하다.
     *
     * @param  User  $user  로그인 회원
     * @return string 수단 id
     *
     * @throws TwoFactorMeansRequiredException 이 회원에게 쓸 수 있는 수단이 없을 때
     */
    private function resolveRequiredTwoFactorProviderId(User $user): string
    {
        try {
            $providerId = $this->resolveTwoFactorProvider($user);
        } catch (TwoFactorEnrollmentRequiredException) {
            $providerId = null;
        }

        if ($providerId === null) {
            throw new TwoFactorMeansRequiredException;
        }

        return $providerId;
    }

    /**
     * 비밀번호가 아닌 다른 수단으로 본인이 확인된 회원을 로그인시킵니다.
     *
     * 외부 인증(소셜 로그인·향후 패스키 등)이 코어 로그인 관문을 그대로 통과하는 공개 API 다. 비밀번호 로그인
     * ({@see login()})에서 자격 증명 단계만 뺀 순서를 **같은 메서드로** 밟는다: 잠금 → 회원 상태 → 관리자 경로 판정 →
     * 2단계 인증(인증 앱(TOTP) 포함) → 비밀번호 변경 관문(비밀번호가 있는 회원만) → 휴면 해제 + 토큰 발급.
     * 그래서 응답 모양도 `login()` 과 같다 — 인증 완료(`user`·`token`) / `two_factor_required` / `password_change_required`.
     *
     * 본인 확인 책임은 호출자에게 있다 — 이 메서드는 「이 회원이 본인이다」를 믿고 관문만 적용한다. 확장은 직접 부르지 말고
     * 로그인 인계(`LoginHandoffService::issue()`)로 코드를 받아 로그인 화면이 교환하게 한다 — 화면이 2단계 인증·비밀번호
     * 변경·휴면 안내를 비밀번호 로그인과 똑같이 보여 준다.
     *
     * @param  User  $user  본인 확인을 마친 회원
     * @param  array{login_method: string, skip_two_factor?: bool}  $context  login_method = 로그인 수단 이름(필수 — 예 `social:kakao`,
     *                                                                        로그인 완료 훅에 실린다), skip_two_factor = 2단계 인증
     *                                                                        단계만 건너뜀(운영자가 그 수단에 2단계 인증을 끈 경우)
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array 로그인 결과 (`login()` 과 같은 모양)
     *
     * @throws LoginMethodRequiredException 로그인 수단 이름(`login_method`)이 없을 때
     * @throws ValidationException 회원 상태가 로그인을 허용하지 않을 때 (`email` 오류 — 코어 문구)
     * @throws AccountLockedException 계정이 잠겨 있을 때
     * @throws AuthorizationException 관리자 경로에 관리자가 아닌 회원일 때
     * @throws TwoFactorEnrollmentRequiredException 2단계 인증 수단이 없는데 운영자가 메일 폴백을 껐을 때
     * @throws TwoFactorDeliveryFailedException 2단계 인증 코드를 보내지 못했을 때
     * @throws PasswordChangeUnavailableException 변경 단계를 열 수 없을 때
     *
     * @since 7.2.0
     */
    public function loginVerifiedUser(User $user, array $context = [], string $guard = 'user'): array
    {
        self::assertLoginMethodGiven($context);

        $this->assertNotLocked($user);
        $this->assertLoginAllowedStatus($user);
        $this->assertGuardAllowed($user, $guard);

        $context = self::normalizeLoginContext($context);

        if (! $context['skip_two_factor']) {
            $twoFactorProviderId = $this->resolveTwoFactorProviderId($user);

            if ($twoFactorProviderId !== null) {
                $challenge = $this->startTwoFactorChallenge($user, (string) $user->email, $twoFactorProviderId);
                $this->rememberLoginContext((string) $challenge['challenge_id'], $context);

                return $challenge;
            }
        }

        // 비밀번호 변경 관문 — 비밀번호가 없는 회원은 만료·관리자 지정 둘 다 비대상이라 열리지 않는다.
        $passwordChange = $this->passwordChangeGate($user, $guard);

        if ($passwordChange !== null) {
            $this->rememberLoginContext((string) $passwordChange['challenge_id'], $context);

            return $passwordChange;
        }

        return $this->issueLoginSession($user, (string) $user->email, $context);
    }

    /**
     * 외부 인증 컨텍스트에 로그인 수단 이름이 있는지 확인합니다.
     *
     * 이름이 없으면 로그인 완료 훅·활동 로그가 그 로그인을 비밀번호 로그인(`password`)으로 기록하게 되므로 받지 않는다.
     *
     * @param  array<string, mixed>  $context  호출자 컨텍스트
     * @return void 반환값 없음
     *
     * @throws LoginMethodRequiredException 로그인 수단 이름(`login_method`)이 비었거나 문자열이 아닐 때
     */
    public static function assertLoginMethodGiven(array $context): void
    {
        $method = $context['login_method'] ?? null;

        if (! is_string($method) || trim($method) === '') {
            throw new LoginMethodRequiredException;
        }
    }

    /**
     * 로그인 컨텍스트를 정리합니다 (알려진 키만, 수단 이름 기본값 `password`).
     *
     * 외부 인증 확장이 넘기는 키는 네 개다 — 범위를 벗어난 값은 버린다(없는 것과 같다).
     * - `login_method` 로그인 수단 이름(≤64자)
     * - `skip_two_factor` 2단계 인증 단계만 건너뜀
     * - `max_session_minutes` 세션 수명 상한(분, 1~10080) — 토큰 만료가 이 시각을 넘지 않는다(연장·갱신 포함)
     * - `external_ref` 외부 세션 참조(≤100자) — 로그인 완료 훅에 실려 확장이 자기 세션 기록과 토큰을 잇는다
     *
     * @param  array<string, mixed>  $context  호출자 컨텍스트
     * @return array{login_method: string, skip_two_factor: bool, max_session_minutes: int|null, external_ref: string|null} 정리한 컨텍스트
     */
    public static function normalizeLoginContext(array $context): array
    {
        $method = $context['login_method'] ?? 'password';
        $cap = $context['max_session_minutes'] ?? null;
        $ref = $context['external_ref'] ?? null;

        return [
            'login_method' => is_string($method) && $method !== '' ? mb_substr($method, 0, 64) : 'password',
            'skip_two_factor' => ($context['skip_two_factor'] ?? false) === true,
            'max_session_minutes' => is_int($cap) && $cap >= 1 && $cap <= 10080 ? $cap : null,
            'external_ref' => is_string($ref) && $ref !== '' && mb_strlen($ref) <= 100 ? $ref : null,
        ];
    }

    /**
     * 로그인이 단계(2단계 인증·비밀번호 변경)로 넘어갈 때 정리한 로그인 컨텍스트 전체를 그 단계 식별자에 묶어 둡니다.
     *
     * 단계를 마치는 요청(`completeTwoFactor()` 등)은 컨텍스트를 모른다 — 묶어 두지 않으면 외부 인증 로그인이 2단계 인증을
     * 거치는 순간 로그인 완료 훅에 `password` 로 기록되고 세션 수명 상한·외부 참조가 사라진다. 비밀번호 로그인(기본 컨텍스트)은 남기지 않는다.
     *
     * @param  string  $challengeId  단계 식별자
     * @param  array<string, mixed>  $context  컨텍스트
     * @return void 반환값 없음
     */
    private function rememberLoginContext(string $challengeId, array $context): void
    {
        $context = self::normalizeLoginContext($context);

        if ($challengeId === '' || $context === self::normalizeLoginContext([])) {
            return;
        }

        app(CacheInterface::class)->put(self::LOGIN_METHOD_CACHE_PREFIX.hash('sha256', $challengeId), $context, 1800);
    }

    /**
     * 단계 식별자에 묶어 둔 로그인 컨텍스트를 꺼냅니다 (꺼내면 지운다).
     *
     * @param  string  $challengeId  단계 식별자
     * @return array<string, mixed> 컨텍스트 (묶인 것이 없으면 빈 배열 — 비밀번호 로그인)
     */
    private function pullLoginContext(string $challengeId): array
    {
        $cache = app(CacheInterface::class);
        $key = self::LOGIN_METHOD_CACHE_PREFIX.hash('sha256', $challengeId);
        $stored = $cache->get($key);
        $cache->forget($key);

        if (is_array($stored)) {
            return self::normalizeLoginContext($stored);
        }

        // 7.2.0 이전 형식(수단 이름 문자열)으로 남은 단계 — 배포 직후 진행 중이던 로그인을 잇는다.
        return is_string($stored) && $stored !== '' ? ['login_method' => $stored] : [];
    }

    /**
     * 회원 상태가 로그인을 허용하지 않으면 그 상태의 코어 문구로 거절합니다.
     *
     * 비밀번호 로그인과 외부 인증 로그인이 같은 판정·같은 문구를 쓴다. 휴면은 통과한다(세션을 여는 자리에서 해제).
     *
     * @param  User  $user  로그인하는 회원
     * @return void 반환값 없음
     *
     * @throws ValidationException 로그인을 허용하지 않는 상태일 때 (`email` 오류)
     */
    private function assertLoginAllowedStatus(User $user): void
    {
        if ($this->isLoginAllowedStatus($user->status)) {
            return;
        }

        $messageKey = match ($user->status) {
            UserStatus::Inactive->value => 'auth.account_inactive',
            UserStatus::Blocked->value => 'auth.account_blocked',
            UserStatus::Withdrawn->value => 'auth.account_withdrawn',
            default => 'auth.invalid_credentials',
        };

        throw ValidationException::withMessages([
            'email' => [__($messageKey)],
        ]);
    }

    /**
     * 관리자 로그인 경로에 관리자가 아닌 회원이면 거절합니다.
     *
     * 세션을 여는 자리(휴면 해제·로그인 완료 훅) **앞**과 2단계 인증 코드 발송 앞에서 부른다 — 뒤에서 거절하면
     * 관리자가 아닌 휴면 회원이 관리자 로그인 화면에서 휴면이 풀리고 로그인 완료 훅(장바구니 병합 등)까지 돈 뒤 403 을
     * 받는다. 판정은 관리자 컨트롤러가 쓰는 `User::isAdmin()` 과 같다.
     *
     * @param  User  $user  로그인하는 회원
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return void 반환값 없음
     *
     * @throws AuthorizationException 관리자 경로에 관리자가 아닌 회원일 때
     */
    private function assertGuardAllowed(User $user, string $guard): void
    {
        if ($guard === 'admin' && ! $user->isAdmin()) {
            throw new AuthorizationException(__('auth.admin_required'));
        }
    }

    /**
     * 비밀번호 변경 관문 — 바꿔야 하면 토큰 대신 변경 단계 식별자를 돌려줍니다.
     *
     * 관리자 로그인에 비관리자가 들어오면 관문을 건너뛴다(A16) — 호출 경로가 앞에서 `assertGuardAllowed()` 로 이미
     * 거절하므로 도달하지 않는 방어선이다. 비관리자의 비밀번호가 관리자 경로로 바뀌지 않게 남겨 둔다.
     *
     * @param  User  $user  비밀번호·2단계 인증을 통과한 회원
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array<string, mixed>|null 변경 단계 응답 (요구가 없으면 null)
     *
     * @throws PasswordChangeUnavailableException 식별자를 저장하지 못했을 때
     */
    private function passwordChangeGate(User $user, string $guard): ?array
    {
        if ($guard === 'admin' && ! $user->isAdmin()) {
            return null;
        }

        $requirement = $this->passwordExpiryService->loginRequirement($user);

        if ($requirement === null) {
            return null;
        }

        $challengeId = $this->passwordChangeChallengeService->issue($user, $guard, $requirement);

        if ($challengeId === null) {
            Log::error('비밀번호 변경 단계 식별자를 저장하지 못했습니다 — 로그인을 차단합니다', ['user_id' => $user->id]);

            throw new PasswordChangeUnavailableException;
        }

        return [
            'password_change_required' => true,
            'challenge_id' => $challengeId,
            'reason' => $requirement->reason->value,
            'can_postpone' => $requirement->canPostpone,
            'postpone_days' => $requirement->postponeDays,
            'expired_at' => $requirement->expiredAt?->toIso8601String(),
            'expires_at' => now()->addSeconds(PasswordChangeChallengeService::TTL_SECONDS)->toIso8601String(),
        ];
    }

    /**
     * 로그인 변경 단계에서 새 비밀번호를 받아 로그인을 마칩니다.
     *
     * 같은 식별자의 동시 제출은 잠금으로 하나만 통과시킨다. 관리자 경로는 비밀번호를 쓰기 **전에**
     * 관리자 여부를 확인한다(A16). 새 비밀번호 규칙(재사용·현재값 비교 포함)은 FormRequest 가 식별자를
     * 확인한 뒤 평가했다.
     *
     * @param  string  $challengeId  변경 단계 식별자
     * @param  string  $password  새 비밀번호 (평문)
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array 로그인 결과 (user, token, token_type, dormancy_restored?)
     *
     * @throws ValidationException 식별자가 무효·처리 중일 때
     * @throws AuthorizationException 관리자 경로에 비관리자 식별자가 왔을 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     */
    public function completePasswordChange(string $challengeId, string $password, string $guard): array
    {
        return $this->withPasswordChangeLock($challengeId, function () use ($challengeId, $password, $guard) {
            [$user, $payload] = $this->resolvePasswordChangeChallenge($challengeId, $guard);

            // 단계를 연 뒤 비밀번호 인증 정책이 바뀌었으면 이 단계로 비밀번호를 바꾸지 못한다.
            app(PasswordAuthPolicyService::class)->assertAllowed(['identifier' => (string) $user->email, 'user' => $user, 'guard' => $guard, 'purpose' => 'login_password_change']);

            $oldHash = $user->getRawOriginal('password');

            DB::transaction(function () use ($user, $password, $oldHash, $payload) {
                $this->userRepository->update($user, ['password' => Hash::make($password)]);

                // 로그인 단계 변경은 모든 기기 로그아웃(D17) 뒤 새 토큰을 발급한다.
                $this->passwordChangeService->recordChange(
                    $user,
                    is_string($oldHash) ? $oldHash : null,
                    PasswordChangeSource::LoginRequired,
                    $user,
                    null,
                    ['reason' => (string) ($payload['reason'] ?? '')],
                );
            });

            $this->passwordChangeChallengeService->consume($challengeId);

            // 알림(`password_changed`)은 이 훅을 구독한다 — 다른 변경 경로와 같은 안내가 나가야 한다.
            HookManager::doAction('core.auth.after_password_changed', $user);

            Auth::login($user);

            return $this->issueLoginSession($user, (string) $user->email, $this->pullLoginContext($challengeId));
        });
    }

    /**
     * 로그인 변경 단계에서 '다음에 변경' 을 골라 로그인을 마칩니다 ('다음에 변경 허용' 모드의 만료만).
     *
     * @param  string  $challengeId  변경 단계 식별자
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array 로그인 결과 (user, token, token_type, dormancy_restored?, postponed_until)
     *
     * @throws ValidationException 식별자가 무효·처리 중이거나 연기할 수 없을 때
     * @throws AuthorizationException 관리자 경로에 비관리자 식별자가 왔을 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     */
    public function postponePasswordChange(string $challengeId, string $guard): array
    {
        return $this->withPasswordChangeLock($challengeId, function () use ($challengeId, $guard) {
            [$user, $payload] = $this->resolvePasswordChangeChallenge($challengeId, $guard);

            // 발급 시점의 판정과 지금의 판정을 함께 본다 — 그 사이 운영자가 '변경해야 로그인' 으로 바꿨으면 연기할 수 없다.
            $requirement = $this->passwordExpiryService->loginRequirement($user);

            if (! ($payload['can_postpone'] ?? false) || $requirement === null || ! $requirement->canPostpone) {
                throw ValidationException::withMessages([
                    'challenge_id' => [__('auth.password_change_postpone_not_allowed')],
                ]);
            }

            $until = $this->passwordExpiryService->postpone($user);

            $this->passwordChangeChallengeService->consume($challengeId);

            HookManager::doAction('core.auth.password_change_postponed', $user, [
                'until' => $until->toIso8601String(),
                'until_display' => $until->copy()->setTimezone($user->getTimezone())->toDateString(),
            ]);

            Auth::login($user);

            $result = $this->issueLoginSession($user, (string) $user->email, $this->pullLoginContext($challengeId));
            $result['postponed_until'] = $until->toIso8601String();

            return $result;
        });
    }

    /**
     * 연기 요청이 검증 단계에서 거절됐을 때 응답 상위 안내에 쓸 언어 키를 고릅니다.
     *
     * 식별자는 유효한데 연기할 수 없는 단계(변경해야 로그인·관리자가 정해 준 비밀번호)면 「연기 불가」,
     * 그 밖(없음·만료·경로 불일치)은 「단계가 만료되었거나 올바르지 않음」이다.
     *
     * @param  ValidationException  $e  postponePasswordChange 가 던진 검증 예외
     * @return string 언어 키
     */
    public function postponeFailureMessageKey(ValidationException $e): string
    {
        $messages = $e->errors()['challenge_id'] ?? [];

        return in_array(__('auth.password_change_postpone_not_allowed'), $messages, true)
            ? 'auth.password_change_postpone_not_allowed'
            : 'auth.password_change_invalid_challenge';
    }

    /**
     * 변경 단계 식별자를 확인하고 회원을 돌려줍니다 (상태·잠금·관리자 경로 검사 포함).
     *
     * @param  string  $challengeId  변경 단계 식별자
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array{0: User, 1: array<string, mixed>} 회원과 식별자 내용
     *
     * @throws ValidationException 식별자가 무효일 때
     * @throws AuthorizationException 관리자 경로에 비관리자 식별자가 왔을 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     */
    private function resolvePasswordChangeChallenge(string $challengeId, string $guard): array
    {
        $payload = $this->passwordChangeChallengeService->peek($challengeId);
        $user = $payload === null ? null : $this->userRepository->findById((int) $payload['user_id']);

        // 없음·만료·경로 불일치·그 사이 비밀번호가 바뀜 — 전부 같은 응답으로 답한다(상태를 캐내지 못하게).
        if ($payload === null || $user === null || ! $this->passwordChangeChallengeService->matches($payload, $user, $guard)) {
            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.password_change_invalid_challenge')],
            ]);
        }

        // 비밀번호를 쓰기 **전에** 관리자 여부를 본다 — 관리자 경로로 비관리자의 비밀번호를 바꾸거나
        // 그 세션을 지우지 않는다. 식별자는 더 쓸 데가 없으므로 소비한다.
        if ($guard === 'admin' && ! $user->isAdmin()) {
            $this->passwordChangeChallengeService->consume($challengeId);
        }

        $this->assertGuardAllowed($user, $guard);

        if (! $this->isLoginAllowedStatus($user->status)) {
            $this->passwordChangeChallengeService->consume($challengeId);

            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.password_change_invalid_challenge')],
            ]);
        }

        $this->assertNotLocked($user);

        return [$user, $payload];
    }

    /**
     * 같은 변경 단계 식별자의 동시 처리를 하나로 줄입니다.
     *
     * @param  string  $challengeId  변경 단계 식별자
     * @param  callable(): array  $callback  잠금 안에서 실행할 처리
     * @return array 처리 결과
     *
     * @throws ValidationException 이미 처리 중일 때
     */
    private function withPasswordChangeLock(string $challengeId, callable $callback): array
    {
        $lock = Cache::lock($this->passwordChangeChallengeService->lockKey($challengeId), 10);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.password_change_in_progress')],
            ]);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * 계정이 잠겨 있으면 예외를 던집니다.
     *
     * 세션(토큰)을 발급하는 지점은 전부 이 검사를 거쳐야 합니다. 2단계 인증이 켜져 있으면
     * 비밀번호 확인(`login`)은 challenge 만 돌려주고 실제 세션은 `completeTwoFactor()` 가
     * 발급하므로, 한쪽에만 검사가 있으면 잠기기 전에 받아 둔 challenge 를 잠긴 뒤 완료하는
     * 것만으로 잠금이 통째로 우회됩니다. 그 뒤 로그인 완료 훅이 실패 횟수·잠금 시각까지
     * 초기화해 흔적도 남지 않습니다.
     *
     * @param  User|null  $user  검사 대상 사용자 (없으면 검사 대상 아님)
     * @return void 반환값 없음
     *
     * @throws AccountLockedException 계정이 잠겨 있을 때
     */
    private function assertNotLocked(?User $user): void
    {
        if (! (bool) g7_core_settings('security.login_attempt_enabled', true)) {
            return;
        }

        if ($user === null || ! $this->userRepository->isLocked($user)) {
            return;
        }

        // 영구 잠금은 해제 시각이 없다 — diffInSeconds(null) 로 폭발하지 않도록 분기.
        $remaining = $user->locked_until === null
            ? null
            : max(1, (int) ceil(now()->diffInSeconds($user->locked_until, false) / 60));

        throw new AccountLockedException(
            lockedUntil: $user->locked_until,
            remainingMinutes: $remaining,
        );
    }

    /**
     * 가입되지 않은 주소가 잠금 흉내 상태면 가입 계정과 같은 잠금 예외를 던집니다.
     *
     * @param  string  $email  로그인에 쓰인 이메일
     * @return void 반환값 없음
     *
     * @throws AccountLockedException 그 주소의 실패가 임계 횟수에 이르렀을 때
     */
    private function assertUnknownEmailNotLocked(string $email): void
    {
        $lock = app(UnknownEmailLockService::class)->lockState($email);

        if ($lock === null) {
            return;
        }

        throw new AccountLockedException(
            lockedUntil: $lock['locked_until'],
            remainingMinutes: $lock['remaining_minutes'],
        );
    }

    /**
     * 2단계 인증을 요구해야 하는지 판정하고, 요구한다면 쓸 프로바이더 id 를 돌려줍니다.
     *
     * 판정(요구 여부)과 발행(어떤 수단으로)이 갈라지면, 판정이 "가능" 이라 한 수단과 실제
     * 발행된 수단이 어긋난다(예: 판정은 메일 폴백인데 발행은 미등록 TOTP). 한 메서드가
     * 두 답을 함께 내서 그 틈을 없앤다.
     *
     * @param  User  $user  로그인 시도 사용자
     * @return string|null 2단계 인증에 쓸 provider id, 요구하지 않으면 null
     *
     * @throws TwoFactorEnrollmentRequiredException 수단이 없는데 운영자가 메일 폴백을 껐을 때
     */
    private function resolveTwoFactorProviderId(User $user): ?string
    {
        if (! (bool) g7_core_settings('security.two_factor_auth', false)) {
            return null;
        }

        // 적용 범위. 레거시 저장값(`two_factor_auth=true` 단독)의 의미는 "전체"였으므로
        // 기본값 'all' 이 그 의미를 그대로 보존한다 — 설정 마이그레이션 불필요.
        $scope = (string) g7_core_settings('security.two_factor_scope', 'all');

        if ($scope === 'admin' && ! $user->isAdmin()) {
            return null;
        }

        // 코드를 받을 수단이 없으면 요구할 수 없다 — 요구하면 그 계정은 영구히 잠긴다.
        return $this->resolveTwoFactorProvider($user);
    }

    /**
     * 이 사용자의 2단계 인증을 실제로 수행할 프로바이더 id 를 해석합니다.
     *
     * 해석 순서:
     * 1. login purpose 로 해석된 프로바이더가 이 사용자에게 사용 가능하면 채택
     *    (TOTP 처럼 사용자별 등록이 필요한 수단은 `UserScopedIdentityProviderInterface` 로 답한다)
     * 2. 불가하면 메일 폴백 — 미등록 관리자가 콜드스타트로 잠기는 경우를 구조적으로 배제한다.
     *    폴백을 원치 않는 사이트는 `identity.allow_mail_fallback=false` 로 끌 수 있다.
     * 3. 둘 다 불가하면 null — 호출자는 2단계 인증을 요구하지 않는다(요구하면 영구 잠금).
     *
     * 판정은 부작용이 없어야 한다 — 로그인 판정 경로에서 호출되므로 challenge 를 만들지 않는다.
     *
     * @param  User  $user  로그인 시도 사용자
     * @return string|null 사용할 provider id, 없으면 null
     *
     * @throws TwoFactorEnrollmentRequiredException 수단이 없는데 운영자가 메일 폴백을 껐을 때
     */
    private function resolveTwoFactorProvider(User $user): ?string
    {
        $manager = app(IdentityVerificationManager::class);
        $purpose = IdentityVerificationPurpose::Login->value;

        $provider = null;

        try {
            $provider = $manager->resolveForPurpose($purpose);
        } catch (\Throwable $e) {
            // 등록된 프로바이더가 하나도 purpose 를 지원하지 않는 상태 — 메일 폴백으로 넘어간다.
        }

        if ($provider !== null && $this->canProviderChallengeUser($provider, $user)) {
            return $provider->getId();
        }

        // 메일 폴백. 해석된 프로바이더가 이미 메일이었다면 위에서 걸러졌다는 뜻이므로
        // (= 이메일이 없거나 메일 설정이 꺼졌다) 여기서도 통과하지 못한다.
        //
        // 폴백 허용 여부는 **해석된 프로바이더를 소유한 확장**이 정한다 — 사용자별 등록이
        // 필요한 수단을 들여온 쪽이 "미등록자를 어떻게 할지" 를 아는 유일한 주체다.
        // 그 프로바이더가 답하지 않으면(보조 계약 미구현) 허용으로 본다.
        $allowMailFallback = $this->isMailFallbackAllowed($provider);

        if ($allowMailFallback && $manager->has(self::MAIL_PROVIDER_ID)) {
            $mail = $manager->get(self::MAIL_PROVIDER_ID);

            if ($mail->supportsPurpose($purpose) && $this->canProviderChallengeUser($mail, $user)) {
                return $mail->getId();
            }
        }

        // 여기부터는 이 사용자에게 요구할 수 있는 수단이 하나도 없다. 갈림길은 그것이
        // **운영자의 결정인지** 다.
        //
        // 운영자가 폴백을 명시적으로 껐다면 그대로 통과시킬 수 없다 — 끈 결과가 "2단계
        // 인증이 아예 없는 로그인" 이 되어 정반대가 된다. 등록을 마치거나 관리자 초기화·
        // `totp:reset` 으로 구제받아야 한다.
        if (! $allowMailFallback) {
            Log::warning('2단계 인증 수단이 없고 메일 폴백이 꺼져 있어 로그인을 차단합니다', [
                'user_id' => $user->id,
                'resolved_provider_id' => $provider?->getId(),
            ]);

            throw new TwoFactorEnrollmentRequiredException;
        }

        // 운영자의 결정이 아니라면(메일 설정이 깨진 경우 등) 차단하지 않는다 — 차단하면
        // 아무도 로그인하지 못해 설정을 되돌릴 수단까지 사라진다. 대신 운영자가 알아차릴
        // 수 있도록 남긴다.
        Log::warning('2단계 인증이 켜져 있으나 이 사용자에게 사용할 수 있는 수단이 없습니다 — 2단계 인증 없이 로그인시킵니다', [
            'user_id' => $user->id,
            'resolved_provider_id' => $provider?->getId(),
        ]);

        return null;
    }

    /**
     * 해석된 프로바이더가 메일 폴백을 허용하는지 판정합니다.
     *
     * 판정 주체는 그 프로바이더를 들여온 확장이다 — 자기 설정에서 값을 읽어
     * `allowsMailFallback()` 으로 답한다. 이 계약을 구현하지 않은 프로바이더(메일 자신,
     * 외부 본인확인 등)는 폴백 개념이 없으므로 허용으로 본다.
     *
     * @param  IdentityVerificationInterface|null  $provider  해석된 프로바이더
     * @return bool 메일 폴백 허용 여부
     */
    private function isMailFallbackAllowed(?IdentityVerificationInterface $provider): bool
    {
        if ($provider instanceof MailFallbackAwareProviderInterface) {
            return $provider->allowsMailFallback();
        }

        return true;
    }

    /**
     * 프로바이더가 이 사용자에게 challenge 를 발행할 수 있는지 판정합니다.
     *
     * 보조 계약을 구현한 프로바이더는 자기 판정을 쓰고, 구현하지 않은 기존 프로바이더는
     * 코어의 기존 규칙(이메일 보유)을 그대로 적용한다 — 기존 동작·테스트 계약 보존.
     *
     * @param  IdentityVerificationInterface  $provider  대상 프로바이더
     * @param  User  $user  대상 사용자
     * @return bool challenge 발행 가능 여부
     */
    private function canProviderChallengeUser(IdentityVerificationInterface $provider, User $user): bool
    {
        if (! $provider->isAvailable()) {
            return false;
        }

        if ($provider instanceof UserScopedIdentityProviderInterface) {
            return $provider->canChallengeUser($user);
        }

        // 대체 주소(`…@xxx.invalid`)는 이메일이 없는 것과 같다 — 닿지 않는 주소로 인증번호를 보내면 영영 로그인할 수 없다.
        return ! UndeliverableEmail::is($user->email);
    }

    /**
     * 2단계 인증 challenge 를 발행합니다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $email  로그인에 사용한 이메일
     * @param  string|null  $providerId  사용할 provider id (null 이면 Manager 기본 해석)
     * @return array{two_factor_required: bool, challenge_id: string, provider_id: string, expires_at: mixed} challenge 정보
     */
    private function startTwoFactorChallenge(User $user, string $email, ?string $providerId = null): array
    {
        $identity = app(IdentityVerificationService::class);

        $challenge = $identity->start(
            IdentityVerificationPurpose::Login->value,
            $user,
            [
                'origin_type' => 'route',
                'origin_identifier' => 'auth.login',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
            $providerId,
        );

        // 코드를 보내지 못하면 사용자는 완료할 수 없는 challenge 를 들고 막다른 길에 선다.
        // "인증번호를 보냈습니다" 로 안내하면 원인을 알 방법이 없으므로, 발송 실패는 실패로 알린다.
        // 이때 2단계 인증을 건너뛰고 로그인시키지는 않는다 — 보안 통제가 조용히 열리면
        // 메일 설정이 깨진 동안 2단계 인증이 없는 것과 같아진다.
        if (($identity->getStatus($challenge->id)['status'] ?? null) === IdentityVerificationStatus::Failed->value) {
            Log::error('2단계 인증 코드 발송 실패 — 로그인을 차단합니다', [
                'user_id' => $user->id,
                'challenge_id' => $challenge->id,
                'provider_id' => $challenge->providerId,
            ]);

            // 자격 증명은 올바르다 — 401 로 뭉개면 사용자는 비밀번호를 의심하며 같은 실패를
            // 반복하고, 운영자는 메일 설정이 깨진 사실을 알 방법이 없다.
            throw new TwoFactorDeliveryFailedException;
        }

        HookManager::doAction('core.auth.two_factor_requested', $user, [
            'email' => $email, // audit:allow resource-member-email-ungated 훅 페이로드(응답 아님)
            'challenge_id' => $challenge->id,
            'ip_address' => request()->ip(),
        ]);

        return [
            'two_factor_required' => true,
            'challenge_id' => $challenge->id,
            'provider_id' => $challenge->providerId,
            'expires_at' => $challenge->expiresAt ?? null,
        ];
    }

    /**
     * 2단계 인증 코드를 확인하고 로그인을 완료합니다.
     *
     * 코드 확인 뒤 토큰 발급 전에 비밀번호 변경 관문을 지난다 — 이 시점에는 2단계 인증 코드가 이미
     * 소비됐으므로, 변경 단계 식별자가 만료되면 처음부터 다시 로그인해야 한다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  array<string, mixed>  $input  프로바이더 입력 (코드 등)
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array 로그인 결과 (user, token, token_type) 또는 비밀번호 변경 단계 안내
     *
     * @throws ValidationException 검증 실패 또는 challenge 불일치
     * @throws AccountLockedException 계정이 잠겨 있거나 이번 오답으로 잠겼을 때
     * @throws PasswordChangeUnavailableException 변경 단계를 열 수 없을 때
     */
    public function completeTwoFactor(string $challengeId, array $input, string $guard = 'user'): array
    {
        $identity = app(IdentityVerificationService::class);

        $status = $identity->getStatus($challengeId);

        // 이 흐름 전용 challenge 인지 먼저 확인한다. purpose 를 검사하지 않으면 다른 용도로
        // 발급된 challenge(가입·비밀번호 재설정 등)를 들고 와 로그인할 수 있다.
        if (($status['purpose'] ?? null) !== IdentityVerificationPurpose::Login->value) {
            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.two_factor_invalid_challenge')],
            ]);
        }

        // 잠긴 계정의 코드는 평가하지 않는다 — 평가하면 잠긴 동안에도 맞혀 보기·challenge 소모가 일어난다(KVE-2026-3195, 7.1.6)
        $challengeUser = $this->resolveChallengeUser($challengeId);
        $this->assertNotLocked($challengeUser);

        $result = $identity->verify($challengeId, $input, [
            'origin_type' => 'route',
            'origin_identifier' => 'auth.login',
        ]);

        if (! $result->success) {
            // 2단계 오답도 로그인 실패 횟수에 합산한다 — 기존 계정 잠금·잠김 안내·비밀번호 재설정 해제 경로를 그대로 탄다
            if ($challengeUser !== null && in_array($result->failureCode, ['INVALID_CODE', 'MAX_ATTEMPTS'], true)) {
                $this->recordTwoFactorFailure($challengeUser, $challengeId);
                $this->assertNotLocked($this->userRepository->findById((int) $challengeUser->getKey()));
            }

            throw ValidationException::withMessages([
                'code' => [__('auth.two_factor_failed')],
            ]);
        }

        $user = $identity->resolveVerifiedUser($challengeId, IdentityVerificationPurpose::Login->value);

        // 휴면도 통과시킨다 — 여기서 막으면 2FA 사이트의 휴면 회원은 해제가 구조적으로
        // 불가능하고, 응답이 "잘못된 challenge" 라 사유조차 알 수 없다.
        if (! $user || ! $this->isLoginAllowedStatus($user->status)) {
            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.two_factor_invalid_challenge')],
            ]);
        }

        // 세션을 여는 것은 이 지점이다 — challenge 발급 이후에 잠겼을 수 있으므로 재검사한다.
        // Auth::login() 앞에 두어야 로그인 완료 훅이 잠금 필드를 초기화하지 못한다.
        $this->assertNotLocked($user);

        // 관리자 경로에 관리자가 아닌 회원 — 세션을 열기 전에 거절한다(휴면 해제·로그인 완료 훅 미실행).
        $this->assertGuardAllowed($user, $guard);

        // 외부 인증 로그인이 2단계 인증으로 넘어왔으면 그 수단 이름을 이어받는다.
        $loginContext = $this->pullLoginContext($challengeId);

        // 비밀번호 변경 관문 — 2단계 인증을 통과한 뒤, 토큰 발급 앞.
        $passwordChange = $this->passwordChangeGate($user, $guard);

        if ($passwordChange !== null) {
            $this->rememberLoginContext((string) $passwordChange['challenge_id'], $loginContext);

            return $passwordChange;
        }

        Auth::login($user);

        return $this->issueLoginSession($user, (string) $user->email, $loginContext);
    }

    /**
     * 2단계 인증 코드를 재발송합니다.
     *
     * 아직 사용되지 않은 challenge 만 재발송 대상입니다. 기존 challenge 를 취소하고 새로
     * 발행하므로, 재발송 이후에는 앞서 받은 인증번호가 통하지 않습니다 — 재발송을 남겨 두면
     * 유효한 코드가 여러 개 동시에 살아 있어 대입 시도의 표적이 넓어집니다.
     *
     * 해석된 사용자는 응답 페이로드에 실리지 않고 out 파라미터로만 올린다 — 관리자 경로가
     * 그 사용자로 등급을 판정해야 하지만, 사용자 경로가 그대로 내보내면 모델이 응답에 새는다.
     *
     * 관리자 경로는 기존 challenge 를 취소하고 새 인증번호를 보내기 **전에** 관리자인지 판정한다 — 사용자 로그인에서 받은
     * challenge 를 관리자 재발송에 들고 와도 메일이 다시 가지 않고 원 challenge 도 살아 있다.
     *
     * 원 challenge 에 묶인 로그인 수단(외부 인증 로그인의 `login_method`)은 새 challenge 로 옮긴다.
     *
     * @param  string  $challengeId  비밀번호 확인 단계가 돌려준 challenge UUID
     * @param  User|null  $resolvedUser  (out) challenge 가 식별한 사용자
     * @param  string  $guard  로그인 경로 (user | admin)
     * @return array{two_factor_required: bool, challenge_id: string, provider_id: string, expires_at: mixed} 새 challenge 정보
     *
     * @throws ValidationException challenge 가 재발송 대상이 아닐 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     * @throws AuthorizationException 관리자 경로에 관리자가 아닌 회원일 때
     * @throws TwoFactorDeliveryFailedException 인증번호를 보내지 못했을 때
     * @throws TwoFactorResendNotSupportedException 코드를 보내지 않는 수단(인증 앱)일 때
     * @throws TwoFactorResendCooldownException 메일 재발송 대기 시간이 남았을 때
     */
    public function resendTwoFactorChallenge(string $challengeId, ?User &$resolvedUser = null, string $guard = 'user'): array
    {
        $log = $this->identityLogRepository->findById($challengeId);

        // 존재하지 않음 / 다른 용도 / 이미 검증·취소·실패·만료 — 전부 같은 응답으로 답한다.
        // 사유를 구분해 주면 challenge id 를 넣어 보며 상태를 캐낼 수 있다.
        $resendable = [
            IdentityVerificationStatus::Requested->value,
            IdentityVerificationStatus::Sent->value,
        ];

        if ($log === null
            || $log->purpose !== IdentityVerificationPurpose::Login->value
            || ! in_array($log->status->value, $resendable, true)
            || ($log->expires_at !== null && $log->expires_at->isPast())
        ) {
            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.two_factor_invalid_challenge')],
            ]);
        }

        $user = $log->user_id === null ? null : $this->userRepository->findById((int) $log->user_id);

        // 휴면도 통과시킨다 — 여기서 막으면 2FA 사이트의 휴면 회원은 해제가 구조적으로
        // 불가능하고, 응답이 "잘못된 challenge" 라 사유조차 알 수 없다.
        if (! $user || ! $this->isLoginAllowedStatus($user->status)) {
            throw ValidationException::withMessages([
                'challenge_id' => [__('auth.two_factor_invalid_challenge')],
            ]);
        }

        // challenge 발급 이후에 잠겼을 수 있다 — 재발송도 잠긴 계정에는 코드를 보내지 않는다.
        $this->assertNotLocked($user);

        $resolvedUser = $user;

        $this->assertGuardAllowed($user, $guard);

        // 재발송은 같은 수단으로 다시 발급한다 — 원 challenge 의 프로바이더를 그대로 쓰고,
        // 그 사이 사용할 수 없게 됐으면(플러그인 비활성화·자격증명 삭제 등) 현재 해석 결과로 되돌린다.
        // 명시 수단을 쓸 수 없으면 발행 단계가 예외를 던지므로(폴백 없음), 여기서 미리 가려 현재 해석 결과로 바꾼다.
        $providerId = $this->loginProviderStillUsable((string) $log->provider_id)
            ? (string) $log->provider_id
            : $this->resolveTwoFactorProviderId($user);

        // 코드를 보내지 않는 수단(인증 앱)은 재발송하지 않는다 — 새 challenge 가 시도 상한을 0 으로 되돌리는 통로였다(KVE-2026-3195, D3)
        if (! $this->challengeDeliversCode($log, $providerId)) {
            throw new TwoFactorResendNotSupportedException;
        }

        // 메일 재발송은 공개 인증 요청과 같은 대기 시간을 지킨다 — 연타로 메일을 쏟아내지 못하게(KVE 동형 A2)
        if ($providerId === self::MAIL_PROVIDER_ID) {
            $remaining = app(IdentityVerificationService::class)->cooldownRemaining(
                IdentityVerificationPurpose::Login->value,
                $user,
                $providerId,
            );

            if ($remaining > 0) {
                throw new TwoFactorResendCooldownException($remaining);
            }
        }

        app(IdentityVerificationService::class)->cancel($log->id);

        $challenge = $this->startTwoFactorChallenge(
            $user,
            (string) $user->email,
            $providerId,
        );

        // 외부 인증 로그인의 수단 이름은 challenge 식별자에 묶여 있다 — 새 식별자로 옮기지 않으면
        // 재발송 뒤 완료가 로그인 완료 훅에 password 로 기록된다. 발급이 성공한 뒤에 옮긴다.
        $this->rememberLoginContext((string) $challenge['challenge_id'], $this->pullLoginContext($challengeId));

        return $challenge;
    }

    /**
     * 로그인 challenge 의 대상 회원을 돌려줍니다 (코드 평가 전 잠금 확인용).
     *
     * @param  string  $challengeId  challenge ID
     * @return User|null 대상 회원 (없으면 null)
     */
    private function resolveChallengeUser(string $challengeId): ?User
    {
        $log = $this->identityLogRepository->findById($challengeId);

        if ($log === null || $log->user_id === null) {
            return null;
        }

        return $this->userRepository->findById((int) $log->user_id);
    }

    /**
     * 로그인 2단계 오답을 로그인 실패로 기록합니다 (실패 횟수·잠금·활동 로그는 `core.auth.login_failed` 구독자가 맡는다).
     *
     * 회원 id 를 문맥에 실어, 이메일이 비었거나 바뀐 회원도 미가입 주소 경로로 새지 않고 그 회원에 쌓이게 한다.
     *
     * @param  User  $user  대상 회원
     * @param  string  $challengeId  challenge ID
     * @return void 반환값 없음
     */
    private function recordTwoFactorFailure(User $user, string $challengeId): void
    {
        HookManager::doAction('core.auth.login_failed', (string) $user->email, [
            'stage' => 'two_factor',
            'user_id' => (int) $user->getKey(),
            'challenge_id' => $challengeId,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'attempted_at' => now(),
        ]);
    }

    /**
     * 원 challenge 의 수단을 로그인 2단계 재발송에 그대로 쓸 수 있는지 판정합니다.
     *
     * 그 사이 플러그인이 꺼졌거나 로그인 목적을 지원하지 않게 됐으면 false — 명시 수단으로 발행하면
     * 다른 수단으로 바꾸지 않고 예외를 던지므로, 호출자는 현재 해석 결과로 바꿔 발행한다.
     *
     * @param  string  $providerId  원 challenge 의 수단 id (빈 문자열이면 false)
     * @return bool 등록돼 있고 로그인 목적을 지원하면 true
     */
    private function loginProviderStillUsable(string $providerId): bool
    {
        if ($providerId === '') {
            return false;
        }

        $manager = app(IdentityVerificationManager::class);

        return $manager->has($providerId)
            && $manager->get($providerId)->supportsPurpose(IdentityVerificationPurpose::Login->value);
    }

    /**
     * challenge 의 수단이 코드를 보내는 수단인지 판정합니다.
     *
     * 보조 계약(`ChallengeDeliveryAwareProviderInterface`)을 구현한 수단은 그 답을 따르고, 구현하지 않은 구버전 수단은
     * 채널이 `app` 일 때만 「보내지 않음」으로 본다.
     *
     * @param  IdentityVerificationLog  $log  원 challenge
     * @param  string|null  $providerId  재발송할 수단
     * @return bool 코드를 보내면 true
     */
    private function challengeDeliversCode(IdentityVerificationLog $log, ?string $providerId): bool
    {
        if ((string) $log->channel === 'app') {
            return false;
        }

        $manager = app(IdentityVerificationManager::class);

        if ($providerId !== null && $providerId !== '' && $manager->has($providerId)) {
            $provider = $manager->get($providerId);

            if ($provider instanceof ChallengeDeliveryAwareProviderInterface) {
                return $provider->deliversChallengeCode();
            }
        }

        return true;
    }

    /**
     * 이미 발급된 세션(토큰 + web 세션)을 되돌립니다.
     *
     * 2단계 인증 완료는 코드 확인 시점에 토큰을 발급하므로, 그 뒤에 권한 검사로 거절하는
     * 경로는 발급분을 반드시 회수해야 합니다. `logout()` 은 `currentAccessToken()` 에
     * 의존해 이 시점(요청 자체는 미인증)에는 아무 일도 하지 않습니다.
     *
     * @param  User  $user  대상 사용자
     * @param  string  $plainTextToken  방금 발급한 평문 토큰 ({id}|{token} 형식)
     * @return void 반환값 없음
     */
    public function revokeIssuedSession(User $user, string $plainTextToken): void
    {
        PersonalAccessToken::findToken($plainTextToken)?->delete();

        // completeTwoFactor() 의 Auth::login() 이 연 세션도 함께 닫는다(개발자 화면 짝 표식 포함).
        if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            request()->session()->forget(ScreenAuth::SESSION_KEY);
        }
    }

    /**
     * 토큰을 발급하고 로그인 완료 훅을 실행합니다.
     *
     * @param  User  $user  로그인 사용자
     * @param  string  $email  로그인에 사용한 이메일
     * @param  array<string, mixed>  $context  로그인 컨텍스트 (없으면 비밀번호 로그인 — 키는 {@see normalizeLoginContext()})
     * @return array{user: User, token: string, token_type: string} 로그인 결과
     */
    private function issueLoginSession(User $user, string $email, array $context = []): array
    {
        $context = self::normalizeLoginContext($context);

        // 세션을 여는 **유일한 자리**에서 휴면을 해제한다 — 비밀번호 경로와 2FA 경로가
        // 둘 다 여기로 모이므로 한 곳만 고쳐도 두 경로가 같은 강도를 갖는다.
        $dormancyRestored = $this->restoreDormantAccount($user);

        // 세션 수명 상한 — 평소 만료와 상한 중 이른 쪽이 만료이고(평소 무기한이면 상한), 상한은 절대 만료로 남아
        // 사용 중 연장(TokenExpirySlider)과 토큰 갱신(refreshToken)이 넘지 못한다.
        $absoluteExpiresAt = $context['max_session_minutes'] !== null ? now()->addMinutes($context['max_session_minutes']) : null;
        $expiresAt = $this->getTokenExpiresAt();

        if ($absoluteExpiresAt !== null && ($expiresAt === null || $absoluteExpiresAt->lessThan($expiresAt))) {
            $expiresAt = $absoluteExpiresAt;
        }

        $newToken = $user->createToken('auth-token', ['*'], $expiresAt);
        $token = $newToken->plainTextToken;

        // 토큰을 연 로그인 수단과 절대 만료를 남긴다 — 운영 정책이 바뀌어 특정 수단의 토큰만 회수할 때 쓴다.
        $this->userRepository->updateAccessTokenSessionMeta($newToken->accessToken, $context['login_method'], $absoluteExpiresAt);

        // 세션에 사용자 저장 (/dev 대시보드 인증·개발자 화면 회원 판정용)
        // StartApiSession 미들웨어가 세션을 시작한 경우에만 동작. 이번 로그인의 토큰을 세션에 짝으로 묶어
        // 그 토큰이 회수되면 세션 로그인도 무효가 되게 한다(ScreenAuth, #792).
        if (request()->hasSession() && request()->session()->isStarted()) {
            Auth::guard('web')->login($user);
            ScreenAuth::pair($user, $newToken->accessToken);
        }

        // Hook 발생 (로그인 완료)
        HookManager::doAction('core.auth.after_login', $user, [
            'email' => $email, // audit:allow resource-member-email-ungated 훅 페이로드(응답 아님)
            'login_time' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            // 로그인 수단 — 비밀번호 로그인은 `password`, 외부 인증은 호출자가 정한 이름(예 `social:kakao`). 키 추가만(기존 키 불변).
            'login_method' => $context['login_method'],
            // 이번 로그인의 토큰 id 와 외부 세션 참조 — 외부 인증 확장이 자기 세션 기록과 토큰을 잇는다(7.2.0, 키 추가만).
            'token_id' => (int) $newToken->accessToken->getKey(),
            'external_ref' => $context['external_ref'],
        ]);

        $result = [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ];

        // 해제됐을 때만 실어 보낸다 — 평시 응답에 `false` 를 늘 담으면 화면이 그 키의 존재를
        // 조건으로 쓰다가 언제나 참이 되어 매 로그인마다 안내가 뜬다.
        if ($dormancyRestored) {
            $result['dormancy_restored'] = true;
        }

        return $result;
    }

    /**
     * 로그인을 허용하는 상태인지 판정합니다.
     *
     * 판정은 `UserStatus::allowsLogin()` 한 곳에 둔다 — 비밀번호 경로·2FA 완료 경로·재발송 경로와
     * 터미널 비밀번호 재설정 경고가 같은 어휘를 쓴다.
     *
     * @param  string|null  $status  사용자 상태 값
     * @return bool 로그인 허용 여부
     */
    private function isLoginAllowedStatus(?string $status): bool
    {
        return UserStatus::allowsLogin($status);
    }

    /**
     * 휴면 계정이면 활성으로 되돌립니다.
     *
     * 로그인 성공을 본인확인으로 보는 2단계형 휴면 정책(설계 확정)의 해제 단계입니다.
     * 설정(`privacy.dormant_enabled`)을 보지 않는 이유: 운영자가 기능을 끈 뒤에도 이미 휴면인
     * 회원은 남아 있고, 설정을 조건으로 걸면 그 회원들이 되돌릴 방법 없이 갇힙니다.
     *
     * @param  User  $user  로그인한 사용자
     * @return bool 해제 여부 (원래 휴면이 아니었으면 false)
     */
    private function restoreDormantAccount(User $user): bool
    {
        if ($user->status !== UserStatus::Dormant->value) {
            // 아직 휴면이 아닌데 통지 표지가 남은 회원 — 사전 안내를 받고 **안내대로 로그인한**
            // 바로 그 회원이다. 상태가 바뀌지 않으므로 아래 짝 표를 거치지 않고, 표지를 비우는
            // 다른 자리(Enum 짝 표·전환 단계)는 전부 「상태가 바뀔 때만」 도달한다. 그대로 두면
            // 사전 안내 쿼리(`whereNull`)가 이 회원을 영구히 배제하는 동안 전환 쿼리
            // (`whereNotNull` + 기한 경과)는 즉시 잡아, 다시 장기 미접속이 됐을 때 **2년 전에
            // 발송된 이미 취소된 안내가 마지막 통지인 채로** 예고 없이 휴면으로 넘어간다.
            // 로그인은 그 자체가 활동이므로 표지를 무효로 본다 — 그 회원은 다음 주기에
            // 사전 안내 대상으로 정상 복귀한다.
            if ($user->dormant_notified_at !== null) {
                $this->userRepository->update($user, ['dormant_notified_at' => null]);
            }

            return false;
        }

        // 짝 컬럼(`dormant_at`·`dormant_notified_at` 등)의 정의는 Enum 한 곳이 소유한다 —
        // 관리자 경로(`UserService` 의 생성·수정·일괄)와 같은 표를 읽어야 어휘가 갈라지지
        // 않는다. 통지 표지를 남겨 두면 이 회원이 다시 장기 미접속이 됐을 때 사전 안내를
        // 이미 받은 것으로 판정되어 예고 없이 휴면으로 넘어간다.
        $this->userRepository->update($user, array_merge(
            ['status' => UserStatus::Active->value],
            UserStatus::Active->transitionTimestamps(),
        ));

        $this->logActivity('user.dormant_restored', [
            'log_type' => ActivityLogType::User,
            'description_key' => 'activity_log.description.user_dormant_restored',
            // 형제 `user.withdraw` 와 같은 어휘(uuid) — 휴면 3키는 개인정보 파기 생명주기의
            // 기록이라 실명 사본을 남기지 않는다. {@see DormantAccountService::convertToDormant()}
            'description_params' => ['user_id' => (string) $user->uuid],
            'user_id' => $user->id,
            'properties' => [
                'user_uuid' => (string) $user->uuid,
                'restored_by' => 'login',
            ],
        ]);

        return true;
    }

    /**
     * 새로운 사용자를 등록하고 인증 토큰을 발급합니다.
     *
     * 가입 단계 정책 매칭:
     *   - core.auth.signup_before_submit (route): RegisterRequest 검증 단계에서 평가
     *   - core.auth.signup_after_create (hook): 가입 직후 PendingVerification 으로 둘지 결정
     *
     * 비밀번호가 비어 있으면 비밀번호 없는 회원(외부 인증 전용)으로 만든다 — 공개 가입 API 는 `RegisterRequest` 가
     * 비밀번호를 필수로 받으므로 이 분기는 확장(소셜 로그인 등)이 서버에서 직접 부를 때만 열린다.
     *
     * @param  array<string, mixed>  $data  RegisterRequest 가 검증한 가입 데이터 (확장 호출은 같은 키 모양)
     *                                      확장 내부 가입 옵션(7.2.0 — 공개 가입 API 는 이 옵션을 넘기지 않는다):
     *                                      - `identity_policy => 'skip'` + `identity_policy_reason`(필수 문자열, 예 `external:vendor-plugin:{idp}`) — 사이트의 가입
     *                                      본인인증 정책(가입 전 인증, 가입 후 인증 대기)을 따르지 않는다. 회사 IdP 처럼 신원을 이미 보증한 가입에만 쓴다. 사유가 없으면
     *                                      생략하지 않는다. 생략하면 활동 로그 `user.register_identity_policy_skipped` 가 남는다.
     *                                      - `record_consents => false` — 이용약관·개인정보 동의 기록을 남기지 않는다(동의를 받지 않은 사전 등록·프로비저닝).
     *                                      - `required_fields => 'defer'` — 필수 회원 항목을 받지 않고 만든다(화면 없는 생성). `users.required_fields_deferred_at` 에
     *                                      생성 시각을 남기고, 그 회원이 처음 로그인하면 추가 정보 입력 화면으로 보낸다.
     *                                      - `nickname_policy => 'skip_field'` — 닉네임이 닉네임 정책(중복 금지, 금지어)에 걸리면 닉네임만 비우고 만든다(화면 없는 생성).
     *                                      버린 사실은 액션 `core.user.nickname_rejected` 로 알린다. 이 옵션이 없으면 검증 예외(`nickname`)를 던진다.
     * @param  array<string, mixed>  $data  RegisterRequest 가 검증한 가입 데이터 (확장 호출은 같은 키 모양)
     * @param  array{issue_token?: bool, identity_policy?: string, identity_policy_reason?: string, record_consents?: bool, required_fields?: string, nickname_policy?: string}  $options
     *                                                                                                                                                                                     issue_token=false 면 토큰·세션을 만들지 않는다 — 호출자가 로그인
     *                                                                                                                                                                                     인계(`LoginHandoffService`)로 코어 로그인 관문을 거치게 할 때
     * @return array{user: User, token: string|null, token_type: string} 사용자 + 토큰 (issue_token=false 면 token=null)
     */
    public function register(array $data, array $options = []): array
    {
        $issueToken = (bool) ($options['issue_token'] ?? true);
        $recordConsents = ($options['record_consents'] ?? true) !== false;
        $skipReason = self::identityPolicySkipReason($options);

        $now = now();

        // 사전 검증 훅: AssertIdentityVerifiedBeforeRegister 가 정책 기반으로 verification_token 검증
        // (확장이 본인인증 정책 생략을 고르면 그 사실을 문맥에 실어 리스너가 건너뛴다)
        HookManager::doAction('core.auth.before_register', $data, array_filter([
            'signup_stage' => 'before_submit',
            'http_method' => 'POST',
            'identity_policy' => $skipReason !== null ? 'skip' : null,
            'identity_policy_reason' => $skipReason,
        ], fn ($value) => $value !== null));

        // signup_after_create 정책 매칭 시 PendingVerification, 아니면 Active (정책 생략 가입은 Active)
        $modeCPolicy = $skipReason !== null ? null : $this->policyService->resolve(
            scope: 'hook',
            target: 'core.auth.after_register',
            context: ['signup_stage' => 'after_create'],
        );
        $status = ($modeCPolicy && $modeCPolicy->enabled)
            ? UserStatus::PendingVerification->value
            : UserStatus::Active->value;

        // 회원 항목 — 운영자 설정(환경설정 > 회원)에서 사용 중이고 가입에서 받는 항목만 저장한다.
        // 컬럼 저장 항목(이름, 닉네임, 휴대폰 등)은 회원 행에, 그 밖의 항목(운영자 추가 항목 등)은 생성 뒤 같은 트랜잭션에서 저장한다.
        // 꺼진 항목의 값은 요청에 실려 와도 버린다(이름을 끈 사이트는 users.name 이 NULL 이다).
        $fieldContext = FieldContext::for(FieldContext::SURFACE_SIGNUP);
        $fieldValues = app(FieldValueService::class);
        $fields = $fieldValues->partition(FieldSet::CORE_USER, $data, $fieldContext);

        $fixedData = [
            'email' => $data['email'], // audit:allow resource-member-email-ungated 가입 저장 데이터(응답 아님)
            'password' => isset($data['password']) && $data['password'] !== '' ? Hash::make($data['password']) : null,
            'ip_address' => request()->ip(),
            'status' => $status,
        ];
        $userData = array_merge($fields['columns'], ['country' => $this->detectCountryFromAcceptLanguage()], $fixedData);
        // 언어를 받지 않았으면 종전 기본값을 쓴다(컬럼이 NOT NULL).
        $userData['language'] = $userData['language'] ?? 'ko';

        // 표시 이름 종류 — 고를 권한이 있고 운영자가 허용한 종류일 때만 저장한다. 요청 규칙을 거친 가입은 이미 통과했고,
        // 요청을 거치지 않는 경로가 실어 보낸 값도 같은 판정으로 거른다(맞지 않으면 버리고 회원 기본 종류로 보인다).
        $chosenKind = $data['display_name_kind'] ?? null;
        if (is_string($chosenKind) && $chosenKind !== '' && app(DisplayNameChoicePolicy::class)->rejection(null, $chosenKind) === null) {
            $userData['display_name_kind'] = $chosenKind;
        }

        // 필수 항목을 건너뛰고 만드는 경로(화면 없는 외부 인증 생성, 관리자 사전 등록 등)는 그 사실을 남긴다 —
        // 그 회원이 처음 로그인하면 추가 정보 입력 화면으로 보낸다.
        if (($options['required_fields'] ?? null) === 'defer') {
            $userData['required_fields_deferred_at'] = $now;
        }

        // 확장이 가입 저장 데이터(회원 행의 컬럼 값)를 조정할 수 있게 한다. 이메일, 비밀번호, 상태, 접속 IP 는 필터가 바꾸지 못한다.
        $filteredData = HookManager::applyFilters('core.auth.filter_register_data', $userData, $data, $options);
        if (is_array($filteredData)) {
            $userData = array_merge($filteredData, $fixedData);
        }

        // 닉네임 정책 — 요청 규칙을 거친 가입은 이미 통과했고, 요청을 거치지 않는 경로(외부 인증 가입 등)는 여기서 판정한다.
        // 통과하면 비교용 정규화 값과 중복 키를 함께 저장한다.
        $nicknamePolicy = app(NicknamePolicyService::class);
        [$userData, $nicknameRejected] = $nicknamePolicy->apply(null, $userData, FieldContext::SURFACE_SIGNUP, null, $options);

        // 계정 생성 이후 단계에서 실패하면 이메일만 점유한 유령 계정이 남아
        // 같은 이메일로 재가입조차 불가능해진다. 생성~토큰 발급을 하나로 묶는다.
        try {
            [$user, $newToken] = DB::transaction(function () use ($userData, $data, $now, $issueToken, $recordConsents, $fields, $fieldValues, $fieldContext) {
                $user = $this->userRepository->create($userData);

                // 컬럼이 아닌 회원 항목 값(운영자 추가 항목, 확장 핸들러 항목)
                $fieldValues->write($user, FieldSet::CORE_USER, $fields['values'], $fieldContext);

                // 비밀번호 만료 기준일 — 가입 시점이 첫 변경일이다.
                $this->passwordChangeService->initializeNewUser($user, PasswordChangeSource::Register);

                // 약관 동의 이력 기록 (동의를 받지 않은 확장 내부 가입은 남기지 않는다)
                if ($recordConsents) {
                    $this->recordConsents($user, $data, $now);
                }

                // 'user' 역할 자동 할당 (UserService 패턴과 동일)
                $userRole = $this->roleRepository->findByIdentifier('user');
                if ($userRole) {
                    $user->roles()->sync([$userRole->id]);
                }

                // 가입 트랜잭션 안 훅 — 동일인 선점처럼 「가입과 함께 성공하거나 함께 취소」되어야 하는 기록을 확장이 남긴다
                // (KVE-2026-3192). 동기 구독만 의미가 있고(큐 구독은 커밋 뒤로 밀린다), 구독자가 예외를 던지면 가입 전체가 롤백된다.
                HookManager::doAction(
                    'core.auth.registering',
                    $user,
                    Arr::except($data, ['password', 'password_confirmation']),
                    ['signup_stage' => 'in_transaction'],
                );

                $newToken = $issueToken
                    ? $user->createToken('auth-token', ['*'], $this->getTokenExpiresAt())
                    : null;

                return [$user, $newToken];
            });
        } catch (UniqueConstraintViolationException $e) {
            // 판정과 저장 사이에 다른 회원이 같은 닉네임을 먼저 저장한 경우 — 닉네임 칸의 검증 오류로 알린다.
            throw $nicknamePolicy->duplicateFrom($e) ?? $e;
        }
        $token = $newToken?->plainTextToken;

        // 커밋 후 부수효과 (캐시는 DB 트랜잭션의 롤백 대상이 아니다)
        $user->flushPermissionCaches();

        // 정책에 걸려 버린 닉네임을 알린다(외부 인증 확장이 자기 기록에 사유를 남긴다).
        if ($nicknameRejected !== null) {
            HookManager::doAction('core.user.nickname_rejected', $user, $nicknameRejected['nickname'], $nicknameRejected['reason'], [
                'surface' => FieldContext::SURFACE_SIGNUP,
                'operation' => 'register',
            ]);
        }

        // 가입 직후에도 개발자 화면에서 로그인으로 보이도록 서버 세션에 로그인을 남기고 토큰과 짝을 맺는다
        // (가입 라우트가 세션을 연 경우에만 — #792 D20).
        if ($newToken !== null && request()->hasSession() && request()->session()->isStarted()) {
            Auth::guard('web')->login($user);
            ScreenAuth::pair($user, $newToken->accessToken);
        }

        // Hook 발생 (회원가입 완료) — 알림 발송은 NotificationHookListener,
        // signup_after_create 정책이 enabled 면 InitiateIdentityChallengeAfterRegister 가 challenge 발행.
        HookManager::doAction('core.auth.after_register', $user, [
            'registration_time' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'signup_stage' => 'after_create',
            'verification_token' => $data['verification_token'] ?? null,
            // 본인인증 정책 생략 가입이면 가입 후 인증 대기 리스너가 challenge 를 만들지 않는다(7.2.0, 키 추가만)
            'identity_policy' => $skipReason !== null ? 'skip' : null,
            // 모듈이 동적 추가한 가입 필드(예: 결제 통화)를 리스너가 읽을 수 있도록 검증된 가입 데이터 전달.
            // request() 직접 접근을 피하기 위해 Service 가 도메인 객체로 넘긴다(Listener 규율).
            // 비밀번호 원문은 뺀다 — 큐 구독자마다 작업 표에 사본이 남고, 읽는 구독자가 없다(#138 후속 — 설계 결정).
            'registration_data' => Arr::except($data, ['password', 'password_confirmation']),
        ]);

        if ($skipReason !== null) {
            $this->logActivity('user.register_identity_policy_skipped', [
                'log_type' => ActivityLogType::User,
                'description_key' => 'activity_log.description.user_register_identity_policy_skipped',
                'description_params' => ['user_id' => (string) $user->uuid, 'reason' => $skipReason],
                'user_id' => $user->id,
                'properties' => [
                    'user_uuid' => (string) $user->uuid,
                    'reason' => $skipReason,
                ],
            ]);
        }

        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * 본인이 스스로 하는 가입(공개 가입 API, 소셜 가입 등)을 받는지 돌려줍니다.
     *
     * 환경설정 「일반 회원가입 받기」(`security.registration_enabled`)와 필터 `core.auth.self_registration_open(bool $open)` 결과를
     * 함께 본다(확장이 닫을 수 있다 — 예: 사이트 전체 회사 계정 강제). 회사가 만드는 계정(외부 인증 자동 생성, 프로비저닝, 관리자
     * 추가)은 이 판정과 무관하게 `register()`·`UserService::createUser()` 로 만든다.
     *
     * @return bool 받으면 true
     *
     * @since 7.2.0
     */
    public function isSelfRegistrationOpen(): bool
    {
        $open = (bool) g7_core_settings('security.registration_enabled', true);

        return (bool) HookManager::applyFilters('core.auth.self_registration_open', $open);
    }

    /**
     * 가입 옵션에서 본인인증 정책 생략 사유를 꺼냅니다.
     *
     * `identity_policy === 'skip'` 이고 사유가 비어 있지 않은 문자열일 때만 생략한다 — 사유 없는 생략은 받지 않는다.
     *
     * @param  array<string, mixed>  $options  가입 옵션
     * @return string|null 생략 사유 (생략하지 않으면 null)
     */
    private static function identityPolicySkipReason(array $options): ?string
    {
        if (($options['identity_policy'] ?? null) !== 'skip') {
            return null;
        }

        $reason = $options['identity_policy_reason'] ?? null;

        if (! is_string($reason) || trim($reason) === '') {
            Log::warning('가입 본인인증 정책 생략에 사유가 없어 생략하지 않습니다');

            return null;
        }

        return mb_substr(trim($reason), 0, 100);
    }

    /**
     * 사용자를 로그아웃시키고 현재 디바이스의 인증 토큰만 삭제합니다.
     *
     * 토큰을 지우기 **전**에 필터 `core.auth.logout_redirect_url(null, User $user, array{guard, token_id})` 로 로그아웃 뒤 갈
     * 곳을 묻는다(7.2.0) — 외부 인증 확장이 그 토큰에 묶인 자기 세션을 찾아 IdP 로그아웃 경로를 돌려준다. 같은 출처 경로만 남긴다.
     *
     * @param  User  $user  로그아웃할 사용자
     * @param  string  $guard  로그아웃 경로 (user | admin)
     * @return string|null 로그아웃 뒤 이동할 같은 출처 경로 (없으면 null — 화면이 로그인 화면으로 간다)
     */
    public function logout(User $user, string $guard = 'user'): ?string
    {
        // Hook 발생 (로그아웃 시작)
        HookManager::doAction('core.auth.before_logout', $user);

        // 지울 토큰을 먼저 찾는다
        $currentToken = $user->currentAccessToken();

        // Case 1: 토큰만 보낸 경우 - currentAccessToken()이 PersonalAccessToken 반환
        // Case 2: 쿠키 + 토큰을 함께 보낸 경우 또는 TransientToken
        // Authorization 헤더에서 Bearer 토큰을 직접 추출한다
        $personalAccessToken = $currentToken instanceof PersonalAccessToken ? $currentToken : null;

        if ($personalAccessToken === null) {
            $bearerToken = request()->bearerToken();

            if ($bearerToken && str_contains($bearerToken, '|')) {
                // plainTextToken 형식: {tokenId}|{actualToken}
                // DB에는 actualToken 부분만 해시되어 저장됨
                $parts = explode('|', $bearerToken, 2);
                if (count($parts) === 2) {
                    $hashedToken = hash('sha256', $parts[1]);

                    $personalAccessToken = $user->tokens()
                        ->where('token', $hashedToken)
                        ->first();
                }
            }
        }

        // 로그아웃 뒤 이동 경로 — 확장이 어느 세션인지 알도록 토큰을 지우기 전에 묻는다
        $redirectUrl = SameOriginPath::normalize(HookManager::applyFilters('core.auth.logout_redirect_url', null, $user, [
            'guard' => $guard,
            'token_id' => $personalAccessToken?->getKey(),
        ]));

        $personalAccessToken?->delete();

        // 세션 무효화 (StartApiSession 미들웨어가 세션을 시작한 경우)
        if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        // Hook 발생 (로그아웃 완료)
        HookManager::doAction('core.auth.logout', $user);

        return $redirectUrl;
    }

    /**
     * 사용자의 인증 토큰을 갱신합니다.
     *
     * @param  User  $user  토큰을 갱신할 사용자
     * @return array 새로운 토큰 정보
     *
     * @throws AccountLockedException 계정이 잠겨 있을 때
     */
    public function refreshToken(User $user): array
    {
        // 재발급도 세션을 여는 지점이다. 유효한 기존 세션이 전제라 신규 로그인 우회는
        // 아니지만, 관리자가 계정을 잠근 뒤에도 그 세션이 무기한 연장되면 잠금이 실효를 잃는다.
        $this->assertNotLocked($user);

        // 현재 토큰 삭제 (다른 디바이스는 유지)
        $currentToken = $user->currentAccessToken();

        // 외부 인증이 준 세션 수명 상한과 로그인 수단은 새 토큰이 물려받는다 — 갱신으로 상한을 넘지 못하게.
        $absoluteExpiresAt = null;
        $loginMethod = null;

        // PersonalAccessToken만 삭제 (TransientToken은 세션 기반이므로 삭제 불필요)
        if ($currentToken instanceof PersonalAccessToken) {
            $absoluteExpiresAt = $currentToken->absolute_expires_at ? Carbon::parse($currentToken->absolute_expires_at) : null;
            $loginMethod = $currentToken->login_method;
            $currentToken->delete();

            if ($absoluteExpiresAt !== null && $absoluteExpiresAt->isPast()) {
                throw new AuthenticationException(__('auth.session_cap_reached'));
            }
        }

        // 새 토큰 생성
        $expiresAt = $this->getTokenExpiresAt();

        if ($absoluteExpiresAt !== null && ($expiresAt === null || $absoluteExpiresAt->lessThan($expiresAt))) {
            $expiresAt = $absoluteExpiresAt;
        }

        $newToken = $user->createToken('auth-token', ['*'], $expiresAt);
        $token = $newToken->plainTextToken;

        if ($currentToken instanceof PersonalAccessToken) {
            $this->userRepository->updateAccessTokenSessionMeta($newToken->accessToken, $loginMethod, $absoluteExpiresAt);
        }

        // 웹 세션 회원이 같으면 개발자 화면 세션의 짝을 새 토큰으로 바꾼다 — 지운 옛 토큰을 짝으로 둔 채면
        // 갱신 직후 개발자 화면이 로그아웃으로 보인다(#792 F6-2).
        ScreenAuth::repair($user, $newToken->accessToken);

        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * 모든 디바이스에서 사용자를 로그아웃시킵니다.
     *
     * @param  User  $user  로그아웃할 사용자
     * @return void 반환값 없음
     */
    public function logoutFromAllDevices(User $user): void
    {
        // Hook 발생 (모든 디바이스 로그아웃 완료)
        HookManager::doAction('auth.logout_all_devices', $user);

        // 사용자의 모든 토큰 삭제
        $user->tokens()->delete();

        // web 세션도 함께 끊는다 — 토큰만 지우면 남은 세션 쿠키로 인증되는 경로가 남는다(KVE-2026-3187 동형)
        $this->userRepository->deleteSessionsByUserIds([(int) $user->getKey()]);

        if (request()->hasSession() && request()->session()->isStarted()) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }

    /**
     * 비밀번호 찾기 요청을 처리합니다.
     *
     * 가입 여부를 응답으로 알려 주지 않는다 — 가입되지 않은 주소도 예외 없이 끝나고, 컨트롤러는 같은 응답을
     * 돌려준다. 응답 시간으로도 갈리지 않게 세 경로(미가입 · 재요청 대기 중 · 발송)가 같은 해시 연산을 한 번씩
     * 하고, 메일 발송·활동 기록을 일으키는 훅은 응답을 보낸 뒤에 발화한다.
     *
     * 같은 주소로 `auth.passwords.users.throttle`(초) 안에 다시 요청하면 토큰을 바꾸지 않고 메일도 다시 보내지
     * 않는다 — 남의 주소로 재설정 메일을 계속 보내는 것을 막는다.
     *
     * 관리자 화면에서 온 요청이어도 관리자가 아닌 계정에는 일반 재설정 주소를 보낸다.
     *
     * @param  string  $email  사용자 이메일
     * @param  string|null  $redirectPrefix  리다이렉트 경로 접두사 (예: 'admin')
     * @return void 반환값 없음
     */
    public function forgotPassword(string $email, ?string $redirectPrefix = null): void
    {
        $policy = app(PasswordAuthPolicyService::class);
        $guard = $redirectPrefix ? 'admin' : 'user';

        // 식별자 기준 비밀번호 인증 정책 — 도메인 정책은 화면에 바로 안내한다(회원 유무와 무관하므로 열거가 아니다).
        $policy->assertAllowed(['identifier' => $email, 'user' => null, 'guard' => $guard, 'purpose' => 'reset_request']);

        $user = $this->userRepository->findByEmail($email);

        // 토큰 생성 (64자 랜덤 문자열) — 해시 연산은 어느 경로에서나 한 번 한다(응답 시간 균일).
        $token = Str::random(64);
        $tokenHash = Hash::make($token);

        // 없는 이메일과 메일을 보낼 수 없는 대체 주소(`…@xxx.invalid`)는 조용히 끝낸다 — 응답은 일반 성공과 같아
        // 계정의 존재를 드러내지 않고(7.1.6), 닿지 않는 메일도 보내지 않는다.
        if (! $user || UndeliverableEmail::is($user->email)) {
            return;
        }

        // 회원 기준 비밀번호 인증 정책 — 응답은 일반 성공과 같게 두고(열거 방지), 재설정 링크 대신 확장이 안내 메일을 보내도록
        // 액션만 발화한다. 재설정 토큰은 만들지 않는다.
        $decision = $policy->decide(['identifier' => $email, 'user' => $user, 'guard' => $guard, 'purpose' => 'reset_request']);

        if (! $decision->allowed) {
            HookManager::doAction('core.auth.password_reset_blocked', $user, $decision);

            return;
        }

        // 재요청 대기 — 직전 발송에서 정해진 시간이 지나지 않았으면 조용히 끝낸다.
        $cooldownSeconds = (int) config('auth.passwords.users.throttle', 60);
        $existing = $this->passwordResetTokenRepository->findByEmail($user->email);

        if ($cooldownSeconds > 0
            && $existing?->created_at !== null
            && $existing->created_at->copy()->addSeconds($cooldownSeconds)->isFuture()
        ) {
            return;
        }

        // 기존 토큰 삭제 후 새 토큰 저장 (해시로 저장)
        $this->passwordResetTokenRepository->updateOrCreateByEmail($user->email, [
            'token' => $tokenHash,
            'created_at' => now(),
        ]);

        // 관리자 화면 주소는 관리자 계정에만 보낸다.
        if ($redirectPrefix === 'admin' && ! $user->isAdmin()) {
            $redirectPrefix = null;
        }

        // 리셋 URL 생성 (extract_data 필터에서 사용) — 메일에 싣는 링크라 화면 주소 헬퍼의 링크 변형을 거친다(#792·#888).
        // 관리자 재설정 화면은 관리자 범위라 밖으로 나가는 링크의 주소(G7_PUBLIC_LINK_URL, 비면 사이트 주소), 사용자 재설정
        // 화면은 「사용자 화면 URL」(비면 그 링크 주소)과 주소 필터를 따른다.
        $resetQuery = [
            'token' => $token,
            'email' => $user->email, // audit:allow resource-member-email-ungated 재설정 메일 URL 쿼리(응답 아님)
        ];
        $resetUrl = $redirectPrefix
            ? ScreenUrl::absoluteForLinks('admin.reset_password', '/'.$redirectPrefix.'/reset-password', [], ScreenUrl::SCOPE_ADMIN)
            : ScreenUrl::absoluteForLinks('auth.reset_password', '/reset-password', $resetQuery);
        if ($redirectPrefix) {
            $resetUrl .= (str_contains($resetUrl, '?') ? '&' : '?').http_build_query($resetQuery);
        } elseif (! str_contains($resetUrl, 'token=')) {
            // 주소 필터가 쿼리를 떨어뜨린 경로를 돌려줘도 토큰·이메일은 반드시 싣는다
            $resetUrl .= (str_contains($resetUrl, '?') ? '&' : '?').http_build_query($resetQuery);
        }

        // Hook 발생 (비밀번호 재설정 요청) — 알림 발송은 NotificationHookListener가 처리.
        // `redirect_prefix` 는 재설정 화면이 관리자 쪽인지 알린다 — 메일의 사이트 링크도 같은 쪽 주소로 만든다.
        // 응답을 보낸 뒤에 발화한다: 발송 시간이 응답 시간에 실리면 가입 주소만 느려진다.
        defer(static function () use ($user, $resetUrl, $redirectPrefix): void {
            HookManager::doAction('core.auth.after_reset_password_request', $user, [
                'reset_url' => $resetUrl,
                'redirect_prefix' => $redirectPrefix,
            ]);
        });
    }

    /**
     * 토큰 기록이 없을 때 대신 비교할 해시를 반환합니다.
     *
     * 가입되지 않은 주소·토큰을 받은 적 없는 주소도 토큰이 틀린 주소와 같은 비용의 해시 비교를 하게 한다.
     * 해시 한 개를 만들어 캐시에 두고 다시 쓴다(매번 만들면 그 경로만 더 느려진다).
     * 재설정 요청 검증의 토큰 사전확인도 같은 해시를 쓴다.
     *
     * @return string 비교용 해시
     */
    public function resetTokenDecoyHash(): string
    {
        return (string) app(CacheInterface::class)->remember(
            'auth.reset_token_decoy_hash',
            static fn (): string => Hash::make(Str::random(64)),
            2592000
        );
    }

    /**
     * 비밀번호 재설정 토큰을 검증합니다.
     *
     * @param  string  $token  비밀번호 재설정 토큰
     * @param  string  $email  사용자 이메일
     * @param  string|null  $redirectPrefix  재설정 화면 구분값 (`admin` 이면 관리자 재설정 화면 — 비밀번호 인증 정책을 관리자 경로로 판정)
     * @return array{valid: bool, error?: string}
     */
    public function validateResetToken(string $token, string $email, ?string $redirectPrefix = null): array
    {
        // 1·2. 사용자와 토큰 레코드 조회 — 가입되지 않은 주소와 토큰 기록이 없는 주소는 토큰이 틀린 것과
        //      같은 문구로 답한다(계정 존재를 알려 주지 않는다). 해시 비교도 같은 횟수로 한다.
        $user = $this->userRepository->findByEmail($email);
        $record = $user ? $this->passwordResetTokenRepository->findByEmail($email) : null;

        if (! $record) {
            Hash::check($token, $this->resetTokenDecoyHash());

            return [
                'valid' => false,
                'error' => __('auth.reset_token_invalid'),
            ];
        }

        // 3. 토큰 해시 검증
        if (! Hash::check($token, $record->token)) {
            return [
                'valid' => false,
                'error' => __('auth.reset_token_invalid'),
            ];
        }

        // 4. 만료 시간 체크
        // Carbon 날짜 연산은 strict 타입 경계라 설정이 문자열이면 TypeError 가 난다 → 정수 보장
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        if ($record->created_at->addMinutes($expireMinutes)->isPast()) {
            $record->delete();

            return [
                'valid' => false,
                'error' => __('auth.reset_token_expired'),
            ];
        }

        // 비밀번호 인증 정책 — 정책이 바뀌기 전에 받은 재설정 링크로 비밀번호를 만들지 못하게 한다. 유효한 링크를
        // 확인한 뒤에만 묻는다 — 앞에서 물으면 이메일과 아무 토큰으로 그 회원이 회사 계정 전용인지 드러난다(열거 방지).
        // 경로는 재설정 화면을 따른다 — 관리자 재설정 화면이면 거절 응답의 다른 로그인 수단 주소가 관리자 로그인으로 온다.
        app(PasswordAuthPolicyService::class)->assertAllowed(['identifier' => $email, 'user' => $user, 'guard' => $redirectPrefix ? 'admin' : 'user', 'purpose' => 'reset_complete']);

        return ['valid' => true];
    }

    /**
     * 사용자의 비밀번호를 재설정합니다.
     *
     * @param  string  $token  비밀번호 재설정 토큰
     * @param  string  $email  사용자 이메일
     * @param  string  $password  새로운 비밀번호
     * @param  string|null  $redirectPrefix  재설정 화면 구분값 (`admin` 이면 관리자 재설정 화면 — 비밀번호 인증 정책을 관리자 경로로 판정)
     * @return void 반환값 없음
     *
     * @throws ValidationException 토큰이 유효하지 않을 때 (가입되지 않은 이메일도 같은 오류)
     */
    public function resetPassword(string $token, string $email, string $password, ?string $redirectPrefix = null): void
    {
        // 가입되지 않은 주소와 토큰 기록이 없는 주소는 토큰이 틀린 것과 같은 필드·문구로 답한다
        // (계정 존재를 알려 주지 않는다). 해시 비교도 같은 횟수로 한다.
        $user = $this->userRepository->findByEmail($email);
        $record = $user ? $this->passwordResetTokenRepository->findByEmail($email) : null;

        if (! $record) {
            Hash::check($token, $this->resetTokenDecoyHash());

            throw ValidationException::withMessages([
                'token' => [__('auth.reset_token_invalid')],
            ]);
        }

        // 토큰 검증 (해시 비교)
        if (! Hash::check($token, $record->token)) {
            throw ValidationException::withMessages([
                'token' => [__('auth.reset_token_invalid')],
            ]);
        }

        // 만료 시간 체크 (기본 60분)
        // Carbon 날짜 연산은 strict 타입 경계라 설정이 문자열이면 TypeError 가 난다 → 정수 보장
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);

        if ($record->created_at->addMinutes($expireMinutes)->isPast()) {
            // 만료된 토큰 삭제
            $record->delete();

            throw ValidationException::withMessages([
                'token' => [__('auth.reset_token_expired')],
            ]);
        }

        // 비밀번호 인증 정책 — 정책이 바뀌기 전에 받은 재설정 링크로 비밀번호를 만들지 못하게 한다. 유효한 링크를
        // 확인한 뒤에만 묻는다 — 앞에서 물으면 이메일과 아무 토큰으로 그 회원이 회사 계정 전용인지 드러난다(열거 방지).
        app(PasswordAuthPolicyService::class)->assertAllowed(['identifier' => $email, 'user' => $user, 'guard' => $redirectPrefix ? 'admin' : 'user', 'purpose' => 'reset_complete']);

        // 재설정 실행 본문(잠금 해제·변경 기록·모든 기기 로그아웃·완료 훅)은 터미널 재설정과 같은 서비스가 소유한다.
        // 비밀번호를 바꾼 뒤 토큰 삭제가 실패하면 그 재설정 토큰이 계속 유효한 채로 남아 재사용된다(보안) —
        // 토큰 삭제를 같은 트랜잭션에 묶는다.
        // 지운 행이 1 이 아니면 다른 요청이 같은 링크를 먼저 썼다 — 예외로 이 요청의 비밀번호 변경까지 되돌린다(KVE 동형 O6).
        $this->passwordResetService->resetByOperator(
            $user,
            $password,
            PasswordChangeSource::Reset,
            insideTransaction: function () use ($record): void {
                if ($this->passwordResetTokenRepository->deleteMatching($record) !== 1) {
                    throw ValidationException::withMessages([
                        'token' => [__('auth.reset_token_invalid')],
                    ]);
                }
            },
        );
    }

    /**
     * 회원가입 시 약관 동의 이력을 기록합니다.
     *
     * 코어 타입(terms, privacy)을 기록하고,
     * core.auth.record_consents 훅으로 플러그인 확장 동의 처리를 허용합니다.
     *
     * @param  User  $user  가입 완료된 사용자
     * @param  array  $data  요청 데이터
     * @param  Carbon  $agreedAt  동의 일시
     * @return void 반환값 없음
     */
    private function recordConsents(User $user, array $data, Carbon $agreedAt): void
    {
        $ip = request()->ip();

        $coreConsents = [
            ConsentType::Terms->value,
            ConsentType::Privacy->value,
        ];

        foreach ($coreConsents as $type) {
            $this->userConsentRepository->record([
                'user_id' => $user->id,
                'consent_type' => $type,
                'agreed_at' => $agreedAt,
                'ip_address' => $ip,
            ]);
        }

        // 플러그인 확장 동의 처리 (마케팅 등 추가 동의)
        // 동의 시각은 ISO8601 문자열로 넘긴다 — 구독자 시그니처(string)가 이 모양을 계약으로 받는다(큐 직렬화는
        // 날짜 객체도 되살리지만, 이 훅은 공개 계약이라 문자열 모양을 유지한다).
        // 가입 데이터의 비밀번호 원문은 넘기지 않는다 — 큐 구독자(활동 로그)의 작업 표에 사본이 남는다(#138 후속 — 설계 결정)
        HookManager::doAction('core.auth.record_consents', $user, Arr::except($data, ['password', 'password_confirmation']), $agreedAt->toIso8601String(), $ip);
    }
}
