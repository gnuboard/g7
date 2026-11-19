<?php

namespace Plugins\Sirsoft\Sso\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CSV 가져오기 요청 — csv 본문(열 email,name,idp_key,subject)과 mode(preview 미리보기 / execute 실행).
 *
 * @since 1.0.0
 */
class AccountImportRequest extends FormRequest
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
            'csv' => ['required', 'string', 'max:1048576'],
            'mode' => ['required', Rule::in(['preview', 'execute'])],
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
            'csv' => __($p.'csv'),
            'mode' => __($p.'mode'),
        ];
    }
}
