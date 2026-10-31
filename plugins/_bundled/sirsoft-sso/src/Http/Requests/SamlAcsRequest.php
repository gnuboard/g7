<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;

/**
 * SAML ACS 요청 — IdP 가 브라우저를 통해 POST 하는 SAMLResponse, RelayState.
 *
 * 브라우저 이동이라 검증 실패를 오류 응답으로 내지 않는다 — 컨트롤러가 `isWellFormed()` 로 보고 로그인 화면 오류로 보낸다.
 *
 * @since 1.0.0
 */
class SamlAcsRequest extends FormRequest
{
    /** RelayState 최대 길이 (규격 권고 80바이트 — 같은 출처 경로 복귀까지 넉넉히) */
    private const MAX_RELAY_STATE = 512;

    /**
     * IdP 응답은 누구나 — state·묶음 쿠키(SP-initiated) 또는 IdP-initiated 허용 + 응답 1회 + 서명이 위조를 막는다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 형식 판정은 `isWellFormed()` 가 한다(실패가 응답을 끊지 않게).
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * SAMLResponse 가 문자열이고 크기 상한 안이며, RelayState 가 문자열(있으면)인지 확인합니다.
     *
     * @return bool 정상이면 true
     */
    public function isWellFormed(): bool
    {
        $response = $this->input('SAMLResponse');
        if (! is_string($response) || $response === '' || strlen($response) > (int) ceil(SamlDriver::MAX_RESPONSE_BYTES * 4 / 3) + 4) {
            return false;
        }

        $relay = $this->input('RelayState');

        return $relay === null || (is_string($relay) && strlen($relay) <= self::MAX_RELAY_STATE);
    }
}
