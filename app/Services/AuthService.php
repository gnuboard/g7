<?php

namespace App\Services;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\IdentityVerificationInterface;
use App\Contracts\Extension\MailFallbackAwareProviderInterface;
use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Enums\ConsentType;
use App\Enums\IdentityVerificationPurpose;
use App\Enums\IdentityVerificationStatus;
use App\Enums\PasswordChangeSource;
use App\Enums\UserStatus;
use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\PasswordChangeUnavailableException;
use App\Exceptions\Auth\TwoFactorDeliveryFailedException;
use App\Exceptions\Auth\TwoFactorEnrollmentRequiredException;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
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
     * @return string|null ISO 3166-1 alpha-2 국가 코드 (2자리 대문자)
     */
    private function detectCountryFromAcceptLanguage(): ?string
    {
        $acceptLanguage = request()->header('Accept-Language');

        if (empty($acceptLanguage)) {
            return null;
        }

        // Accept-Language: ko-KR,ko;q=0.9,en-US;q=0.8 형식 파싱
        // 첫 번째 언어에서 국가 코드 추출 시도
        if (preg_match('/^([a-z]{2})-([A-Z]{2})/', $acceptLanguage, $matches)) {
            return $matches[2];
        }

        // 언어 코드만 있는 경우 (ko, en, ja 등) → 기본 국가 매핑 (config 기반)
        if (preg_match('/^([a-z]{2})/', $acceptLanguage, $matches)) {
            $languageToCountry = config('app.locale_country_fallback', []);

            return $languageToCountry[$matches[1]] ?? null;
        }

        return null;
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
     * @throws ValidationException 인증 정보가 올바르지 않을 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     * @throws PasswordChangeUnavailableException 변경 단계를 열 수 없을 때
     */
    public function login(string $email, string $password, string $guard = 'user'): array
    {
        // 사전 잠금 체크 — 잠긴 계정은 Auth::attempt 자체를 시도하지 않는다.
        // (실패 카운트가 0 으로 리셋된 잠금 상태에서 Failed 이벤트가 다시
        //  카운트를 올려 재잠금 시각을 갱신하는 부작용 방지)
        $this->assertNotLocked($this->userRepository->findByEmail($email));

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

        // 사용자 상태 체크 — Active 와 Dormant 만 로그인 허용
        //
        // 휴면은 **막는 상태가 아니라 되돌리는 상태**다. 로그인 성공(비밀번호 확인, 2FA 사이트는
        // 코드 확인까지) 자체가 본인확인이므로 통과시키고, 세션을 여는 자리에서 Active 로
        // 복귀시킨다({@see issueLoginSession()}). 여기서 막으면 휴면 회원에게 해제 경로가
        // 아예 없고, 열거 방지 문구가 사유를 삼켜 "비밀번호가 틀렸다" 로만 안내된다.
        if (! $this->isLoginAllowedStatus($user->status)) {
            Auth::logout();

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

        // 2단계 인증이 켜져 있으면 여기서 토큰을 발급하지 않는다.
        // 비밀번호 확인만으로 세션이 열리면 2단계가 없는 것과 같으므로, 인증 코드를 확인할
        // 때까지 로그인 상태를 만들지 않고 challenge 만 돌려준다.
        // 판정보다 **먼저** 회수한다 — `resolveTwoFactorProviderId()` 는 수단이 없고 메일
        // 폴백이 꺼진 계정에 `TwoFactorEnrollmentRequiredException` 을 던진다. 회수를 판정
        // 뒤에 두면 그 경로만 `Auth::attempt()` 가 연 세션을 닫지 못하고, 컨트롤러가 그
        // 예외를 정상 응답으로 바꾸므로 세션 쿠키가 그대로 발급된다.
        // `issueLoginSession()` 이 자기 자리에서 다시 로그인시키므로 성공 경로는 불변이다.
        Auth::logout();

        $twoFactorProviderId = $this->resolveTwoFactorProviderId($user);

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
     * 비밀번호 변경 관문 — 바꿔야 하면 토큰 대신 변경 단계 식별자를 돌려줍니다.
     *
     * 관리자 로그인에 비관리자가 들어오면 관문을 건너뛴다(A16) — 종전 경로(토큰 발급 → 컨트롤러 회수 +
     * 403)를 그대로 타야 비관리자의 비밀번호가 관리자 경로로 바뀌지 않는다.
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

            return $this->issueLoginSession($user, (string) $user->email);
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

            $result = $this->issueLoginSession($user, (string) $user->email);
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

            throw new AuthorizationException(__('auth.admin_required'));
        }

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
     * @return void
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

        return filled($user->email);
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

        $result = $identity->verify($challengeId, $input, [
            'origin_type' => 'route',
            'origin_identifier' => 'auth.login',
        ]);

        if (! $result->success) {
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

        // 비밀번호 변경 관문 — 2단계 인증을 통과한 뒤, 토큰 발급 앞.
        $passwordChange = $this->passwordChangeGate($user, $guard);

        if ($passwordChange !== null) {
            return $passwordChange;
        }

        Auth::login($user);

        return $this->issueLoginSession($user, (string) $user->email);
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
     * @param  string  $challengeId  비밀번호 확인 단계가 돌려준 challenge UUID
     * @param  User|null  $resolvedUser  (out) challenge 가 식별한 사용자
     * @return array{two_factor_required: bool, challenge_id: string, provider_id: string, expires_at: mixed} 새 challenge 정보
     *
     * @throws ValidationException challenge 가 재발송 대상이 아닐 때
     * @throws AccountLockedException 계정이 잠겨 있을 때
     * @throws TwoFactorDeliveryFailedException 인증번호를 보내지 못했을 때
     */
    public function resendTwoFactorChallenge(string $challengeId, ?User &$resolvedUser = null): array
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

        app(IdentityVerificationService::class)->cancel($log->id);

        // 재발송은 같은 수단으로 다시 발급한다 — 원 challenge 의 프로바이더를 그대로 쓰고,
        // 그 사이 사용할 수 없게 됐으면(자격증명 삭제 등) 현재 해석 결과로 되돌린다.
        return $this->startTwoFactorChallenge(
            $user,
            (string) $user->email,
            $log->provider_id ?: $this->resolveTwoFactorProviderId($user),
        );
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
     * @return void
     */
    public function revokeIssuedSession(User $user, string $plainTextToken): void
    {
        PersonalAccessToken::findToken($plainTextToken)?->delete();

        // completeTwoFactor() 의 Auth::login() 이 연 세션도 함께 닫는다.
        if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
    }

    /**
     * 토큰을 발급하고 로그인 완료 훅을 실행합니다.
     *
     * @param  User  $user  로그인 사용자
     * @param  string  $email  로그인에 사용한 이메일
     * @return array{user: User, token: string, token_type: string} 로그인 결과
     */
    private function issueLoginSession(User $user, string $email): array
    {
        // 세션을 여는 **유일한 자리**에서 휴면을 해제한다 — 비밀번호 경로와 2FA 경로가
        // 둘 다 여기로 모이므로 한 곳만 고쳐도 두 경로가 같은 강도를 갖는다.
        $dormancyRestored = $this->restoreDormantAccount($user);

        $token = $user->createToken('auth-token', ['*'], $this->getTokenExpiresAt())->plainTextToken;

        // 세션에 사용자 저장 (/dev 대시보드 인증용)
        // StartApiSession 미들웨어가 세션을 시작한 경우에만 동작
        if (request()->hasSession() && request()->session()->isStarted()) {
            Auth::guard('web')->login($user);
        }

        // Hook 발생 (로그인 완료)
        HookManager::doAction('core.auth.after_login', $user, [
            'email' => $email, // audit:allow resource-member-email-ungated 훅 페이로드(응답 아님)
            'login_time' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
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
     * 휴면은 "막을 상태" 가 아니라 "되돌릴 상태" 라서 통과 목록에 든다 — 판정을 한 곳에 두어
     * 비밀번호 경로·2FA 완료 경로·재발송 경로가 같은 어휘를 쓰게 한다.
     *
     * @param  string|null  $status  사용자 상태 값
     * @return bool 로그인 허용 여부
     */
    private function isLoginAllowedStatus(?string $status): bool
    {
        return in_array($status, [
            UserStatus::Active->value,
            UserStatus::Dormant->value,
        ], true);
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
     * @param  array<string, mixed>  $data  RegisterRequest 가 검증한 가입 데이터
     * @return array{user: User, token: string, token_type: string} 사용자 + 토큰
     */
    public function register(array $data): array
    {
        $now = now();

        // 사전 검증 훅: AssertIdentityVerifiedBeforeRegister 가 정책 기반으로 verification_token 검증
        HookManager::doAction('core.auth.before_register', $data, [
            'signup_stage' => 'before_submit',
            'http_method' => 'POST',
        ]);

        // signup_after_create 정책 매칭 시 PendingVerification, 아니면 Active
        $modeCPolicy = $this->policyService->resolve(
            scope: 'hook',
            target: 'core.auth.after_register',
            context: ['signup_stage' => 'after_create'],
        );
        $status = ($modeCPolicy && $modeCPolicy->enabled)
            ? UserStatus::PendingVerification->value
            : UserStatus::Active->value;

        $userData = [
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'email' => $data['email'], // audit:allow resource-member-email-ungated 가입 저장 데이터(응답 아님)
            'password' => Hash::make($data['password']),
            'mobile' => $data['mobile'] ?? null,
            'phone' => $data['phone'] ?? null,
            'language' => $data['language'] ?? 'ko',
            'country' => $this->detectCountryFromAcceptLanguage(),
            'ip_address' => request()->ip(),
            'status' => $status,
        ];

        // 계정 생성 이후 단계에서 실패하면 이메일만 점유한 유령 계정이 남아
        // 같은 이메일로 재가입조차 불가능해진다. 생성~토큰 발급을 하나로 묶는다.
        [$user, $token] = DB::transaction(function () use ($userData, $data, $now) {
            $user = $this->userRepository->create($userData);

            // 비밀번호 만료 기준일 — 가입 시점이 첫 변경일이다.
            $this->passwordChangeService->initializeNewUser($user, PasswordChangeSource::Register);

            // 약관 동의 이력 기록
            $this->recordConsents($user, $data, $now);

            // 'user' 역할 자동 할당 (UserService 패턴과 동일)
            $userRole = $this->roleRepository->findByIdentifier('user');
            if ($userRole) {
                $user->roles()->sync([$userRole->id]);
            }

            $token = $user->createToken('auth-token', ['*'], $this->getTokenExpiresAt())->plainTextToken;

            return [$user, $token];
        });

        // 커밋 후 부수효과 (캐시는 DB 트랜잭션의 롤백 대상이 아니다)
        $user->flushPermissionCaches();

        // Hook 발생 (회원가입 완료) — 알림 발송은 NotificationHookListener,
        // signup_after_create 정책이 enabled 면 InitiateIdentityChallengeAfterRegister 가 challenge 발행.
        HookManager::doAction('core.auth.after_register', $user, [
            'registration_time' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'signup_stage' => 'after_create',
            'verification_token' => $data['verification_token'] ?? null,
            // 모듈이 동적 추가한 가입 필드(예: 결제 통화)를 리스너가 읽을 수 있도록 검증된 가입 데이터 전달.
            // request() 직접 접근을 피하기 위해 Service 가 도메인 객체로 넘긴다(Listener 규율).
            // 비밀번호 원문은 뺀다 — 큐 구독자마다 작업 표에 사본이 남고, 읽는 구독자가 없다(#138 후속 — 설계 결정).
            'registration_data' => Arr::except($data, ['password', 'password_confirmation']),
        ]);

        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * 사용자를 로그아웃시키고 현재 디바이스의 인증 토큰만 삭제합니다.
     *
     * @param  User  $user  로그아웃할 사용자
     * @return void
     */
    public function logout(User $user): void
    {
        // Hook 발생 (로그아웃 시작)
        HookManager::doAction('core.auth.before_logout', $user);

        // 토큰 삭제 처리
        $currentToken = $user->currentAccessToken();

        // Case 1: 토큰만 보낸 경우 - currentAccessToken()이 PersonalAccessToken 반환
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        }
        // Case 2: 쿠키 + 토큰을 함께 보낸 경우 또는 TransientToken
        // Authorization 헤더에서 Bearer 토큰을 직접 추출하여 삭제
        else {
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

                    if ($personalAccessToken) {
                        $personalAccessToken->delete();
                    }
                }
            }
        }

        // 세션 무효화 (StartApiSession 미들웨어가 세션을 시작한 경우)
        if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        // Hook 발생 (로그아웃 완료)
        HookManager::doAction('core.auth.logout', $user);
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

        // PersonalAccessToken만 삭제 (TransientToken은 세션 기반이므로 삭제 불필요)
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        }

        // 새 토큰 생성
        $token = $user->createToken('auth-token', ['*'], $this->getTokenExpiresAt())->plainTextToken;

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
     * @return void
     */
    public function logoutFromAllDevices(User $user): void
    {
        // Hook 발생 (모든 디바이스 로그아웃 완료)
        HookManager::doAction('auth.logout_all_devices', $user);

        // 사용자의 모든 토큰 삭제
        $user->tokens()->delete();
    }

    /**
     * 비밀번호 찾기 요청을 처리합니다.
     *
     * @param  string  $email  사용자 이메일
     * @param  string|null  $redirectPrefix  리다이렉트 경로 접두사 (예: 'admin')
     * @return void
     *
     * @throws ValidationException 등록되지 않은 이메일일 때
     */
    public function forgotPassword(string $email, ?string $redirectPrefix = null): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.email_not_registered')],
            ]);
        }

        // 토큰 생성 (64자 랜덤 문자열)
        $token = Str::random(64);

        // 기존 토큰 삭제 후 새 토큰 저장 (해시로 저장)
        $this->passwordResetTokenRepository->updateOrCreateByEmail($email, [
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        // 리셋 URL 생성 (extract_data 필터에서 사용)
        $resetPath = $redirectPrefix
            ? '/'.$redirectPrefix.'/reset-password'
            : '/reset-password';
        $resetUrl = config('app.url').$resetPath.'?'.http_build_query([
            'token' => $token,
            'email' => $user->email, // audit:allow resource-member-email-ungated 재설정 메일 URL 쿼리(응답 아님)
        ]);

        // Hook 발생 (비밀번호 재설정 요청) — 알림 발송은 NotificationHookListener가 처리
        HookManager::doAction('core.auth.after_reset_password_request', $user, [
            'reset_url' => $resetUrl,
        ]);
    }

    /**
     * 비밀번호 재설정 토큰을 검증합니다.
     *
     * @param  string  $token  비밀번호 재설정 토큰
     * @param  string  $email  사용자 이메일
     * @return array{valid: bool, error?: string}
     */
    public function validateResetToken(string $token, string $email): array
    {
        // 1. 사용자 존재 확인
        $user = $this->userRepository->findByEmail($email);
        if (! $user) {
            return [
                'valid' => false,
                'error' => __('auth.email_not_registered'),
            ];
        }

        // 2. 토큰 레코드 조회
        $record = $this->passwordResetTokenRepository->findByEmail($email);
        if (! $record) {
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

        return ['valid' => true];
    }

    /**
     * 사용자의 비밀번호를 재설정합니다.
     *
     * @param  string  $token  비밀번호 재설정 토큰
     * @param  string  $email  사용자 이메일
     * @param  string  $password  새로운 비밀번호
     * @return void
     *
     * @throws ValidationException 등록되지 않은 이메일이거나 토큰이 유효하지 않을 때
     */
    public function resetPassword(string $token, string $email, string $password): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [__('auth.email_not_registered')],
            ]);
        }

        // 토큰 조회
        $record = $this->passwordResetTokenRepository->findByEmail($email);

        if (! $record) {
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

        // 재설정 메일을 받아 링크를 연 것 자체가 본인 확인이므로 로그인 잠금도 함께 푼다.
        // 잠금은 이메일만 알면 남이 걸 수 있어, 이 경로가 없으면 피해자는 차단 시간이 끝나기를
        // 기다리거나(공격자가 다시 걸면 끝이 없다) 관리자 해제를 받아야 한다 — 관리자가 한 명뿐인
        // 사이트에서 그 관리자가 잠기면 복구 수단이 없다. 무기한 잠금도 같은 이유로 푼다.
        $wasLocked = $this->userRepository->isLocked($user);
        $oldPasswordHash = $user->getRawOriginal('password');

        // 비밀번호를 바꾼 뒤 토큰 삭제가 실패하면 그 재설정 토큰이 계속 유효한 채로
        // 남아 재사용된다(보안). 두 단계를 하나로 묶는다.
        DB::transaction(function () use ($user, $password, $record, $oldPasswordHash) {
            // Hook 발생 (비밀번호 재설정 시작)
            HookManager::doAction('core.auth.before_reset_password', $user);

            // 비밀번호 업데이트
            $this->userRepository->update($user, [
                'password' => Hash::make($password),
            ]);

            // 변경 기록 + 모든 기기 로그아웃(D17) — 메일 링크로 바꾼 것은 탈취 대응일 수 있어 현재 기기도 남기지 않는다.
            $this->passwordChangeService->recordChange(
                $user,
                is_string($oldPasswordHash) ? $oldPasswordHash : null,
                PasswordChangeSource::Reset,
            );

            // 로그인 실패 횟수·잠금 초기화
            $this->userRepository->resetLoginAttempts($user);

            // 사용된 토큰 삭제
            $record->delete();
        });

        if ($wasLocked) {
            HookManager::doAction('core.auth.account_unlocked', $user->fresh(), [
                'ip_address' => request()->ip(),
                'reason' => 'password_reset',
            ]);
        }

        // Hook 발생 (비밀번호 변경 완료) — 알림 발송은 NotificationHookListener가 처리
        HookManager::doAction('core.auth.after_password_changed', $user);
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
     * @return void
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
