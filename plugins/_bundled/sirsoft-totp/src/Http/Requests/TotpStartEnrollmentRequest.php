<?php

namespace Plugins\Sirsoft\Totp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 인증 앱 등록 시작 요청 — 현재 비밀번호를 다시 받는다.
 *
 * 로그인된 탭만 있으면 비밀번호를 몰라도 인증 앱을 새로 등록하고 백업 코드를 받을 수 있었다(KVE-2026-3194).
 * 필드 이름은 코어 비밀번호 변경 요청(`ChangePasswordRequest`)과 같은 `current_password` 다.
 *
 * @since 1.0.3
 */
class TotpStartEnrollmentRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'max:255'],
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
            'current_password.required' => __('sirsoft-totp::messages.validation.current_password_required'),
            'current_password.max' => __('sirsoft-totp::messages.validation.current_password_max'),
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
            'current_password' => __('sirsoft-totp::messages.validation.current_password_attribute'),
        ];
    }
}
