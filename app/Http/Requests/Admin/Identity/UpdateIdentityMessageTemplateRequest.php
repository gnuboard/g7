<?php

namespace App\Http\Requests\Admin\Identity;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * IDV 메시지 템플릿 수정 FormRequest.
 *
 * 운영자 편집 가능 필드: subject(다국어), body(다국어 필수), is_active.
 * channel/definition_id 는 시스템 식별자라 편집 불가.
 */
class UpdateIdentityMessageTemplateRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'subject' => ['sometimes', 'nullable', 'array', new TranslatableField(maxLength: 500)],
            'body' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 65535)],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters(
            'core.identity.message_template.filter_update_rules',
            $rules,
            $this->route('template')
        );
    }
}
