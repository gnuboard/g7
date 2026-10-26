<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

/**
 * 역할 매핑 기록 저장소 계약.
 *
 * @since 1.0.0
 */
interface SsoRoleGrantRepositoryInterface
{
    /**
     * 그 IdP 매핑이 회원에게 붙인 역할 ID 를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @return array<int, int> 역할 ID
     */
    public function roleIdsFor(int $userId, int $idpId): array;

    /**
     * 회원의 매핑 기록을 IdP 구분 없이 반환합니다 (회원 상세 「매핑」 배지).
     *
     * @param  int  $userId  회원 ID
     * @return array<int, array{role_id: int, idp_id: int}> 기록
     */
    public function forUser(int $userId): array;

    /**
     * 매핑이 붙인 역할을 기록합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  array<int, int>  $roleIds  역할 ID
     * @return void
     */
    public function record(int $userId, int $idpId, array $roleIds): void;

    /**
     * 매핑 기록을 지웁니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  array<int, int>|null  $roleIds  역할 ID (null 이면 그 IdP 기록 전부)
     * @return int 지운 행 수
     */
    public function forget(int $userId, int $idpId, ?array $roleIds = null): int;

    /**
     * 역할 identifier 를 ID 로 바꿉니다 (없는 역할은 뺀다).
     *
     * @param  array<int, string>  $identifiers  역할 identifier
     * @return array<string, int> identifier => ID
     */
    public function roleIdsByIdentifier(array $identifiers): array;

    /**
     * 역할 ID 를 identifier 로 바꿉니다.
     *
     * @param  array<int, int>  $roleIds  역할 ID
     * @return array<int, string> identifier
     */
    public function identifiersOf(array $roleIds): array;

    /**
     * 관리자 권한(admin 타입 권한)을 가진 역할만 골라 identifier 를 반환합니다.
     *
     * @param  array<int, string>  $identifiers  역할 identifier
     * @return array<int, string> 관리자 권한 역할 identifier
     */
    public function adminRoleIdentifiers(array $identifiers): array;

    /**
     * 회원의 지금 역할 ID 를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array<int, int> 역할 ID
     */
    public function currentRoleIds(int $userId): array;

    /**
     * 회원의 매핑 기록을 모두 지웁니다 (탈퇴, 삭제 정리).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int;
}
