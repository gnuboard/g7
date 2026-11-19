<?php

namespace App\Fields;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Fields\Contracts\FieldTypeInterface;
use App\Fields\Types\AddressType;
use App\Fields\Types\BooleanType;
use App\Fields\Types\ChoiceType;
use App\Fields\Types\DateRangeType;
use App\Fields\Types\DatetimeType;
use App\Fields\Types\DateType;
use App\Fields\Types\EmailType;
use App\Fields\Types\FileType;
use App\Fields\Types\ImageType;
use App\Fields\Types\MultiChoiceType;
use App\Fields\Types\NumberType;
use App\Fields\Types\PhoneType;
use App\Fields\Types\TextareaType;
use App\Fields\Types\TextType;
use App\Fields\Types\UrlType;
use Illuminate\Support\Facades\Log;

/**
 * 항목 유형 등록소.
 *
 * 코어 유형 15종에 활성 확장의 `getFieldTypes()` 와 필터 `core.field.filter_types` 가 더한 유형을 합친다.
 * 확장이 꺼지면 그 확장의 유형은 목록에서 빠진다 — 그 유형으로 만든 항목은 「사용 불가」가 된다.
 *
 * @since 7.2.0
 */
class FieldTypeRegistry
{
    /** 코어 유형 클래스 — 운영자 화면의 타일 순서이기도 하다 */
    public const CORE_TYPES = [
        TextType::class, TextareaType::class, NumberType::class, BooleanType::class,
        DateType::class, DatetimeType::class, DateRangeType::class,
        ChoiceType::class, MultiChoiceType::class,
        PhoneType::class, EmailType::class, UrlType::class,
        AddressType::class, FileType::class, ImageType::class,
    ];

    /**
     * 모은 유형 (null 이면 아직 모으지 않음).
     *
     * @var array<string, FieldTypeInterface>|null
     */
    protected ?array $types = null;

    /**
     * 유형을 제공한 쪽 — 유형 키 => [종류, 식별자].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $owners = [];

    /**
     * 유형을 직접 등록합니다 (테스트와 부팅 코드용).
     *
     * @param  FieldTypeInterface  $type  유형
     * @param  string  $ownerType  제공한 쪽의 종류 (core|module|plugin)
     * @param  string  $ownerIdentifier  제공한 쪽의 식별자
     * @return void 반환값 없음
     */
    public function register(FieldTypeInterface $type, string $ownerType = 'core', string $ownerIdentifier = 'core'): void
    {
        $this->all();
        $this->types[$type->key()] = $type;
        $this->owners[$type->key()] = [$ownerType, $ownerIdentifier];
    }

    /**
     * 유형을 반환합니다.
     *
     * @param  string  $key  유형 키
     * @return FieldTypeInterface|null 유형 (없거나 제공 확장이 꺼져 있으면 null)
     */
    public function get(string $key): ?FieldTypeInterface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 유형이 쓸 수 있는 상태인지 반환합니다.
     *
     * @param  string  $key  유형 키
     * @return bool 등록되어 있으면 true
     */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * 유형을 제공한 쪽을 반환합니다.
     *
     * @param  string  $key  유형 키
     * @return array{0: string, 1: string} [종류, 식별자]
     */
    public function ownerOf(string $key): array
    {
        $this->all();

        return $this->owners[$key] ?? ['core', 'core'];
    }

    /**
     * 모든 유형을 반환합니다.
     *
     * @return array<string, FieldTypeInterface> 유형 키 => 유형
     */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $types = [];
        $this->owners = [];
        foreach (self::CORE_TYPES as $class) {
            $type = app($class);
            $types[$type->key()] = $type;
            $this->owners[$type->key()] = ['core', 'core'];
        }

        foreach ($this->extensionDeclarations() as [$ownerType, $identifier, $declared]) {
            foreach ($declared as $candidate) {
                $type = is_string($candidate) && class_exists($candidate) ? app($candidate) : $candidate;
                if (! $type instanceof FieldTypeInterface) {
                    continue;
                }
                if (isset($types[$type->key()])) {
                    Log::warning('항목 유형 키 충돌 — 먼저 등록된 쪽을 유지합니다', ['type' => $type->key(), 'skipped' => $identifier]);

                    continue;
                }
                $types[$type->key()] = $type;
                $this->owners[$type->key()] = [$ownerType, $identifier];
            }
        }

        $filtered = HookManager::applyFilters('core.field.filter_types', $types);
        $this->types = [];
        foreach (is_array($filtered) ? $filtered : $types as $type) {
            if ($type instanceof FieldTypeInterface) {
                $this->types[$type->key()] = $type;
                $this->owners[$type->key()] ??= ['plugin', 'filter'];
            }
        }

        return $this->types;
    }

    /**
     * 모은 결과를 버립니다 (확장 활성 상태가 바뀐 뒤와 테스트용).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->types = null;
        $this->owners = [];
    }

    /**
     * 활성 확장의 유형 선언을 모읍니다.
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, mixed>}> [종류, 식별자, 선언 목록]
     */
    protected function extensionDeclarations(): array
    {
        $declarations = [];
        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getFieldTypes')) {
                    $declarations[] = ['module', $module->getIdentifier(), (array) $module->getFieldTypes()];
                }
            }
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getFieldTypes')) {
                    $declarations[] = ['plugin', $plugin->getIdentifier(), (array) $plugin->getFieldTypes()];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('항목 유형: 확장 선언을 읽지 못했습니다', ['error' => $e->getMessage()]);
        }

        return $declarations;
    }
}
