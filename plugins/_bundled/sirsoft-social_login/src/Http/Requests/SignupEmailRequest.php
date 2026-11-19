<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

/**
 * 가입 이메일 인증번호 요청.
 *
 * @since 1.0.0
 */
class SignupEmailRequest extends PendingSignupRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return parent::rules() + [
            'email' => ['required', 'string', 'email', 'max:255'],
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
            'email' => __('sirsoft-social_login::validation.attributes.email'),
        ];
    }
}
