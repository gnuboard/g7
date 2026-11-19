<?php

namespace App\Fields;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\DisplayName\NicknameRequirement;
use App\Enums\FieldEditScope;
use App\Enums\FieldSourceType;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Models\FieldDefinition;
use App\Support\LocalizedValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 항목 등록소 — 집합의 항목 목록을 「지금 쓸 수 있는 모습」으로 돌려준다.
 *
 * DB 행에서 꺼진 확장이 선언한 행을 빼고, 동적 선택지를 채우고, 필터 `core.field.filter_definitions` 를 태운다.
 * 반환하는 모델은 메모의 사본이라 호출자가 속성을 바꿔도 다른 호출에 새지 않는다.
 *
 * @since 7.2.0
 */
class FieldRegistry
{
    /** @var array<string, array<string, string>|string|null> 확장 이름 원값 메모 (`{종류}:{식별자}` => 이름) — 요청 범위 */
    protected array $extensionNames = [];

    /**
     * @param  FieldDefinitionRepositoryInterface  $definitions  항목 정의 저장소
     * @param  FieldSetRegistry  $sets  집합 등록소
     * @param  FieldTypeRegistry  $types  유형 등록소
     */
    public function __construct(
        protected FieldDefinitionRepositoryInterface $definitions,
        protected FieldSetRegistry $sets,
        protected FieldTypeRegistry $types,
    ) {}

