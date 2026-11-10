<?php

namespace App\Http\Requests\SearchHistory;

use App\Extension\HookManager;
use App\Services\SearchHistoryService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 브라우저 최근 검색어 병합 요청 (`POST /api/me/search-history/merge`)
 *
 * 로그인 직후 엔진이 비회원 때 쌓인 브라우저 목록을 보낸다. 지금 선언되지 않은 범위(끈 확장)의 항목은 서비스가
 * 건너뛴다 — 한 항목 때문에 전체 병합을 422 로 거부하면 나머지 검색어까지 잃는다. `searched_at` 은 밀리초 시각.
 *
 * @since 7.1.5
 */
class MergeSearchHistoryRequest extends FormRequest
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
            'items' => ['present', 'array', 'max:'.SearchHistoryService::MERGE_MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.scope' => ['required', 'string', 'max:100'],
            'items.*.keyword' => ['required', 'string', 'max:200'],
            'items.*.searched_at' => ['nullable', 'integer', 'min:0'],
        ];

        return HookManager::applyFilters('core.search_history.merge_validation_rules', $rules, $this);
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙별 메시지
     */
    public function messages(): array
    {
        return [
            'items.max' => __('search.history.validation.items_max', ['max' => SearchHistoryService::MERGE_MAX_ITEMS]),
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
            'items' => __('search.history.attributes.items'),
            'items.*' => __('search.history.attributes.items'),
            'items.*.scope' => __('search.history.attributes.scope'),
            'items.*.keyword' => __('search.history.attributes.keyword'),
            'items.*.searched_at' => __('search.history.attributes.searched_at'),
        ];
    }
}
