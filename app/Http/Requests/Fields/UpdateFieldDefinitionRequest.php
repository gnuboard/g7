<?php

namespace App\Http\Requests\Fields;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\HookManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Models\FieldDefinition;

/**
 * 항목 수정 요청 (표의 스위치 한 칸만 보내는 부분 수정 포함).
 *
 * 키와 유형은 만든 뒤 바꾸지 못하므로 받지 않는다.
 *
 * @since 7.2.0
 */
class UpdateFieldDefinitionRequest extends FieldDefinitionRequest
{
    /** 수정 대상 항목 메모 */
    protected FieldDefinition|false|null $target = null;

    /**
     * 수정 대상 항목을 반환합니다.
     *
     * @return FieldDefinition|null 항목 (없거나 다른 집합이면 null — 컨트롤러가 404 로 응답한다)
     */
    public function targetDefinition(): ?FieldDefinition
    {
        if ($this->target === null) {
            $definition = app(FieldDefinitionRepositoryInterface::class)->findById((int) $this->route('field'));
            $this->target = $definition !== null && $definition->field_set === (string) $this->route('set') ? $definition : false;
        }

        return $this->target ?: null;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $definition = $this->targetDefinition();
        $type = $this->fieldType($definition?->type);

        $rules = $this->commonRules($type, partial: true);

        if ($definition !== null && $type?->supports(FieldTypeInterface::FEATURE_OPTIONS)) {
            $rules['options'] = [...$this->optionPresenceRules($definition), 'array', 'max:'.self::MAX_OPTIONS];
            // 저장된 선택지의 값은 못 바꾼다 — 값이 바뀌면 그 값을 고른 회원의 선택이 끊긴다. 지우고 새로 넣는 것은 된다.
        }

        return HookManager::applyFilters('core.field_definition.update_validation_rules', $rules, $this);
    }

    /**
     * 선택지 존재 규칙을 반환합니다.
     *
     * 선택지를 보내지 않은 요청이라도, 필수로 켜는 요청이면 저장된 선택지가 하나 이상 있어야 한다
     * (선택지 0개인 선택 항목을 필수로 만들면 아무도 통과할 수 없다).
     *
     * @param  FieldDefinition  $definition  수정 대상 항목
     * @return array<int, mixed> 존재 규칙
     */
    protected function optionPresenceRules(FieldDefinition $definition): array
    {
        $allowOther = $definition->type === 'choice'
            && ($this->has('constraints.allow_other') ? $this->boolean('constraints.allow_other') : (bool) $definition->constraint('allow_other', false));
        if ($allowOther) {
            return ['sometimes'];
        }

        $becomesRequired = $this->has('required') ? $this->boolean('required') : $definition->required;
        $becomesEnabled = $this->has('enabled') ? $this->boolean('enabled') : $definition->enabled;
        $stored = count($definition->options ?? []);

        if ($this->has('options')) {
            return ['required', 'min:1'];
        }

        if ($stored === 0 && $becomesRequired && $becomesEnabled && $this->has('required')) {
            return ['required', 'min:1'];
        }

        return ['sometimes'];
    }
}
