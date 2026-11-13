<?php

namespace App\Http\Requests\Fields;

use App\Extension\HookManager;

/**
 * 항목 조회 요청 (목록, 상세, 미입력 회원 수).
 *
 * 본문이 없는 읽기 요청이다. 삭제 요청도 낡은 표 판정용 `fields_version` 만 받으므로 이 클래스를 쓴다.
 *
 * @since 7.2.0
 */
class ShowFieldDefinitionRequest extends FieldDefinitionRequest
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
        ];

        return HookManager::applyFilters('core.field_definition.show_validation_rules', $rules, $this);
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
        ];
    }
}
