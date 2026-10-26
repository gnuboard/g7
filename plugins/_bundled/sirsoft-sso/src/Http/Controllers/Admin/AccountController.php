<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\AccountImportRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\AccountListRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\AccountStoreRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\AccountUnlinkRequest;
use Plugins\Sirsoft\Sso\Services\Admin\AccountAdminService;
use Plugins\Sirsoft\Sso\Services\Admin\CsvImportService;

/**
 * 관리자 「회원 연결」 API — 목록, 연결 추가와 사전 등록, CSV 가져오기, 연결 해제.
 *
 * @since 1.0.0
 */
class AccountController extends AdminBaseController
{
    use RespondsWithAdminErrors;

    /**
     * @param  AccountAdminService  $service  연결 관리
     * @param  CsvImportService  $import  CSV 가져오기
     */
    public function __construct(
        private readonly AccountAdminService $service,
        private readonly CsvImportService $import,
    ) {
        parent::__construct();
    }

    /**
     * 연결 목록.
     *
     * @param  AccountListRequest  $request  검증된 조건
     * @return JsonResponse `{data, pagination, abilities}`
     */
    public function index(AccountListRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.accounts.listed', $this->service->list($request->validated()) + [
            'abilities' => [
                'can_provision' => PermissionHelper::check('sirsoft-sso.accounts.provision'),
                'can_unlink' => PermissionHelper::check('sirsoft-sso.accounts.unlink'),
                'is_super' => (bool) $this->getCurrentUser()?->isSuperAdmin(),
            ],
        ]);
    }

    /**
     * 연결을 추가합니다 (회원이 없으면 비밀번호 없이 사전 등록).
     *
     * @param  AccountStoreRequest  $request  검증된 요청
     * @return JsonResponse 만든 연결(201) 또는 403·404·409·422
     */
    public function store(AccountStoreRequest $request): JsonResponse
    {
        try {
            $row = $this->service->link($request->validated(), $this->getCurrentUser());
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.accounts.created', $row, 201);
    }

    /**
     * 연결을 해제합니다.
     *
     * @param  AccountUnlinkRequest  $request  검증된 요청 (`confirm_last_method`)
     * @param  int  $id  연결 ID
     * @return JsonResponse `{id}` 또는 404·409
     */
    public function destroy(AccountUnlinkRequest $request, int $id): JsonResponse
    {
        try {
            $this->service->unlink($id, (bool) $request->validated('confirm_last_method', false));
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.accounts.deleted', ['id' => $id]);
    }

    /**
     * CSV 미리보기(`mode=preview`) 또는 실행(`mode=execute`).
     *
     * @param  AccountImportRequest  $request  검증된 요청 (`csv`, `mode`)
     * @return JsonResponse `{rows, counts, executed}`
     */
    public function import(AccountImportRequest $request): JsonResponse
    {
        try {
            $result = $this->import->import((string) $request->validated('csv'), $request->validated('mode') === 'execute', $this->getCurrentUser());
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', $result['executed'] ? 'admin.accounts.imported' : 'admin.accounts.previewed', $result);
    }
}
