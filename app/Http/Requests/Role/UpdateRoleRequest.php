<?php

namespace App\Http\Requests\Role;

use App\Enums\ScopeType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 역할 수정 요청 검증
 *
 * 역할의 name, description, is_active, permission_ids를 수정할 수 있습니다.
 * name과 description은 다국어 필드로 처리됩니다.
 */
class UpdateRoleRequest extends FormRequest
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
     * 검증 전 데이터 전처리
     *
     * 역호환성을 위해 문자열로 들어온 name, description을
     * 다국어 배열 형식으로 자동 변환합니다.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);

        // name이 문자열로 들어온 경우 배열로 변환 (역호환성)
        if ($this->has('name') && is_string($this->name)) {
            $nameArray = [];
            foreach ($locales as $locale) {
                $nameArray[$locale] = $this->name;
            }
            $this->merge(['name' => $nameArray]);
        }

        // description이 문자열로 들어온 경우 배열로 변환
        if ($this->has('description') && is_string($this->description)) {
            $descriptionArray = [];
            foreach ($locales as $locale) {
                $descriptionArray[$locale] = $this->description;
            }
            $this->merge(['description' => $descriptionArray]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', new LocaleRequiredTranslatable(maxLength: 100)],
            'description' => ['nullable', new TranslatableField(maxLength: 500)],
            'is_active' => 'sometimes|boolean',
            'permissions' => 'nullable|array',
            'permissions.*.id' => ['required', 'integer', Rule::exists(Permission::class, 'id')],
            'permissions.*.scope_type' => ['nullable', Rule::enum(ScopeType::class)],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.role.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('role.validation.name_required'),
            'permissions.array' => __('role.validation.permission_ids_array'),
            'permissions.*.id.exists' => __('role.validation.permission_ids_exists'),
            'permissions.*.id.integer' => __('role.validation.permission_ids_integer'),
        ];
    }
}
