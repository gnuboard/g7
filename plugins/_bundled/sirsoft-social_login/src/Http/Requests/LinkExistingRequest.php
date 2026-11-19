<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

/**
 * 「이미 가입된 이메일」 확인 요청 — 기존 계정 비밀번호 또는 기존 계정 이메일 인증번호.
 *
 * @since 1.0.0
 */
class LinkExistingRequest extends PendingSignupRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return parent::rules() + [
            'method' => ['required', 'string', 'in:password,email'],
            'password' => ['required_if:method,password', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return parent::attributes() + [
            'method' => __('sirsoft-social_login::validation.attributes.method'),
            'password' => __('sirsoft-social_login::validation.attributes.password'),
        ];
    }
}
