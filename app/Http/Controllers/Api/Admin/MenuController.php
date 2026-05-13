<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Menu\CreateMenuRequest;
use App\Http\Requests\Menu\MenuListRequest;
use App\Http\Requests\Menu\UpdateMenuOrderRequest;
use App\Http\Requests\Menu\UpdateMenuRequest;
use App\Http\Resources\MenuCollection;
use App\Http\Resources\MenuResource;
use App\Models\Menu;
use App\Services\MenuService;
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
    public function __construct(
        private MenuService $menuService
    ) {
        parent::__construct();
    }

    public function index(MenuListRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $user = Auth::user();

            // 필터가 있으면 필터링된 메뉴 조회, 없으면 전체 조회
            if (isset($filters['is_active']) || ! empty($filters['filters'])) {
                $menus = $this->menuService->getFilteredMenusForManagement($filters, $user);
            } else {
                $menus = $this->menuService->getTopLevelMenusForManagement($user);
            }

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
     * 활성화된 메뉴들을 네비게이션용으로 반환합니다.
     *
     * @return JsonResponse 네비게이션용 메뉴 데이터를 포함한 JSON 응답
     */
    public function active(): JsonResponse
    {
        try {
            // 현재 사용자 정보 가져오기
            $user = Auth::user();

            if ($user) {
                // 사용자가 접근 가능한 메뉴만 조회
                $navigationMenus = $this->menuService->getAccessibleNavigationMenus($user);
            } else {
                // 사용자 정보가 없으면 모든 활성화된 메뉴 조회 (fallback)
                $navigationMenus = $this->menuService->getNavigationMenus();
            }

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
        } catch (\Exception $e) {
            return $this->error('menu.update_error', 500, $e->getMessage());
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
