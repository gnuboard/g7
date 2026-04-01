<?php

namespace App\Rules;

use App\Models\TemplateLayout;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidParentLayout implements ValidationRule, DataAwareRule
{
    private const MAX_INHERITANCE_DEPTH = 10;

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
     * 부모 레이아웃 검증
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // extends 필드가 없으면 검증 통과 (상속하지 않는 레이아웃)
        if (empty($value)) {
            return;
        }

        // extends 필드는 문자열이어야 함
        if (!is_string($value)) {
            $fail(__('validation.layout_inheritance.extends_must_be_string'));

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

        // 부모 레이아웃이 존재하는지 확인
        $parentLayout = TemplateLayout::where('template_id', $templateId)
            ->where('name', $value)
            ->first();

        if (!$parentLayout) {
            $fail(__('validation.layout_inheritance.parent_not_found', ['parent' => $value]));

            return;
        }

        // 순환 참조 검증
        $visited = [];
        $currentLayoutName = $this->data['layout_name'] ?? $this->data['name'] ?? null;

        if ($currentLayoutName && !$this->checkCircularReference($templateId, $value, $visited, $currentLayoutName)) {
            $trace = implode(' → ', $visited).' → '.$value;
            $fail(__('validation.layout_inheritance.circular_reference', ['trace' => $trace]));

            return;
        }

        // 상속 깊이 검증
        $depth = $this->calculateInheritanceDepth($templateId, $value);
        if ($depth > self::MAX_INHERITANCE_DEPTH) {
            $fail(__('validation.layout_inheritance.max_depth_exceeded', ['max' => self::MAX_INHERITANCE_DEPTH]));

            return;
        }
    }

    /**
     * 순환 참조 확인
     */
    private function checkCircularReference(int $templateId, string $parentName, array &$visited, string $currentLayoutName): bool
    {
        // 현재 레이아웃 이름을 부모로 참조하면 순환 참조
        if ($parentName === $currentLayoutName) {
            $visited[] = $currentLayoutName;

            return false;
        }

        // 이미 방문한 레이아웃이면 순환 참조
        if (in_array($parentName, $visited)) {
            return false;
        }

        $visited[] = $parentName;

        // 부모 레이아웃의 extends 확인
        $parentLayout = TemplateLayout::where('template_id', $templateId)
            ->where('name', $parentName)
            ->first();

        if (!$parentLayout) {
            return true; // 부모가 없으면 순환 참조 아님
        }

        $parentContent = is_string($parentLayout->content)
            ? json_decode($parentLayout->content, true)
            : $parentLayout->content;

        // 부모도 extends가 있으면 재귀 확인
        if (isset($parentContent['extends'])) {
            return $this->checkCircularReference($templateId, $parentContent['extends'], $visited, $currentLayoutName);
        }

        return true;
    }

    /**
     * 상속 깊이 계산
     */
    private function calculateInheritanceDepth(int $templateId, string $layoutName): int
    {
        $depth = 1; // 현재 레이아웃부터 시작
        $currentName = $layoutName;
        $visited = [];

        while ($currentName && $depth <= self::MAX_INHERITANCE_DEPTH) {
            // 무한 루프 방지
            if (in_array($currentName, $visited)) {
                return self::MAX_INHERITANCE_DEPTH + 1; // 순환 참조로 최대 깊이 초과 반환
            }

            $visited[] = $currentName;

            $layout = TemplateLayout::where('template_id', $templateId)
                ->where('name', $currentName)
                ->first();

            if (!$layout) {
                break;
            }

            $content = is_string($layout->content)
                ? json_decode($layout->content, true)
                : $layout->content;

            if (isset($content['extends'])) {
                $currentName = $content['extends'];
                $depth++;
            } else {
                break;
            }
        }

        return $depth;
    }
}
