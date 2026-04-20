<?php

namespace App\Services;

use App\Contracts\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserConsentRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ConsentType;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private RoleRepositoryInterface $roleRepository,
        private UserConsentRepositoryInterface $userConsentRepository,
        private PasswordResetTokenRepositoryInterface $passwordResetTokenRepository
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

        // 언어 코드만 있는 경우 (ko, en, ja 등) → 기본 국가 매핑
        if (preg_match('/^([a-z]{2})/', $acceptLanguage, $matches)) {
            $languageToCountry = [
                'ko' => 'KR',
                'en' => 'US',
                'ja' => 'JP',
                'zh' => 'CN',
                'de' => 'DE',
                'fr' => 'FR',
                'es' => 'ES',
            ];

            return $languageToCountry[$matches[1]] ?? null;
        }

        return null;
    }

    /**
     * 사용자를 로그인시키고 인증 토큰을 발급합니다.
     *
     * @param  string  $email  사용자 이메일
     * @param  string  $password  사용자 비밀번호
     * @return array 사용자 정보와 토큰을 포함한 배열
     *
     * @throws ValidationException 인증 정보가 올바르지 않을 때
     */
    public function login(string $email, string $password): array
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => [__('auth.invalid_credentials')],
            ]);
        }

        $user = Auth::user();

        // 사용자 상태 체크 - Active만 로그인 허용
        if ($user->status !== UserStatus::Active->value) {
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

        $token = $user->createToken('auth-token', ['*'], $this->getTokenExpiresAt())->plainTextToken;

        // 세션에 사용자 저장 (/dev 대시보드 인증용)
        // StartApiSession 미들웨어가 세션을 시작한 경우에만 동작
        if (request()->hasSession() && request()->session()->isStarted()) {
            Auth::guard('web')->login($user);
        }

        // Hook 발생 (로그인 완료)
        HookManager::doAction('core.auth.after_login', $user, [
            'email' => $email,
            'login_time' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * 새로운 사용자를 등록하고 인증 토큰을 발급합니다.
     *
     * @param  array  $data  사용자 등록 데이터
     * @return array 사용자 정보와 토큰을 포함한 배열
     */
    public function register(array $data): array
    {
        $now = now();

        $userData = [
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'language' => $data['language'] ?? 'ko',
            'country' => $this->detectCountryFromAcceptLanguage(),
            'ip_address' => request()->ip(),
        ];

        $user = $this->userRepository->create($userData);

        // 약관 동의 이력 기록
        $this->recordConsents($user, $data, $now);

        // 'user' 역할 자동 할당 (UserService 패턴과 동일)
        $userRole = $this->roleRepository->findByIdentifier('user');
        if ($userRole) {
            $user->roles()->sync([$userRole->id]);
        }

        $token = $user->createToken('auth-token', ['*'], $this->getTokenExpiresAt())->plainTextToken;

        // Hook 발생 (회원가입 완료) — 알림 발송은 NotificationHookListener가 처리
        HookManager::doAction('core.auth.after_register', $user, [
            'registration_time' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
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
     */
    public function logout(User $user): void
    {
        // Hook 발생 (로그아웃 시작)
        HookManager::doAction('core.auth.before_logout', $user);

        // 토큰 삭제 처리
        $currentToken = $user->currentAccessToken();

        // Case 1: 토큰만 보낸 경우 - currentAccessToken()이 PersonalAccessToken 반환
        if ($currentToken instanceof \Laravel\Sanctum\PersonalAccessToken) {
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
     */
    public function refreshToken(User $user): array
    {
        // 현재 토큰 삭제 (다른 디바이스는 유지)
        $currentToken = $user->currentAccessToken();

        // PersonalAccessToken만 삭제 (TransientToken은 세션 기반이므로 삭제 불필요)
        if ($currentToken instanceof \Laravel\Sanctum\PersonalAccessToken) {
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
            ? '/' . $redirectPrefix . '/reset-password'
            : '/reset-password';
        $resetUrl = config('app.url') . $resetPath . '?' . http_build_query([
            'token' => $token,
            'email' => $user->email,
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
        $expireMinutes = config('auth.passwords.users.expire', 60);
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
        $expireMinutes = config('auth.passwords.users.expire', 60);

        if ($record->created_at->addMinutes($expireMinutes)->isPast()) {
            // 만료된 토큰 삭제
            $record->delete();

            throw ValidationException::withMessages([
                'token' => [__('auth.reset_token_expired')],
            ]);
        }

        // Hook 발생 (비밀번호 재설정 시작)
        HookManager::doAction('core.auth.before_reset_password', $user);

        // 비밀번호 업데이트
        $this->userRepository->update($user, [
            'password' => Hash::make($password),
        ]);

        // 사용된 토큰 삭제
        $record->delete();

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
        HookManager::doAction('core.auth.record_consents', $user, $data, $agreedAt, $ip);
    }
}
