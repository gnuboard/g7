<?php

namespace App\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Extension\Template\TemplateMenu;
use App\Extension\Template\TemplateSettingsSchema;
use App\Helpers\InlineMediaPolicy;
use App\Models\Attachment;
use App\Rules\SafeLinkRule;
use App\Rules\TranslatableField;
use App\Services\TemplateSettingsService;
use App\Support\LocalizedValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;

/**
 * 템플릿 설정 저장 요청 검증 (#434)
 *
 * 템플릿 선언 파일(`config/settings/defaults.json`)의 칸 선언(`schema.{섹션}.{키}`)으로 규칙을 만든다 — 플러그인 설정
 * 요청({@see UpdatePluginSettingsRequest})과 같은 방식이고, 스키마 출처만 PHP 클래스 대신 선언 파일이다.
 * 입력은 섹션 중첩(`{섹션: {키: 값}}`)이며 제출한 섹션·키만 검증·저장한다.
 *
 * 첨부 칸은 첨부 ID 배열이며, 각 ID 는 그 칸 컬렉션(`template_settings.{템플릿}.{섹션}.{키}`)에 실재해야 한다 —
 * 다른 템플릿·코어 로고의 첨부를 끌어올 수 없다. 이미지 칸은 이미지 첨부만 받는다.
 */
class UpdateTemplateSettingsRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어(`core.templates.settings`)가 한다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 전 입력을 정리합니다 — 화면이 되돌려 보내는 첨부 객체를 ID 로 바꾸고, 서버 계산값·선언 밖 섹션을 뺀다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $schema = $this->schema();

        if ($schema === null) {
            return;
        }

        $input = Arr::only($this->all(), $schema->categories);

        foreach ($schema->computedPaths() as $path) {
            Arr::forget($input, $path);
        }

        foreach ($schema->attachmentPaths() as $path) {
            $value = Arr::get($input, $path);

            if (is_array($value)) {
                Arr::set($input, $path, array_values(array_map(
                    fn ($item) => is_array($item) ? ($item['id'] ?? null) : $item,
                    $value
                )));
            }
        }

        $this->replace($input);
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $identifier = (string) $this->route('identifier');
        $schema = $this->schema();
        $rules = [];

        if ($schema !== null) {
            foreach ($schema->categories as $category) {
                $rules[$category] = ['sometimes', 'array'];
            }

            foreach ($schema->flatFields() as $path => $field) {
                foreach ($this->fieldRules($schema, $path, $field) as $attribute => $fieldRules) {
                    $rules[$attribute] = $fieldRules;
                }
            }
        }

        // 3번째 인자로 이번 요청 입력값을 넘긴다 — 확장이 입력값에 따른 조건부 규칙을 만들 수 있게(플러그인 설정 요청과 같은 계약).
        return HookManager::applyFilters('core.template_settings.update_validation_rules', $rules, $identifier, $this->all());
    }

    /**
     * 칸 하나의 규칙 — 칸 자신과 하위(`.*`·`.*.{행 칸}`) 규칙을 함께 돌려준다.
     *
     * 제출하지 않은 칸은 기존 값을 유지하므로 `required` 는 `sometimes` 와 함께 건다(보낸 경우에만 비우지 못한다).
     *
     * @param  TemplateSettingsSchema  $schema  선언
     * @param  string  $path  칸 경로 (`섹션.키`)
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<string, array<int, mixed>> 속성 => 규칙
     */
    private function fieldRules(TemplateSettingsSchema $schema, string $path, array $field): array
    {
        $presence = ($field['required'] ?? false) ? ['sometimes', 'required'] : ['sometimes', 'nullable'];
        $rules = [$path => array_merge($presence, $this->valueRules($field))];

        switch ($field['type']) {
            case 'attachments':
            case 'image':
                $rules[$path] = array_merge($presence, ['array', 'max:'.$schema->maxFiles($path)]);
                $exists = Rule::exists(Attachment::class, 'id')->where('collection', $schema->collection($path));

                if ($field['type'] === 'image') {
                    $exists = $exists->whereIn('mime_type', InlineMediaPolicy::RASTER_IMAGE_MIME_TYPES);
                }

                $rules[$path.'.*'] = ['integer', $exists];
                break;

            case 'list':
                $rules[$path] = array_merge($presence, ['array', 'max:'.(int) ($field['max_items'] ?? 20)]);
                $rules[$path.'.*'] = ['array'];

                foreach ((array) ($field['item_fields'] ?? []) as $itemKey => $itemField) {
                    $itemPresence = ($itemField['required'] ?? false) ? ['required'] : ['nullable'];
                    $rules[$path.'.*.'.$itemKey] = array_merge($itemPresence, $this->valueRules($itemField));
                }
                break;

            case 'menu':
                // 칸 단위 규칙만 여기 — 종류별 필수·자동 항목 1회·깊이·전체 수·id 중복은 after() 가 항목 위치에 단다
                $rules[$path] = array_merge($presence, ['array']);
                $rules = array_merge($rules, TemplateMenu::itemRules($path, $field));
                break;
        }

        return $rules;
    }

    /**
     * 값 종류별 규칙(첨부·목록 제외).
     *
     * @param  array<string, mixed>  $field  칸 정의
     * @return array<int, mixed> 규칙
     */
    private function valueRules(array $field): array
    {
        $maxLength = isset($field['max_length']) ? (int) $field['max_length'] : null;

        return match ($field['type']) {
            'string' => array_filter(['string', isset($field['min']) ? 'min:'.(int) $field['min'] : null, 'max:'.($maxLength ?? 1000)]),
            'text' => ['string', 'max:'.($maxLength ?? 10000)],
            'integer' => array_values(array_filter([
                'integer',
                isset($field['min']) ? 'min:'.(int) $field['min'] : null,
                isset($field['max']) ? 'max:'.(int) $field['max'] : null,
                isset($field['step']) && (int) $field['step'] > 1 ? 'multiple_of:'.(int) $field['step'] : null,
            ])),
            'boolean' => ['boolean'],
            'enum' => [Rule::in(TemplateSettingsSchema::optionValues($field))],
            'url' => ['url:http,https', 'max:2048'],
            'link' => ['string', new SafeLinkRule],
            'email' => ['email', 'max:255'],
            'color' => ['string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'],
            'multilingual' => [new TranslatableField($maxLength ?? 1000, (bool) ($field['required'] ?? false))],
            default => [],
        };
    }

    /**
     * 검증 오류에 쓸 칸 표시명 — 「섹션 · 칸」(선언 라벨을 현재 로케일로 푼 값).
     *
     * 확장·언어팩이 `validation.attributes.{섹션}.{키}` 를 현재 로케일로 등록했으면 그것이 우선이다.
     *
     * @return array<string, string> 속성 => 표시명
     */
    public function attributes(): array
    {
        $locale = app()->getLocale();
        $attributes = [];
        $labels = app(TemplateSettingsService::class)->fieldLabels((string) $this->route('identifier'));

        foreach ($labels as $path => $label) {
            $translationKey = 'validation.attributes.'.$path;

            if (Lang::has($translationKey, $locale, false)) {
                $resolved = Lang::get($translationKey, [], $locale);
            } elseif (str_contains($path, '.*.')) {
                // 목록 칸의 행 칸 — 「칸 N번째 항목의 행 칸」. `:position` 은 검증기가 오류 위치의 1부터 센 자리로 바꾼다
                [$listPath, $itemKey] = explode('.*.', $path, 2);
                $listLabel = $labels[$listPath] ?? $listPath;
                $itemLabel = str_starts_with($label, $listLabel.' · ') ? substr($label, strlen($listLabel) + 3) : $itemKey;
                $resolved = __('templates.settings.item_attributes.row', ['field' => $listLabel, 'position' => ':position', 'key' => $itemLabel], $locale);
            } else {
                $resolved = $label;
            }

            $attributes[$path] = $resolved;

            if (! str_contains($path, '.*.')) {
                $attributes[$path.'.*'] = $resolved;
            }
        }

        // 메뉴 칸 항목 — 보낸 트리를 따라 자리마다 「칸 N번째 항목(이름)의 키」 를 단다(하위 메뉴는 상위 항목 이름 아래 자리)
        $schema = $this->schema();
        foreach ($schema === null ? [] : $schema->flatFields() as $path => $field) {
            if (($field['type'] ?? null) === 'menu' && is_array($items = Arr::get($this->all(), $path))) {
                $this->menuItemAttributes($attributes, $path, null, $labels[$path] ?? $path, $items, $locale);
            }
        }

        return $attributes;
    }

    /**
     * 메뉴 항목 자리마다 검증 오류 표시 이름을 답니다 (하위 메뉴까지).
     *
     * @param  array<string, string>  $attributes  표시 이름 목록 (채운다)
     * @param  string  $path  이 단계 항목 목록의 경로 (`header.menu` · `header.menu.2.children`)
     * @param  string|null  $parent  상위 항목 표시 이름 (최상위면 null)
     * @param  string  $fieldLabel  칸 표시 이름 (「섹션 · 칸」)
     * @param  array<int|string, mixed>  $items  이 단계 항목 목록 (요청 입력)
     * @param  string  $locale  로케일
     * @return void 반환값 없음
     */
    private function menuItemAttributes(array &$attributes, string $path, ?string $parent, string $fieldLabel, array $items, string $locale): void
    {
        $keys = (array) __('templates.settings.item_attributes.keys', [], $locale);

        foreach ($items as $index => $item) {
            if (! is_int($index) || ! is_array($item)) {
                continue;
            }

            $name = LocalizedValue::pick($item['label'] ?? null, $locale);
            $name = is_string($name) ? trim($name) : '';
            $replace = ['field' => $fieldLabel, 'parent' => (string) $parent, 'position' => $index + 1, 'name' => $name];
            $line = ($parent === null ? 'item' : 'child').($name !== '' ? '_named' : '');
            $itemLabel = __('templates.settings.item_attributes.'.$line, $replace, $locale);
            $at = $path.'.'.$index;

            $attributes[$at] = $itemLabel;
            foreach ($keys as $key => $keyLabel) {
                $attributes[$at.'.'.$key] = __('templates.settings.item_attributes.key', ['item' => $itemLabel, 'key' => $keyLabel], $locale);
            }
            $attributes[$at.'.roles.*'] = $attributes[$at.'.roles'] ?? $itemLabel;

            if (is_array($item['children'] ?? null)) {
                $this->menuItemAttributes($attributes, $at.'.children', $itemLabel, $fieldLabel, $item['children'], $locale);
            }
        }
    }

    /**
     * 칸 규칙 뒤에 도는 검사 — 메뉴 칸의 구조 위반을 그 항목 위치(`섹션.키.2.children.0`)에 단다.
     *
     * 편집기는 오류 위치로 어느 항목을 고칠지 표시하므로, 칸 전체 하나로 뭉치지 않는다.
     *
     * @return array<int, callable> 후속 검사
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $schema = $this->schema();

                if ($schema === null) {
                    return;
                }

                foreach ($schema->flatFields() as $path => $field) {
                    if ($field['type'] !== 'menu' || ! Arr::has($this->all(), $path) || Arr::get($this->all(), $path) === null) {
                        continue;
                    }

                    foreach (TemplateMenu::messages($path, Arr::get($this->all(), $path), $field) as $attribute => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($attribute, $message);
                        }
                    }
                }
            },
        ];
    }

    /**
     * 이 요청 대상 템플릿의 선언을 돌려줍니다.
     *
     * @return TemplateSettingsSchema|null 선언
     */
    private function schema(): ?TemplateSettingsSchema
    {
        return app(TemplateSettingsService::class)->schema((string) $this->route('identifier'));
    }
}
