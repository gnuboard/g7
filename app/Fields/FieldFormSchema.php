<?php

namespace App\Fields;

use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\Types\FileType;
use App\Models\FieldDefinition;
use Illuminate\Support\Collection;

/**
 * 화면이 항목을 그릴 때 쓰는 항목 계약을 만든다.
 *
 * 가입 화면, 내 정보 수정, 추가 정보 입력, 관리자 회원 폼이 같은 모양을 받는다. 화면은 이 계약만 보고 입력칸을 그리므로
 * 라벨·안내·선택지는 요청 로케일로 풀어서 싣고, 필수 여부는 검증 규칙(`FieldRuleFactory`)과 같은 조건으로 계산한다.
 *
 * @since 7.2.0
 */
class FieldFormSchema
{
    /** 화면에 싣지 않는 제한 키 (서버 전용) */
    private const HIDDEN_CONSTRAINTS = ['options_source', 'regex', 'format', 'schemes', 'parts'];

    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldValueService  $values  항목 값 Service (표시 문자열·빈 값 판정)
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldValueService $values,
    ) {}

    /**
     * 표면에서 그릴 항목 계약 목록을 반환합니다.
     *
     * 가입 표면에서는 가입 화면에서 받지 못하는 유형(파일·이미지)도 `deferred: true` 로 싣는다 —
     * 화면이 「가입 후 올릴 수 있습니다」 안내 줄을 그리기 위해서다. 그 항목은 검증·저장 대상이 아니다.
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @param  array<string, mixed>  $current  항목 키 => 현재 값 (수정 화면. 가입은 빈 배열)
     * @return array<int, array<string, mixed>> 표시 순서의 항목 계약
     */
    public function forSurface(string $set, FieldContext $context, array $current = []): array
    {
        $definitions = $this->registry->forSurface($set, $context);

        if ($context->surface === FieldContext::SURFACE_SIGNUP) {
            $definitions = $definitions->concat($this->deferredOnSignup($set, $context, $definitions))
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->values();
        }

        $fields = [];
        foreach ($definitions as $definition) {
            $type = $this->registry->typeOf($definition);
            if ($type === null) {
                continue;
            }
            $fields[] = $this->describe($definition, $type, $context, $current[$definition->key] ?? null);
        }

        return $fields;
    }

    /**
     * 항목 하나의 계약을 만듭니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldTypeInterface  $type  유형
     * @param  FieldContext  $context  요청 문맥
     * @param  mixed  $value  현재 값 (없으면 null)
     * @return array<string, mixed> 항목 계약
     */
    public function describe(FieldDefinition $definition, FieldTypeInterface $type, FieldContext $context, mixed $value = null): array
    {
        $attribute = $this->registry->attributeFor($definition);
        $deferred = $context->surface === FieldContext::SURFACE_SIGNUP && ! $type->supports(FieldTypeInterface::FEATURE_SIGNUP);
        $constraints = $definition->constraints ?? [];
        $ui = $definition->ui ?? [];

        return [
            'key' => $definition->key,
            // 폼 경로 — 입력칸의 name 이자 검증 오류 키
            'name' => $attribute,
            'type' => $definition->type,
            'renderer' => $type->renderer(),
            'owner' => $definition->isCustom() ? 'custom' : $definition->source_identifier,
            'owner_type' => $definition->source_type,
            // 선언한 확장의 이름 — 화면이 확장별 묶음 제목으로 쓴다(코어 항목과 운영자 추가 항목은 null)
            'source_label' => $this->registry->sourceLabel($definition),
            'label' => $definition->localized('name') ?? $definition->key,
            'placeholder' => $definition->localized('placeholder'),
            'help' => $definition->localized('description'),
            'required' => ! $deferred && $this->isRequired($definition, $context, $value),
            // 요청 한 번에만 걸리는 잠금 — 필터 `core.field.filter_definitions` 구독자가 항목 사본의 `ui.readonly`,
            // `ui.locked_reason` 을 채우면 그대로 나간다(소셜, SSO 가입 창에서 공급자가 관리하는 칸). 초기값은 `constraints.default`.
            'readonly' => ($ui['readonly'] ?? false) === true,
            'locked_reason' => is_string($ui['locked_reason'] ?? null) ? $ui['locked_reason'] : null,
            'deferred' => $deferred,
            'options' => $this->options($definition),
            'constraints' => (object) $this->publicConstraints($constraints),
            'default' => $this->defaultValue($definition),
            'ui' => (object) ($definition->ui ?? []),
            'parts' => $this->parts($definition, $type, $attribute),
            // 보기용 글자 — 값이 없으면 null. 「아니오」(false)는 필수 판정에서는 빈 값이지만 저장된 답이므로 글자로 보인다.
            'display_value' => $value !== false && $this->values->isEmpty($definition, $value) ? null : $this->values->display($definition, $value),
            // 파일·이미지 항목 — 지금 올라가 있는 파일과 올릴 때의 제한(확장자, 개수, 크기). 그 밖의 유형은 null
            'files' => $type instanceof FileType ? (is_array($value) ? array_values($value) : []) : null,
            'file_rules' => $type instanceof FileType ? [
                'extensions' => $type->allowedExtensions($definition),
                'max_count' => $type->maxCount($definition),
                'max_size_mb' => $type->maxSizeMb($definition),
            ] : null,
        ];
    }

    /**
     * 가입 화면에 안내 줄로만 나타나는 항목(가입에서 받지 못하는 유형)을 고릅니다.
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @param  Collection<int, FieldDefinition>  $already  이미 고른 항목
     * @return Collection<int, FieldDefinition> 안내 줄 항목
     */
    protected function deferredOnSignup(string $set, FieldContext $context, Collection $already): Collection
    {
        $keys = $already->map(fn (FieldDefinition $definition) => $definition->key)->all();

        return $this->registry->active($set, $context->scopeKey, $context)
            ->filter(fn (FieldDefinition $definition) => ! in_array($definition->key, $keys, true)
                && $definition->show_on_signup
                && $definition->edit_scope === FieldDefinition::SCOPE_SELF
                && $this->registry->typeOf($definition)?->supports(FieldTypeInterface::FEATURE_SIGNUP) === false)
            ->values();
    }

    /**
     * 화면에 필수로 표시할지 반환합니다 (`FieldRuleFactory::isRequired()` 와 같은 조건).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldContext  $context  요청 문맥
     * @param  mixed  $value  현재 값
     * @return bool 필수면 true
     */
    protected function isRequired(FieldDefinition $definition, FieldContext $context, mixed $value): bool
    {
        if (! $definition->required) {
            return false;
        }

        return ! ($context->surface === FieldContext::SURFACE_ADMIN_UPDATE && $this->values->isEmpty($definition, $value));
    }

    /**
     * 선택지를 요청 로케일의 글자로 풉니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<int, array{value: string, label: string}> 선택지
     */
    protected function options(FieldDefinition $definition): array
    {
        $options = [];
        foreach ($definition->options ?? [] as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }
            $value = (string) $option['value'];
            $options[] = [
                'value' => $value,
                'label' => FieldDefinition::localizeValue($option['label'] ?? null) ?? $value,
            ];
        }

        return $options;
    }

    /**
     * 화면에 실을 제한 값을 만듭니다 (서버 전용 키를 빼고 다국어 값을 푼다).
     *
     * @param  array<string, mixed>  $constraints  제한 값
     * @return array<string, mixed> 화면용 제한 값
     */
    protected function publicConstraints(array $constraints): array
    {
        $public = array_diff_key($constraints, array_flip(self::HIDDEN_CONSTRAINTS));
        // 다국어로 입력받는 제약(체크 문구, 형식 오류 문구, 숫자 단위)은 요청 로케일의 글자로 푼다 — 객체로 나가면 화면에 「[object Object]」가 보인다.
        foreach (['inline_label', 'regex_message', 'unit'] as $localized) {
            if (array_key_exists($localized, $public)) {
                $public[$localized] = FieldDefinition::localizeValue($public[$localized]);
            }
        }

        return $public;
    }

    /**
     * 값이 없을 때 화면이 보여 줄 기본값을 반환합니다.
     *
     * 화면은 이 값을 보여 주기만 하고 회원이 바꾸기 전에는 보내지 않는다(언어 칸은 지금 보는 언어가 골라져 보인다).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return mixed 기본값 (없으면 null)
     */
    protected function defaultValue(FieldDefinition $definition): mixed
    {
        if ($definition->constraint('options_source') === 'supported_locales') {
            return app()->getLocale();
        }

        return $definition->constraint('default');
    }

    /**
     * 여러 칸으로 받는 유형(주소)의 부분별 폼 경로를 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldTypeInterface  $type  유형
     * @param  string  $attribute  요청 속성 경로
     * @return array<string, string>|null 부분 => 폼 경로 (한 칸 유형은 null)
     */
    protected function parts(FieldDefinition $definition, FieldTypeInterface $type, string $attribute): ?array
    {
        return method_exists($type, 'partPaths') ? $type->partPaths($definition, $attribute) : null;
    }
}
