<?php

namespace App\Http\Requests\SearchHistory;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 내 최근 검색어 자동 저장 설정 요청 (`PUT /api/me/search-history/settings`)
 *
 * @since 7.1.5
 */
class UpdateSearchHistorySettingsRequest extends FormRequest
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
            'auto_save' => ['required', 'boolean'],
        ];

        return HookManager::applyFilters('core.search_history.update_settings_validation_rules', $rules, $this);
    }

    /**
     * 항목 이름을 반환합니다.
     *
     * @return array<string, string> 필드 → 이름
     */
    public function attributes(): array
    {
        return [
            'auto_save' => __('search.history.attributes.auto_save'),
        ];
    }
}
