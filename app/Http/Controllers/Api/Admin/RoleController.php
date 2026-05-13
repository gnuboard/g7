<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ExtensionOwnedRoleDeleteException;
use App\Exceptions\SystemRoleDeleteException;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Role\RoleListRequest;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleCollection;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 관리자용 역할 관리 컨트롤러
 *
 * 관리자가 시스템 역할들을 관리할 수 있는 기능을 제공합니다.
 */
class RoleController extends AdminBaseController
{
    public function __construct(
        private RoleService $roleService
    ) {
        parent::__construct();
    }

    /**
     * 페이지네이션된 역할 목록을 조회합니다.
     *
     * @param  RoleListRequest  $request  역할 목록 요청 데이터
     * @return JsonResponse 역할 목록을 포함한 JSON 응답
     */
    public function index(RoleListRequest $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'is_active']);
            $perPage = $request->input('per_page', 20);

            $roles = $this->roleService->getPaginatedRoles($filters, $perPage);

            return $this->successWithResource(
                'role.fetch_success',
                new RoleCollection($roles)
            );
        } catch (Exception $e) {
            return $this->error('role.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 활성화된 역할 목록을 조회합니다. (선택 옵션용)
     *
     * core.permissions.read 권한 보유 시 전체 활성 역할을 반환하고,
     * 미보유 시 현재 사용자에게 부여된 역할만 반환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return JsonResponse 활성화된 역할 목록을 포함한 JSON 응답
     */
    public function active(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();

            // 역할 관리 권한(core.permissions.read) 보유 → 전체 활성 역할 (사용자 관리용)
            // 미보유 → 자기에게 부여된 역할만 (자기 정보 수정 폼 표시용)
            $roles = PermissionHelper::check('core.permissions.read')
                ? $this->roleService->getActiveRoles()
                : $this->roleService->getUserActiveRoles($user);

            return $this->success('role.fetch_success', [
                'data' => RoleResource::collection($roles),
                'abilities' => [
                    'can_assign_roles' => PermissionHelper::check('core.permissions.update'),
                ],
            ]);
        } catch (Exception $e) {
            return $this->error('role.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 특정 역할의 상세 정보를 조회합니다.
     *
     * @param  Role  $role  조회할 역할 모델
     * @return JsonResponse 역할 상세 정보를 포함한 JSON 응답
     */
    public function show(Role $role): JsonResponse
    {
        try {
            $role = $this->roleService->getRoleWithPermissions($role);

            return $this->successWithResource(
                'role.fetch_success',
                new RoleResource($role)
            );
        } catch (Exception $e) {
            return $this->error('role.fetch_failed', 500, $e->getMessage());
        }
    }

    /**
     * 새로운 역할을 생성합니다.
     *
     * @param  StoreRoleRequest  $request  역할 생성 요청 데이터
     * @return JsonResponse 생성된 역할 정보를 포함한 JSON 응답
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        try {
            $role = $this->roleService->createRole($request->validated());

            return $this->successWithResource(
                'role.create_success',
                new RoleResource($role),
                201
            );
        } catch (ValidationException $e) {
            return $this->error('role.create_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('role.create_failed', 500, $e->getMessage());
        }
    }

    /**
     * 기존 역할 정보를 수정합니다.
     *
     * @param  UpdateRoleRequest  $request  역할 수정 요청 데이터
     * @param  Role  $role  수정할 역할 모델
     * @return JsonResponse 수정된 역할 정보를 포함한 JSON 응답
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        try {
            $updatedRole = $this->roleService->updateRole($role, $request->validated());

            return $this->successWithResource(
                'role.update_success',
                new RoleResource($updatedRole)
            );
        } catch (ValidationException $e) {
            return $this->error('role.update_failed', 422, $e->errors());
        } catch (Exception $e) {
            return $this->error('role.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 역할의 활성화 상태를 토글합니다.
     *
     * @param  Role  $role  토글할 역할 모델
     * @return JsonResponse 토글 결과를 포함한 JSON 응답
     */
    public function toggleStatus(Role $role): JsonResponse
    {
        try {
            $result = $this->roleService->toggleRoleStatus($role);

            if ($result) {
                $role->refresh();
                $role->loadCount('users');

                return $this->successWithResource(
                    'role.update_success',
                    new RoleResource($role)
                );
            } else {
                return $this->error('role.update_failed');
            }
        } catch (Exception $e) {
            return $this->error('role.update_failed', 500, $e->getMessage());
        }
    }

    /**
     * 역할을 삭제합니다.
     *
     * @param  Role  $role  삭제할 역할 모델
     * @return JsonResponse 삭제 결과 JSON 응답
     */
    public function destroy(Role $role): JsonResponse
    {
        try {
            $this->roleService->deleteRole($role);

            return $this->success('role.delete_success');
        } catch (SystemRoleDeleteException $e) {
            return $this->error('role.system_role_delete_error', 403, $e->getMessage());
        } catch (ExtensionOwnedRoleDeleteException $e) {
            return $this->error('role.extension_owned_role_delete_error', 403, $e->getMessage());
        } catch (Exception $e) {
            return $this->error('role.delete_failed', 500, $e->getMessage());
        }
    }
}
