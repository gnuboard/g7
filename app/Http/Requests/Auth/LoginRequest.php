<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'email' => 'required|email',
            // 로그인은 기존 비밀번호를 대조하는 행위이지 새 정책을 강제하는 지점이 아니다.
            // 길이 규칙을 두면 정책 미달 비밀번호에 422 를 주어 미인증 공격자에게 그 사실을 누설한다.
            'password' => 'required|string',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.auth.login_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.auth.email.required'),
            'email.email' => __('validation.auth.email.email'),
            'password.required' => __('validation.auth.password.required'),
        ];
    }
}
