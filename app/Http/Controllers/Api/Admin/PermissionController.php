<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\PermissionService;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * 관리자용 권한 관리 컨트롤러
 *
 * 권한 목록을 계층형 트리 구조로 제공합니다.
 */
class PermissionController extends AdminBaseController
{
    public function __construct(
        private PermissionService $permissionService
    ) {
        parent::__construct();
    }

    /**
     * 계층형 권한 트리를 조회합니다.
     *
     * @return JsonResponse 계층형 권한 트리를 포함한 JSON 응답
     */
    public function index(): JsonResponse
    {
        try {
            $permissionTree = $this->permissionService->getPermissionTree();

            return $this->success('permission.fetch_success', [
                'data' => $permissionTree,
            ]);
        } catch (Exception $e) {
            return $this->error('permission.fetch_failed', 500, $e->getMessage());
        }
    }
}
