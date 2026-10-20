<?php

namespace Plugins\Sirsoft\SocialLogin\Repositories\Contracts;

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
     * @return void
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
     * @return void
     */
    public function update(SocialAccount $account, array $values): void;

    /**
     * 연결 1건을 지웁니다.
     *
     * @param  SocialAccount  $account  연결
     * @return void
     */
    public function delete(SocialAccount $account): void;
}
