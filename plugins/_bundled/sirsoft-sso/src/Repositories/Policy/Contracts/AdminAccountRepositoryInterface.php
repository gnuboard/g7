<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy\Contracts;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * 관리자 「회원 연결」 화면, 회원 상세·목록 주입, 마이페이지 카드의 연결 조회 저장소 계약.
 *
 * @since 1.0.0
 */
interface AdminAccountRepositoryInterface
{
    /**
     * 연결 목록 (최근 연결순, 회원·IdP 함께).
     *
     * @param  array{idp_id?: int|null, status?: string|null, via?: string|null, q?: string|null, per_page?: int|null}  $filters  조건
     * @return LengthAwarePaginator 페이지
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * ID 로 찾습니다 (회원·IdP 함께).
     *
     * @param  int  $id  연결 ID
     * @return SsoAccount|null 연결
     */
    public function findById(int $id): ?SsoAccount;

    /**
     * 연결을 지웁니다.
     *
     * @param  SsoAccount  $account  연결
     * @return void 반환값 없음
     */
    public function delete(SsoAccount $account): void;

    /**
     * 회원의 연결 (IdP 함께, IdP 순서).
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoAccount> 연결
     */
    public function forUserWithIdp(int $userId): Collection;

    /**
     * 이메일로 회원을 찾습니다 (대소문자 무시).
     *
     * @param  string  $email  이메일
     * @return User|null 회원
     */
    public function findUserByEmail(string $email): ?User;

    /**
     * ID 로 회원을 찾습니다.
     *
     * @param  int  $id  회원 ID
     * @return User|null 회원
     */
    public function findUser(int $id): ?User;

    /**
     * 회원 묶음의 IdP 추가 속성 중 지정 키를 한 번에 읽습니다 (목록 열 — 1쿼리). 속성 표가 없으면 빈 배열.
     *
     * @param  array<int, int>  $userIds  회원 ID
     * @param  array<int, string>  $keys  속성 키 (department, title 등)
     * @return array<int, array<string, string>> 회원 ID → 키 → 값
     */
    public function attributesForUsers(array $userIds, array $keys): array;

    /**
     * 회원의 IdP 추가 속성 전부 (상세 탭). 속성 표가 없으면 빈 배열.
     *
     * @param  int  $userId  회원 ID
     * @return array<int, array{idp_id: int, key: string, value: string|null, synced_at: string|null}> 속성
     */
    public function attributesForUser(int $userId): array;

    /**
     * 회원의 역할 중 매핑이 붙인 역할 identifier (역할 출처 배지). 표가 없으면 빈 배열.
     *
     * @param  int  $userId  회원 ID
     * @return array<int, string> 역할 identifier
     */
    public function mappedRoleIdentifiers(int $userId): array;

    /**
     * 「그 IdP 와 연결된 회원」 ID 서브쿼리 (회원 목록 필터).
     *
     * @param  string  $idpKey  IdP 키
     * @return Builder<SsoAccount> 서브쿼리 (`user_id` 하나)
     */
    public function userIdsLinkedToIdpQuery(string $idpKey): Builder;

    /**
     * 「회사 계정 연결이 하나라도 있는 회원」 ID 서브쿼리 (회원 목록 필터 「SSO 연결 없음」 — 이 밖의 회원을 고른다).
     *
     * @return Builder<SsoAccount> 서브쿼리 (`user_id` 하나)
     */
    public function userIdsWithAnyLinkQuery(): Builder;
}
