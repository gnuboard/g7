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

    /**
     * 검증 항목명을 반환합니다.
     *
     * @return array<string, string> 규칙 키 => 항목명
     */
    public function attributes(): array
    {
        return $this->analyticsSettingsAttributes();
    }

    /**
     * 검증을 마친 값을 규칙이 선언한 형으로 돌려줍니다.
     *
     * @param  string|null  $key  뽑아 올 키 (null 이면 전부)
     * @param  mixed  $default  키가 없을 때 돌려줄 값
     * @return mixed 검증을 마친 값
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();

        if (! is_array($validated)) {
            return $validated;
        }

        // 뽑아 오는 방식은 부모와 같게 둔다 — `data_get` 이라야 점 표기·와일드카드가 그대로 산다.
        return data_get($this->castAnalyticsSettings($validated), $key, $default);
    }
}
