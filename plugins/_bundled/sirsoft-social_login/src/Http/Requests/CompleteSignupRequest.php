<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

/**
 * 소셜 가입 완료 요청.
 *
 * 비밀번호는 받지 않는다(제공자 검수 기준 — 소셜 가입에서 비밀번호를 요구하지 않는다). 이메일은 가입 대기가 「입력」
 * 단계일 때만 의미가 있고, 인증번호로 확인한 이메일과 같아야 한다(서비스가 대조). 본인인증 가입 정책이 켜진 사이트는
 * 라우트 미들웨어(`identity.policy:core.auth.signup_before_submit`)가 확인 토큰을 요구한다.
 *
 * @since 1.0.0
 */
class CompleteSignupRequest extends PendingSignupRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return parent::rules() + [
            'name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'agree_terms' => ['accepted'],
            'agree_privacy' => ['accepted'],
            'verification_token' => ['nullable', 'string', 'max:255'],
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
            'name' => __('sirsoft-social_login::validation.attributes.name'),
            'nickname' => __('sirsoft-social_login::validation.attributes.nickname'),
            'email' => __('sirsoft-social_login::validation.attributes.email'),
            'agree_terms' => __('sirsoft-social_login::validation.attributes.agree_terms'),
            'agree_privacy' => __('sirsoft-social_login::validation.attributes.agree_privacy'),
            'verification_token' => __('sirsoft-social_login::validation.attributes.verification_token'),
        ];
    }
}
