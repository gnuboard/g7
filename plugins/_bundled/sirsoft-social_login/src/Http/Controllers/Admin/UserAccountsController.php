<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\SocialLogin\Exceptions\SocialFlowException;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Concerns\RespondsWithFlowErrors;
use Plugins\Sirsoft\SocialLogin\Services\AccountLinkService;

/**
 * 관리자 회원 상세 「소셜 로그인」 탭 — 회원의 연결 열람·강제 해제.
 *
 * @since 1.0.0
 */
class UserAccountsController extends AdminBaseController
{
    use RespondsWithFlowErrors;

    /** 강제 해제 권한 */
    private const UNLINK_PERMISSION = 'sirsoft-social_login.accounts.unlink';

    /**
     * @param  AccountLinkService  $links  연결 서비스
     */
    public function __construct(private readonly AccountLinkService $links)
    {
        parent::__construct();
    }

    /**
     * 회원 목록 가입경로 필터의 선택지(연결이 있는 제공자)를 반환합니다.
     *
     * @return JsonResponse `[{key, label, count}]`
     */
    public function providerOptions(): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.user_accounts.options', $this->links->linkedProviderOptions());
    }

    /**
     * 회원의 연결 목록을 반환합니다.
     *
     * @param  string  $uuid  회원 UUID
     * @return JsonResponse `{accounts, has_password, abilities}` 또는 404
     */
    public function index(string $uuid): JsonResponse
    {
        $user = $this->links->findUserByUuid($uuid);
        if ($user === null) {
            return ResponseHelper::notFound('user.not_found');
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.user_accounts.listed', $this->links->adminSummaryFor($user) + [
            'abilities' => ['can_unlink' => PermissionHelper::check(self::UNLINK_PERMISSION)],
        ]);
    }

    /**
     * 회원의 연결을 강제로 끊습니다 (마지막 수단 가드 없음).
     *
     * @param  string  $uuid  회원 UUID
     * @param  string  $key  제공자 키
     * @return JsonResponse `{provider_key}` 또는 404
     */
    public function destroy(string $uuid, string $key): JsonResponse
    {
        $user = $this->links->findUserByUuid($uuid);
        if ($user === null) {
            return ResponseHelper::notFound('user.not_found');
        }

        try {
            $this->links->adminUnlink($user, $key);
        } catch (SocialFlowException $e) {
            return $this->flowError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-social_login', 'messages.admin.user_accounts.unlinked', ['provider_key' => $key]);
    }
}
