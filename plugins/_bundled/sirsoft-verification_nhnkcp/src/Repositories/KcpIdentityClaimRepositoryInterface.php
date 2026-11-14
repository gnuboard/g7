<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Repositories;

/**
 * NHN KCP 동일인 식별값 선점 Repository 계약.
 *
 * @since 1.1.4
 */
interface KcpIdentityClaimRepositoryInterface
{
    /**
     * 해시를 회원에게 선점시킵니다 — 이미 다른 회원이 쥐고 있으면 false.
     *
     * 같은 회원이 다시 선점하면 true(멱등). 동시에 두 가입이 들어오면 UNIQUE 가 한쪽만 남긴다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @param  int  $userId  선점할 회원 ID
     * @return bool 이 회원이 쥐고 있으면 true
     */
    public function claim(string $type, string $hash, int $userId): bool;

    /**
     * 비어 있을 때만 선점합니다 (이미 누가 쥐었으면 아무것도 하지 않는다).
     *
     * 판정 키가 아닌 다른 식별값을 미리 채워 두어 운영자가 판정 키를 바꿔도 선점이 이어지게 한다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @param  int  $userId  회원 ID
     * @return void 반환값 없음
     */
    public function claimSoft(string $type, string $hash, int $userId): void;

    /**
     * 해시를 쥔 회원 ID 를 돌려줍니다.
     *
     * @param  string  $type  식별값 종류 (di|ci)
     * @param  string  $hash  식별값 해시
     * @return int|null 회원 ID (없으면 null)
     */
    public function findHolderId(string $type, string $hash): ?int;

    /**
     * 회원이 쥔 선점을 모두 풉니다 (탈퇴·삭제).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function releaseByUserId(int $userId): int;
}
