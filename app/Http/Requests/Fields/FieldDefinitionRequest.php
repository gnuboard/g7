<?php

namespace App\Http\Requests\Fields;

use App\Enums\FieldEditScope;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldSet;
use App\Fields\FieldSetRegistry;
use App\Fields\FieldTypeRegistry;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 항목 추가·수정 요청의 공통 기반.
 *
 * 권한은 라우트의 `field_set.permission` 미들웨어가 집합의 권한(`FieldSet::readPermission`·`managePermission`)으로 확인한다.
 *
 * @since 7.2.0
 */
abstract class FieldDefinitionRequest extends FormRequest
{
    /** 본인인증 값으로 연결할 수 있는 종류 */
    public const IDV_CLAIMS = ['name', 'mobile', 'birth_date', 'gender'];

    /** 선택지 개수 상한 */
    public const MAX_OPTIONS = 50;

    /**
     * 요청 권한 — 라우트 미들웨어가 집합의 권한으로 확인하므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청 대상 집합을 반환합니다.
     *
     * @return FieldSet|null 집합 (없으면 null — 컨트롤러가 404 로 응답한다)
     */
    public function fieldSet(): ?FieldSet
    {
        return app(FieldSetRegistry::class)->get((string) $this->route('set'));
    }

    /**
     * 유형 키로 유형을 반환합니다.
     *
     * @param  string|null  $key  유형 키
     * @return FieldTypeInterface|null 유형
     */
    protected function fieldType(?string $key): ?FieldTypeInterface
    {
        return $key === null ? null : app(FieldTypeRegistry::class)->get($key);
    }

    /**
     * 추가·수정에 공통인 규칙을 반환합니다.
     *
     * @param  FieldTypeInterface|null  $type  항목의 유형 (알 수 없으면 null)
     * @param  bool  $partial  부분 수정인지 (true 면 이름도 생략할 수 있다)
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    protected function commonRules(?FieldTypeInterface $type, bool $partial): array
    {
        $rules = [
            'fields_version' => ['nullable', 'string', 'max:64'],
            'name' => [$partial ? 'sometimes' : 'required', new LocaleRequiredTranslatable(maxLength: 100, strictLocales: true)],
            'description' => ['nullable', new TranslatableField(maxLength: 500, strictLocales: true)],
            'placeholder' => ['nullable', new TranslatableField(maxLength: 255, strictLocales: true)],
            'constraints' => ['nullable', 'array'],
            'constraints.unique' => ['nullable', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'required' => ['sometimes', 'boolean'],
            'show_on_signup' => ['sometimes', 'boolean'],
            'edit_scope' => ['sometimes', Rule::enum(FieldEditScope::class)],
            'require_existing_members' => ['sometimes', 'boolean'],
            'public_profile' => ['sometimes', 'boolean'],
            'admin_list_column' => ['sometimes', 'boolean'],
            'admin_searchable' => ['sometimes', 'boolean'],
            'display_name_usable' => ['sometimes', 'boolean'],
            'idv_claim' => ['nullable', 'string', Rule::in(self::IDV_CLAIMS)],
            'idv_lock' => ['sometimes', 'boolean'],
            'ui' => ['nullable', 'array'],
            'ui.group' => ['nullable', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
            'ui.width' => ['nullable', 'string', 'in:full,half'],
        ];

        if ($type !== null) {
            $rules = array_merge($rules, $this->withoutDanglingComparisons($type->constraintRules()));

            if ($type->supports(FieldTypeInterface::FEATURE_OPTIONS)) {
                $rules = array_merge($rules, $this->optionRules());
            }
        }

        return $rules;
    }

    /**
     * 비교 대상이 요청에 없는 크기 비교 규칙(`gte:다른 칸`)을 뺍니다.
     *
     * 최댓값만 보내고 최솟값을 비운 요청에서 `gte` 는 비교 대상이 없어 실패한다 — 둘 다 보냈을 때만 비교한다.
     *
     * @param  array<string, array<int, mixed>>  $rules  유형의 제한 값 규칙
     * @return array<string, array<int, mixed>> 걸러 낸 규칙
     */
    protected function withoutDanglingComparisons(array $rules): array
    {
        foreach ($rules as $attribute => $attributeRules) {
            $rules[$attribute] = array_values(array_filter($attributeRules, function ($rule) {
                if (! is_string($rule) || ! str_starts_with($rule, 'gte:')) {
                    return true;
                }
                $other = $this->input(substr($rule, 4));

                return $other !== null && $other !== '';
            }));
        }

        return $rules;
    }

