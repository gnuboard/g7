<?php

namespace Plugins\Sirsoft\SocialLogin\Services;

use App\Models\User;
use Plugins\Sirsoft\SocialLogin\DTO\NormalizedProfile;
use Plugins\Sirsoft\SocialLogin\DTO\TokenSet;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 연결 행에 제공자 토큰과 프로필 사본을 남깁니다.
 *
 * 토큰은 암호화 컬럼에 두고, 갱신 토큰은 응답에 있을 때만 바꾼다(네이버는 갱신 응답에 다시 주지 않는다).
 * 원본 프로필(`profile_raw`)은 보존 기간 설정만큼만 두고, 기간이 0 이면 남기지 않는다. 원본 프로필에는 토큰이 없다
 * (드라이버가 프로필 응답만 담는다).
 *
 * @since 1.0.0
 */
class AccountCredentialWriter
{
    /** 원본 프로필 기본 보존(일) */
    private const DEFAULT_RAW_RETENTION_DAYS = 14;

    /**
     * @param  SocialAccountRepositoryInterface  $accounts  연결 저장소
     */
    public function __construct(private readonly SocialAccountRepositoryInterface $accounts) {}

    /**
     * 새 연결을 만듭니다.
     *
     * @param  User  $user  회원
     * @param  string  $providerKey  제공자 키
     * @param  NormalizedProfile  $profile  정규화 프로필
     * @param  TokenSet|null  $tokens  토큰 (없으면 비워 둔다)
     * @param  bool  $loggedIn  이번 왕복이 로그인이었는지 (마지막 로그인 시각)
     * @return SocialAccount 만든 연결
     */
    public function create(User $user, string $providerKey, NormalizedProfile $profile, ?TokenSet $tokens, bool $loggedIn = false): SocialAccount
    {
        return $this->accounts->create([
            'user_id' => $user->getKey(),
            'provider_key' => $providerKey,
            'provider_user_id' => $profile->providerUserId,
            ...$this->profileValues($profile),
            ...$this->tokenValues($tokens, null),
            'last_login_at' => $loggedIn ? now() : null,
        ]);
    }

    /**
     * 기존 연결의 프로필 사본과 토큰을 새 값으로 바꿉니다.
     *
     * @param  SocialAccount  $account  연결
     * @param  NormalizedProfile  $profile  정규화 프로필
     * @param  TokenSet|null  $tokens  토큰
     * @param  bool  $loggedIn  이번 왕복이 로그인이었는지
     * @return void
     */
    public function refresh(SocialAccount $account, NormalizedProfile $profile, ?TokenSet $tokens, bool $loggedIn = false): void
    {
        $values = [...$this->profileValues($profile), ...$this->tokenValues($tokens, $account)];
        if ($loggedIn) {
            $values['last_login_at'] = now();
        }

        $this->accounts->update($account, $values);
    }

    /**
     * 프로필 사본 컬럼 값을 만듭니다.
     *
     * @param  NormalizedProfile  $profile  정규화 프로필
     * @return array<string, mixed> 컬럼 값
     */
    private function profileValues(NormalizedProfile $profile): array
    {
        $days = (int) plugin_setting('sirsoft-social_login', 'profile_raw_retention_days', self::DEFAULT_RAW_RETENTION_DAYS);
        $keepRaw = $days > 0 && $profile->raw !== [];

        return [
            'email' => $profile->email !== null ? mb_substr($profile->email, 0, 255) : null, // audit:allow resource-member-email-ungated 연결 행 저장값(응답 아님 — 모델이 직렬화를 막지 않지만 응답은 마스킹해서만 낸다)
            'email_verified' => $profile->emailVerified,
            'name' => $profile->name !== null ? mb_substr($profile->name, 0, 255) : null,
            'nickname' => $profile->nickname !== null ? mb_substr($profile->nickname, 0, 50) : null,
            'avatar_url' => $profile->avatarUrl !== null && strlen($profile->avatarUrl) <= 1024 ? $profile->avatarUrl : null,
            'profile_raw' => $keepRaw ? $profile->raw : null,
            'profile_raw_expires_at' => $keepRaw ? now()->addDays($days) : null,
        ];
    }

    /**
     * 토큰 컬럼 값을 만듭니다 — 갱신 토큰은 응답에 있을 때만 바꾼다.
     *
     * @param  TokenSet|null  $tokens  토큰
     * @param  SocialAccount|null  $existing  기존 연결 (새 연결이면 null)
     * @return array<string, mixed> 컬럼 값
     */
    private function tokenValues(?TokenSet $tokens, ?SocialAccount $existing): array
    {
        if ($tokens === null) {
            return [];
        }

        $values = [
            'access_token' => $tokens->accessToken !== '' ? $tokens->accessToken : null,
            'token_expires_at' => $tokens->expiresAt,
        ];

        if ($tokens->refreshToken !== null) {
            $values['refresh_token'] = $tokens->refreshToken;
        } elseif ($existing === null) {
            $values['refresh_token'] = null;
        }

        return $values;
    }
}
