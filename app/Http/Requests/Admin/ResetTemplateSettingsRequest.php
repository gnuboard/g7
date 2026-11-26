<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Services\TemplateSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 템플릿 설정 기본값 되돌리기 요청 검증
 *
 * `section` 을 주면 그 섹션만, 없으면 전체를 되돌린다. 섹션은 그 템플릿 선언의 섹션이어야 한다.
 */
class ResetTemplateSettingsRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어(`core.templates.settings`)가 한다.
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
        $schema = app(TemplateSettingsService::class)->schema((string) $this->route('identifier'));

        $rules = [
            'section' => ['nullable', 'string', Rule::in($schema?->categories ?? [])],
        ];

        return HookManager::applyFilters('core.template_settings.reset_validation_rules', $rules, (string) $this->route('identifier'), $this->all());
    }

    /**
     * 검증 오류에 쓸 칸 표시명.
     *
     * @return array<string, string> 속성 => 표시명
     */
    public function attributes(): array
    {
        return [
            'section' => __('templates.settings.attributes.section'),
        ];
    }
}