    /**
     * 선택지 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    protected function optionRules(): array
    {
        return [
            'options' => ['array', 'max:'.self::MAX_OPTIONS],
            'options.*.value' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_\-]*$/', 'distinct', 'not_in:__other__'],
            'options.*.label' => ['required', new LocaleRequiredTranslatable(maxLength: 100, strictLocales: true)],
        ];
    }

    /**
     * 사용자 정의 검증 메시지.
     *
     * @return array<string, string> 규칙 => 문구
     */
    public function messages(): array
    {
        return [
            'key.regex' => __('fields.validation.key_format'),
            'key.unique' => __('fields.validation.key_unique'),
            'type.in' => __('fields.validation.type_invalid'),
            'options.required' => __('fields.validation.options_required'),
            'options.min' => __('fields.validation.options_required'),
            'options.max' => __('fields.validation.options_max', ['max' => self::MAX_OPTIONS]),
            'options.*.value.regex' => __('fields.validation.option_value_format'),
            'options.*.value.distinct' => __('fields.validation.option_value_distinct'),
            'options.*.value.not_in' => __('fields.validation.option_value_format'),
        ];
    }

    /**
     * 검증 속성명.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return [
            'key' => __('fields.attributes.key'),
            'type' => __('fields.attributes.type'),
            'name' => __('fields.attributes.name'),
            'description' => __('fields.attributes.description'),
            'placeholder' => __('fields.attributes.placeholder'),
            'options' => __('fields.attributes.options'),
            'options.*.value' => __('fields.attributes.option_value'),
            'options.*.label' => __('fields.attributes.option_label'),
            'constraints' => __('fields.attributes.constraints'),
            'constraints.min_length' => __('fields.attributes.min_length'),
            'constraints.max_length' => __('fields.attributes.max_length'),
            'constraints.min' => __('fields.attributes.min'),
            'constraints.max' => __('fields.attributes.max'),
            'constraints.decimals' => __('fields.attributes.decimals'),
            'constraints.unit' => __('fields.attributes.unit'),
            'constraints.unit.*' => __('fields.attributes.unit'),
            'constraints.rows' => __('fields.attributes.rows'),
            'constraints.regex' => __('fields.attributes.regex'),
            'constraints.regex_message' => __('fields.attributes.regex_message'),
            'constraints.regex_message.*' => __('fields.attributes.regex_message'),
            'constraints.display' => __('fields.attributes.display'),
            'constraints.allow_other' => __('fields.attributes.allow_other'),
            'constraints.min_select' => __('fields.attributes.min_select'),
            'constraints.max_select' => __('fields.attributes.max_select'),
            'constraints.widget' => __('fields.attributes.widget'),
            'constraints.inline_label' => __('fields.attributes.inline_label'),
            'constraints.inline_label.*' => __('fields.attributes.inline_label'),
            'constraints.default' => __('fields.attributes.default'),
            'constraints.extensions' => __('fields.attributes.extensions'),
            'constraints.extensions.*' => __('fields.attributes.extensions'),
            'constraints.max_count' => __('fields.attributes.max_count'),
            'constraints.max_size_mb' => __('fields.attributes.max_size_mb'),
            'constraints.unique' => __('fields.attributes.unique'),
            'enabled' => __('fields.attributes.enabled'),
            'required' => __('fields.attributes.required'),
            'show_on_signup' => __('fields.attributes.show_on_signup'),
            'edit_scope' => __('fields.attributes.edit_scope'),
            'require_existing_members' => __('fields.attributes.require_existing_members'),
            'public_profile' => __('fields.attributes.public_profile'),
            'admin_list_column' => __('fields.attributes.admin_list_column'),
            'admin_searchable' => __('fields.attributes.admin_searchable'),
            'display_name_usable' => __('fields.attributes.display_name_usable'),
            'idv_claim' => __('fields.attributes.idv_claim'),
            'idv_lock' => __('fields.attributes.idv_lock'),
            'ui' => __('fields.attributes.ui'),
            'ui.group' => __('fields.attributes.ui_group'),
            'ui.width' => __('fields.attributes.ui_width'),
            'fields_version' => __('fields.attributes.fields_version'),
            'order' => __('fields.attributes.order'),
            'order.*' => __('fields.attributes.order'),
        ];
    }
}