    /**
     * 집합의 모든 항목을 반환합니다 (꺼진 항목·사용 불가 유형 포함 — 운영자 표가 쓴다).
     *
     * @param  string  $set  집합 식별자
     * @param  string|null  $scopeKey  집합 안의 범위 키 (게시판 식별자 등)
     * @param  FieldContext|null  $context  요청 문맥 (필터에 넘긴다)
     * @return Collection<int, FieldDefinition> 표시 순서의 항목 목록
     */
    public function forSet(string $set, ?string $scopeKey = null, ?FieldContext $context = null): Collection
    {
        if (! $this->sets->has($set)) {
            return new Collection;
        }

        $rows = $this->storedOrDeclared($set)
            ->filter(fn (FieldDefinition $definition) => $this->sourceIsActive($definition))
            ->map(fn (FieldDefinition $definition) => $this->prepare(clone $definition))
            ->values();

        $filtered = HookManager::applyFilters('core.field.filter_definitions', $rows, $set, $scopeKey, $context?->toArray() ?? []);
        if ($filtered instanceof Collection) {
            $rows = $filtered->filter(fn ($definition) => $definition instanceof FieldDefinition)->values();
        }

        return $rows->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * 집합에서 지금 쓰이는 항목(사용 중이고 유형이 쓸 수 있는 것)을 반환합니다.
     *
     * @param  string  $set  집합 식별자
     * @param  string|null  $scopeKey  집합 안의 범위 키
     * @param  FieldContext|null  $context  요청 문맥
     * @return Collection<int, FieldDefinition> 항목 목록
     */
    public function active(string $set, ?string $scopeKey = null, ?FieldContext $context = null): Collection
    {
        return $this->forSet($set, $scopeKey, $context)
            ->filter(fn (FieldDefinition $definition) => $definition->enabled && $this->isAvailable($definition))
            ->values();
    }

    /**
     * 표면에서 입력받는 항목을 반환합니다.
     *
     * | 표면 | 대상 |
     * | --- | --- |
     * | signup | 가입 화면 표시 + 본인 수정 범위 + 가입에서 받을 수 있는 유형 |
     * | self_update, required_info | 본인 수정 범위 |
     * | 그 밖(관리자, 확장 표면) | 사용 중인 전부 |
     *
     * 항목이 `ui.surfaces` 로 표면을 한정했으면 그 표면에서만 받는다.
     *
     * @param  string  $set  집합 식별자
     * @param  FieldContext  $context  요청 문맥
     * @return Collection<int, FieldDefinition> 항목 목록
     */
    public function forSurface(string $set, FieldContext $context): Collection
    {
        return $this->active($set, $context->scopeKey, $context)
            ->filter(function (FieldDefinition $definition) use ($context) {
                $surfaces = ($definition->ui ?? [])['surfaces'] ?? null;
                if (is_array($surfaces) && ! in_array($context->surface, $surfaces, true)) {
                    return false;
                }
                if ($context->onlyKeys !== null && ! in_array($definition->key, $context->onlyKeys, true)) {
                    return false;
                }
                if (! $context->isSelfSurface()) {
                    return true;
                }
                if ($definition->edit_scope !== FieldEditScope::Self_->value) {
                    return false;
                }
                if ($context->surface === FieldContext::SURFACE_SIGNUP) {
                    return $definition->show_on_signup
                        && $this->typeOf($definition)?->supports(FieldTypeInterface::FEATURE_SIGNUP) === true;
                }

                return true;
            })
            ->values();
    }

    /**
     * 집합에서 키로 항목 하나를 찾습니다.
     *
     * @param  string  $set  집합 식별자
     * @param  string  $key  항목 키
     * @param  string|null  $scopeKey  집합 안의 범위 키
     * @return FieldDefinition|null 항목 (없으면 null)
     */
    public function find(string $set, string $key, ?string $scopeKey = null): ?FieldDefinition
    {
        return $this->forSet($set, $scopeKey)->first(fn (FieldDefinition $definition) => $definition->key === $key);
    }

    /**
     * 항목의 유형을 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return FieldTypeInterface|null 유형 (제공 확장이 꺼져 있으면 null)
     */
    public function typeOf(FieldDefinition $definition): ?FieldTypeInterface
    {
        return $this->types->get($definition->type);
    }

    /**
     * 항목의 유형이 지금 쓸 수 있는지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(FieldDefinition $definition): bool
    {
        return $this->types->has($definition->type);
    }

    /**
     * 항목을 선언한 확장의 이름을 반환합니다 (운영자 표의 「출처」, 폼의 확장별 묶음 제목).
     *
     * 코어 항목과 운영자가 만든 항목은 null 이다. 확장을 찾지 못하면(꺼졌거나 지워짐) 식별자를 그대로 돌려준다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string|null 확장 이름
     */
    public function sourceLabel(FieldDefinition $definition, ?string $locale = null): ?string
    {
        if (! in_array($definition->source_type, ['module', 'plugin'], true)) {
            return null;
        }

        return $this->extensionLabel((string) $definition->source_type, (string) $definition->source_identifier, $locale);
    }

    /**
     * 확장(모듈·플러그인)의 사람이 읽는 이름을 요청 언어로 반환합니다 (항목 출처, 확장 입력 유형의 배지).
     *
     * 설치 기록의 다국어 이름을 먼저 쓴다 — 설치·동기화 때 활성 언어팩(ja 등)이 manifest 이름에 병합돼 저장되므로
     * ko·en 만 있는 manifest 보다 언어가 넓다(레이아웃 확장 출처 이름 `LayoutExtensionService::resolveSourceLabel` 과 같은 규칙).
     * 기록이 없으면 manifest 이름, 그것도 없으면 식별자다. 한 요청 안에서는 확장마다 한 번만 읽는다.
     *
     * @param  string  $type  확장 종류 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $locale  로케일 (생략하면 현재 로케일)
     * @return string 확장 이름
     */
    public function extensionLabel(string $type, string $identifier, ?string $locale = null): string
    {
        $key = $type.':'.$identifier;
        if (! array_key_exists($key, $this->extensionNames)) {
            $this->extensionNames[$key] = $this->extensionNameValue($type, $identifier);
        }

        $name = $this->extensionNames[$key];
        if (is_array($name)) {
            $name = LocalizedValue::pick($name, $locale);
        }

        return is_string($name) && $name !== '' ? $name : $identifier;
    }

    /**
     * 확장 이름의 원값(다국어 배열 또는 글자)을 읽습니다 — 설치 기록 → manifest 순.
     *
     * @param  string  $type  확장 종류 (module|plugin)
     * @param  string  $identifier  확장 식별자
     * @return array<string, string>|string|null 이름 원값 (못 찾으면 null)
     */
    protected function extensionNameValue(string $type, string $identifier): array|string|null
    {
        try {
            $record = match ($type) {
                'module' => app(ModuleRepositoryInterface::class)->findByIdentifier($identifier),
                'plugin' => app(PluginRepositoryInterface::class)->findByIdentifier($identifier),
                default => null,
            };
            $recorded = $record?->name;
            if (is_string($recorded) && str_starts_with($recorded, '{')) {
                $decoded = json_decode($recorded, true);
                $recorded = is_array($decoded) ? $decoded : $recorded;
            }
            if ((is_array($recorded) && $recorded !== []) || (is_string($recorded) && $recorded !== '')) {
                return $recorded;
            }

            $extension = match ($type) {
                'module' => app(ModuleManager::class)->getModule($identifier),
                'plugin' => app(PluginManager::class)->getPlugin($identifier),
                default => null,
            };

            return $extension?->getName();
        } catch (\Throwable $e) {
            Log::debug('항목 출처: 확장 이름을 읽지 못했습니다', ['extension' => $type.':'.$identifier, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 집합을 반환합니다.
     *
     * @param  string  $set  집합 식별자
     * @return FieldSet|null 집합
     */
    public function set(string $set): ?FieldSet
    {
        return $this->sets->get($set);
    }

    /**
     * 항목의 요청 속성 경로를 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return string 요청 속성 경로
     */
    public function attributeFor(FieldDefinition $definition): string
    {
        return $this->sets->get($definition->field_set)?->attributeFor($definition) ?? $definition->key;
    }

    /**
     * 집합의 정의 버전을 반환합니다.
     *
     * @param  string  $set  집합 식별자
     * @return string 정의 버전
     */
    public function version(string $set): string
    {
        return $this->definitions->version($set);
    }

    /**
     * 저장된 항목을 읽되, 코어 항목 행이 아직 없으면 코어 선언을 그대로 읽습니다.
     *
     * 코어 항목 행은 설치와 코어 업데이트의 선언 동기화가 만든다. 그 전 구간(새 코드가 올라간 뒤 동기화 스텝이 돌기 전,
     * 마이그레이션 전, 시딩 없이 도는 실행)에는 행이 없는데, 그때 회원 폼이 이름 규칙과 저장을 통째로 잃으면 안 된다.
     * 그래서 코어 출처 행이 하나도 없는 집합은 `config/core.php` 의 `fields` 선언으로 저장하지 않은 항목을 만들어 쓴다 —
     * 기본값이 종전 동작이므로 동기화 전에도 화면과 검증이 달라지지 않는다. 운영자 설정은 동기화 뒤부터 적용된다.
     *
     * @param  string  $set  집합 식별자
     * @return Collection<int, FieldDefinition> 항목 목록
     */
    protected function storedOrDeclared(string $set): Collection
    {
        $rows = $this->definitions->allForSet($set);
        if ($rows->contains(fn (FieldDefinition $definition) => $definition->source_type === FieldSourceType::Core->value)) {
            return $rows;
        }

        return $this->declaredCoreFields($set)->concat($rows)->values();
    }

    /**
     * 코어 선언에서 저장하지 않은 항목을 만듭니다.
     *
     * @param  string  $set  집합 식별자
     * @return Collection<int, FieldDefinition> 선언 항목 (그 집합의 코어 선언이 없으면 빈 목록)
     */
    protected function declaredCoreFields(string $set): Collection
    {
        $declared = HookManager::applyFilters('core.fields.config', (array) config('core.fields', []));
        $fields = new Collection;

        foreach (is_array($declared) ? $declared : [] as $declaration) {
            if (! is_array($declaration)
                || ($declaration['field_set'] ?? null) !== $set
                || ! is_string($declaration['key'] ?? null)
                || ! is_string($declaration['type'] ?? null)
                || ! is_array($declaration['name'] ?? null)) {
                continue;
            }

            $fields->push(new FieldDefinition([
                'field_set' => $set,
                'key' => $declaration['key'],
                ...FieldDefinitionSyncHelper::attributesFrom(FieldSourceType::Core->value, FieldSourceType::Core->value, $declaration),
            ]));
        }

        return $fields;
    }

    /**
     * 항목을 선언한 확장이 켜져 있는지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return bool 코어·운영자 항목이거나 선언 확장이 켜져 있으면 true
     */
    protected function sourceIsActive(FieldDefinition $definition): bool
    {
        return match ($definition->source_type) {
            FieldSourceType::Module->value => in_array($definition->source_identifier, ModuleManager::getActiveModuleIdentifiers(), true),
            FieldSourceType::Plugin->value => in_array($definition->source_identifier, PluginManager::getActivePluginIdentifiers(), true),
            default => true,
        };
    }

    /**
     * 선언이 가리킨 동적 선택지를 채우고, 다른 설정이 거는 강제 잠금을 적용합니다.
     *
     * 표시 이름 설정이 닉네임을 요구하면 닉네임 항목의 사용(과 필수)을 고정하고 잠금을 싣는다({@see NicknameRequirement}).
     * 저장된 행은 바꾸지 않는다.
     *
     * @param  FieldDefinition  $definition  항목 정의 (사본)
     * @return FieldDefinition 선택지를 채우고 잠금을 적용한 항목
     */
    protected function prepare(FieldDefinition $definition): FieldDefinition
    {
        if ($definition->constraint('options_source') === 'supported_locales') {
            $names = (array) config('app.locale_names', []);
            $definition->options = array_map(
                fn (string $locale) => ['value' => $locale, 'label' => $names[$locale] ?? $locale],
                array_values((array) config('app.supported_locales', ['ko', 'en'])),
            );
        }

        if ($definition->field_set === FieldSet::CORE_USER && $definition->key === NicknameRequirement::NICKNAME_FIELD) {
            $definition = app(NicknameRequirement::class)->apply($definition);
        }

        return $definition;
    }

    /**
     * 항목에 지금 걸려 있는 잠금을 반환합니다 (선언한 쪽의 잠금 + 다른 설정이 거는 강제 잠금).
     *
     * 저장소에서 바로 읽은 항목에는 강제 잠금이 실려 있지 않으므로, 운영자 변경을 저장하기 전에 이 메서드로 확인한다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return array<string, mixed> 속성 => 사유 (번역 키)
     */
    public function locksFor(FieldDefinition $definition): array
    {
        return [...($definition->locks ?? []), ...app(NicknameRequirement::class)->locksFor($definition)];
    }
}
