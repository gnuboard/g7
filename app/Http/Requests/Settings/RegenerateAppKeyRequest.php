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
     * @return bool 최고 관리자 역할일 경우 true
     */
    public function authorize(): bool
    {
        // 최고 관리자만 허용 (role_id = 1 또는 super_admin 권한)
        return $this->user()?->hasRole('super_admin');
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'password' => 'required|string',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.regenerate_app_key_validation_rules', $rules, $this);
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
        ];
    }
}
