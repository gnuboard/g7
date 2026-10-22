<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

/**
 * 인증번호 확인 요청 (가입 이메일 확인·기존 계정 이메일 확인 공용).
 *
 * @since 1.0.0
 */
class VerifyCodeRequest extends PendingSignupRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return parent::rules() + [
            'challenge_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:10'],
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
            'challenge_id' => __('sirsoft-social_login::validation.attributes.challenge_id'),
            'code' => __('sirsoft-social_login::validation.attributes.verification_code'),
        ];
    }
}
