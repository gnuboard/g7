<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Http\Controllers\Admin\RespondsWithAdminErrors;
use Plugins\Sirsoft\Sso\Http\Requests\Me\LinkStartRequest;
use Plugins\Sirsoft\Sso\Services\Admin\MyAccountService;

/**
 * 마이페이지 「연결된 회사 계정」 API — 내 연결 목록, 연결 시작(1회용 시작 티켓), 연결 해제.
 *
 * @since 1.0.0
 */
class MeController extends AuthBaseController
{
    use RespondsWithAdminErrors;

    /**
     * @param  MyAccountService  $service  내 연결
     */
    public function __construct(private readonly MyAccountService $service)
    {
        parent::__construct();
    }

    /**
     * 내 연결과 연결할 수 있는 IdP.
     *
     * @return JsonResponse `{accounts, linkable, enforced}`
     */
    public function index(): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.me.loaded', $this->service->overview($this->getCurrentUser()));
    }

    /**
     * 연결 시작 주소를 발급합니다.
     *
     * @param  LinkStartRequest  $request  검증된 요청 (`idp`)
     * @return JsonResponse `{start_url}` 또는 409·422
     */
    public function start(LinkStartRequest $request): JsonResponse
    {
        try {
            $result = $this->service->startLink($this->getCurrentUser(), (string) $request->validated('idp'), $request);
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.me.link_started', $result);
    }

    /**
     * 내 연결을 해제합니다.
     *
     * @param  int  $id  연결 ID
     * @return JsonResponse `{id}` 또는 404·422
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->unlink($this->getCurrentUser(), $id);
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.me.unlinked', ['id' => $id]);
    }
}
