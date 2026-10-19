<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Repositories\Contracts\SocialAccountRepositoryInterface;

/**
 * 회원-소셜 계정 연결 저장소 구현.
 *
 * @since 1.0.0
 */
class SocialAccountRepository implements SocialAccountRepositoryInterface
{
    /**
     * 제공자와 제공자 사용자 ID 로 연결을 찾습니다.
     *
     * `provider_user_id` 컬럼이 utf8mb4_bin 이라 비교가 대소문자를 구분한다.
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $providerUserId  제공자 사용자 ID
     * @return SocialAccount|null 연결, 없으면 null
     */
    public function findByProviderUid(string $providerKey, string $providerUserId): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('provider_key', $providerKey)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    /**
     * 회원의 연결 목록을 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SocialAccount> 연결 목록
     */
    public function findForUser(int $userId): Collection
    {
        return SocialAccount::query()->where('user_id', $userId)->orderBy('linked_at')->get();
    }

    /**
     * 회원의 연결 수를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 연결 수
     */
    public function countForUser(int $userId): int
    {
        return SocialAccount::query()->where('user_id', $userId)->count();
    }

    /**
     * 연결을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값
     * @return SocialAccount 만든 연결
     */
    public function create(array $attributes): SocialAccount
    {
        return SocialAccount::query()->create($attributes + ['linked_at' => now()]);
    }

    /**
     * 토큰을 갱신합니다.
     *
     * 네이버 등은 갱신 응답에 갱신 토큰을 다시 주지 않는다 — 그때 기존 값을 지우면 다음 갱신이 불가능해진다.
     *
     * @param  SocialAccount  $account  연결
     * @param  string|null  $accessToken  접근 토큰
     * @param  string|null  $refreshToken  갱신 토큰 (null 이면 유지)
     * @param  \DateTimeInterface|null  $expiresAt  만료 시각
     * @return void
     */
    public function updateTokens(SocialAccount $account, ?string $accessToken, ?string $refreshToken, ?\DateTimeInterface $expiresAt): void
    {
        $values = ['access_token' => $accessToken, 'token_expires_at' => $expiresAt];

        if ($refreshToken !== null && $refreshToken !== '') {
            $values['refresh_token'] = $refreshToken;
        }

        $account->forceFill($values)->save();
    }

    /**
     * 회원의 연결을 모두 지웁니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int
    {
        return SocialAccount::query()->where('user_id', $userId)->delete();
    }

    /**
     * 이 제공자 연결만 있고 비밀번호가 없는 회원 수를 반환합니다.
     *
     * @param  string  $providerKey  제공자 키
     * @return int 회원 수
     */
    public function socialOnlyUserCountForProvider(string $providerKey): int
    {
        $accounts = (new SocialAccount)->getTable();
        $users = (new User)->getTable();

        return SocialAccount::query()
            ->where('provider_key', $providerKey)
            ->whereIn('user_id', function ($query) use ($users) {
                $query->select('id')->from($users)->whereNull('password');
            })
            ->whereNotIn('user_id', function ($query) use ($accounts, $providerKey) {
                $query->select('user_id')->from($accounts)->where('provider_key', '!=', $providerKey);
            })
            ->count();
    }

    /**
     * 여러 회원의 연결 제공자 키 목록을 한 번의 쿼리로 반환합니다.
     *
     * @param  array<int, int>  $userIds  회원 ID 목록
     * @return array<int, array<int, string>> 회원 ID => 제공자 키 목록
     */
    public function providersForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $map = [];
        SocialAccount::query()
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->orderBy('linked_at')
            ->get(['user_id', 'provider_key'])
            ->each(function (SocialAccount $row) use (&$map) {
                $map[$row->user_id][] = $row->provider_key;
            });

        return $map;
    }
}
