<?php

namespace App\Rules;

use App\Models\TemplateLayout;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidSlotStructure implements ValidationRule, DataAwareRule
{
    /**
     * 검증할 전체 데이터
     */
    protected array $data = [];

    /**
     * 전체 데이터 설정
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * 슬롯 구조 검증
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // slots 필드가 없으면 검증 통과
        if (empty($value)) {
            return;
        }

        // slots 필드는 객체(배열)여야 함
        if (!is_array($value)) {
            $fail(__('validation.layout_inheritance.slots_must_be_object'));

            return;
        }

        // extends 필드가 없으면 slots를 사용할 수 없음
        $extends = $this->data['content']['extends'] ?? null;
        if (!$extends) {
            // extends가 없는데 slots가 있으면 무시하고 검증 통과
            return;
        }

        // template_id 가져오기 (우선순위: data -> 라우트 파라미터)
        $templateId = $this->data['template_id'] ?? null;

        // data에 없으면 라우트 파라미터에서 templateName으로 조회
        if (! $templateId) {
            $templateName = request()->route('templateName');
            if ($templateName) {
                $template = \App\Models\Template::where('identifier', $templateName)->first();
                $templateId = $template?->id;
            }
        }

        if (! $templateId) {
            $fail(__('validation.request.template_id.required'));

            return;
        }

        // 부모 레이아웃 조회
        $parentLayout = TemplateLayout::where('template_id', $templateId)
            ->where('name', $extends)
            ->first();

        if (!$parentLayout) {
            $fail(__('validation.layout_inheritance.parent_not_found', ['parent' => $extends]));

            return;
        }

        // 부모 레이아웃 content 파싱
        $parentContent = is_string($parentLayout->content)
            ? json_decode($parentLayout->content, true)
            : $parentLayout->content;

        // 부모 레이아웃에 정의된 슬롯 수집
        $parentSlots = $this->collectSlotsFromComponents($parentContent['components'] ?? []);

        // 슬롯 검증
        foreach ($value as $slotName => $slotComponents) {
            // 슬롯 이름은 문자열이어야 함
            if (!is_string($slotName)) {
                $fail(__('validation.layout_inheritance.slot_name_must_be_string'));

                return;
            }

            // 슬롯 값은 배열이어야 함
            if (!is_array($slotComponents)) {
                $fail(__('validation.layout_inheritance.slot_value_must_be_array', ['slotName' => $slotName]));

                return;
            }

            // 부모 레이아웃에 정의된 슬롯인지 확인
            if (!in_array($slotName, $parentSlots)) {
                $fail(__('validation.layout_inheritance.slot_not_defined_in_parent', ['slotName' => $slotName]));

                return;
            }
        }
    }

    /**
     * 컴포넌트에서 슬롯 수집 (재귀)
     */
    private function collectSlotsFromComponents(array $components): array
    {
        $slots = [];

        foreach ($components as $component) {
            // slot 필드가 있으면 수집
            if (isset($component['slot']) && is_string($component['slot'])) {
                $slots[] = $component['slot'];
            }

            // children이 있으면 재귀 호출
            if (isset($component['children']) && is_array($component['children'])) {
                $childSlots = $this->collectSlotsFromComponents($component['children']);
                $slots = array_merge($slots, $childSlots);
            }
        }

        return array_unique($slots);
    }
}
