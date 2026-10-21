<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * 비회원 역할의 권한 부여에 스코프(본인·소유역할)가 실리면 거부하는 FormRequest 공용 동작
 *
 * 비회원에게는 「본인」 을 판정할 신원이 없다. 스코프를 저장해도 코어 판정 경로(PermissionHelper·
 * GuestRoleResolver)가 비회원에서 스코프를 건너뛰므로 「본인만」 이 실제로는 「전체」 가 된다
 * — 비회원 역할에 「비밀글 조회 = 본인만」 을 주면 비밀번호 없이 모든 비밀글이 열렸다. 그래서 저장
 * 자체를 막는다(7.1.1). 오류는 실린 항목마다 `permissions.{N}.scope_type` 에 붙인다.
 *
 * @since 7.1.1
 */
trait RejectsGuestPermissionScope
{
    /**
     * 스코프를 받을 수 없는 역할이면 스코프가 실린 권한 항목마다 오류를 더합니다.
     *
     * @param  Validator  $validator  검증기
     * @param  bool  $supportsScope  대상 역할이 스코프를 지원하는지 여부
     * @return void 반환값 없음
     */
    protected function rejectGuestPermissionScope(Validator $validator, bool $supportsScope): void
    {
        if ($supportsScope) {
            return;
        }

        $permissions = $this->input('permissions');
        if (! is_array($permissions)) {
            return;
        }

        foreach ($permissions as $index => $permission) {
            if (is_array($permission) && ($permission['scope_type'] ?? null) !== null) {
                $validator->errors()->add(
                    "permissions.{$index}.scope_type",
                    __('role.validation.guest_scope_not_allowed')
                );
            }
        }
    }
}
