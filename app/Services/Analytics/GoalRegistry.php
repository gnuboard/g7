<?php

namespace App\Services\Analytics;

use App\Analytics\DTO\GoalDefinition;
use App\Analytics\ExtensionFilter;
use App\Analytics\LocalizedText;
use App\Contracts\Analytics\GoalRegistryInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Services\Analytics\Concerns\CollectsExtensionDeclarations;

/**
 * 방문자 통계 전환(목표) 등록부
 *
 * 코어는 `config/analytics.php` 의 `goals`, 확장은 진입 클래스의 `getAnalyticsGoals()`
 * 오버라이드가 선언 지점입니다. 설치본마다 존재하는 확장이 다르고 확장이 사라지면 그
 * 목표도 사라져야 하므로 DB 에 적재하지 않고 매번 모읍니다.
 *
 * 선언은 항목별로 정규화하고, 형식이 틀린 항목만 사유와 함께 배제합니다. 모은 결과에는
 * `core.analytics.registered_goals` 필터를 한 번 더 걸어, 설정에 따라 목표를 숨기거나
 * 더하는 확장이 끼어들 수 있게 합니다.
 */
class GoalRegistry implements GoalRegistryInterface
{
    use CollectsExtensionDeclarations;

    /**
     * 모은 목표 (요청 수명 동안 유지)
     *
     * @var array<string, GoalDefinition>|null
     */
    private ?array $goals = null;

    /**
     * 배제된 선언의 사유
     *
     * @var array<int, string>
     */
    private array $warnings = [];

