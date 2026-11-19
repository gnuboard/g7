<?php

namespace App\Http\Requests\ExtensionTrustKey;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 확장 신뢰 키(제작자 공개키) 삭제 요청 — 본인 재확인 입력만 받는다.
 *
 * @since 7.2.0
 */
class DestroyExtensionTrustKeyRequest extends FormRequest
{
    /**
     * 권한 판정은 라우트 미들웨어가 담당합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        $rules = [
            // 비밀번호 없는 관리자(외부 인증 전용)는 아무것도 보내지 않는다 — 컨트롤러가 본인인증(428)을 요구하고, 엔진이 받은
            // 인증 토큰(verification_token)을 붙여 다시 보낸다. 비밀번호가 있는 관리자가 비우면 컨트롤러가 422 로 답한다
            'password' => ['nullable', 'string'],
            'verification_token' => ['nullable', 'string', 'max:128'],
        ];

        return HookManager::applyFilters('core.extension_trust_keys.destroy_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'password.string' => __('extension_integrity.validation.password_required'),
        ];
    }

    /**
     * 검증 오류 문구의 항목 이름.
     *
     * @return array<string, string> 항목 이름
     */
    public function attributes(): array
    {
        return [
            'password' => __('extension_integrity.attributes.password'),
        ];
    }
}
