<?php

namespace App\Contracts\Repositories;

/**
 * 회원 이전 비밀번호 해시 이력 Repository 인터페이스
 *
 * @since 7.1.0
 */
interface UserPasswordHistoryRepositoryInterface
{
    /**
     * 회원의 최근 이전 비밀번호 해시를 최신순으로 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $limit  최대 개수 (0 이하이면 빈 배열)
     * @return array<int, string> 해시 목록
     */
    public function latestHashes(int $userId, int $limit): array;

    /**
     * 이전 비밀번호 해시를 한 건 기록합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $hash  이전 비밀번호 해시
     * @return void
     */
    public function push(int $userId, string $hash): void;

    /**
     * 최신 $keep 건만 남기고 나머지를 삭제합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $keep  남길 건수
     * @return int 삭제 건수
     */
    public function pruneBeyond(int $userId, int $keep): int;

    /**
     * 회원의 이력을 전부 삭제합니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 삭제 건수
     */
    public function deleteByUser(int $userId): int;

    /**
     * 회원의 이력 건수를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 건수
     */
    public function countByUser(int $userId): int;
}
