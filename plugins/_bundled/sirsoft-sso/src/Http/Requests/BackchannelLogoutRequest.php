<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * OIDC back-channel 로그아웃 요청 — IdP 서버가 보낸 폼 값 `logout_token`.
 *
 * 서버 간 호출이라 G7 오류 형식(422)을 내지 않는다 — 형식이 틀리면 드라이버 검증이 실패하고 세션 서비스가 규격대로 400 을 돌려준다.
 *
 * @since 1.0.0
 */
class BackchannelLogoutRequest extends FormRequest
{
    /**
     * 누구나 — logout_token 서명 검증과 jti 1회 사용이 위조를 막는다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 판정은 드라이버가 한다(규격 응답 400 을 지키려고).
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
