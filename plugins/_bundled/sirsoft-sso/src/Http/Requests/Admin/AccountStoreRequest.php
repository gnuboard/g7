<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;

/**
 * 「연결 추가」 요청 — 기존 회원(user_id) 또는 새 회원 사전 등록(new_user) 중 하나.
 *
 * @since 1.0.0
 */
class AccountStoreRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어가 본다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'idp_id' => ['required', 'integer', Rule::exists(IdentityProvider::class, 'id')],
            'subject' => ['required', 'string', 'max:191'],
            'user_id' => ['nullable', 'integer', 'required_without:new_user', 'prohibits:new_user'],
            'new_user' => ['nullable', 'array', 'required_without:user_id'],
            'new_user.name' => ['nullable', 'string', 'max:50'],
            'new_user.email' => ['required_with:new_user', 'email', 'max:255'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $p = 'sirsoft-sso::admin.attributes.';

        return [
            'idp_id' => __($p.'idp_id'),
            'subject' => __($p.'subject'),
            'user_id' => __($p.'user_id'),
            'new_user' => __($p.'new_user'),
            'new_user.name' => __($p.'new_user_name'),
            'new_user.email' => __($p.'new_user_email'),
        ];
    }
}
