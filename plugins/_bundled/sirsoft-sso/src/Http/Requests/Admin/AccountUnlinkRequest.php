<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 연결 해제 요청 — 마지막 로그인 수단이면 confirm_last_method 로 확인을 받는다.
 *
 * @since 1.0.0
 */
class AccountUnlinkRequest extends FormRequest
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
            'confirm_last_method' => ['sometimes', 'boolean'],
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
            'confirm_last_method' => __($p.'confirm_last_method'),
        ];
    }
}
