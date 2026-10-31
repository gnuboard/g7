<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 비밀번호 재설정 토큰 검증 요청
 */
class ValidateResetTokenRequest extends FormRequest
{
    /**
     * 요청 권한을 판정합니다.
     *
     * 공개 엔드포인트이므로 항상 통과시킨다 — 접근 제어는 미들웨어가 담당한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 재설정 토큰 검증 요청의 검증 규칙을 반환합니다.
     *
     * @return array<string, ValidationRule|array<mixed>|string> 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'token' => 'required|string',
            'email' => 'required|email',
            // 재설정 화면 구분값 — `admin` 이면 관리자 재설정 화면(거절 응답의 다른 로그인 수단 주소가 관리자 로그인)
            'redirect_prefix' => ['nullable', 'string', 'in:admin'],
        ];

        return HookManager::applyFiltersWithLegacyName(
            'core.auth.validate_reset_token_validation_rules',
            'core.auth.validate_reset_token_rules',
            $rules,
            $this
        );
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 규칙 키 => 메시지
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
