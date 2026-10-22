<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 가입 대기 토큰을 싣는 요청의 공통 규칙.
 *
 * 가입 대기 토큰은 가입 화면 주소(`social_pending`)로 받은 48자 영숫자다. 대기 자체의 존재·만료는 서비스가 본다.
 *
 * @since 1.0.0
 */
class PendingSignupRequest extends FormRequest
{
    /**
     * 게스트 진입점 — 누구나 요청할 수 있습니다(토큰이 곧 권한).
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [
            'pending_token' => ['required', 'string', 'size:48', 'alpha_num'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'pending_token' => __('sirsoft-social_login::validation.attributes.pending_token'),
            'name' => __('sirsoft-social_login::validation.attributes.name'),
            'nickname' => __('sirsoft-social_login::validation.attributes.nickname'),
            'email' => __('sirsoft-social_login::validation.attributes.email'),
            'agree_terms' => __('sirsoft-social_login::validation.attributes.agree_terms'),
            'agree_privacy' => __('sirsoft-social_login::validation.attributes.agree_privacy'),
            'challenge_id' => __('sirsoft-social_login::validation.attributes.challenge_id'),
            'code' => __('sirsoft-social_login::validation.attributes.verification_code'),
            'method' => __('sirsoft-social_login::validation.attributes.method'),
            'password' => __('sirsoft-social_login::validation.attributes.password'),
        ];
    }

    /**
     * 가입 대기 토큰을 반환합니다.
     *
     * @return string 토큰
     */
    public function pendingToken(): string
    {
        return (string) $this->input('pending_token');
    }
}
