<?php

namespace App\Http\Requests\Template;

use App\Enums\LayoutSyncMode;
use App\Extension\HookManager;
use App\Extension\Layout\LayoutSyncOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefreshTemplateLayoutsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 라우트의 permission 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'template_name' => 'required|string|max:255',
            // 레이아웃 설치 소스(auto|json) — 이 요청 동안만 설정을 덮는다. 생략하면 설정(LAYOUT_INSTALL_SOURCE)을 따른다.
            'layout_source' => ['nullable', 'string', 'in:auto,json'],
            // 레이아웃 처리 방식 — keep(기본)은 운영자가 고친 화면을 지키고, overwrite 는 파일 판으로 덮는다.
            'layout_strategy' => ['nullable', 'string', Rule::in(LayoutSyncMode::values())],
            // 화면별 결정 — 키는 미리보기 응답의 entries[].key, 값은 take-theirs(새 판)|keep-ours(운영자 판).
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['string', Rule::in([LayoutSyncOptions::TAKE_THEIRS, LayoutSyncOptions::KEEP_OURS])],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.template.refresh_layouts_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template_name.required' => __('templates.validation.name_required'),
            'template_name.string' => __('templates.validation.name_string'),
            'template_name.max' => __('templates.validation.name_max'),
            'layout_source.in' => __('templates.errors.invalid_layout_source'),
            'layout_strategy.in' => __('extensions.layout_sync.errors.invalid_strategy', ['value' => (string) $this->input('layout_strategy'), 'allowed' => implode(', ', LayoutSyncMode::values())]),
            'decisions.array' => __('extensions.layout_sync.errors.invalid_decisions_file'),
            'decisions.*.in' => __('extensions.layout_sync.errors.invalid_decision', ['key' => ':attribute']),
        ];
    }

    /**
     * 검증 오류 문구에 쓸 필드 이름.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'layout_strategy' => __('validation.attributes.layout_strategy'),
            'decisions' => __('validation.attributes.decisions'),
            'decisions.*' => __('validation.attributes.decisions.*'),
        ];
    }
}
