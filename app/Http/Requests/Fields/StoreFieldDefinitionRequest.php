<?php

namespace App\Http\Requests\Fields;

use App\Extension\HookManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldTypeRegistry;
use App\Models\FieldDefinition;
use Illuminate\Validation\Rule;

/**
 * 운영자 항목 추가 요청.
 *
 * @since 7.2.0
 */
class StoreFieldDefinitionRequest extends FieldDefinitionRequest
{
    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $set = $this->fieldSet();
        $typeKey = is_string($this->input('type')) ? $this->input('type') : null;
        $type = $this->fieldType($typeKey);

        // 집합이 받는 유형 가운데 지금 쓸 수 있는(제공 확장이 켜진) 유형만 고를 수 있다.
        $allowedTypes = array_values(array_filter(
            array_keys(app(FieldTypeRegistry::class)->all()),
            fn (string $key) => $set?->allowsType($key) ?? true,
        ));

        $rules = [
            'key' => [
                'required', 'string', 'regex:'.FieldDefinition::KEY_PATTERN,
                Rule::unique(FieldDefinition::class, 'key')->where('field_set', (string) $this->route('set')),
            ],
            'type' => ['required', 'string', Rule::in($allowedTypes)],
            ...$this->commonRules($type, partial: false),
        ];

        if ($type?->supports(FieldTypeInterface::FEATURE_OPTIONS)) {
            // 선택 항목은 선택지가 하나 이상 있어야 한다(「기타」만 받는 하나 선택은 예외).
            $rules['options'] = $this->boolean('constraints.allow_other') && $typeKey === 'choice'
                ? ['nullable', 'array', 'max:'.self::MAX_OPTIONS]
                : ['required', 'array', 'min:1', 'max:'.self::MAX_OPTIONS];
        }

        return HookManager::applyFilters('core.field_definition.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명 — 공통 이름에 추가 요청 전용 칸을 더한다.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'key' => __('fields.attributes.key'),
            'type' => __('fields.attributes.type'),
        ];
    }
}
