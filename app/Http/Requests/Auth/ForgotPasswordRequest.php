<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    /**
     * 요청 권한 확인 — 공개 엔드포인트라 항상 통과시킨다(접근 제어는 미들웨어가 담당).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 가입 여부는 검증하지 않는다 — 여기서 가르면 응답으로 계정 존재를 알 수 있다.
        // 형식 오류만 거르고, 가입되지 않은 주소도 같은 응답을 받는다(AuthService::forgotPassword).
        $rules = [
            'email' => ['required', 'email', 'max:255'],
            'redirect_prefix' => ['nullable', 'string', 'in:admin'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.auth.forgot_password_validation_rules', $rules, $this);
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
        ];
    }
}
