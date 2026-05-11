<?php

namespace App\Http\Requests\LanguagePack;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 언어팩 목록 조회 요청 (필터/페이지네이션).
 */
class IndexLanguagePackRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트 미들웨어가 담당하므로 항상 true 반환.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 정의합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', Rule::in(LanguagePackScope::values())],
            'target_identifier' => ['nullable', 'string', 'max:150'],
            'locale' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', Rule::in(LanguagePackStatus::values())],
            'vendor' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'exclude_protected' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
