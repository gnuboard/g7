<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 로그인 진입 판정 요청 검증
 *
 * 로그인 화면이 이메일(또는 사내 아이디) 칸을 벗어날 때 보내는 판정 요청이다.
 *
 * @since 7.2.0
 */
class LoginDiscoverRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다 (공개 — 로그인 전 화면).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'identifier' => ['required', 'string', 'max:255'],
        ];

        return HookManager::applyFilters('core.auth.login_discover_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'identifier.required' => __('validation.required', ['attribute' => __('auth.identifier')]),
            'identifier.max' => trans_choice('validation.max.string', 255, ['attribute' => __('auth.identifier'), 'max' => 255]),
        ];
    }
}
