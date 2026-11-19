<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 가입 화면 항목 조회 요청.
 *
 * 비로그인 화면이 부른다. 선택 인자는 본인인증을 거쳐 온 가입(`verification_token`)과 소셜·SSO 가입 창
 * (`pending_token`·`pending_source`)이 초기값과 잠금을 받을 때 쓴다 — 값 해석은 각 확장이 한다.
 *
 * @since 7.2.0
 */
class RegisterFieldsRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 조회라 항상 허용한다.
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
            'verification_token' => ['nullable', 'string', 'max:255'],
            'pending_token' => ['nullable', 'string', 'max:255'],
            'pending_source' => ['nullable', 'string', 'max:64'],
            // 표시 이름 종류 미리보기에 쓸 입력 중인 값 (저장하지 않는다)
            'preview_name' => ['nullable', 'string', 'max:255'],
            'preview_nickname' => ['nullable', 'string', 'max:50'],
            'preview_email' => ['nullable', 'string', 'max:255'],
        ];

        return HookManager::applyFilters('core.auth.register_fields_validation_rules', $rules, $this);
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
            'pending_token' => __('fields.attributes.pending_token'),
            'pending_source' => __('fields.attributes.pending_source'),
            'preview_name' => __('display_name.attributes.preview_name'),
            'preview_nickname' => __('display_name.attributes.preview_nickname'),
            'preview_email' => __('display_name.attributes.preview_email'),
        ];
    }
}
