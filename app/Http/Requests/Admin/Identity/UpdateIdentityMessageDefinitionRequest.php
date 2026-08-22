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
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array 필드별 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'description' => ['sometimes', 'nullable', 'array', new TranslatableField(maxLength: 1000)],
            // audit:allow formrequest-sometimes-array-min-inert reason: 채널 0개는 유효한 상태가 아니다.
            // 발송 채널을 모두 해제하면 본인인증 메시지가 전달되지 않으므로 오류를 노출하는 것이 올바른 동작이다.
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters(
            'core.identity.message_definition.update_validation_rules',
            $rules,
            $this->route('definition')
        );
    }
}
