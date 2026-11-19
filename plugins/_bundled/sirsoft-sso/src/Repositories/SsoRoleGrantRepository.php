<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use App\Enums\PermissionType;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\Models\SsoRoleGrant;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoRoleGrantRepositoryInterface;

/**
 * 역할 매핑 기록 저장소.
 *
 * @since 1.0.0
 */
class SsoRoleGrantRepository implements SsoRoleGrantRepositoryInterface
{
    /**
     * 그 IdP 매핑이 회원에게 붙인 역할 ID 를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @return array<int, int> 역할 ID
     */
    public function roleIdsFor(int $userId, int $idpId): array
    {
        return SsoRoleGrant::query()
            ->where('user_id', $userId)
            ->where('idp_id', $idpId)
            ->pluck('role_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 회원의 매핑 기록을 IdP 구분 없이 반환합니다 (회원 상세 「매핑」 배지).
     *
     * @param  int  $userId  회원 ID
     * @return array<int, array{role_id: int, idp_id: int}> 기록
     */
    public function forUser(int $userId): array
    {
        return SsoRoleGrant::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['role_id', 'idp_id'])
            ->map(static fn (SsoRoleGrant $g) => ['role_id' => (int) $g->role_id, 'idp_id' => (int) $g->idp_id])
            ->all();
    }

    /**
     * 매핑이 붙인 역할을 기록합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  array<int, int>  $roleIds  역할 ID
     * @return void
     */
    public function record(int $userId, int $idpId, array $roleIds): void
    {
        foreach ($roleIds as $roleId) {
            SsoRoleGrant::query()->firstOrCreate(
                ['user_id' => $userId, 'role_id' => (int) $roleId, 'idp_id' => $idpId],
                ['granted_at' => now()],
            );
        }
    }

    /**
     * 매핑 기록을 지웁니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $idpId  IdP ID
     * @param  array<int, int>|null  $roleIds  역할 ID (null 이면 그 IdP 기록 전부)
     * @return int 지운 행 수
     */
    public function forget(int $userId, int $idpId, ?array $roleIds = null): int
    {
        $query = SsoRoleGrant::query()->where('user_id', $userId)->where('idp_id', $idpId);
        if ($roleIds !== null) {
            if ($roleIds === []) {
                return 0;
            }
            $query->whereIn('role_id', $roleIds);
        }

        return $query->delete();
    }

    /**
     * 역할 identifier 를 ID 로 바꿉니다 (없는 역할은 뺀다).
     *
     * @param  array<int, string>  $identifiers  역할 identifier
     * @return array<string, int> identifier => ID
     */
    public function roleIdsByIdentifier(array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        return Role::query()
            ->whereIn('identifier', array_values(array_unique($identifiers)))
            ->pluck('id', 'identifier')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 역할 ID 를 identifier 로 바꿉니다.
     *
     * @param  array<int, int>  $roleIds  역할 ID
     * @return array<int, string> identifier
     */
    public function identifiersOf(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return Role::query()->whereIn('id', $roleIds)->orderBy('identifier')->pluck('identifier')->map(static fn ($i) => (string) $i)->all();
    }

    /**
     * 관리자 권한(admin 타입 권한)을 가진 역할만 골라 identifier 를 반환합니다.
     *
     * @param  array<int, string>  $identifiers  역할 identifier
     * @return array<int, string> 관리자 권한 역할 identifier
     */
    public function adminRoleIdentifiers(array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        return Role::query()
            ->whereIn('identifier', array_values(array_unique($identifiers)))
            ->whereHas('permissions', fn ($permission) => $permission->where('type', PermissionType::Admin->value))
            ->pluck('identifier')
            ->map(static fn ($i) => (string) $i)
            ->all();
    }

    /**
     * 회원의 지금 역할 ID 를 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return array<int, int> 역할 ID
     */
    public function currentRoleIds(int $userId): array
    {
        return DB::table('user_roles')->where('user_id', $userId)->pluck('role_id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * 회원의 매핑 기록을 모두 지웁니다 (탈퇴, 삭제 정리).
     *
     * @param  int  $userId  회원 ID
     * @return int 지운 행 수
     */
    public function deleteForUser(int $userId): int
    {
        return SsoRoleGrant::query()->where('user_id', $userId)->delete();
    }
}
