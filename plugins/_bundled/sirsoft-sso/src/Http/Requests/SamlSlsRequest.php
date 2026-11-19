<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;

/**
 * SAML SLS 요청 — IdP 발 LogoutRequest(SAMLRequest) 또는 우리 로그아웃 요청의 답(SAMLResponse).
 *
 * 형식이 맞지 않으면 컨트롤러가 400 으로 끝낸다(`isWellFormed()`). 서명, 발급자, 대상 검증은 드라이버가 라이브러리로 한다.
 *
 * @since 1.0.0
 */
class SamlSlsRequest extends FormRequest
{
    /** @var array<string, int> 칸별 최대 길이 */
    private const MAX_LENGTHS = ['RelayState' => 512, 'SigAlg' => 200, 'Signature' => 2048];

    /**
     * IdP 요청은 누구나 — 메시지 서명이 위조를 막는다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 형식 판정은 `isWellFormed()` 가 한다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * SAMLRequest 와 SAMLResponse 중 정확히 하나가 크기 상한 안의 문자열이고, 나머지 칸 길이가 정상인지 확인합니다.
     *
     * @return bool 정상이면 true
     */
    public function isWellFormed(): bool
    {
        $max = (int) ceil(SamlDriver::MAX_RESPONSE_BYTES * 4 / 3) + 4;
        $messages = 0;
        foreach (['SAMLRequest', 'SAMLResponse'] as $field) {
            $value = $this->input($field);
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || $value === '' || strlen($value) > $max) {
                return false;
            }
            $messages++;
        }

        if ($messages !== 1) {
            return false;
        }

        foreach (self::MAX_LENGTHS as $field => $limit) {
            $value = $this->input($field);
            if ($value !== null && (! is_string($value) || strlen($value) > $limit)) {
                return false;
            }
        }

        return true;
    }
}
