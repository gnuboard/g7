<?php

namespace App\Repositories;

use App\Contracts\Repositories\MenuRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Helpers\PermissionHelper;
use App\Models\Menu;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\User;
use App\Repositories\Concerns\ResolvesSortSpec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MenuRepository implements MenuRepositoryInterface
{
    use ResolvesSortSpec;

    /** 허용 정렬 컬럼 (MenuListRequest 와 동일 집합) */
    private const SORTABLE_COLUMNS = ['created_at', 'name', 'slug', 'order'];

    /**
     * 다국어 JSON 컬럼 목록 (검색·정렬 시 로케일 경로로 풀어야 하는 컬럼)
     *
     * @var array<int, string>
     */
    private const TRANSLATABLE_COLUMNS = ['name'];

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

    /**
     * 관리용 최상위 메뉴 목록을 조회합니다. (비활성 확장 메뉴 제외)
     *
     * @param  array  $activeModuleIdentifiers  활성 모듈 식별자 목록
     * @param  array  $activePluginIdentifiers  활성 플러그인 식별자 목록
     * @param  User|null  $user  접근 권한 판정 대상 사용자 (null 이면 역할 필터 미적용)
     * @return Collection 최상위 메뉴 컬렉션 (자식 메뉴 포함)
     */
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
            // 하위 메뉴의 역할은 목록에서 로드하지 않는다 — 화면이 쓰는 것은 하위 메뉴의
            // 개수와 표시 정보뿐이고, 역할 배지는 선택한(상위) 메뉴에만 나온다. 로드하면
            // 상위 메뉴마다 하위 전체의 역할 피벗까지 함께 실려 응답이 배가 된다.
            // Resource 는 로드되지 않은 하위 메뉴의 `roles` 키를 **생략**한다(빈 배열이 아니다)
            // — 빈 배열은 "역할 제한 없음" 이라는 사실 아닌 단언이 되어, 화면이 그 값을 그대로
            // 저장 요청에 실으면 역할 제한이 통째로 해제된다.
            $query->orderBy('order');
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
     * 여러 ID로 메뉴를 한 번에 조회합니다.
     *
     * @param  array<int, int>  $ids  메뉴 ID 목록
     * @return Collection 메뉴 컬렉션
     */
    public function findByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return Menu::whereIn('id', $ids)->get();
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
     * URL 로 메뉴를 찾습니다.
     *
     * @param  string  $url  메뉴 URL
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findByUrl(string $url): ?Menu
    {
        return Menu::where('url', $url)->first();
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
                                ->from((new Module)->getTable())
                                ->whereColumn('modules.identifier', 'menus.extension_identifier')
                                ->where('modules.status', 'active');
                        });
                })
                // 활성화된 플러그인의 메뉴
                ->orWhere(function ($q) {
                    $q->where('extension_type', ExtensionOwnerType::Plugin)
                        ->whereExists(function ($subQuery) {
                            $subQuery->select(DB::raw(1))
                                ->from((new Plugin)->getTable())
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

    /**
     * 필터 조건이 적용된 최상위 메뉴 목록을 조회합니다.
     *
     * @param  array  $filters  필터 조건 배열 (sort_by/sort_order/검색 필드)
     * @param  array  $activeModuleIdentifiers  활성 모듈 식별자 목록
     * @param  array  $activePluginIdentifiers  활성 플러그인 식별자 목록
     * @param  User|null  $user  접근 권한 판정 대상 사용자 (null 이면 역할 필터 미적용)
     * @return Collection 최상위 메뉴 컬렉션 (자식 메뉴 포함)
     */
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
                                $this->applySearchableField($subQ, $searchField, $value, $operator);
                            });
                        }
                    });
                } elseif (in_array($field, $searchableFields)) {
                    $this->applySearchableField($query, $field, $value, $operator);
                }
            }
        }

        // 정렬 (허용 컬럼 화이트리스트로 해석)
        foreach ($this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'order', 'asc') as $sort) {
            $query->orderBy($this->qualifySortColumn($sort['column']), $sort['direction']);
        }

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

            // 하위 메뉴의 역할은 목록에서 로드하지 않는다 — 화면이 쓰는 것은 하위 메뉴의
            // 개수와 표시 정보뿐이고, 역할 배지는 선택한(상위) 메뉴에만 나온다. 로드하면
            // 상위 메뉴마다 하위 전체의 역할 피벗까지 함께 실려 응답이 배가 된다.
            // 접근 제어(accessibleBy)는 whereHas 로 결과 집합을 좁히므로 그대로 유지된다.
            // Resource 는 로드되지 않은 하위 메뉴의 `roles` 키를 생략한다 — 위 메서드와 동일.
            $childQuery->orderBy('order');
        }])->get();
    }

    /**
     * 필터 연산자를 쿼리에 적용합니다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $field  필드명
     * @param  string  $value  검색 값
     * @param  string  $operator  연산자 (like, eq, starts_with, ends_with)
     */
    /**
     * 검색 대상 필드에 조건을 적용합니다. (다국어 JSON 컬럼 인지)
     *
     * `name` 은 다국어 JSON 컬럼이다. JSON 은 유니코드 이스케이프(`\uXXXX`)로 저장되므로
     * 컬럼 전체에 LIKE 를 걸면 한글·일본어 검색어가 **절대 매칭되지 않는다**
     * (저장된 바이트는 `{"ko":"대시보드"}` 이다).
     * 지원 로케일별 JSON 경로(`name->ko`)로 나눠 검색한다 — 저장소 공통 관례다.
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $field  필드명
     * @param  string  $value  검색 값
     * @param  string  $operator  연산자 (like, eq, starts_with, ends_with)
     */
    private function applySearchableField($query, string $field, string $value, string $operator): void
    {
        if (! in_array($field, self::TRANSLATABLE_COLUMNS, true)) {
            $this->applyFilterOperator($query, $field, $value, $operator);

            return;
        }

        $query->where(function ($q) use ($field, $value, $operator) {
            foreach ($this->translatableLocales() as $locale) {
                $q->orWhere(function ($subQ) use ($field, $locale, $value, $operator) {
                    $this->applyFilterOperator($subQ, "{$field}->{$locale}", $value, $operator);
                });
            }
        });
    }

    /**
     * 정렬 컬럼이 다국어 JSON 컬럼이면 현재 로케일 경로로 바꿉니다.
     *
     * JSON 원문으로 정렬하면 `{"ko":"\uXXXX...` 문자열 순서가 되어 사람이 읽는 순서와
     * 무관해진다. 화면에 보이는 값(현재 로케일)으로 정렬해야 목록 순서가 납득 가능해진다.
     *
     * @param  string  $column  정렬 컬럼
     * @return string 실제 정렬에 쓸 컬럼 표현
     */
    private function qualifySortColumn(string $column): string
    {
        if (! in_array($column, self::TRANSLATABLE_COLUMNS, true)) {
            return $column;
        }

        return $column.'->'.app()->getLocale();
    }

    /**
     * 다국어 필드가 값을 가질 수 있는 로케일 목록을 반환합니다.
     *
     * 다국어 JSON 필드의 언어 집합은 `translatable_locales` 다 — 번역 파일이 없어도
     * 데이터는 저장될 수 있으므로 UI 표시 언어(`supported_locales`)보다 넓다.
     *
     * @return array<int, string> 로케일 코드 목록
     */
    private function translatableLocales(): array
    {
        $locales = config('app.translatable_locales', config('app.supported_locales', []));

        return empty($locales) ? [app()->getLocale()] : array_values($locales);
    }

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
