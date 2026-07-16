<?php

namespace App\Repositories;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Helpers\PermissionHelper;
use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MenuRepository implements MenuRepositoryInterface
{
    /**
     * 모든 메뉴를 조회합니다.
     *
     * @return Collection 메뉴 컬렉션 (관계 데이터 포함)
     */
    public function getAll(): Collection
    {
        return Menu::with(['creator', 'parent', 'children'])
            ->orderBy('order')
            ->get();
    }

    /**
     * 최상위 메뉴들을 조회합니다.
     *
     * @return Collection 최상위 메뉴 컬렉션 (활성화된 것만)
     */
    public function getTopLevelMenus(): Collection
    {
        return Menu::topLevel()
            ->active()
            ->with(['creator', 'activeChildren'])
            ->get();
    }

    public function getTopLevelMenusForManagement(array $activeModuleIdentifiers = [], array $activePluginIdentifiers = [], ?User $user = null): Collection
    {
        $extensionFilter = function ($query) use ($activeModuleIdentifiers, $activePluginIdentifiers) {
            // 코어 메뉴 또는 사용자 생성 메뉴
            $query->whereNull('extension_type')
                ->orWhere('extension_type', ExtensionOwnerType::Core)
                // 활성화된 모듈의 메뉴
                ->orWhere(function ($q) use ($activeModuleIdentifiers) {
                    $q->where('extension_type', ExtensionOwnerType::Module)
                        ->whereIn('extension_identifier', $activeModuleIdentifiers);
                })
                // 활성화된 플러그인의 메뉴
                ->orWhere(function ($q) use ($activePluginIdentifiers) {
                    $q->where('extension_type', ExtensionOwnerType::Plugin)
                        ->whereIn('extension_identifier', $activePluginIdentifiers);
                });
        };

        $query = Menu::whereNull('parent_id')
            ->where($extensionFilter);

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'core.menus.read');

        // 사용자 역할 기반 접근 제어
        if ($user) {
            $query->accessibleBy($user);
        }

        return $query->with(['creator', 'roles', 'children' => function ($query) use ($extensionFilter, $user) {
                $query->where($extensionFilter);
                if ($user) {
                    $query->accessibleBy($user);
                }
                $query->with('roles')
                    ->orderBy('order');
            }])
            ->orderBy('order')
            ->get();
    }

    /**
     * 활성화된 메뉴들만 조회합니다.
     *
     * @return Collection 활성화된 메뉴 컬렉션
     */
    public function getActiveMenus(): Collection
    {
        return Menu::active()
            ->with(['creator', 'parent', 'activeChildren'])
            ->orderBy('order')
            ->get();
    }

    /**
     * ID로 메뉴를 찾습니다.
     *
     * @param  int  $id  메뉴 ID
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findById(int $id): ?Menu
    {
        return Menu::with(['creator', 'parent', 'children'])->find($id);
    }

    /**
     * 슬러그로 메뉴를 찾습니다.
     *
     * @param  string  $slug  메뉴 슬러그
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findBySlug(string $slug): ?Menu
    {
        return Menu::where('slug', $slug)->first();
    }

    /**
     * 새로운 메뉴를 생성합니다.
     *
     * @param  array  $data  메뉴 생성 데이터
     * @return Menu 생성된 메뉴 모델
     */
    public function create(array $data): Menu
    {
        return Menu::create($data);
    }

    /**
     * 기존 메뉴를 업데이트합니다.
     *
     * @param  Menu  $menu  업데이트할 메뉴 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Menu $menu, array $data): bool
    {
        return $menu->update($data);
    }

    /**
     * 메뉴를 삭제합니다.
     *
     * @param  Menu  $menu  삭제할 메뉴 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Menu $menu): bool
    {
        return $menu->delete();
    }

    /**
     * 메뉴의 순서를 업데이트합니다.
     *
     * @param  array  $menuOrders  메뉴 ID와 순서 매핑 배열
     * @return bool 업데이트 성공 여부
     */
    public function updateOrder(array $menuOrders): bool
    {
        try {
            foreach ($menuOrders as $order => $menuId) {
                Menu::where('id', $menuId)->update(['order' => $order + 1]);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 계층 구조를 고려한 메뉴 순서를 업데이트합니다.
     *
     * @param  array  $orderData  계층 구조 순서 데이터
     * @return bool 업데이트 성공 여부
     */
    public function updateOrderWithHierarchy(array $orderData): bool
    {
        try {
            DB::beginTransaction();

            // ① depth 이동 처리 (parent_id 변경)
            if (isset($orderData['moved_items'])) {
                foreach ($orderData['moved_items'] as $movedItem) {
                    Menu::where('id', $movedItem['id'])
                        ->update(['parent_id' => $movedItem['new_parent_id']]);
                }
            }

            // ② 부모 메뉴 순서 업데이트
            if (isset($orderData['parent_menus'])) {
                foreach ($orderData['parent_menus'] as $parentMenu) {
                    Menu::where('id', $parentMenu['id'])
                        ->update(['order' => $parentMenu['order']]);
                }
            }

            // ③ 자식 메뉴 순서 업데이트
            if (isset($orderData['child_menus'])) {
                foreach ($orderData['child_menus'] as $parentId => $children) {
                    foreach ($children as $childMenu) {
                        Menu::where('id', $childMenu['id'])
                            ->where('parent_id', $parentId)
                            ->update(['order' => $childMenu['order']]);
                    }
                }
            }

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();

            return false;
        }
    }

    /**
     * 특정 확장에 속한 메뉴들을 조회합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return Collection 확장에 속한 메뉴 컬렉션
     */
    public function getMenusByExtension(ExtensionOwnerType $type, string $identifier): Collection
    {
        return Menu::where('extension_type', $type)
            ->where('extension_identifier', $identifier)
            ->with(['creator', 'parent', 'children'])
            ->orderBy('order')
            ->get();
    }

    /**
     * 부모 메뉴의 자식 메뉴들을 조회합니다.
     *
     * @param  int  $parentId  부모 메뉴 ID
     * @return Collection 자식 메뉴 컬렉션
     */
    public function getChildrenByParent(int $parentId): Collection
    {
        return Menu::where('parent_id', $parentId)
            ->with(['creator', 'children'])
            ->orderBy('order')
            ->get();
    }

    /**
     * 네비게이션용 활성화된 메뉴들을 자식 메뉴와 함께 조회합니다.
     *
     * @return Collection 활성화된 메뉴 컬렉션 (자식 메뉴 포함)
     */
    public function getActiveMenusWithChildren(): Collection
    {
        $activeExtensionFilter = function ($query) {
            // 코어 메뉴 또는 사용자 생성 메뉴
            $query->whereNull('extension_type')
                ->orWhere('extension_type', ExtensionOwnerType::Core)
                // 활성화된 모듈의 메뉴
                ->orWhere(function ($q) {
                    $q->where('extension_type', ExtensionOwnerType::Module)
                        ->whereExists(function ($subQuery) {
                            $subQuery->select(DB::raw(1))
                                ->from('modules')
                                ->whereColumn('modules.identifier', 'menus.extension_identifier')
                                ->where('modules.status', 'active');
                        });
                })
                // 활성화된 플러그인의 메뉴
                ->orWhere(function ($q) {
                    $q->where('extension_type', ExtensionOwnerType::Plugin)
                        ->whereExists(function ($subQuery) {
                            $subQuery->select(DB::raw(1))
                                ->from('plugins')
                                ->whereColumn('plugins.identifier', 'menus.extension_identifier')
                                ->where('plugins.status', 'active');
                        });
                });
        };

        return Menu::active()
            ->where($activeExtensionFilter)
            ->with(['activeChildren' => function ($query) use ($activeExtensionFilter) {
                $query->where($activeExtensionFilter)
                    ->orderBy('order');
            }])
            ->whereNull('parent_id') // 최상위 메뉴만
            ->orderBy('order')
            ->get();
    }

    /**
     * 사용자가 접근 가능한 네비게이션용 메뉴들을 조회합니다.
     *
     * @param  User  $user  접근 권한을 확인할 사용자
     * @return Collection 사용자가 접근 가능한 메뉴 컬렉션
     */
    public function getAccessibleNavigationMenus(User $user): Collection
    {
        return Menu::active()
            ->accessibleBy($user)
            ->with(['activeChildren' => function ($query) use ($user) {
                $query->accessibleBy($user)->orderBy('order');
            }])
            ->whereNull('parent_id') // 최상위 메뉴만
            ->orderBy('order')
            ->get();
    }

    /**
     * slug와 확장 정보로 메뉴를 찾습니다.
     *
     * @param  string  $slug  메뉴 슬러그
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findBySlugAndExtension(string $slug, ExtensionOwnerType $extensionType, string $extensionIdentifier): ?Menu
    {
        return Menu::where('slug', $slug)
            ->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier)
            ->first();
    }

    /**
     * 메뉴를 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Menu 생성 또는 업데이트된 메뉴 모델
     */
    public function updateOrCreate(array $attributes, array $values): Menu
    {
        return Menu::updateOrCreate($attributes, $values);
    }

    /**
     * 특정 확장의 모든 메뉴를 삭제합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByExtension(ExtensionOwnerType $type, string $identifier): int
    {
        return Menu::where('extension_type', $type)
            ->where('extension_identifier', $identifier)
            ->delete();
    }

    /**
     * 같은 부모 내에서 최대 순서 값을 조회합니다.
     *
     * @param  int|null  $parentId  부모 메뉴 ID (null이면 최상위)
     * @return int 최대 순서 값 (없으면 0)
     */
    public function getMaxOrder(?int $parentId = null): int
    {
        return (int) Menu::where('parent_id', $parentId)->max('order');
    }

    public function getFilteredTopLevelMenus(array $filters, array $activeModuleIdentifiers = [], array $activePluginIdentifiers = [], ?User $user = null): Collection
    {
        $extensionFilter = function ($query) use ($activeModuleIdentifiers, $activePluginIdentifiers) {
            $query->whereNull('extension_type')
                ->orWhere('extension_type', ExtensionOwnerType::Core)
                ->orWhere(function ($q) use ($activeModuleIdentifiers) {
                    $q->where('extension_type', ExtensionOwnerType::Module)
                        ->whereIn('extension_identifier', $activeModuleIdentifiers);
                })
                ->orWhere(function ($q) use ($activePluginIdentifiers) {
                    $q->where('extension_type', ExtensionOwnerType::Plugin)
                        ->whereIn('extension_identifier', $activePluginIdentifiers);
                });
        };

        $query = Menu::whereNull('parent_id')
            ->where($extensionFilter);

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'core.menus.read');

        // 사용자 역할 기반 접근 제어
        if ($user) {
            $query->accessibleBy($user);
        }

        // 활성화 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 다중 검색 조건 적용
        if (! empty($filters['filters'])) {
            $searchableFields = ['name', 'slug', 'url'];

            foreach ($filters['filters'] as $filter) {
                $field = $filter['field'] ?? null;
                $value = $filter['value'] ?? null;
                $operator = $filter['operator'] ?? 'like';

                if (! $field || ! $value) {
                    continue;
                }

                // 'all' 필드인 경우 모든 검색 가능 필드에서 검색
                if ($field === 'all') {
                    $query->where(function ($q) use ($searchableFields, $value, $operator) {
                        foreach ($searchableFields as $searchField) {
                            $q->orWhere(function ($subQ) use ($searchField, $value, $operator) {
                                $this->applyFilterOperator($subQ, $searchField, $value, $operator);
                            });
                        }
                    });
                } elseif (in_array($field, $searchableFields)) {
                    $this->applyFilterOperator($query, $field, $value, $operator);
                }
            }
        }

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'order';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        // 자식 메뉴도 동일한 조건 적용하여 로드
        return $query->with(['creator', 'roles', 'children' => function ($childQuery) use ($extensionFilter, $filters, $user) {
            $childQuery->where($extensionFilter);

            // 자식 메뉴에도 사용자 역할 기반 접근 제어 적용
            if ($user) {
                $childQuery->accessibleBy($user);
            }

            // 자식 메뉴에도 활성화 상태 필터 적용
            if (isset($filters['is_active'])) {
                $childQuery->where('is_active', $filters['is_active']);
            }

            $childQuery->with('roles')->orderBy('order');
        }])->get();
    }

    /**
     * 필터 연산자를 쿼리에 적용합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  쿼리 빌더
     * @param  string  $field  필드명
     * @param  string  $value  검색 값
     * @param  string  $operator  연산자 (like, eq, starts_with, ends_with)
     */
    private function applyFilterOperator($query, string $field, string $value, string $operator): void
    {
        switch ($operator) {
            case 'eq':
                $query->where($field, $value);
                break;
            case 'starts_with':
                $query->where($field, 'like', $value.'%');
                break;
            case 'ends_with':
                $query->where($field, 'like', '%'.$value);
                break;
            case 'like':
            default:
                $query->where($field, 'like', '%'.$value.'%');
                break;
        }
    }
}
