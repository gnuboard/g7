<?php

namespace App\Http\Requests\SearchHistory;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 내 최근 검색어 전체 삭제 요청 (`DELETE /api/me/search-history?scope=`)
 *
 * 범위를 주면 그 범위만, 없으면 전 범위를 지운다. 지금 선언되지 않은 범위(끈 확장)의 검색어도 본인이 지울 수 있어야
 * 하므로 범위 선언 여부는 보지 않는다.
 *
 * @since 7.1.5
 */
class DestroyAllSearchHistoryRequest extends FormRequest
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
            'scope' => ['nullable', 'string', 'max:100'],
        ];

        return HookManager::applyFilters('core.search_history.destroy_all_validation_rules', $rules, $this);
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
