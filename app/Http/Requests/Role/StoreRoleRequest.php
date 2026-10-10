<?php

namespace App\Http\Requests\Role;

use App\Enums\PasswordExpiryPolicy;
use App\Enums\ScopeType;
use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'identifier' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique(Role::class, 'identifier')],
            'name' => ['required', new LocaleRequiredTranslatable(maxLength: 100)],
            'description' => ['nullable', new TranslatableField(maxLength: 500)],
            'is_active' => 'boolean',
            // 비밀번호 만료 (#736) — 사이트 설정 따름(기본)·적용·제외. 회원 개별 설정이 우선이고, 한 회원의 역할끼리 부딪치면 적용.
            'password_expiry_policy' => ['sometimes', 'nullable', 'string', Rule::in(PasswordExpiryPolicy::values())],
            'permissions' => 'nullable|array',
            'permissions.*.id' => ['required', 'integer', Rule::exists(Permission::class, 'id')],
            'permissions.*.scope_type' => ['nullable', Rule::enum(ScopeType::class)],
            // 역할 폼 「접근 가능 메뉴」 — 보내면 이 역할이 접근할 메뉴 전체 목록이다(안 보내면 메뉴 불변).
            'menu_ids' => ['sometimes', 'array'],
            'menu_ids.*' => ['integer', Rule::exists(Menu::class, 'id')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.role.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명을 반환합니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            // 다국어 칸 — 코어 다국어 필수 문구(「:attribute의 :language(:locale) 값은 필수입니다」)가 칸 이름으로 쓴다
            'name' => __('validation.role.attributes.name'),
            'description' => __('validation.role.attributes.description'),
            'menu_ids' => __('validation.attributes.menu_ids'),
            'menu_ids.*' => __('validation.attributes.menu_ids'),
            'password_expiry_policy' => __('validation.attributes.password_expiry_policy'),
        ];
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
            'menu_ids.array' => __('validation.role.menu_ids.array'),
            'menu_ids.*.integer' => __('validation.role.menu_ids.integer'),
            'menu_ids.*.exists' => __('validation.role.menu_ids.exists'),
            'password_expiry_policy.string' => __('user.validation.password_expiry_policy_invalid'),
            'password_expiry_policy.in' => __('user.validation.password_expiry_policy_invalid'),
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
