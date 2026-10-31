<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Me;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 마이페이지 회사 계정 연결 시작 요청.
 *
 * @since 1.0.0
 */
class LinkStartRequest extends FormRequest
{
    /**
     * 로그인 회원 본인 요청 — 인증은 라우트 미들웨어가 본다.
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
            'idp' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
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
            'idp' => __($p.'idp'),
        ];
    }
}
