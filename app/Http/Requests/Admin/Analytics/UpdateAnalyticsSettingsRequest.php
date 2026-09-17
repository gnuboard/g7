<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use App\Http\Requests\Admin\Analytics\Concerns\HasAnalyticsSettingsRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 방문자 통계 설정 저장 요청
 *
 * 설정 화면이 탭 하나만 저장해도 되도록 모든 키를 선택 항목으로 둡니다 — 보낸 값만
 * 바뀌고 나머지는 그대로 남습니다.
 */
class UpdateAnalyticsSettingsRequest extends FormRequest
{
    use HasAnalyticsSettingsRules;

    /**
     * 인가는 라우트 미들웨어가 판정합니다.
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
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = $this->analyticsSettingsRules();

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.settings_validation_rules', $rules, $this);
    }
}
