<?php

namespace App\Http\Requests\Identity;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 본인인증 채움값 조회 요청 검증.
 *
 * 자격은 본인인증을 마치고 받은 토큰(`verification_token`)이 증명한다 — 토큰을 가진 쪽이 인증한 당사자다.
 * 회원에게 묶인 토큰은 그 회원으로 로그인한 요청만 값을 받는다(판정은 Service).
 *
 * @since 7.2.0
 */
class VerifiedClaimsRequest extends FormRequest
{
    /**
     * 요청 권한 — 토큰이 자격이므로 true 고정.
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
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'verification_token' => ['required', 'string', 'max:255'],
            // 토큰을 발급받은 본인인증 목적 — 생략하면 가입(signup)
            'purpose' => ['nullable', 'string', 'max:64'],
        ];

        return HookManager::applyFilters('core.identity.verified_claims_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            'verification_token' => __('fields.attributes.verification_token'),
            'purpose' => __('fields.attributes.purpose'),
        ];
    }
}
