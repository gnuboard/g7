<?php

namespace App\Services;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class PermissionService
{
    public function __construct(
        private PermissionRepositoryInterface $permissionRepository
    ) {}

    /**
     * 계층형 권한 트리를 타입별로 그룹화하여 반환합니다.
     *
     * 모든 레벨이 펼치기/접기 가능한 구조이며, 코어 권한이 항상 먼저 표시됩니다.
     * 권한은 type 필드(admin/user)에 따라 그룹화되어 반환됩니다.
     *
     * @return array 타입별 권한 트리
     *               [
     *               'permissions' => ['admin' => [...], 'user' => [...]],
     *               'types' => ['admin', 'user'],
     *               'default_type' => 'admin',
     *               ]
     */
    public function getPermissionTree(): array
    {
        // 최상위 권한(모듈)부터 재귀적으로 자식 로드
        $rootPermissions = $this->permissionRepository->getRootsWithChildren();

        // 코어 권한을 항상 먼저 표시하기 위해 PHP에서 정렬
        $rootPermissions = $rootPermissions->sortBy(function ($permission) {
            // 코어는 0, 나머지는 1로 우선순위 부여 후 order로 정렬
            $priority = $permission->extension_type === ExtensionOwnerType::Core ? 0 : 1;

            return [$priority, $permission->order];
        })->values();

        // 전체 트리를 먼저 빌드
        $fullTree = [];
        foreach ($rootPermissions as $permission) {
            $fullTree[] = $this->formatPermissionNode($permission);
        }

        // 타입별로 트리를 재귀적으로 필터링하여 그룹화
        $groupedByType = [];
        $types = PermissionType::values();

        foreach ($types as $type) {
            $groupedByType[$type] = [
                'label' => PermissionType::from($type)->label(),
                'icon' => PermissionType::from($type)->icon(),
                'permissions' => $this->filterTreeByType($fullTree, $type),
            ];
        }

        $result = [
            'permissions' => $groupedByType,
            'types' => $types,
            'default_type' => PermissionType::Admin->value,
            'scope_options' => [
                ['value' => null, 'label' => __('auth.scope_type_all')],
                ['value' => 'role', 'label' => __('auth.scope_type_role')],
                ['value' => 'self', 'label' => __('auth.scope_type_self')],
            ],
        ];

        // 필터 훅 - 결과 데이터 변형 (확장성 확보)
        $result = HookManager::applyFilters('core.permission.filter_tree_result', $result);

        return $result;
    }

    /**
     * 단일 권한 노드를 포맷팅합니다 (재귀).
     *
     * @param  Permission  $permission  권한 모델
     * @return array 포맷된 권한 노드
     */
    private function formatPermissionNode(Permission $permission): array
    {
        $node = [
            'id' => $permission->id,
            'identifier' => $permission->identifier,
            'name' => $permission->getLocalizedName(),
            'name_raw' => $permission->name,
            'description' => $permission->getLocalizedDescription(),
            'description_raw' => $permission->description,
            'extension_type' => $permission->extension_type?->value,
            'extension_identifier' => $permission->extension_identifier,
            'type' => $permission->type?->value,
            'is_assignable' => $permission->children->isEmpty(),
            'resource_route_key' => $permission->resource_route_key,
            'owner_key' => $permission->owner_key,
            'children' => [],
            'leaf_count' => $this->countLeafNodes($permission),
        ];

        if ($permission->children->isNotEmpty()) {
            foreach ($permission->children as $child) {
                $node['children'][] = $this->formatPermissionNode($child);
            }
        }

        return $node;
    }

    /**
     * 하위 리프 노드 개수를 계산합니다.
     *
     * @param  Permission  $permission  권한 모델
     * @return int 리프 노드 개수
     */
    private function countLeafNodes(Permission $permission): int
    {
        if ($permission->children->isEmpty()) {
            return 1;
        }

        $count = 0;
        foreach ($permission->children as $child) {
            $count += $this->countLeafNodes($child);
        }

        return $count;
    }

    /**
     * 트리 노드 배열을 특정 타입의 리프 노드만 포함하도록 재귀적으로 필터링합니다.
     *
     * 리프 노드는 해당 타입과 일치할 때만 포함되며,
     * 비리프 노드(그룹)는 하위에 해당 타입의 리프가 존재할 때만 포함됩니다.
     *
     * @param  array  $nodes  포맷된 권한 노드 배열
     * @param  string  $type  필터링할 권한 타입 (admin/user)
     * @return array 필터링된 노드 배열
     */
    private function filterTreeByType(array $nodes, string $type): array
    {
        $filtered = [];

        foreach ($nodes as $node) {
            $filteredNode = $this->filterNodeByType($node, $type);
            if ($filteredNode !== null) {
                $filtered[] = $filteredNode;
            }
        }

        return $filtered;
    }

    /**
     * 단일 노드를 특정 타입으로 필터링합니다 (재귀).
     *
     * @param  array  $node  포맷된 권한 노드
     * @param  string  $type  필터링할 권한 타입
     * @return array|null 필터링된 노드 또는 null (해당 타입 없음)
     */
    private function filterNodeByType(array $node, string $type): ?array
    {
        // 리프 노드: 타입이 일치하면 포함, 아니면 제외
        if (empty($node['children'])) {
            return ($node['type'] === $type) ? $node : null;
        }

        // 비리프 노드: 자식을 재귀적으로 필터링
        $filteredChildren = $this->filterTreeByType($node['children'], $type);

        // 해당 타입의 하위 노드가 없으면 이 브랜치 제외
        if (empty($filteredChildren)) {
            return null;
        }

        $node['children'] = $filteredChildren;
        $node['leaf_count'] = $this->countLeafNodesFromArray($filteredChildren);

        return $node;
    }

    /**
     * 포맷된 노드 배열에서 리프 노드 개수를 계산합니다.
     *
     * @param  array  $nodes  포맷된 권한 노드 배열
     * @return int 리프 노드 개수
     */
    private function countLeafNodesFromArray(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            if (empty($node['children'])) {
                $count++;
            } else {
                $count += $this->countLeafNodesFromArray($node['children']);
            }
        }

        return $count;
    }

    /**
     * 할당 가능한 권한 ID 목록을 반환합니다. (리프 노드만)
     *
     * @return array 할당 가능한 권한 ID 배열
     */
    public function getAssignablePermissionIds(): array
    {
        return $this->permissionRepository->getAssignableIds();
    }

    /**
     * 모든 권한 목록을 반환합니다.
     *
     * @return Collection 권한 컬렉션
     */
    public function getAllPermissions(): Collection
    {
        return $this->permissionRepository->getAll();
    }

    /**
     * 특정 권한을 ID로 조회합니다.
     *
     * @param  int  $id  권한 ID
     * @return Permission|null 권한 모델 또는 null
     */
    public function findById(int $id): ?Permission
    {
        return $this->permissionRepository->findById($id);
    }

    /**
     * 특정 확장의 권한들을 조회합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string|null  $identifier  확장 식별자
     * @return Collection 권한 컬렉션
     */
    public function getByExtension(ExtensionOwnerType $type, ?string $identifier = null): Collection
    {
        return $this->permissionRepository->getByExtension($type, $identifier);
    }
}
