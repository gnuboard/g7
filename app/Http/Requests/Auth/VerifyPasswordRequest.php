<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
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
        // 비밀번호가 없는 회원(외부 인증 전용)은 비밀번호 대신 이메일 인증번호 확인으로 받은 토큰을 보낸다.
        // 그 회원은 빈 본문도 받는다 — 관문이 본인인증(428)을 요구해 토큰을 받아 오게 한다(옛 화면의 「본인 확인 계속하기」).
        $user = $this->user();
        $rules = [
            'password' => $user instanceof User && ! $user->hasPassword()
                ? 'nullable|string'
                : 'required_without:verification_token|nullable|string',
            'verification_token' => 'nullable|string|max:128',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFiltersWithLegacyName(
            'core.auth.verify_password_validation_rules',
            'core.auth.verify_password_rules',
            $rules);
    }
}
