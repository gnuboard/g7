<?php

namespace App\Http\Requests\Menu;

use App\Extension\HookManager;
use App\Models\Menu;
use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\MenuIconSpecRule;
use App\Rules\NotCircularParent;
use App\Support\MenuIcon\MenuIconSpec;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 권한 검증 결과 (항상 true)
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
        // Route Model Binding으로 Menu 모델을 받으므로 menu->id 사용
        $menuId = $this->route('menu')?->id;

        $rules = [
            'name' => ['nullable', new LocaleRequiredTranslatable(maxLength: 255)],
            'slug' => ['required', 'string', 'max:255', Rule::unique(Menu::class, 'slug')->ignore($menuId)],
            'url' => 'nullable|string|max:500',
            // 레거시 icon 문자열은 FA 클래스만 받는다(#381) — 이미지·SVG 는 icon_spec 으로. 클래스가 아닌 문자열은 원래 빈 칸으로 저장되던 값이다.
            // 단, 현재 저장값을 그대로 되돌려 보내면(아이콘 칸 모르는 템플릿이 예전 비클래스 문자열을 실어 보냄) 형식 검사를 건너뛴다.
            'icon' => $this->keepsStoredIcon()
                ? ['nullable', 'string']
                : ['nullable', 'string', 'max:100', 'regex:'.MenuIconSpec::CLASS_PATTERN],
            'icon_spec' => ['nullable', 'array', new MenuIconSpecRule($this->route('menu') instanceof Menu ? $this->route('menu') : null)],
            // 순서 변경 엔드포인트(UpdateMenuOrderRequest)와 동일하게 자손 전체를 검사한다.
            // NotCircularParent 생성자는 int non-nullable 이므로 $menuId 가 있을 때만 부착한다.
            'parent_id' => ['nullable', 'integer', Rule::exists(Menu::class, 'id')],
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'extension_type' => 'nullable|string|in:core,module,plugin',
            'extension_identifier' => 'nullable|string|max:255',
            'roles' => 'nullable|array',
            'roles.*' => ['integer', Rule::exists(Role::class, 'id')],
        ];

        if ($menuId !== null) {
            $rules['parent_id'][] = new NotCircularParent((int) $menuId);
        }

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.menu.update_validation_rules', $rules, $this);
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
            'icon.regex' => __('validation.menu.icon.format'),
            'icon.string' => __('validation.menu.icon.format'),
            'icon_spec.array' => __('validation.menu.icon_spec.array'),
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
     * 요청의 레거시 icon 문자열이 메뉴의 현재 저장값을 그대로 되돌려 보낸 것인지 판정합니다 (#381).
     *
     * @return bool 현재 저장값과 같은 아이콘 문자열이면 true
     */
    private function keepsStoredIcon(): bool
    {
        $menu = $this->route('menu');
        $icon = $this->input('icon');

        return $menu instanceof Menu && is_string($icon) && MenuIconSpec::isUnchanged($icon, $menu->getRawOriginal('icon'));
    }

    /**
     * 검증 항목 표시 이름을 반환합니다.
     *
     * 다국어 칸은 코어 다국어 필수 문구(「:attribute의 :language(:locale) 값은 필수입니다」)가 칸 이름으로 쓴다.
     * 없으면 칸 이름 없이 「한국어(ko) 값은 필수입니다」만 나왔다(2026-10-07 실측).
     *
     * @return array<string, string> 필드 → 표시 이름
     */
    public function attributes(): array
    {
        return [
            'name' => __('validation.menu.attributes.name'),
        ];
    }

    /**
     * 검증 전 데이터 전처리
     *
     * 역호환성을 위해 문자열로 입력된 name을 다국어 배열로 자동 변환합니다.
     *
     * @return void 반환값 없음
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
