<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

use Illuminate\Support\Carbon;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityClaim;

/**
 * NHN KCP 동일인 식별값 선점 Repository.
 *
 * @since 1.1.4
 */
class KcpIdentityClaimRepository implements KcpIdentityClaimRepositoryInterface
{
    /** 허용 식별값 종류 */
    private const TYPES = ['di', 'ci'];

    /**
     * 해시를 회원에게 선점시킵니다.
     *
     * `insertOrIgnore` 뒤 잠금 읽기로 쥔 회원을 확인한다 — REPEATABLE READ 스냅샷에서 일반 SELECT 는 상대 트랜잭션이
     * 막 커밋한 행을 못 볼 수 있어, 두 가입이 모두 「내가 쥐었다」고 믿는 일이 생긴다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @param  int  $userId  선점할 회원 ID
     * @return bool 이 회원이 쥐고 있으면 true
     */
    public function claim(string $type, string $hash, int $userId): bool
    {
        if (! in_array($type, self::TYPES, true) || $hash === '') {
            return true;
        }

        KcpIdentityClaim::query()->insertOrIgnore([
            'hash_type' => $type,
            'hash' => $hash,
            'user_id' => $userId,
            'created_at' => Carbon::now(),
        ]);

        $holder = KcpIdentityClaim::query()
            ->where('hash_type', $type)
            ->where('hash', $hash)
            ->lockForUpdate()
            ->value('user_id');

        return $holder !== null && (int) $holder === $userId;
    }

    /**
     * 비어 있을 때만 선점합니다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @param  int  $userId  회원 ID
     * @return void 반환값 없음
     */
    public function claimSoft(string $type, string $hash, int $userId): void
    {
        if (! in_array($type, self::TYPES, true) || $hash === '') {
            return;
        }

        KcpIdentityClaim::query()->insertOrIgnore([
            'hash_type' => $type,
            'hash' => $hash,
            'user_id' => $userId,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * 해시를 쥔 회원 ID 를 돌려줍니다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @return int|null 회원 ID (없으면 null)
     */
    public function findHolderId(string $type, string $hash): ?int
    {
        $holder = KcpIdentityClaim::query()->where('hash_type', $type)->where('hash', $hash)->value('user_id');

        return $holder !== null ? (int) $holder : null;
    }

    /**
     * 회원이 쥔 선점을 모두 풉니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function releaseByUserId(int $userId): int
    {
        return KcpIdentityClaim::query()->where('user_id', $userId)->delete();
    }
}
