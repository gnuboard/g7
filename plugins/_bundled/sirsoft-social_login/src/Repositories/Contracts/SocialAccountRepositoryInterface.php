<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;

/**
 * 회원-소셜 계정 연결 저장소.
 *
 * @since 1.0.0
 */
interface SocialAccountRepositoryInterface
{
    /**
     * 제공자와 제공자 사용자 ID 로 연결을 찾습니다 (대소문자 구분).
     *
     * @param  string  $providerKey  제공자 키
     * @param  string  $providerUserId  제공자 사용자 ID
     * @return SocialAccount|null 연결, 없으면 null
     */
    public function findByProviderUid(string $providerKey, string $providerUserId): ?SocialAccount;

    /**
     * 회원의 연결 목록을 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SocialAccount> 연결 목록
     */
    public function findForUser(int $userId): Collection;

    /**
     * 회원의 연결 수를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 연결 수
     */
    public function countForUser(int $userId): int;

    /**
     * 연결을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  컬럼 값
     * @return SocialAccount 만든 연결
     */
    public function create(array $attributes): SocialAccount;

    /**
     * 토큰을 갱신합니다 — 응답에 갱신 토큰이 없으면 기존 값을 유지합니다.
     *
     * @param  SocialAccount  $account  연결
     * @param  string|null  $accessToken  접근 토큰
     * @param  string|null  $refreshToken  갱신 토큰 (null 이면 유지)
     * @param  \DateTimeInterface|null  $expiresAt  만료 시각
     * @return void 반환값 없음
     */
    public function updateTokens(SocialAccount $account, ?string $accessToken, ?string $refreshToken, ?\DateTimeInterface $expiresAt): void;

    /**
     * 회원의 연결을 모두 지웁니다.
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int;

    /**
     * 이 제공자 연결만 있고 비밀번호가 없는 회원 수를 반환합니다 (제공자 비활성 영향 조회).
     *
     * @param  string  $providerKey  제공자 키
     * @return int 회원 수
     */
    public function socialOnlyUserCountForProvider(string $providerKey): int;

    /**
     * 여러 회원의 연결 제공자 키 목록을 한 번의 쿼리로 반환합니다 (회원 목록 열).
     *
     * @param  array<int, int>  $userIds  회원 ID 목록
     * @return array<int, array<int, string>> 회원 ID => 제공자 키 목록
     */
    public function providersForUsers(array $userIds): array;

    /**
     * 회원의 특정 제공자 연결을 찾습니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $providerKey  제공자 키
     * @return SocialAccount|null 연결, 없으면 null
     */
    public function findForUserAndProvider(int $userId, string $providerKey): ?SocialAccount;

    /**
     * 연결 행의 값을 바꿉니다 (프로필 사본·마지막 로그인 등 — 토큰은 updateTokens).
     *
     * @param  SocialAccount  $account  연결
     * @param  array<string, mixed>  $values  컬럼 값
     * @return void 반환값 없음
     */
    public function update(SocialAccount $account, array $values): void;

    /**
     * 연결 1건을 지웁니다.
     *
     * @param  SocialAccount  $account  연결
     * @return void 반환값 없음
     */
    public function delete(SocialAccount $account): void;

    /**
     * 제공자에 연결된 회원 수를 반환합니다.
     *
     * @param  string  $providerKey  제공자 키
     * @return int 연결 수
     */
    public function countForProvider(string $providerKey): int;

    /**
     * 제공자별 연결 수를 한 번의 쿼리로 반환합니다 (통계).
     *
     * @return array<string, int> 제공자 키 => 연결 수
     */
    public function countsByProvider(): array;

    /**
     * 제공자별 「이 제공자로만 로그인하는 회원」 수를 반환합니다 (통계).
     *
     * @return array<string, int> 제공자 키 => 회원 수
     */
    public function socialOnlyCountsByProvider(): array;

    /**
     * 연결 회원 수와 그중 비밀번호가 없는 회원 수를 반환합니다 (진단).
     *
     * @return array{linked_users: int, passwordless_users: int} 회원 수
     */
    public function linkedUserCounts(): array;

    /**
     * 원본 프로필 보존 기한이 지난 행의 원본을 비웁니다 (정리 배치).
     *
     * @param  bool  $dryRun  true 면 세기만
     * @return int 대상 행 수
     */
    public function clearExpiredProfileRaw(bool $dryRun = false): int;

    /**
     * 그 제공자에 연결된 회원 ID 서브쿼리를 만듭니다 (관리자 회원 목록 「가입경로」 확장 필터용).
     *
     * @param  string  $providerKey  제공자 키
     * @return QueryBuilder user_id 한 열을 고르는 서브쿼리
     */
    public function userIdsLinkedToProviderQuery(string $providerKey): QueryBuilder;
}