    /**
     * 등록부를 생성합니다.
     *
     * @param  ModuleManagerInterface  $moduleManager  모듈 관리자
     * @param  PluginManagerInterface  $pluginManager  플러그인 관리자
     */
    public function __construct(
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function all(): array
    {
        if ($this->goals !== null) {
            return $this->goals;
        }

        $this->warnings = [];
        $goals = [];

        $core = config('analytics.goals', []);
        foreach ($this->normalize(is_array($core) ? $core : [], 'core', 'core') as $goal) {
            $goals[$goal->qualifiedKey()] = $goal;
        }

        foreach ($this->collectDeclarations('getAnalyticsGoals') as $source) {
            foreach ($this->normalize($source['items'], $source['type'], $source['identifier']) as $goal) {
                $goals[$goal->qualifiedKey()] = $goal;
            }
        }

        $filtered = ExtensionFilter::apply('core.analytics.registered_goals', $goals);

        // 필터가 형식을 깨뜨려도 수집이 멈추면 안 된다 — 정의 객체만 남긴다.
        $this->goals = [];
        foreach (is_array($filtered) ? $filtered : $goals as $key => $goal) {
            if ($goal instanceof GoalDefinition) {
                $this->goals[$goal->qualifiedKey()] = $goal;
            } else {
                $this->warnings[] = "core.analytics.registered_goals: 정의 객체가 아닌 항목을 버렸습니다 ({$key}).";
            }
        }

        return $this->goals;
    }

    /**
     * {@inheritDoc}
     */
    public function find(string $qualifiedKey): ?GoalDefinition
    {
        return $this->all()[$qualifiedKey] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function warnings(): array
    {
        $this->all();

        return $this->warnings;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        $this->goals = null;
        $this->warnings = [];
    }

    /**
     * 선언 목록을 정의 객체로 정규화합니다.
     *
     * @param  array<int|string, mixed>  $declared  선언 목록
     * @param  string  $ownerType  공급자 종류 (core, module, plugin)
     * @param  string  $ownerIdentifier  공급자 식별자
     * @return array<int, GoalDefinition> 통과한 정의
     */
    private function normalize(array $declared, string $ownerType, string $ownerIdentifier): array
    {
        $goals = [];
        $seen = [];

        foreach ($declared as $index => $item) {
            $where = "{$ownerIdentifier}/goals[{$index}]";

            // 한 항목의 형식 혼동(문자열 자리에 배열·객체 등)이 던지면 그 항목만 버린다 — 등록부가
            // 멈추면 그날 집계·수집 진단·목표 기록이 함께 멈추고 원인도 보이지 않는다.
            try {
                if (! is_array($item)) {
                    $this->warnings[] = "{$where}: 목표 선언은 배열이어야 합니다.";

                    continue;
                }

                $key = $item['key'] ?? null;
                if (! self::isValidDeclarationKey($key)) {
                    $this->warnings[] = "{$where}: key 는 영소문자·숫자·밑줄 1~50자여야 합니다 — ".var_export($key, true);

                    continue;
                }

                if (isset($seen[$key])) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: 같은 키가 두 번 선언되어 뒤의 것을 버렸습니다.";

                    continue;
                }

                $label = LocalizedText::normalize($item['label'] ?? null);
                if ($label === []) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: label 이 없습니다.";

                    continue;
                }

                $category = (string) ($item['category'] ?? 'custom');
                if (! in_array($category, GoalDefinition::CATEGORIES, true)) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: 알 수 없는 category — {$category} (허용: ".implode('|', GoalDefinition::CATEGORIES).')';

                    continue;
                }

                $dedupe = (string) ($item['dedupe'] ?? 'every_time');
                if (! in_array($dedupe, GoalDefinition::DEDUPE_MODES, true)) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: 알 수 없는 dedupe — {$dedupe} (허용: ".implode('|', GoalDefinition::DEDUPE_MODES).')';

                    continue;
                }

                [$attributes, $attributeError] = $this->normalizeAttributes($item['attributes'] ?? []);
                if ($attributeError !== null) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: {$attributeError}";

                    continue;
                }

                $window = $item['attribution_window_days'] ?? 30;
                if (! is_int($window) || $window < 0 || $window > 365) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: attribution_window_days 는 0~365 정수여야 합니다.";

                    continue;
                }

                $dimensions = $item['dimensions'] ?? [];
                if (! is_array($dimensions) || array_filter($dimensions, fn ($d) => ! self::isValidDeclarationKey($d)) !== []) {
                    $this->warnings[] = "{$ownerIdentifier}.{$key}: dimensions 는 분류 기준 키 목록이어야 합니다.";

                    continue;
                }

                $seen[$key] = true;
                $goals[] = new GoalDefinition(
                    key: $key,
                    ownerType: $ownerType,
                    ownerIdentifier: $ownerIdentifier,
                    label: $label,
                    description: LocalizedText::normalize($item['description'] ?? null),
                    category: $category,
                    dedupe: $dedupe,
                    attributes: $attributes,
                    attributionWindowDays: $window,
                    dimensions: array_values($dimensions),
                );
            } catch (\Throwable $e) {
                $this->warnings[] = "{$where}: 목표 선언 형식 오류 — {$e->getMessage()}";

                continue;
            }
        }

        return $goals;
    }

    /**
     * 속성 스키마를 검사합니다.
     *
     * @param  mixed  $attributes  선언된 스키마
     * @return array{0: array<string, array<string, mixed>>, 1: string|null} [정규화된 스키마, 위반 사유]
     */
    private function normalizeAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [[], 'attributes 는 이름 → 규칙 맵이어야 합니다.'];
        }

        $schema = [];

        foreach ($attributes as $name => $rule) {
            if (! self::isValidDeclarationKey($name) || ! is_array($rule)) {
                return [[], 'attributes 항목 형식 위반 — '.var_export($name, true)];
            }

            $type = (string) ($rule['type'] ?? 'string');
            if (! in_array($type, GoalDefinition::ATTRIBUTE_TYPES, true)) {
                return [[], "attributes.{$name}: 알 수 없는 type — {$type}"];
            }

            if ($type === 'enum' && (! is_array($rule['values'] ?? null) || $rule['values'] === [])) {
                return [[], "attributes.{$name}: enum 은 values 가 필요합니다."];
            }

            $schema[$name] = array_filter([
                'type' => $type,
                'required' => (bool) ($rule['required'] ?? false),
                'min' => isset($rule['min']) && is_numeric($rule['min']) ? $rule['min'] + 0 : null,
                'max' => isset($rule['max']) && is_numeric($rule['max']) ? $rule['max'] + 0 : null,
                'values' => $type === 'enum' ? array_values(array_map('strval', $rule['values'])) : null,
            ], fn ($value) => $value !== null);
        }

        return [$schema, null];
    }
}
