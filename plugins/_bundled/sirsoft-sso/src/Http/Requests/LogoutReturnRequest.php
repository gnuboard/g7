<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * IdP 로그아웃 복귀 요청 — IdP 가 브라우저를 돌려보내는 주소. 받는 값이 없다(갈 화면은 시작 때 세션에 둔 guard 로 정한다).
 *
 * @since 1.0.0
 */
class LogoutReturnRequest extends FormRequest
{
    /**
     * 누구나 — 로그인 화면으로 보내기만 한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 받는 값이 없다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
