<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 대기 토큰만으로 하는 요청 (동의 대기 조회, 연결 확인 조회, 연결 확인 인증번호 요청).
 *
 * 토큰 형식은 라우트 제약이 본다. 권한은 대기 토큰과 브라우저 묶음 쿠키 대조(서비스)다.
 *
 * @since 1.0.0
 */
class PendingTokenRequest extends FormRequest
{
    /**
     * 손님 요청이다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 본문이 없다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
