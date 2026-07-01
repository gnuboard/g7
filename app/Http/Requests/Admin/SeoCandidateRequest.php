<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SEO 후보 조회 요청 검증.
 *
 * 편집기가 현재 레이아웃의 extensions·page_type 을 query 로 보낸다. 권한 체크는 라우트
 * `core.templates.layouts.edit` 미들웨어에서 수행한다(authorize() 는 true 고정).
 */
class SeoCandidateRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 검증 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            // extensions 는 JSON 문자열 또는 배열 — 컨트롤러가 관대하게 파싱(가드는 미들웨어).
            'extensions' => ['nullable'],
            'page_type' => ['nullable', 'string'],
        ];

        return HookManager::applyFilters('core.seo_candidate.index_validation_rules', $rules, $this);
    }
}
