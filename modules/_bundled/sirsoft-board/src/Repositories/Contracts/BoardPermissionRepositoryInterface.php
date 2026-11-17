<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use App\Models\Permission;
use Illuminate\Support\Collection;

/**
 * 게시판 동적 권한 Repository 인터페이스
 *
 * 게시판별로 만들어지는 권한(`sirsoft-board.{slug}`, `sirsoft-board.{slug}.{action}`)의
 * 하위 트리 조회와 연결 행까지 포함한 삭제를 담당합니다.
 */
interface BoardPermissionRepositoryInterface
{
    /**
     * 권한의 직계 자식 권한을 조회합니다.
     *
     * @param  Permission  $permission  부모 권한
     * @return Collection<int, Permission> 직계 자식 권한 목록
     */
    public function getChildren(Permission $permission): Collection;

    /**
     * 식별자가 정확히 일치하거나 그 하위 식별자(`{identifier}.*`)인 권한을 조회합니다.
     *
     * @param  string  $identifier  기준 권한 식별자
     * @return Collection<int, Permission> 기준 권한과 하위 식별자 권한 목록
     */
    public function getByIdentifierTree(string $identifier): Collection;

    /**
     * 권한들을 역할 부여·훅 매핑과 함께 주어진 순서대로 삭제합니다.
     *
     * DB 외래키 연쇄에 기대지 않고 권한마다 역할 부여(`role_permissions`)를 떼고 훅 매핑(`permission_hooks`)을
     * 지운 뒤 권한 행을 지운다. 부모가 먼저 지워지면 외래키 연쇄가 자식을 지워 연결 행 정리를 건너뛰므로
     * 호출자는 깊은 자식부터 정렬해 넘겨야 한다.
     *
     * @param  iterable<int, Permission>  $permissions  삭제할 권한 목록 (깊은 자식부터)
     * @return int 삭제한 권한 수
     */
    public function deleteWithRelations(iterable $permissions): int;
}
