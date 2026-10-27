<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * OIDC 콜백 요청 — IdP 가 보낸 code, state, error.
 *
 * 브라우저 이동이라 검증 실패를 오류 응답으로 내지 않는다 — 컨트롤러가 `isWellFormed()` 로 보고 state 실패 화면으로 보낸다.
 *
 * @since 1.0.0
 */
class OidcCallbackRequest extends FormRequest
{
    /** @var array<string, int> 칸별 최대 길이 */
    private const MAX_LENGTHS = ['state' => 128, 'code' => 2048, 'error' => 100, 'error_description' => 1000];

    /**
     * IdP 콜백은 누구나 — state 와 브라우저 묶음 쿠키가 위조를 막는다.
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
     * 콜백 값이 문자열이고 길이가 정상인지 확인합니다.
     *
     * @return bool 정상이면 true
     */
    public function isWellFormed(): bool
    {
        foreach (self::MAX_LENGTHS as $field => $max) {
            $value = $this->input($field);
            if ($value !== null && (! is_string($value) || strlen($value) > $max)) {
                return false;
            }
        }

        return true;
    }
}
