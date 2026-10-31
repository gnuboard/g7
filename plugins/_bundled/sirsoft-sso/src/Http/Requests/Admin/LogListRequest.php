<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 「이력」 목록 조회 요청 — 로그인 시도와 SCIM 수신이 같은 조건을 쓴다.
 *
 * @since 1.0.0
 */
class LogListRequest extends FormRequest
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
            'outcome' => ['nullable', 'string', 'max:20', 'regex:/^[a-z_]+$/'],
            'intent' => ['nullable', Rule::in(['login', 'link', 'reauth', 'test'])],
            'status_class' => ['nullable', Rule::in(['success', 'error'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
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
            'outcome' => __($p.'outcome'),
            'intent' => __($p.'intent'),
            'status_class' => __($p.'status_class'),
            'from' => __($p.'from'),
            'to' => __($p.'to'),
            'page' => __($p.'page'),
            'per_page' => __($p.'per_page'),
        ];
    }
}
