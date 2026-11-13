<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers\Admin;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\MemberNamePresenter;
use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\PolicyImpactRequest;
use Plugins\Sirsoft\Sso\Http\Requests\Admin\PolicyUpdateRequest;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

/**
 * 관리자 SSO 정책 API — 조회, 저장(W5, W34, W38), 영향 미리보기.
 *
 * 최고관리자 전용 칸(사이트 전체 강제, 역할 강제, 비상 계정)은 권한과 별개로 서비스가 `is_super` 를 본다 — 화면이 그 칸을 잠글 수 있게
 * `abilities.is_super` 를 함께 준다.
 *
 * @since 1.0.0
 */
class PolicyController extends AdminBaseController
{
    use RespondsWithAdminErrors;

    /**
     * @param  PolicyService  $policy  정책
     */
    public function __construct(private readonly PolicyService $policy)
    {
        parent::__construct();
    }

    /**
     * 정책을 반환합니다.
     *
     * @return JsonResponse `{policy, emergency_users, abilities}`
     */
    public function show(): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.policy.loaded', $this->payload($this->policy->current()));
    }

    /**
     * 정책을 저장합니다.
     *
     * @param  PolicyUpdateRequest  $request  검증된 요청
     * @return JsonResponse 저장된 정책 + `revoked_tokens`, 또는 403·422
     */
    public function update(PolicyUpdateRequest $request): JsonResponse
    {
        try {
            $result = $this->policy->update($request->validated(), $this->getCurrentUser());
        } catch (SsoException $e) {
            return $this->adminError($e);
        }

        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.policy.updated', $this->payload($result['policy']) + ['revoked_tokens' => $result['revoked_tokens']]);
    }

    /**
     * 정책 변경의 영향을 미리 셉니다.
     *
     * @param  PolicyImpactRequest  $request  검증된 요청 (바꿀 값)
     * @return JsonResponse `{password_users, password_sessions}`
     */
    public function impact(PolicyImpactRequest $request): JsonResponse
    {
        return ResponseHelper::pluginSuccess('sirsoft-sso', 'admin.policy.impact', $this->policy->impact($request->validated()));
    }

    /**
     * 응답 본문.
     *
     * @param  array<string, mixed>  $policy  정책
     * @return array<string, mixed> 본문
     */
    private function payload(array $policy): array
    {
        $actor = $this->getCurrentUser();

        $emergencyUsers = $this->policy->emergencyUsers($policy['emergency_user_ids']);
        $presenter = app(MemberNamePresenter::class);
        $presenter->preload($emergencyUsers, null, DisplayNameAreaRegistry::AREA_ADMIN);

        return [
            'policy' => $policy,
            // 이름은 다른 관리자 화면과 같이 관리자 영역 표시 이름 — 회원 조회 권한이 있으면 실명이 다를 때 `real_name` 이 따로 실린다
            'emergency_users' => $emergencyUsers
                ->map(fn (User $user) => [
                    'id' => (int) $user->getKey(),
                    'uuid' => (string) $user->uuid,
                    ...$presenter->fields($user, null, DisplayNameAreaRegistry::AREA_ADMIN),
                    'email' => (string) $user->email, // audit:allow resource-member-email-ungated 정책 화면(settings.view) 비상 계정 표시
                    'has_password' => $user->hasPassword(),
                ])->values()->all(),
            'abilities' => [
                'is_super' => $actor instanceof User && $actor->isSuperAdmin(),
                'can_update' => PermissionHelper::check(IdpController::UPDATE_PERMISSION),
            ],
        ];
    }
}
