<?php

namespace App\Http\Requests\SearchHistory;

use App\Extension\HookManager;
use App\Support\SearchScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 내 최근 검색어 목록 조회 요청 (`GET /api/me/search-history?scope=`)
 *
 * @since 7.1.5
 */
class SearchHistoryIndexRequest extends FormRequest
{
    /**
     * 권한 확인 (본인 전용 묶음 — 인증은 라우트 미들웨어가 담당).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'scope' => ['required', 'string', 'max:100', Rule::in(SearchScopes::keys())],
        ];

        return HookManager::applyFilters('core.search_history.index_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙별 메시지
     */
    public function messages(): array
    {
        return [
            'scope.in' => __('search.history.validation.scope_unknown'),
        ];
    }

    /**
     * 항목 이름을 반환합니다.
     *
     * @return array<string, string> 필드 → 이름
     */
    public function attributes(): array
    {
        return [
            'scope' => __('search.history.attributes.scope'),
        ];
    }
}
