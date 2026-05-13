<?php

namespace App\Http\Requests\NotificationDefinition;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationDefinitionRequest extends FormRequest
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
     * 검증 규칙을 반환합니다.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['string', 'max:50'],
            'hooks' => ['sometimes', 'array'],
            'hooks.*' => ['string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return HookManager::applyFilters(
            'core.notification_definition.filter_update_rules',
            $rules,
            $this->route('definition')
        );
    }
}
