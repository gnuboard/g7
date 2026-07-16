<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 비밀번호 재설정 토큰 검증 요청
 */
class ValidateResetTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true; // 공개 엔드포인트
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'token' => 'required|string',
            'email' => 'required|email',
        ];

        return HookManager::applyFilters(
            'core.auth.validate_reset_token_rules',
            $rules,
            $this
        );
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'token.required' => __('validation.auth.token.required'),
            'token.string' => __('validation.string', ['attribute' => __('validation.attributes.token')]),
            'email.required' => __('validation.auth.email.required'),
            'email.email' => __('validation.auth.email.email'),
        ];

        return HookManager::applyFilters(
            'core.auth.validate_reset_token_messages',
            $messages,
            $this
        );
    }
}
