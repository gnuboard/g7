<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\IdpStoreRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\IdpTestRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\IdpUpdateRequest;
use Plugins\Sirsoft\Sso\Services\Admin\IdpAdminService;

/**
 * 관리자 IdP 설정 API — 목록, 상세, 추가, 수정, 삭제, 연결 테스트, SP 키, SCIM 토큰.
 *
 * 비밀값은 응답에서 가린다. 화면이 저장, 테스트 버튼을 잠글 수 있게 `abilities.can_update` 를 함께 준다.
 *
 * @since 1.0.0
 */
class IdpController extends AdminBaseController
{
    use RespondsWithAdminErrors;

    /** 변경 권한 */
    public const UPDATE_PERMISSION = 'sirsoft-sso.settings.update';

    /**
     * @param  IdpAdminService  $service  IdP 관리
     */
    public function __construct(private readonly IdpAdminService $service)
    {
        parent::__construct();
    }

    /**
     * IdP 목록과 추가할 수 있는 프로토콜.
     *
     * @return JsonResponse `{idps, protocols, limits, abilities}`
     */
    public function index(): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.listed', $this->service->list() + [
            'abilities' => [
                'can_update' => PermissionHelper::check(self::UPDATE_PERMISSION),
                'is_super' => (bool) $this->getCurrentUser()?->isSuperAdmin(),
            ],
        ]);
    }

    /**
     * IdP 상세.
     *
     * @param  int  $id  IdP ID
     * @return JsonResponse 상세 또는 404
     */
    public function show(int $id): JsonResponse
    {
        try {
            $idp = $this->service->show($id, $this->getCurrentUser());
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        $idp['abilities']['can_update'] = PermissionHelper::check(self::UPDATE_PERMISSION);

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.loaded', $idp);
    }

    /**
     * IdP 를 추가합니다.
     *
     * @param  IdpStoreRequest  $request  검증된 요청
     * @return JsonResponse 만든 IdP(201) 또는 422
     */
    public function store(IdpStoreRequest $request): JsonResponse
    {
        try {
            $idp = $this->service->create($request->validated(), $this->getCurrentUser());
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.created', $idp, 201);
    }

    /**
     * IdP 를 고칩니다.
     *
     * @param  IdpUpdateRequest  $request  검증된 요청
     * @param  int  $id  IdP ID
     * @return JsonResponse 고친 IdP 또는 403·404·422
     */
    public function update(IdpUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $idp = $this->service->update($id, $request->validated(), $this->getCurrentUser());
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.updated', $idp);
    }

    /**
     * IdP 를 지웁니다.
     *
     * @param  int  $id  IdP ID
     * @return JsonResponse `{id}` 또는 404·409
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->delete($id);
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.deleted', ['id' => $id]);
    }

    /**
     * 연결 테스트 — `browser=true` 면 「브라우저로 로그인 테스트」 시작 주소.
     *
     * @param  IdpTestRequest  $request  검증된 요청
     * @param  int  $id  IdP ID
     * @return JsonResponse `{ok, checks}` 또는 `{start_url}`
     */
    public function test(IdpTestRequest $request, int $id): JsonResponse
    {
        try {
            $identifier = (string) $request->validated('account_identifier', '');
            $account = $identifier !== '' ? ['identifier' => $identifier, 'password' => (string) $request->validated('account_password', '')] : null;
            $result = $this->service->test($id, (bool) $request->validated('browser', false), $this->getCurrentUser(), $request, $account);
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.tested', $result);
    }

    /**
     * SAML SP 서명 키를 만듭니다.
     *
     * @param  int  $id  IdP ID
     * @return JsonResponse `{certificate, expires_at}`
     */
    public function spKeys(int $id): JsonResponse
    {
        try {
            $result = $this->service->generateSpKeys($id);
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.sp_keys_generated', $result);
    }

    /**
     * SCIM 토큰을 새로 발급합니다 (한 번만 보인다).
     *
     * @param  int  $id  IdP ID
     * @return JsonResponse `{token, issued_at, endpoint}`
     */
    public function scimToken(int $id): JsonResponse
    {
        try {
            $result = $this->service->issueScimToken($id);
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.idps.scim_token_issued', $result);
    }
}
