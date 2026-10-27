<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * IdP 로그아웃 시작 요청 — 1회용 로그아웃 티켓(`?ticket=`).
 *
 * 브라우저 이동이라 검증 실패를 오류 응답으로 내지 않는다 — 티켓 형식과 1회 사용은 세션 서비스가 판정하고, 맞지 않으면 IdP 로 보내지
 * 않고 로그인 화면으로 보낸다.
 *
 * @since 1.0.0
 */
class LogoutStartRequest extends FormRequest
{
    /**
     * 누구나 — 티켓이 곧 권한이다(주소에 세션 id 를 싣지 않는다).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 판정은 세션 서비스가 한다(실패가 응답을 끊지 않게).
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
