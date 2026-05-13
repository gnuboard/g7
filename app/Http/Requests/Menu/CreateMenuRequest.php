<?php

namespace App\Http\Requests\Menu;

use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Module;
use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMenuRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
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
            'name' => ['required', new LocaleRequiredTranslatable(maxLength: 255)],
            'slug' => ['required', 'string', 'max:255', Rule::unique(Menu::class, 'slug')],
            'url' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:100',
            'parent_id' => ['nullable', 'integer', Rule::exists(Menu::class, 'id')],
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'extension_type' => 'nullable|string|in:core,module,plugin',
            'extension_identifier' => 'nullable|string|max:255',
            'roles' => 'nullable|array',
            'roles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.menu.create_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('validation.menu.name.required'),
            'slug.required' => __('validation.menu.slug.required'),
            'slug.unique' => __('validation.menu.slug.unique'),
            'slug.max' => __('validation.menu.slug.max'),
            'url.max' => __('validation.menu.url.max'),
            'icon.max' => __('validation.menu.icon.max'),
            'order.integer' => __('validation.menu.order.integer'),
            'order.min' => __('validation.menu.order.min'),
            'parent_id.exists' => __('validation.menu.parent_id.exists'),
            'is_active.boolean' => __('validation.menu.is_active.boolean'),
            'extension_type.in' => __('validation.menu.extension_type.in'),
            'extension_identifier.max' => __('validation.menu.extension_identifier.max'),
            'roles.array' => __('validation.menu.roles.array'),
            'roles.*.integer' => __('validation.menu.roles.integer'),
            'roles.*.exists' => __('validation.menu.roles.exists'),
        ];
    }

    /**
     * 검증 전 데이터 전처리
     *
     * 역호환성을 위해 문자열로 입력된 name을 다국어 배열로 자동 변환합니다.
     */
    protected function prepareForValidation(): void
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);

        if ($this->has('name') && is_string($this->name)) {
            $nameArray = [];
            foreach ($locales as $locale) {
                $nameArray[$locale] = $this->name;
            }
            $this->merge(['name' => $nameArray]);
        }
    }
}
