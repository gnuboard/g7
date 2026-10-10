<?php

namespace App\Http\Resources;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
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
            // 최고관리자 역할은 사용 여부를 바꿀 수 없다 — 역할 폼이 사용 여부 토글을 잠그는 값
            'is_active_locked' => $this->resource instanceof Role
                ? $this->resource->isActiveStateLocked()
                : $this->getValue('identifier') === 'admin',
            // 비밀번호 만료 (#736) — 역할 폼 선택칸이 읽는다(inherit·apply·exempt)
            'password_expiry_policy' => $this->passwordExpiryPolicyValue(),
            'users_count' => $this->when(
                $this->resource->users_count !== null,
                fn () => $this->resource->users_count
            ),
            // 목록은 권한 배열 3종을 싣지 않는다. 그 대신 "몇 개가 할당됐는가" 는 집계로 남긴다
            // — 목록 화면이 권한 규모를 보여주면서도 권한 트리를 전송하지 않게 하는 대체 경로다.
            // 값 검사(`!== null`)가 아니라 별칭 존재 여부로 판정한다: COUNT 는 0건에서도 0 을
            // 돌려주므로 "집계하지 않았다" 와 "집계했더니 0" 이 구분되지 않는다.
            'permissions_count' => $this->whenHas(
                'permissions_count',
                fn () => (int) $this->resource->permissions_count
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
            // 역할 폼 「접근 가능 메뉴」 초기값. 상세 조회가 메뉴 관계를 로드할 때만 싣는다 —
            // 목록 응답에는 넣지 않는다(목록 페이로드 축소 규약).
            'menu_ids' => $this->when(
                $this->resource->relationLoaded('menus'),
                fn () => $this->resource->menus->pluck('id')->map(fn ($id) => (int) $id)->values()->toArray()
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
     * 역할 행의 능력(can_*)을 서버 가드와 같은 판정으로 해석합니다.
     *
     * 권한 보유만으로는 부족합니다 — 보호된 역할(코어/확장 소유)은 삭제가 누구에게나 막혀 있고
     * (`Role::isDeletable()`), 수정·사용 여부 토글은 슈퍼 관리자만 할 수 있습니다
     * (`Role::isModifiableBy()`). 화면은 이 값으로 행 메뉴·토글·수정 폼을 잠그므로, 서버 가드와
     * 같은 판정을 싣지 않으면 누를 수 있는 버튼이 403 으로 끝납니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool> 능력 불리언 맵
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);

        if (! $this->resource instanceof Role) {
            return $abilities;
        }

        $actor = $request->user();
        $modifiable = $this->resource->isModifiableBy($actor instanceof User ? $actor : null);

        $abilities['can_update'] = ($abilities['can_update'] ?? false) && $modifiable;
        $abilities['can_toggle_status'] = ($abilities['can_toggle_status'] ?? false) && $modifiable
            && ! $this->resource->isActiveStateLocked();
        $abilities['can_delete'] = ($abilities['can_delete'] ?? false) && $this->resource->isDeletable();

        return $abilities;
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

    /**
     * 비밀번호 만료 설정 값을 문자열로 반환합니다.
     *
     * @return string inherit | apply | exempt
     */
    private function passwordExpiryPolicyValue(): string
    {
        $policy = $this->getValue('password_expiry_policy');

        if ($policy instanceof \BackedEnum) {
            return (string) $policy->value;
        }

        return is_string($policy) && $policy !== '' ? $policy : 'inherit';
    }
}
