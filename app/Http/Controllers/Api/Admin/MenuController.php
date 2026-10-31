<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Menu\BulkUpdateMenuRolesRequest;
use App\Http\Requests\Menu\CreateMenuRequest;
use App\Http\Requests\Menu\MenuListRequest;
use App\Http\Requests\Menu\UpdateMenuOrderRequest;
use App\Http\Requests\Menu\UpdateMenuRequest;
use App\Http\Resources\MenuCollection;
use App\Http\Resources\MenuResource;
use App\Models\Menu;
use App\Models\User;
use App\Services\MenuService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 메뉴 컨트롤러
 *
 * 관리자가 시스템 메뉴를 생성, 수정, 삭제, 순서 변경할 수 있는 기능을 제공합니다.
 */
class MenuController extends AdminBaseController
{
    /**
     * 메뉴 컨트롤러를 생성합니다.
     *
     * @param  MenuService  $menuService  메뉴 서비스
     */
    public function __construct(
        private MenuService $menuService
    ) {
        parent::__construct();
    }

    /**
     * 관리용 메뉴 목록을 조회합니다.
     *
     * @param  MenuListRequest  $request  목록 필터·정렬 요청
     * @return JsonResponse 메뉴 컬렉션을 담은 JSON 응답
     */
    public function index(MenuListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $user = Auth::user();

            // 필터 유무와 무관하게 같은 경로로 조회한다. 예전에는 필터가 없으면 filters 를
            // 받지 않는 메서드로 분기했는데, 그 경로는 sort_by/sort_order 를 통째로 버려
            // "필터를 하나라도 걸어야 정렬이 먹는" 상태가 됐다 (#492 D-22).
            $menus = $this->menuService->getFilteredMenusForManagement($filters, $user);

            return $this->successWithResource(
                'menu.fetch_success',
                new MenuCollection($menus)
            );
        } catch (\Exception $e) {
            return $this->error('menu.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 최상위 메뉴들을 계층 구조로 반환합니다.
     *
     * @return JsonResponse 계층 구조로 정리된 메뉴 데이터를 포함한 JSON 응답
     */
    public function hierarchy(): JsonResponse
    {
        try {
            $menuHierarchy = $this->menuService->getMenuHierarchy();
            $collection = new MenuCollection($menuHierarchy);

            // 계층 구조로 변환
            $hierarchicalData = $collection->toNavigationArray();

            return $this->success('menu.fetch_success', $hierarchicalData);
        } catch (\Exception $e) {
            return $this->error('menu.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 사이드바용으로 현재 사용자의 역할에 지정된 활성 메뉴를 반환합니다.
     *
     * 메뉴 관리 권한(core.menus.read) 없이 관리자 영역 접근만으로 부른다 — 「접근 가능 메뉴」로
     * 메뉴를 지정받은 역할의 사이드바가 그 권한 유무에 따라 비지 않게 하기 위해서다.
     *
     * @return JsonResponse 네비게이션용 메뉴 데이터를 포함한 JSON 응답
     */
    public function active(): JsonResponse
    {
        try {
            // 이 엔드포인트는 메뉴 관리 권한을 요구하지 않으므로(관리자 영역 접근만) 응답을
            // 반드시 내 역할에 지정된 메뉴로 좁힌다. 인증 사용자가 없을 때 전체 메뉴를 내주던
            // 대체 경로는 두지 않는다 — 인증 미들웨어 밖에서 불려도 빈 목록이다.
            $user = Auth::user();

            if (! $user instanceof User) {
                return $this->success('menu.fetch_success', []);
            }

            $navigationMenus = $this->menuService->getAccessibleNavigationMenus($user);

            $collection = new MenuCollection($navigationMenus);

            // 네비게이션용 계층 구조로 변환
            $navigationData = $collection->toNavigationArray();

            return $this->success('menu.fetch_success', $navigationData['data']);
        } catch (\Exception $e) {
            return $this->error('menu.active_fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 새로운 메뉴를 생성합니다.
     *
     * @param  CreateMenuRequest  $request  메뉴 생성 요청 데이터
     * @return JsonResponse 생성된 메뉴 정보를 포함한 JSON 응답
     */
    public function store(CreateMenuRequest $request): JsonResponse
    {
        try {
            $menu = $this->menuService->createMenu($request->validated());
            $menu->load(['creator', 'parent', 'children', 'roles']);

            return $this->successWithResource(
                'menu.create_success',
                new MenuResource($menu),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('menu.create_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('menu.create_error', 500, $e->getMessage());
        }
    }

    /**
     * 특정 메뉴의 상세 정보를 조회합니다.
     *
     * @param  Menu  $menu  조회할 메뉴 모델
     * @return JsonResponse 메뉴 상세 정보를 포함한 JSON 응답
     */
    public function show(Menu $menu): JsonResponse
    {
        try {
            $menu->load(['creator', 'parent', 'children', 'roles']);

            return $this->successWithResource(
                'menu.fetch_success',
                new MenuResource($menu)
            );
        } catch (\Exception $e) {
            return $this->error('menu.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 기존 메뉴 정보를 업데이트합니다.
     *
     * @param  UpdateMenuRequest  $request  메뉴 업데이트 요청 데이터
     * @param  Menu  $menu  업데이트할 메뉴 모델
     * @return JsonResponse 업데이트된 메뉴 정보를 포함한 JSON 응답
     */
    public function update(UpdateMenuRequest $request, Menu $menu): JsonResponse
    {
        try {
            $result = $this->menuService->updateMenu($menu, $request->validated());

            if ($result) {
                $menu->refresh();
                $menu->load(['creator', 'parent', 'children', 'roles']);

                return $this->successWithResource(
                    'menu.update_success',
                    new MenuResource($menu)
                );
            } else {
                return $this->error('menu.update_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('menu.update_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('menu.update_error', 500, $e->getMessage());
        }
    }

    /**
     * 메뉴를 삭제합니다.
     *
     * @param  Menu  $menu  삭제할 메뉴 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(Menu $menu): JsonResponse
    {
        try {
            $menuId = $menu->id;
            $result = $this->menuService->deleteMenu($menu);

            if ($result) {
                return $this->success('menu.delete_success');
            } else {
                return $this->error('menu.delete_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('menu.delete_failed', 422, $e->errors());
        } catch (\Exception $e) {
            return $this->error('menu.delete_error', 500, $e->getMessage());
        }
    }

    /**
     * 메뉴의 순서를 업데이트합니다 (드래그 앤 드롭).
     *
     * @param  UpdateMenuOrderRequest  $request  메뉴 순서 업데이트 요청 데이터
     * @return JsonResponse 순서 업데이트 결과 JSON 응답
     */
    public function updateOrder(UpdateMenuOrderRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $result = $this->menuService->updateMenuOrderWithHierarchy($validatedData);

            if ($result) {
                return $this->success('menu.order_update_success');
            } else {
                return $this->error('menu.order_update_failed');
            }
        } catch (ValidationException $e) {
            return $this->error('menu.order_update_failed', 422, $e->errors());
        } catch (AuthorizationException $e) {
            // 스코프 밖 메뉴가 포함된 경우. 제네릭 catch 보다 앞에 둬야 인가 거부가 500 으로
            // 뭉개지지 않고 상세 경로(403)와 같은 응답이 된다.
            return $this->error('auth.scope_denied', 403, $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('menu.update_error', 500, $e->getMessage());
        }
    }

    /**
     * 여러 메뉴에 역할을 한꺼번에 더하거나 뺍니다.
     *
     * @param  BulkUpdateMenuRolesRequest  $request  메뉴·역할·작업(add|remove) 요청
     * @return JsonResponse 변경 메뉴 수와 자동으로 함께 처리된 부모·자식 메뉴 ID
     */
    public function bulkUpdateRoles(BulkUpdateMenuRolesRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->menuService->bulkUpdateRoles(
                $validated['menu_ids'],
                $validated['role_ids'],
                $validated['mode'],
            );

            return $this->success('menu.bulk_roles_success', $result, 200, ['count' => $result['updated_count']]);
        } catch (ValidationException $e) {
            return $this->error('menu.bulk_roles_failed', 422, $e->errors());
        } catch (AuthorizationException $e) {
            // 범위 밖 메뉴가 섞인 경우 — 순서 변경과 같은 이유로 제네릭 catch 보다 앞에 둔다.
            return $this->error('auth.scope_denied', 403, $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('menu.bulk_roles_failed', 500, $e->getMessage());
        }
    }

    /**
     * 메뉴의 활성화 상태를 토글합니다.
     *
     * @param  Menu  $menu  대상 메뉴 모델
     * @return JsonResponse 상태 변경된 메뉴 정보를 포함한 JSON 응답
     */
    public function toggleStatus(Menu $menu): JsonResponse
    {
        try {
            $result = $this->menuService->toggleMenuStatus($menu);

            if ($result) {
                $menu->refresh();

                return $this->successWithResource(
                    'menu.update_success',
                    new MenuResource($menu)
                );
            } else {
                return $this->error('menu.update_failed');
            }
        } catch (\Exception $e) {
            return $this->error('menu.update_error', 500, $e->getMessage());
        }
    }

    /**
     * 특정 확장에 속한 메뉴들을 조회합니다.
     *
     * @param  string  $type  확장 타입 (module, plugin)
     * @param  string  $identifier  확장 식별자
     * @return JsonResponse 확장에 속한 메뉴 목록을 포함한 JSON 응답
     */
    public function getByExtension(string $type, string $identifier): JsonResponse
    {
        try {
            $extensionType = ExtensionOwnerType::from($type);

            $menus = $this->menuService->getMenusByExtension($extensionType, $identifier);

            return $this->successWithResource(
                'menu.fetch_success',
                new MenuCollection($menus)
            );
        } catch (\ValueError $e) {
            return $this->error('menu.invalid_extension_type', 422, $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('menu.fetch_failed', 500, $e->getMessage());
        }
    }
}
