<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 처음 로그인 약관 동의 완료 요청 — 이용약관, 개인정보 처리방침 동의가 둘 다 있어야 한다.
 *
 * @since 1.0.0
 */
class ConsentCompleteRequest extends FormRequest
{
    /**
     * 손님 요청이다 — 대기 토큰과 브라우저 묶음 쿠키가 곧 권한이다.
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
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [
            'agree_terms' => ['accepted'],
            'agree_privacy' => ['accepted'],
        ];
    }

    /**
     * 칸 이름.
     *
     * @return array<string, string> 칸 => 이름
     */
    public function attributes(): array
    {
        return [
            'agree_terms' => __('sirsoft-sso::validation.attributes.agree_terms'),
            'agree_privacy' => __('sirsoft-sso::validation.attributes.agree_privacy'),
        ];
    }
}
