<?php

namespace App\Http\Requests\Fields;

use App\Extension\HookManager;
use App\Models\FieldDefinition;
use Illuminate\Validation\Rule;

/**
 * 항목 순서 변경 요청.
 *
 * @since 7.2.0
 */
class ReorderFieldDefinitionsRequest extends FieldDefinitionRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'fields_version' => ['nullable', 'string', 'max:64'],
            'order' => ['required', 'array', 'min:1', 'max:500'],
            'order.*' => [
                'required', 'integer', 'distinct',
                Rule::exists(FieldDefinition::class, 'id')->where('field_set', (string) $this->route('set')),
            ],
        ];

        return HookManager::applyFilters('core.field_definition.reorder_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            'fields_version' => __('fields.attributes.fields_version'),
            'order' => __('fields.attributes.order'),
            'order.*' => __('fields.attributes.order'),
        ];
    }
}
