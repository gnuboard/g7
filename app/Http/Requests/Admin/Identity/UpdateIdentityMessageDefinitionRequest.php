<?php

namespace App\Http\Requests\Admin\Identity;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * IDV 메시지 정의 수정 FormRequest.
 *
 * 운영자 편집 가능 필드: name, description, channels, is_active.
 * provider_id/scope_type/scope_value 는 시스템 식별자라 편집 불가.
 */
class UpdateIdentityMessageDefinitionRequest extends FormRequest
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
            'name' => ['sometimes', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'description' => ['sometimes', 'nullable', 'array', new TranslatableField(maxLength: 1000)],
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters(
            'core.identity.message_definition.filter_update_rules',
            $rules,
            $this->route('definition')
        );
    }
}
