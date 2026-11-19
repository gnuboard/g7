<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 앱 키 재생성 요청 FormRequest
 *
 * 앱 키 재생성 시 비밀번호 검증을 위한 요청 클래스입니다.
 */
class RegenerateAppKeyRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 항상 true (최고관리자 판정은 라우트의 `super.admin` 미들웨어가 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 비밀번호가 없는 최고관리자(외부 인증 전용)는 비밀번호 대신 이메일 인증번호 확인으로 받은 토큰을 보낸다.
        $rules = [
            'password' => 'required_without:verification_token|nullable|string',
            'verification_token' => 'nullable|string|max:128',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.regenerate_app_key_validation_rules', $rules, $this);
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => __('validation.attributes.password'),
            'verification_token' => __('validation.attributes.verification_token'),
        ];
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => __('settings.password_required'),
            'password.required_without' => __('settings.password_required'),
        ];
    }
}
