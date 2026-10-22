<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 소셜 재확인 결과 수령 요청 — 돌아온 주소의 일회용 수령 코드.
 *
 * @since 1.0.0
 */
class ReauthClaimRequest extends FormRequest
{
    /**
     * 로그인 회원만 (라우트 미들웨어가 인증을 본다).
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
            'code' => ['required', 'string', 'size:40', 'alpha_num'],
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
            'code' => __('sirsoft-social_login::validation.attributes.claim_code'),
        ];
    }
}
