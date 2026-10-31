<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 기존 회원 연결 확인 요청 — 비밀번호 또는 메일 인증번호.
 *
 * @since 1.0.0
 */
class LinkVerifyRequest extends FormRequest
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
            'method' => ['required', 'string', 'in:password,email'],
            'password' => ['required_if:method,password', 'nullable', 'string', 'max:255'],
            'challenge_id' => ['required_if:method,email', 'nullable', 'string', 'max:64'],
            'code' => ['required_if:method,email', 'nullable', 'string', 'max:20'],
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
            'method' => __('sirsoft-sso::validation.attributes.method'),
            'password' => __('sirsoft-sso::validation.attributes.password'),
            'challenge_id' => __('sirsoft-sso::validation.attributes.challenge_id'),
            'code' => __('sirsoft-sso::validation.attributes.code'),
        ];
    }
}
