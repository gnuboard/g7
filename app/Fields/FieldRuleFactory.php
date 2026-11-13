<?php

namespace App\Fields;

use App\Extension\HookManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Rules\NicknamePolicy;
use App\Services\IdentityClaimsService;
use App\Services\NicknamePolicyService;
use App\Services\UserLockedFieldsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * 항목 검증 규칙을 만드는 한 곳.
 *
 * 요청 클래스는 「고정 규칙 + 이 팩토리의 규칙」을 만든 뒤 자기 필터 훅을 그대로 태운다.
 * 꺼진 항목과 그 표면에서 받지 않는 항목은 규칙에서 빠진다 — 요청이 그 키를 보내도 검증된 데이터에
 * 들어가지 않으므로 조용히 무시된다.
 *
 * @since 7.2.0
 */
class FieldRuleFactory
{
    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldValueService  $values  항목 값 서비스 (관리자 수정의 현재 값, 중복 검사)
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldValueService $values,
    ) {}

    /**
     * 표면의 검증 규칙을 반환합니다.
     *
     * | 조건 | 규칙 |
     * | --- | --- |
     * | 꺼진 항목, 표면에서 받지 않는 항목 | 제외 |
     * | 필수 항목 | required (예/아니오는 accepted) |
     * | 관리자 수정에서 대상의 현재 값이 빈 필수 항목 | nullable |
     * | 그 밖 | nullable |
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @return array<string, array<int, mixed>> 요청 속성 경로 => 규칙 배열
     */
    public function rules(string $set, FieldContext $context): array
    {
        $definitions = $this->registry->forSurface($set, $context);
        $current = $this->currentValues($set, $context, $definitions);

        $rules = [];
        foreach ($definitions as $definition) {
            $type = $this->registry->typeOf($definition);
            if ($type === null) {
                continue;
            }

            $attribute = $this->registry->attributeFor($definition);
            $typeRules = $type->rules($definition, $attribute);
            if ($typeRules === []) {
                continue;
            }

            $required = $this->isRequired($definition, $context, $current);
            $requiredPaths = $required ? ($type->requiredAttributes($definition, $attribute) ?: [$attribute]) : [];

            foreach ($typeRules as $path => $pathRules) {
                $rules[$path] = $this->withPresence($definition, $path, $pathRules, $attribute, $requiredPaths);
            }

            $rules[$attribute] = [
                ...($rules[$attribute] ?? []),
                ...$this->uniqueRules($definition, $type, $context),
                ...$this->handlerRules($definition, $attribute, $context),
                ...$this->policyRules($definition, $context),
                ...$this->displayNameWordRules($definition, $context),
                ...$this->identityLockRules($definition, $type, $context),
            ];
            if ($rules[$attribute] === []) {
                unset($rules[$attribute]);
            }
        }

        $filtered = HookManager::applyFilters('core.field.filter_rules', $rules, $set, $context->surface, $context->toArray());

        return is_array($filtered) ? $filtered : $rules;
    }

    /**
     * 값이 항목 유형의 규칙에 맞는지 반환합니다 (요청이 아닌 곳에서 온 값을 항목에 넣기 전에 본다).
     *
     * 본인인증 값처럼 서버가 받아 온 값을 회원 항목에 채울 때, 선택지에 없는 값이나 형식이 다른 값을 걸러 낸다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  넣으려는 값
     * @return bool 유형 규칙을 통과하면 true (유형을 쓸 수 없으면 false)
     */
    public function accepts(FieldDefinition $definition, mixed $value): bool
    {
        $type = $this->registry->typeOf($definition);
        if ($type === null) {
            return false;
        }

        $rules = $type->rules($definition, 'value');

        return $rules === [] || ! Validator::make(['value' => $value], $rules)->fails();
    }

    /**
     * 검증 오류 문구에 쓸 속성 이름을 반환합니다 (요청 클래스의 attributes() 에 합친다).
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @param  bool  $skipColumns  컬럼 저장 항목을 뺄지 (기존 요청은 컬럼 칸의 이름을 언어 파일의 검증 라벨에서 받는다)
     * @return array<string, string> 요청 속성 경로 => 항목 이름
     */
    public function attributes(string $set, FieldContext $context, bool $skipColumns = false): array
    {
        $names = [];
        foreach ($this->registry->forSurface($set, $context) as $definition) {
            if ($skipColumns && $definition->storage === FieldDefinition::STORAGE_COLUMN) {
                continue;
            }
            $type = $this->registry->typeOf($definition);
            $label = $definition->localized('name') ?? $definition->key;
            $attribute = $this->registry->attributeFor($definition);

            $names[$attribute] = $label;
            foreach ($type?->inputPaths($definition, $attribute) ?? [] as $path) {
                $names[$path] ??= $label;
            }
            foreach (array_keys($type?->rules($definition, $attribute) ?? []) as $path) {
                $names[$path] ??= $label;
            }
        }

        return $names;
    }

    /**
     * 운영자가 적은 형식 오류 문구를 반환합니다 (요청 클래스의 messages() 에 합친다).
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @return array<string, string> `{속성 경로}.regex` => 문구
     */
    public function messages(string $set, FieldContext $context): array
    {
        $messages = [];
        foreach ($this->registry->forSurface($set, $context) as $definition) {
            $message = FieldDefinition::localizeValue($definition->constraint('regex_message'));
            if ($message !== null) {
                $messages[$this->registry->attributeFor($definition).'.regex'] = $message;
            }
        }

        return $messages;
    }

    /**
     * 이 요청에서 항목을 필수로 볼지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldContext  $context  요청 문맥
     * @param  array<string, mixed>  $current  대상의 현재 값 (관리자 수정에서만 채워진다)
     * @return bool 필수면 true
     */
    protected function isRequired(FieldDefinition $definition, FieldContext $context, array $current): bool
    {
        if (! $definition->required) {
            return false;
        }

        // 새로 필수가 된 항목 때문에 관리자가 옛 회원의 다른 칸을 못 고치는 일을 막는다.
        if ($context->surface === FieldContext::SURFACE_ADMIN_UPDATE
            && $this->values->isEmpty($definition, $current[$definition->key] ?? null)) {
            return false;
        }

        return true;
    }

    /**
     * 유형 규칙 앞에 required 또는 nullable 을 붙입니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $path  규칙을 거는 속성 경로
     * @param  array<int, mixed>  $rules  유형 규칙
     * @param  string  $attribute  항목의 요청 속성 경로
     * @param  array<int, string>  $requiredPaths  required 를 걸 경로 목록
     * @return array<int, mixed> 완성한 규칙
     */
    protected function withPresence(FieldDefinition $definition, string $path, array $rules, string $attribute, array $requiredPaths): array
    {
        // 배열 요소 규칙(`attr.*`)에는 존재 규칙을 붙이지 않는다.
        if (str_ends_with($path, '.*')) {
            return $rules;
        }

        $rest = array_values(array_filter($rules, fn ($rule) => ! in_array($rule, ['nullable', 'required'], true)));

        if (in_array($path, $requiredPaths, true)) {
            return $definition->type === 'boolean' ? ['accepted', ...$rest] : ['required', ...$rest];
        }

        // 필수 항목의 묶음 속성(주소·기간의 부모 배열)은 하위 경로가 required 를 가진다.
        if ($path === $attribute && $requiredPaths !== []) {
            return ['required', ...$rest];
        }

        // 하위 경로가 스스로 조건부 존재 규칙(required_with 등)을 가졌으면 그대로 둔다.
        return in_array('nullable', $rules, true) || $path === $attribute ? ['nullable', ...$rest] : $rules;
    }

    /**
     * 중복 금지 규칙을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldTypeInterface  $type  유형
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 규칙 (중복 금지가 아니면 빈 배열)
     */
    protected function uniqueRules(FieldDefinition $definition, FieldTypeInterface $type, FieldContext $context): array
    {
        if (! $definition->constraint('unique', false)
            || $definition->storage !== FieldDefinition::STORAGE_VALUE
            || ! $type->supports(FieldTypeInterface::FEATURE_UNIQUE)) {
            return [];
        }

        return [function (string $attribute, mixed $value, \Closure $fail) use ($definition, $type, $context) {
            $normalized = $type->normalize($definition, $value);
            if ($normalized !== null && $this->values->hasUniqueConflict($definition, $normalized, $context->owner)) {
                $fail(__('fields.validation.unique', ['attribute' => $definition->localized('name') ?? $definition->key]));
            }
        }];
    }

    /**
     * 확장 핸들러가 더하는 규칙을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 규칙
     */
    protected function handlerRules(FieldDefinition $definition, string $attribute, FieldContext $context): array
    {
        if ($definition->storage !== FieldDefinition::STORAGE_HANDLER) {
            return [];
        }

        return $this->values->handlerFor($definition)?->extraRules($definition, $attribute, $context) ?? [];
    }

    /**
     * 항목에 걸린 정책 규칙을 반환합니다 (회원 닉네임의 중복 금지, 금지어, 변경 주기).
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 규칙 (정책이 없는 항목이면 빈 배열)
     */
    protected function policyRules(FieldDefinition $definition, FieldContext $context): array
    {
        if ($definition->field_set !== FieldSet::CORE_USER
            || $definition->key !== 'nickname'
            || $definition->storage !== FieldDefinition::STORAGE_COLUMN) {
            return [];
        }

        $owner = $context->owner instanceof User && $context->owner->exists ? $context->owner : null;

        return NicknamePolicy::rules($owner, $context->surface, $context->actor instanceof User ? $context->actor : null);
    }

    /**
     * 표시 이름 종류로 쓸 수 있는 회원 추가 항목의 금지어 규칙을 반환합니다.
     *
     * 「표시 이름으로 사용 가능」을 켠 항목의 값은 다른 회원에게 이름으로 보이므로 닉네임과 같은 금지어를 적용한다(사칭 방지).
     * 조건은 닉네임과 같다: 전각, 대소문자, 공백 차이를 무시하고, 관리자가 관리자 화면에서 정하는 값은 면제이며,
     * 지금 저장된 값과 같은 글자면 다시 검사하지 않는다(금지어를 나중에 더해도 다른 칸 저장이 막히지 않는다).
     * 닉네임 항목은 닉네임 정책 규칙이 따로 맡는다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 규칙 (대상이 아니면 빈 배열)
     */
    protected function displayNameWordRules(FieldDefinition $definition, FieldContext $context): array
    {
        if ($definition->field_set !== FieldSet::CORE_USER
            || ! $definition->display_name_usable
            || $definition->storage !== FieldDefinition::STORAGE_VALUE) {
            return [];
        }

        return [function (string $attribute, mixed $value, \Closure $fail) use ($definition, $context) {
            if (! is_string($value) || $value === '') {
                return;
            }

            $policy = app(NicknamePolicyService::class);
            $actor = $context->actor instanceof User ? $context->actor : null;
            $word = $policy->forbiddenWordIn($value, $context->surface, $actor);
            if ($word === null) {
                return;
            }

            // 금지어가 걸린 뒤에만 지금 값을 읽는다 — 대부분의 요청은 값 조회 없이 통과한다.
            if ($context->owner !== null && $context->owner->exists) {
                $current = $this->values->read($context->owner, $definition->field_set, new Collection([$definition]))[$definition->key] ?? null;
                if ($current === $value) {
                    return;
                }
            }

            $fail(__('fields.validation.forbidden_word', [
                'attribute' => $definition->localized('name') ?? $definition->key,
                'word' => $word,
            ]));
        }];
    }

    /**
     * 본인인증으로 잠긴 항목의 「현재 값과 같아야 함」 규칙을 반환합니다.
     *
     * 회원 본인이 값을 고치는 표면(내 정보 수정, 추가 정보 입력)에서만 건다. 요청에 그 항목이 없으면 통과한다.
     * 회원 칸 수정 잠금(`UserLockedFieldsService`)이 맡는 기본 칸(이름, 휴대폰 등)은 그쪽 규칙이 막으므로 여기서는 건너뛴다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  FieldTypeInterface  $type  유형
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 규칙 (잠기지 않았으면 빈 배열)
     */
    protected function identityLockRules(FieldDefinition $definition, FieldTypeInterface $type, FieldContext $context): array
    {
        if (! $definition->idv_lock
            || ! in_array($context->surface, [FieldContext::SURFACE_SELF_UPDATE, FieldContext::SURFACE_REQUIRED_INFO], true)
            || ! $context->owner instanceof User
            || ! $context->owner->exists
            || ($definition->storage === FieldDefinition::STORAGE_COLUMN && in_array($definition->key, UserLockedFieldsService::LOCKABLE, true))) {
            return [];
        }

        $owner = $context->owner;
        if (! in_array($definition->key, app(IdentityClaimsService::class)->lockedKeysFor($owner), true)) {
            return [];
        }

        return [function (string $attribute, mixed $value, \Closure $fail) use ($definition, $type, $owner) {
            $current = $this->values->read($owner, $definition->field_set, new Collection([$definition]))[$definition->key] ?? null;
            $encode = fn (mixed $v) => $v === null || $v === '' || $v === [] ? null : json_encode($v);

            if ($encode($type->normalize($definition, $value)) !== $encode($current)) {
                $fail(__('fields.validation.locked_identity'));
            }
        }];
    }

    /**
     * 관리자 수정에서 대상의 현재 값을 읽습니다.
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @param  Collection<int, FieldDefinition>  $definitions  표면의 항목
     * @return array<string, mixed> 항목 키 => 현재 값 (다른 표면이면 빈 배열)
     */
    protected function currentValues(string $set, FieldContext $context, $definitions): array
    {
        if ($context->surface !== FieldContext::SURFACE_ADMIN_UPDATE || $context->owner === null || ! $context->owner->exists) {
            return [];
        }

        $required = $definitions->filter(fn (FieldDefinition $definition) => $definition->required)->values();

        return $required->isEmpty() ? [] : $this->values->read($context->owner, $set, $required);
    }
}
