<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * IdP 연결 테스트 요청 — browser 가 참이면 「브라우저로 로그인 테스트」 시작 주소를 받는다.
 *
 * @since 1.0.0
 */
class IdpTestRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어가 본다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        return [
            'browser' => ['sometimes', 'boolean'],
            'account_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_password' => ['required_with:account_identifier', 'nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $p = 'sirsoft-sso::admin.attributes.';

        return [
            'browser' => __($p.'browser'),
            'account_identifier' => __($p.'account_identifier'),
            'account_password' => __($p.'account_password'),
        ];
    }
}
