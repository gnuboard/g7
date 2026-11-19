<?php

namespace App\DisplayName;

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\DisplayName\Contracts\DisplayNameKindInterface;
use App\DisplayName\Kinds\FieldValueKind;
use App\DisplayName\Kinds\MaskedIdKind;
use App\DisplayName\Kinds\MaskedNameKind;
use App\DisplayName\Kinds\NameKind;
use App\DisplayName\Kinds\NameNicknameKind;
use App\DisplayName\Kinds\NicknameKind;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Models\FieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 표시 이름 종류 등록소.
 *
 * 코어 종류 5개에 활성 확장의 `getDisplayNameKinds()` 와 필터 `core.display_name.filter_kinds` 가 더한 종류,
 * 그리고 운영자가 「표시 이름으로 사용 가능」을 켠 회원 추가 항목마다의 종류(`field:{항목 키}`)를 합친다.
 * 확장이 꺼지거나 항목이 지워지면 그 종류는 목록에서 빠지고, 그 종류를 가리키던 설정은 계산기가 대체 값으로 넘긴다.
 *
 * @since 7.2.0
 */
class DisplayNameKindRegistry
{
    /** 코어 종류 클래스 — 운영자 화면의 선택지 순서이기도 하다 */
    public const CORE_KINDS = [
        NameKind::class, NicknameKind::class, MaskedNameKind::class, MaskedIdKind::class, NameNicknameKind::class,
    ];

    /** 추가 항목 종류의 키 접두 */
    public const FIELD_PREFIX = 'field:';

