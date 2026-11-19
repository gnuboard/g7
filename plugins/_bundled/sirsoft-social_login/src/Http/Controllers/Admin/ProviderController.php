<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns\RespondsWithFlowErrors;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Admin\CreateProviderRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Admin\DiscoverProviderRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Admin\ReorderProvidersRequest;
use Plugins\Sirsoft\SocialLogin\Http\Requests\Admin\UpdateProviderRequest;
use Plugins\Sirsoft\SocialLogin\Services\ProviderAdminService;

/**
 * 관리자 제공자 설정 — 목록·수정·일반 OIDC 추가·삭제·정렬·discovery·영향.
 *
 * 비밀값은 응답에 싣지 않는다(마스크 또는 null). 화면이 저장·테스트 버튼을 잠글 수 있게 `abilities.can_update` 를 함께 준다.
 *
 * @since 1.0.0
 */
class ProviderController extends AdminBaseController
{
    use RespondsWithFlowErrors;

    /** 변경 권한 */
    private const UPDATE_PERMISSION = 'sirsoft-social_login.settings.update';

    /**
     * @param  ProviderAdminService  $service  제공자 관리 서비스
     */
    public function __construct(private readonly ProviderAdminService $service)
    {
        parent::__construct();
    }

    /**
     * 제공자 목록(내장·커스텀·확장 등록)을 반환합니다.
     *
     * @return JsonResponse `{providers, limits, abilities}`
     */
    public function index(): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.listed', $this->service->list() + [
            'abilities' => ['can_update' => PermissionHelper::check(self::UPDATE_PERMISSION)],
        ]);
    }

    /**
     * 제공자 설정을 바꿉니다.
     *
     * @param  UpdateProviderRequest  $request  검증된 요청
     * @param  string  $key  제공자 키
     * @return JsonResponse 바뀐 제공자 또는 404·422
     */
    public function update(UpdateProviderRequest $request, string $key): JsonResponse
    {
        try {
            $provider = $this->service->update($key, $request->validated());
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.updated', $provider);
    }

    /**
     * 일반 OIDC 제공자를 추가합니다.
     *
     * @param  CreateProviderRequest  $request  검증된 요청
     * @return JsonResponse 만든 제공자(201) 또는 422
     */
    public function store(CreateProviderRequest $request): JsonResponse
    {
        try {
            $provider = $this->service->create($request->validated());
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.created', $provider, 201);
    }

    /**
     * 일반 OIDC 제공자를 지웁니다.
     *
     * @param  string  $key  제공자 키
     * @return JsonResponse `{key}` 또는 404·409·422
     */
    public function destroy(string $key): JsonResponse
    {
        try {
            $this->service->delete($key);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.deleted', ['key' => $key]);
    }

    /**
     * 표시 순서를 바꿉니다.
     *
     * @param  ReorderProvidersRequest  $request  검증된 요청
     * @return JsonResponse `{keys}` 저장된 순서
     */
    public function reorder(ReorderProvidersRequest $request): JsonResponse
    {
        $keys = $this->service->reorder($request->validated('keys'));

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.reordered', ['keys' => $keys]);
    }

    /**
     * discovery 문서를 읽어 주소 칸을 채울 값을 돌려줍니다.
     *
     * @param  DiscoverProviderRequest  $request  검증된 요청
     * @return JsonResponse 주소 값 또는 422
     */
    public function discover(DiscoverProviderRequest $request): JsonResponse
    {
        try {
            $endpoints = $this->service->discover((string) $request->validated('discovery_url'));
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.discovered', $endpoints);
    }

    /**
     * 제공자를 끄거나 자격증명을 바꾸기 전의 영향 수치를 돌려줍니다.
     *
     * @param  string  $key  제공자 키
     * @return JsonResponse `{social_only_users, linked_users}` 또는 404
     */
    public function impact(string $key): JsonResponse
    {
        try {
            $impact = $this->service->impact($key);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.providers.impact', $impact);
    }
}
