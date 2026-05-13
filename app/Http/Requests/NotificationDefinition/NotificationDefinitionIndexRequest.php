<?php

namespace App\Http\Requests\NotificationDefinition;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class NotificationDefinitionIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'extension_type' => ['nullable', 'string', 'in:core,module,plugin'],
            'extension_identifier' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:id,type,extension_type,is_active,created_at,updated_at'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters(
            'core.notification_definition.filter_index_rules',
            $rules
        );
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'per_page.min' => __('validation.min.numeric', ['attribute' => 'per_page', 'min' => 1]),
            'per_page.max' => __('validation.max.numeric', ['attribute' => 'per_page', 'max' => 100]),
        ];
    }
}
