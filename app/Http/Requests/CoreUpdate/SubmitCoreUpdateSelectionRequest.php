<?php

namespace App\Http\Requests\CoreUpdate;

use App\CoreUpdate\CoreUpdateOptions;
use App\CoreUpdate\CoreUpdateOrchestrator;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 웹 코어 업데이트 진행 중 선택 제출 요청 검증 (7.2.1).
 *
 * 명령줄 `core:update` 가 코어 파일을 적용한 뒤 묻는 두 질문의 답이다 — 새로 들어온 미배치 공식 번들 중 배치할 것
 * (`bundled_sources`), 번들 확장·언어팩 중 올릴 것과 확장별 레이아웃 전략(`bundled_updates`). 항목이 기다리던 목록에
 * 있는지는 오케스트레이터가 가른다(목록은 실행 상태에 있다).
 */
class SubmitCoreUpdateSelectionRequest extends FormRequest
{
    /**
     * 권한 판정은 라우트 미들웨어가 담당합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'kind' => ['required', 'string', Rule::in([
                CoreUpdateOrchestrator::SELECTION_BUNDLED_SOURCES,
                CoreUpdateOrchestrator::SELECTION_BUNDLED_UPDATES,
            ])],
            'items' => ['present', 'array', 'max:500'],
            'items.*.key' => ['required', 'string', 'max:255'],
            'items.*.selected' => ['required', 'boolean'],
            'items.*.strategy' => ['sometimes', 'nullable', Rule::in([
                CoreUpdateOptions::LAYOUT_OVERWRITE,
                CoreUpdateOptions::LAYOUT_KEEP,
            ])],
        ];

        // 확장이 선택 요청 규칙을 더할 수 있는 지점(착수 요청의 `core.core_update.start_validation_rules` 와 같은 규약).
        // 오케스트레이터가 읽는 것은 `kind`·`items[].key/selected/strategy` 뿐이다 — 더한 키로 진행 동작을 바꿀 수는 없다.
        return HookManager::applyFilters('core.core_update.selection_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 문구에 쓰는 항목 이름.
     *
     * @return array<string, string> 필드 이름 → 번역된 항목 이름
     */
    public function attributes(): array
    {
        return [
            'kind' => (string) __('settings.core_update.web.attributes.selection_kind'),
            'items' => (string) __('settings.core_update.web.attributes.selection_items'),
            'items.*.key' => (string) __('settings.core_update.web.attributes.selection_items'),
            'items.*.selected' => (string) __('settings.core_update.web.attributes.selection_selected'),
            'items.*.strategy' => (string) __('settings.core_update.web.attributes.layout_strategy'),
        ];
    }
}
