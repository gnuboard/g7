<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 관리자 정책 목록 조회 검증.
 *
 * S1d DataGrid 의 필터 쿼리 파라미터를 화이트리스트로 제한합니다.
 */
class AdminIdentityPolicyIndexRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['route', 'hook', 'custom'])],
            'purpose' => ['nullable', 'string', 'max:64'],
            'source_type' => ['nullable', Rule::in(['core', 'module', 'plugin', 'admin'])],
            'source_identifier' => ['nullable', 'string', 'max:100'],
            'applies_to' => ['nullable', Rule::in(['self', 'admin', 'both'])],
            'fail_mode' => ['nullable', Rule::in(['block', 'log_only'])],
            'enabled' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
