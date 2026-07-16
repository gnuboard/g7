<?php

namespace App\Http\Resources;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RoleResource extends BaseApiResource
{
    /**
     * 역할 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getValue('id'),
            'identifier' => $this->getValue('identifier'),
            'name' => $this->getLocalizedName(),
            'name_raw' => $this->getValue('name'),
            'description' => $this->getLocalizedDescription(),
            'description_raw' => $this->getValue('description'),
            'extension_type' => $this->getValue('extension_type'),
            'extension_identifier' => $this->getValue('extension_identifier'),
            'extension_name' => $this->getExtensionName(),
            'is_deletable' => is_object($this->resource) && method_exists($this->resource, 'isDeletable')
                ? $this->resource->isDeletable()
                : true,
            'is_active' => $this->getValue('is_active', true),
            'users_count' => $this->when(
                $this->resource->users_count !== null,
                fn () => $this->resource->users_count
            ),
            'permission_ids' => $this->when(
                $this->resource->relationLoaded('permissions'),
                fn () => $this->resource->permissions->pluck('id')->toArray()
            ),
            'permission_values' => $this->when(
                $this->resource->relationLoaded('permissions'),
                fn () => $this->resource->permissions->map(fn ($p) => [
                    'id' => $p->id,
                    'scope_type' => $p->pivot->scope_type?->value ?? $p->pivot->scope_type,
                ])->toArray()
            ),
            'permissions' => $this->when(
                $this->resource->relationLoaded('permissions'),
                fn () => $this->buildHierarchicalPermissions($this->resource->permissions)
            ),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 확장(모듈/플러그인)의 로케일별 이름을 반환합니다.
     *
     * @return string|null 확장 이름 또는 null
     */
    protected function getExtensionName(): ?string
    {
        $extensionType = $this->resource->extension_type ?? null;
        $extensionIdentifier = $this->resource->extension_identifier ?? null;

        if ($extensionType === null || $extensionIdentifier === null) {
            return null;
        }

        // Enum 값 비교 (ExtensionOwnerType은 string-backed enum)
        $typeValue = $extensionType instanceof ExtensionOwnerType
            ? $extensionType
            : ExtensionOwnerType::tryFrom($extensionType);

        if ($typeValue === ExtensionOwnerType::Module) {
            $info = app(ModuleManager::class)->getModuleInfo($extensionIdentifier);

            return $info['name'] ?? null;
        }

        if ($typeValue === ExtensionOwnerType::Plugin) {
            $info = app(PluginManager::class)->getPluginInfo($extensionIdentifier);

            return $info['name'] ?? null;
        }

        return null;
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'core.permissions.create',
            'can_update' => 'core.permissions.update',
            'can_delete' => 'core.permissions.delete',
            'can_toggle_status' => 'core.permissions.update',
        ];
    }

    /**
     * 권한을 계층형 구조로 변환합니다.
     *
     * @param  Collection  $permissions  역할에 할당된 권한 컬렉션
     * @return array 계층형 권한 구조
     */
    protected function buildHierarchicalPermissions(Collection $permissions): array
    {
        if ($permissions->isEmpty()) {
            return [];
        }

        // 할당된 권한들의 ID 목록
        $assignedPermissionIds = $permissions->pluck('id')->toArray();

        // 모든 관련 부모 권한 ID 수집 (category level)
        $parentIds = $permissions->pluck('parent_id')->filter()->unique()->toArray();

        if (empty($parentIds)) {
            // 부모가 없으면 flat 구조 그대로 반환
            return PermissionResource::collection($permissions)->resolve();
        }

        // 부모 권한들 조회 (category level)
        $categoryPermissions = Permission::whereIn('id', $parentIds)->get();

        // 그 위의 부모 권한 ID 수집 (module level)
        $moduleParentIds = $categoryPermissions->pluck('parent_id')->filter()->unique()->toArray();

        // module level 권한들 조회
        $modulePermissions = ! empty($moduleParentIds)
            ? Permission::whereIn('id', $moduleParentIds)->get()
            : collect();

        // 계층형 구조 생성
        $hierarchy = [];

        foreach ($modulePermissions as $modulePermission) {
            $moduleData = $this->formatPermissionNode($modulePermission, $assignedPermissionIds);
            $moduleData['children'] = [];

            // 이 모듈에 속한 카테고리들
            $moduleCategories = $categoryPermissions->where('parent_id', $modulePermission->id);

            foreach ($moduleCategories as $categoryPermission) {
                $categoryData = $this->formatPermissionNode($categoryPermission, $assignedPermissionIds);
                $categoryData['children'] = [];

                // 이 카테고리에 속한 권한들 (역할에 할당된 것만)
                $categoryChildPermissions = $permissions->where('parent_id', $categoryPermission->id);

                foreach ($categoryChildPermissions as $childPermission) {
                    $categoryData['children'][] = $this->formatPermissionNode($childPermission, $assignedPermissionIds);
                }

                if (! empty($categoryData['children'])) {
                    $moduleData['children'][] = $categoryData;
                }
            }

            if (! empty($moduleData['children'])) {
                $hierarchy[] = $moduleData;
            }
        }

        // module level 부모가 없는 category들 처리 (2단계 구조인 경우)
        $orphanCategories = $categoryPermissions->whereNull('parent_id');
        foreach ($orphanCategories as $categoryPermission) {
            $categoryData = $this->formatPermissionNode($categoryPermission, $assignedPermissionIds);
            $categoryData['children'] = [];

            $categoryChildPermissions = $permissions->where('parent_id', $categoryPermission->id);
            foreach ($categoryChildPermissions as $childPermission) {
                $categoryData['children'][] = $this->formatPermissionNode($childPermission, $assignedPermissionIds);
            }

            if (! empty($categoryData['children'])) {
                $hierarchy[] = $categoryData;
            }
        }

        return $hierarchy;
    }

    /**
     * 권한 노드를 포맷합니다.
     *
     * @param  Permission  $permission  권한 모델
     * @param  array  $assignedPermissionIds  할당된 권한 ID 목록
     * @return array 포맷된 권한 데이터
     */
    protected function formatPermissionNode(Permission $permission, array $assignedPermissionIds): array
    {
        $node = [
            'id' => $permission->id,
            'parent_id' => $permission->parent_id,
            'identifier' => $permission->identifier,
            'name' => $permission->getLocalizedName(),
            'name_raw' => $permission->name,
            'description' => $permission->getLocalizedDescription(),
            'description_raw' => $permission->description,
            'extension_type' => $permission->extension_type?->value,
            'extension_identifier' => $permission->extension_identifier,
            'resource_route_key' => $permission->resource_route_key,
            'owner_key' => $permission->owner_key,
            'order' => $permission->order,
            'is_assigned' => in_array($permission->id, $assignedPermissionIds),
            'is_assignable' => $permission->isAssignable(),
        ];

        // 피벗 scope_type 포함 (할당된 권한만)
        if ($node['is_assigned'] && $permission->pivot) {
            $node['scope_type'] = $permission->pivot->scope_type?->value ?? $permission->pivot->scope_type;
        }

        return $node;
    }
}
