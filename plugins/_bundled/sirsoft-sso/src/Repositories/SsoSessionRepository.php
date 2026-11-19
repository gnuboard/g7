<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;
use Plugins\Sirsoft\Sso\Models\SsoSession;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoSessionRepositoryInterface;

/**
 * SSO 세션(G7 토큰 ↔ IdP 세션) 저장소.
 *
 * @since 1.0.0
 */
class SsoSessionRepository implements SsoSessionRepositoryInterface
{
    /**
     * 세션을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return SsoSession 만든 세션
     */
    public function create(array $attributes): SsoSession
    {
        return SsoSession::query()->create($attributes);
    }

    /**
     * ID 로 찾습니다.
     *
     * @param  int  $id  세션 ID
     * @return SsoSession|null 세션
     */
    public function findById(int $id): ?SsoSession
    {
        return SsoSession::query()->with('idp')->find($id);
    }

    /**
     * 토큰에 묶인 열린 세션을 찾습니다.
     *
     * @param  int  $tokenId  토큰 ID
     * @return SsoSession|null 세션
     */
    public function findOpenByToken(int $tokenId): ?SsoSession
    {
        return SsoSession::query()->with('idp')->where('token_id', $tokenId)->whereNull('ended_at')->latest('id')->first();
    }

    /**
     * 아직 토큰이 없는 세션에 토큰을 붙입니다 (같은 회원의 세션일 때만).
     *
     * @param  int  $sessionId  세션 ID
     * @param  int  $userId  회원 ID
     * @param  int  $tokenId  토큰 ID
     * @return bool 붙였으면 true
     */
    public function attachToken(int $sessionId, int $userId, int $tokenId): bool
    {
        return SsoSession::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId)
            ->whereNull('token_id')
            ->whereNull('ended_at')
            ->update(['token_id' => $tokenId]) === 1;
    }

    /**
     * 회원의 그 IdP 열린 세션을 반환합니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoSession> 세션
     */
    public function openForUserIdp(int $idpId, int $userId): Collection
    {
        return SsoSession::query()->where('idp_id', $idpId)->where('user_id', $userId)->whereNull('ended_at')->get();
    }

    /**
     * 세션들을 끝냅니다.
     *
     * @param  array<int, int>  $ids  세션 ID
     * @param  string  $reason  종료 사유
     * @return int 끝낸 수
     */
    public function end(array $ids, string $reason): int
    {
        if ($ids === []) {
            return 0;
        }

        return SsoSession::query()
            ->whereIn('id', $ids)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'end_reason' => mb_substr($reason, 0, 20)]);
    }

    /**
     * 세션에 묶인 G7 토큰을 지웁니다 (IdP 발 로그아웃, 퇴사).
     *
     * @param  array<int, int>  $tokenIds  토큰 ID
     * @param  int  $userId  회원 ID (그 회원의 토큰만)
     * @return int 지운 토큰 수
     */
    public function deleteTokens(array $tokenIds, int $userId): int
    {
        $tokenIds = array_values(array_filter(array_map('intval', $tokenIds), static fn (int $id) => $id > 0));
        if ($tokenIds === []) {
            return 0;
        }

        return PersonalAccessToken::query()
            ->whereIn('id', $tokenIds)
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $userId)
            ->delete();
    }

    /**
     * 회원의 세션을 모두 지웁니다 (탈퇴, 삭제 정리).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int
    {
        return SsoSession::query()->where('user_id', $userId)->delete();
    }

    /**
     * 기준 시각 전에 끝난 세션을 지웁니다 (보존 정리).
     *
     * @param  Carbon  $before  기준 시각
     * @return int 지운 행 수
     */
    public function pruneEndedBefore(Carbon $before): int
    {
        return SsoSession::query()->whereNotNull('ended_at')->where('ended_at', '<', $before)->delete();
    }
}
