<?php

namespace Plugins\Sirsoft\Totp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 인증 앱 코드 또는 백업 코드를 받는 요청.
 *
 * 확정·해제·백업 코드 재발급이 같은 입력을 받으므로 한 FormRequest 를 공유합니다.
 * 길이 상한은 백업 코드 표기(`xxxxx-xxxxx`)를 넉넉히 담되 무한 입력을 막는 선입니다.
 *
 * @since 1.0.0
 */
class TotpCodeRequest extends FormRequest
{
    /**
     * 요청 인가 여부.
     *
     * 인증은 라우트 미들웨어(auth:sanctum)가 담당하고, 대상은 항상 본인이다.
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
     * @return array<string, array<int, string>> 규칙 맵
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:6', 'max:32'],
        ];
    }

    /**
     * 검증 실패 메시지.
     *
     * @return array<string, string> 메시지 맵
     */
    public function messages(): array
    {
        return [
            'code.required' => __('sirsoft-totp::messages.validation.code_required'),
            'code.min' => __('sirsoft-totp::messages.validation.code_invalid'),
            'code.max' => __('sirsoft-totp::messages.validation.code_invalid'),
        ];
    }

    /**
     * 속성 표시명.
     *
     * @return array<string, string> 속성명 맵
     */
    public function attributes(): array
    {
        return [
            'code' => __('sirsoft-totp::messages.validation.code_attribute'),
        ];
    }
}
