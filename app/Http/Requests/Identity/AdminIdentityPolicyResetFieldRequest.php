<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S1d "↺ 기본값으로 되돌리기" 요청 검증.
 *
 * 관리자가 특정 필드의 user_overrides 를 해제하고 선언 기본값으로 복원할 때 사용됩니다.
 * 인증/권한은 라우트의 permission 미들웨어 체인이 담당합니다.
 */
class AdminIdentityPolicyResetFieldRequest extends FormRequest
{
    /**
     * 인증/권한은 route middleware 가 담당 — FormRequest 는 true 고정.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', 'in:enabled,grace_minutes,provider_id,fail_mode,conditions'],
        ];
    }
}