    /** 종류 키 형식 */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9_:-]{0,119}$/';

    /**
     * 모은 종류 가운데 추가 항목 종류를 뺀 것 (null 이면 아직 모으지 않음).
     *
     * @var array<string, DisplayNameKindInterface>|null
     */
    protected ?array $static = null;

    /**
     * 종류를 제공한 쪽 — 종류 키 => [종류, 식별자].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $owners = [];

    /**
     * 추가 항목 종류 메모와 그것을 만든 항목 목록 (목록 객체가 바뀌면 다시 만든다).
     *
     * @var array{0: Collection<int, FieldDefinition>, 1: array<string, FieldValueKind>}|null
     */
    protected ?array $fieldKinds = null;

    /**
     * @param  FieldDefinitionRepositoryInterface  $definitions  항목 정의 저장소 (항목 목록이 바뀌었는지 판정)
     */
    public function __construct(
        protected FieldDefinitionRepositoryInterface $definitions,
    ) {}

    /**
     * 추가 항목의 종류 키를 만듭니다.
     *
     * @param  string  $fieldKey  항목 키
     * @return string `field:{항목 키}`
     */
    public static function fieldKindKey(string $fieldKey): string
    {
        return self::FIELD_PREFIX.$fieldKey;
    }

    /**
     * 종류 키가 가리키는 추가 항목 키를 반환합니다.
     *
     * @param  string  $kindKey  종류 키
     * @return string|null 항목 키 (추가 항목 종류가 아니면 null)
     */
    public static function fieldKeyOf(string $kindKey): ?string
    {
        return str_starts_with($kindKey, self::FIELD_PREFIX) ? substr($kindKey, strlen(self::FIELD_PREFIX)) : null;
    }

    /**
     * 종류를 직접 등록합니다 (테스트와 부팅 코드용).
     *
     * @param  DisplayNameKindInterface  $kind  종류
     * @param  string  $ownerType  제공한 쪽의 종류 (core|module|plugin)
     * @param  string  $ownerIdentifier  제공한 쪽의 식별자
     * @return void 반환값 없음
     */
    public function register(DisplayNameKindInterface $kind, string $ownerType = 'core', string $ownerIdentifier = 'core'): void
    {
        $this->staticKinds();
        $this->static[$kind->key()] = $kind;
        $this->owners[$kind->key()] = [$ownerType, $ownerIdentifier];
    }

    /**
     * 종류를 반환합니다.
     *
     * @param  string|null  $key  종류 키
     * @return DisplayNameKindInterface|null 종류 (없거나 제공한 쪽이 꺼져 있으면 null)
     */
    public function get(?string $key): ?DisplayNameKindInterface
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->staticKinds()[$key]
            ?? (str_starts_with($key, self::FIELD_PREFIX) ? ($this->fieldKinds()[$key] ?? null) : null);
    }

    /**
     * 종류가 쓸 수 있는 상태인지 반환합니다.
     *
     * @param  string|null  $key  종류 키
     * @return bool 등록되어 있으면 true
     */
    public function has(?string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * 모든 종류를 반환합니다 (코어, 확장, 추가 항목 순).
     *
     * @return array<string, DisplayNameKindInterface> 종류 키 => 종류
     */
    public function all(): array
    {
        return $this->staticKinds() + $this->fieldKinds();
    }

    /**
     * 추가 항목 종류를 뺀 종류를 반환합니다.
     *
     * 항목 등록소를 읽지 않는다. 항목 등록소가 닉네임 잠금을 판정하면서 종류를 물을 때 서로를 부르지 않게 하려는 것이다.
     *
     * @return array<string, DisplayNameKindInterface> 종류 키 => 종류
     */
    public function staticKinds(): array
    {
        if ($this->static !== null) {
            return $this->static;
        }

        $kinds = [];
        $this->owners = [];
        foreach (self::CORE_KINDS as $class) {
            $kind = app($class);
            $kinds[$kind->key()] = $kind;
            $this->owners[$kind->key()] = ['core', 'core'];
        }

        foreach ($this->extensionDeclarations() as [$ownerType, $identifier, $declared]) {
            foreach ($declared as $candidate) {
                $kind = is_string($candidate) && class_exists($candidate) ? app($candidate) : $candidate;
                if (! $kind instanceof DisplayNameKindInterface || ! $this->acceptable($kind, $identifier)) {
                    continue;
                }
                if (isset($kinds[$kind->key()])) {
                    Log::warning('표시 이름 종류 키 충돌 — 먼저 등록된 쪽을 유지합니다', ['kind' => $kind->key(), 'skipped' => $identifier]);

                    continue;
                }
                $kinds[$kind->key()] = $kind;
                $this->owners[$kind->key()] = [$ownerType, $identifier];
            }
        }

        $filtered = HookManager::applyFilters('core.display_name.filter_kinds', $kinds);
        $this->static = [];
        foreach (is_array($filtered) ? $filtered : $kinds as $kind) {
            if ($kind instanceof DisplayNameKindInterface && $this->acceptable($kind, 'filter')) {
                $this->static[$kind->key()] = $kind;
                $this->owners[$kind->key()] ??= ['plugin', 'filter'];
            }
        }

        return $this->static;
    }

    /**
     * 추가 항목 종류를 반환합니다.
     *
     * 회원 집합에서 사용 중이고 「표시 이름으로 사용 가능」이 켜져 있으며 유형이 표시 이름을 지원하는 항목만 종류가 된다.
     * 관리자만 열람 항목은 종류가 되지 않는다(다른 회원에게 보이면 안 되는 값이다).
     *
     * @return array<string, FieldValueKind> 종류 키 => 종류
     */
    public function fieldKinds(): array
    {
        $source = $this->definitions->allForSet(FieldSet::CORE_USER);
        if ($this->fieldKinds !== null && $this->fieldKinds[0] === $source) {
            return $this->fieldKinds[1];
        }

        $registry = app(FieldRegistry::class);
        $values = app(DisplayNameFieldValues::class);
        $kinds = [];
        foreach ($registry->active(FieldSet::CORE_USER) as $definition) {
            if (! $definition->display_name_usable
                || $definition->storage !== FieldDefinition::STORAGE_VALUE
                || $definition->edit_scope === FieldDefinition::SCOPE_ADMIN_ONLY
                || $registry->typeOf($definition)?->supports(FieldTypeInterface::FEATURE_DISPLAY_NAME) !== true) {
                continue;
            }
            $kind = new FieldValueKind($definition, $values);
            $kinds[$kind->key()] = $kind;
        }

        $this->fieldKinds = [$source, $kinds];

        return $kinds;
    }

    /**
     * 종류를 제공한 쪽을 반환합니다.
     *
     * @param  string  $key  종류 키
     * @return array{0: string, 1: string} [종류, 식별자] (추가 항목 종류는 `['custom', 'custom']`)
     */
    public function ownerOf(string $key): array
    {
        $this->staticKinds();

        return $this->owners[$key] ?? (str_starts_with($key, self::FIELD_PREFIX) ? ['custom', 'custom'] : ['core', 'core']);
    }

    /**
     * 대체 순서를 반환합니다 (자기 자신부터 마지막 대체 종류까지).
     *
     * @param  string  $key  종류 키
     * @return array<int, DisplayNameKindInterface> 대체 순서의 종류 목록 (등록되지 않은 종류는 건너뛴다)
     */
    public function chain(string $key): array
    {
        $chain = [];
        $seen = [];
        $current = $key;
        while ($current !== null && ! isset($seen[$current])) {
            $seen[$current] = true;
            $kind = $this->get($current);
            if ($kind === null) {
                break;
            }
            $chain[] = $kind;
            $current = $kind->fallback();
        }

        return $chain;
    }

    /**
     * 모은 결과를 버립니다 (확장 활성 상태가 바뀐 뒤와 테스트용).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->static = null;
        $this->owners = [];
        $this->fieldKinds = null;
    }

    /**
     * 종류 키가 형식에 맞는지 확인합니다.
     *
     * 추가 항목 종류의 접두(`field:`)는 코어가 쓰므로 확장 종류가 쓰지 못한다.
     *
     * @param  DisplayNameKindInterface  $kind  종류
     * @param  string  $source  등록한 쪽 (로그용)
     * @return bool 받아들이면 true
     */
    protected function acceptable(DisplayNameKindInterface $kind, string $source): bool
    {
        $key = $kind->key();
        if (preg_match(self::KEY_PATTERN, $key) === 1 && ! str_starts_with($key, self::FIELD_PREFIX)) {
            return true;
        }

        Log::warning('표시 이름 종류 키 형식이 맞지 않아 건너뜁니다', ['kind' => $key, 'source' => $source]);

        return false;
    }

    /**
     * 활성 확장의 종류 선언을 모읍니다.
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, mixed>}> [종류, 식별자, 선언 목록]
     */
    protected function extensionDeclarations(): array
    {
        $declarations = [];
        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getDisplayNameKinds')) {
                    $declarations[] = ['module', $module->getIdentifier(), (array) $module->getDisplayNameKinds()];
                }
            }
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getDisplayNameKinds')) {
                    $declarations[] = ['plugin', $plugin->getIdentifier(), (array) $plugin->getDisplayNameKinds()];
                }
            }
        } catch (\Throwable $e) {
            // 설치 전, 마이그레이션 전 구간에서는 확장 목록을 읽지 못할 수 있다. 코어 종류만으로 동작한다.
            Log::debug('표시 이름 종류: 확장 선언을 읽지 못했습니다', ['error' => $e->getMessage()]);
        }

        return $declarations;
    }
}
