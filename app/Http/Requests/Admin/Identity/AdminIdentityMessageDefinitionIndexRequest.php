<?php

namespace App\Http\Requests\Admin\Identity;

use App\Extension\HookManager;
use App\Models\IdentityMessageDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IDV 메시지 정의 목록 조회 FormRequest.
 */
class AdminIdentityMessageDefinitionIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'provider_id' => ['nullable', 'string', 'max:64'],
            'scope_type' => ['nullable', 'string', Rule::in([
                IdentityMessageDefinition::SCOPE_PROVIDER_DEFAULT,
                IdentityMessageDefinition::SCOPE_PURPOSE,
                IdentityMessageDefinition::SCOPE_POLICY,
            ])],
            'scope_value' => ['nullable', 'string', 'max:120'],
            'extension_type' => ['nullable', 'string', Rule::in(['core', 'module', 'plugin'])],
            'extension_identifier' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in([
                'id', 'provider_id', 'scope_type', 'scope_value', 'is_active', 'created_at', 'updated_at',
            ])],
            'sort_order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];

        return HookManager::applyFilters(
            'core.identity.message_definition.filter_index_rules',
            $rules
        );
    }

    /**
     * 검증 메시지.
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
