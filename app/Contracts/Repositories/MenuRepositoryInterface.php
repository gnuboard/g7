<?php

namespace App\Contracts\Repositories;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface MenuRepositoryInterface
{
    /**
     * 모든 메뉴를 조회합니다.
     *
     * @return Collection 메뉴 컬렉션 (관계 데이터 포함)
     */
    public function getAll(): Collection;

    /**
     * 최상위 메뉴들을 조회합니다.
     *
     * @return Collection 최상위 메뉴 컬렉션 (활성화된 것만)
     */
    public function getTopLevelMenus(): Collection;

    public function getTopLevelMenusForManagement(array $activeModuleIdentifiers = [], array $activePluginIdentifiers = [], ?User $user = null): Collection;

    /**
     * 활성화된 메뉴들만 조회합니다.
     *
     * @return Collection 활성화된 메뉴 컬렉션
     */
    public function getActiveMenus(): Collection;

    /**
     * ID로 메뉴를 찾습니다.
     *
     * @param  int  $id  메뉴 ID
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findById(int $id): ?Menu;

    /**
     * 슬러그로 메뉴를 찾습니다.
     *
     * @param  string  $slug  메뉴 슬러그
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findBySlug(string $slug): ?Menu;

    /**
     * 새로운 메뉴를 생성합니다.
     *
     * @param  array  $data  메뉴 생성 데이터
     * @return Menu 생성된 메뉴 모델
     */
    public function create(array $data): Menu;

    /**
     * 기존 메뉴를 업데이트합니다.
     *
     * @param  Menu  $menu  업데이트할 메뉴 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Menu $menu, array $data): bool;

    /**
     * 메뉴를 삭제합니다.
     *
     * @param  Menu  $menu  삭제할 메뉴 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Menu $menu): bool;

    /**
     * 메뉴의 순서를 업데이트합니다.
     *
     * @param  array  $menuOrders  메뉴 ID와 순서 매핑 배열
     * @return bool 업데이트 성공 여부
     */
    public function updateOrder(array $menuOrders): bool;

    /**
     * 계층 구조를 고려한 메뉴 순서를 업데이트합니다.
     *
     * @param  array  $orderData  계층 구조 순서 데이터
     * @return bool 업데이트 성공 여부
     */
    public function updateOrderWithHierarchy(array $orderData): bool;

    /**
     * 특정 확장에 속한 메뉴들을 조회합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return Collection 확장에 속한 메뉴 컬렉션
     */
    public function getMenusByExtension(ExtensionOwnerType $type, string $identifier): Collection;

    /**
     * 부모 메뉴의 자식 메뉴들을 조회합니다.
     *
     * @param  int  $parentId  부모 메뉴 ID
     * @return Collection 자식 메뉴 컬렉션
     */
    public function getChildrenByParent(int $parentId): Collection;

    /**
     * 네비게이션용 활성화된 메뉴들을 자식 메뉴와 함께 조회합니다.
     *
     * @return Collection 활성화된 메뉴 컬렉션 (자식 메뉴 포함)
     */
    public function getActiveMenusWithChildren(): Collection;

    /**
     * 사용자가 접근 가능한 네비게이션용 메뉴들을 조회합니다.
     *
     * @param  User  $user  접근 권한을 확인할 사용자
     * @return Collection 사용자가 접근 가능한 메뉴 컬렉션
     */
    public function getAccessibleNavigationMenus(User $user): Collection;

    /**
     * 메뉴를 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Menu 생성 또는 업데이트된 메뉴 모델
     */
    public function updateOrCreate(array $attributes, array $values): Menu;

    /**
     * slug와 확장 정보로 메뉴를 찾습니다.
     *
     * @param  string  $slug  메뉴 슬러그
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @return Menu|null 찾은 메뉴 모델 또는 null
     */
    public function findBySlugAndExtension(string $slug, ExtensionOwnerType $extensionType, string $extensionIdentifier): ?Menu;

    /**
     * 특정 확장의 모든 메뉴를 삭제합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string  $identifier  확장 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByExtension(ExtensionOwnerType $type, string $identifier): int;

    /**
     * 같은 부모 내에서 최대 순서 값을 조회합니다.
     *
     * @param  int|null  $parentId  부모 메뉴 ID (null이면 최상위)
     * @return int 최대 순서 값 (없으면 0)
     */
    public function getMaxOrder(?int $parentId = null): int;

    public function getFilteredTopLevelMenus(array $filters, array $activeModuleIdentifiers = [], array $activePluginIdentifiers = [], ?User $user = null): Collection;
}
