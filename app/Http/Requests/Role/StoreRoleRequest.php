<?php

namespace App\Http\Requests\Role;

use App\Enums\ScopeType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 역할 생성 요청 검증
 */
class StoreRoleRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool 권한 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'identifier' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique(Role::class, 'identifier')],
            'name' => ['required', new LocaleRequiredTranslatable(maxLength: 100)],
            'description' => ['nullable', new TranslatableField(maxLength: 500)],
            'is_active' => 'boolean',
            'permissions' => 'nullable|array',
            'permissions.*.id' => ['required', 'integer', Rule::exists(Permission::class, 'id')],
            'permissions.*.scope_type' => ['nullable', Rule::enum(ScopeType::class)],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.role.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => __('role.validation.identifier_required'),
            'identifier.regex' => __('role.validation.identifier_format'),
            'identifier.unique' => __('role.validation.identifier_unique'),
            'identifier.max' => __('role.validation.identifier_max'),
            'name.required' => __('role.validation.name_required'),
            'permissions.array' => __('role.validation.permission_ids_array'),
            'permissions.*.id.exists' => __('role.validation.permission_ids_exists'),
            'permissions.*.id.integer' => __('role.validation.permission_ids_integer'),
        ];
    }

    /**
     * 검증 전 데이터 전처리
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);

        // 문자열로 입력된 name을 다국어 배열로 변환
        if ($this->has('name') && is_string($this->name)) {
            $nameArray = [];
            foreach ($locales as $locale) {
                $nameArray[$locale] = $this->name;
            }
            $this->merge(['name' => $nameArray]);
        }

        // description도 동일하게 처리
        if ($this->has('description') && is_string($this->description)) {
            $descArray = [];
            foreach ($locales as $locale) {
                $descArray[$locale] = $this->description;
            }
            $this->merge(['description' => $descArray]);
        }

        // is_active 기본값
        if (! $this->has('is_active')) {
            $this->merge(['is_active' => true]);
        }
    }
}
