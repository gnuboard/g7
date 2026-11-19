<?php

namespace Modules\Sirsoft\Board\Repositories;

use App\Models\Permission;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardPermissionRepositoryInterface;

/**
 * 게시판 동적 권한 Repository 구현체
 */
class BoardPermissionRepository implements BoardPermissionRepositoryInterface
{
    /**
     * 권한의 직계 자식 권한을 조회합니다.
     *
     * @param  Permission  $permission  부모 권한
     * @return Collection<int, Permission> 직계 자식 권한 목록
     */
    public function getChildren(Permission $permission): Collection
    {
        // audit:allow query-unbounded-get reason: 게시판 카테고리의 자식은 권한 정의 키 수만큼만 존재한다
        return $permission->children()->get();
    }

    /**
     * 식별자가 정확히 일치하거나 그 하위 식별자(`{identifier}.*`)인 권한을 조회합니다.
     *
     * @param  string  $identifier  기준 권한 식별자
     * @return Collection<int, Permission> 기준 권한과 하위 식별자 권한 목록
     */
    public function getByIdentifierTree(string $identifier): Collection
    {
        // audit:allow query-unbounded-get reason: 한 게시판의 권한은 권한 정의 키 수만큼만 존재한다
        return Permission::query()
            ->where('identifier', $identifier)
            ->orWhere('identifier', 'like', $identifier.'.%')
            ->get();
    }

    /**
     * 권한들을 역할 부여·훅 매핑과 함께 주어진 순서대로 삭제합니다.
     *
     * @param  iterable<int, Permission>  $permissions  삭제할 권한 목록 (깊은 자식부터)
     * @return int 삭제한 권한 수
     */
    public function deleteWithRelations(iterable $permissions): int
    {
        $deleted = 0;

        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->hooks()->delete();
            $deleted += (int) $permission->delete();
        }

        return $deleted;
    }
}
