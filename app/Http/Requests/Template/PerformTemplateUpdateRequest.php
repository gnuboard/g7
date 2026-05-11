<?php

namespace App\Http\Requests\Template;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 템플릿 업데이트 실행 요청 검증
 *
 * layout_strategy: 레이아웃 전략 (overwrite 또는 keep)
 */
class PerformTemplateUpdateRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'layout_strategy' => ['nullable', 'string', 'in:overwrite,keep'],
            // 코어 버전 비호환 강제 우회 플래그
            'force' => ['nullable', 'boolean'],
        ];

        return HookManager::applyFilters('core.template.perform_update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'layout_strategy.in' => __('templates.errors.invalid_layout_strategy'),
        ];
    }
}
