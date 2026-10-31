<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Sso\Models\SsoAccount;

/**
 * 「회원 연결」 목록 조회 요청.
 *
 * @since 1.0.0
 */
class AccountListRequest extends FormRequest
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
            'idp_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'via' => ['nullable', Rule::in(SsoAccount::VIA)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
            'idp_id' => __($p.'idp_id'),
            'status' => __($p.'status'),
            'via' => __($p.'via'),
            'q' => __($p.'q'),
            'page' => __($p.'page'),
            'per_page' => __($p.'per_page'),
        ];
    }
}
