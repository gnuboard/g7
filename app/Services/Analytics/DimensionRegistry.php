<?php

namespace App\Services\Analytics;

use App\Analytics\DTO\DimensionDefinition;
use App\Analytics\ExtensionFilter;
use App\Analytics\LocalizedText;
use App\Contracts\Analytics\DimensionRegistryInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Services\Analytics\Concerns\CollectsExtensionDeclarations;
use Closure;

/**
 * 방문자 통계 분류 기준(dimension) 등록부
 *
 * 확장 진입 클래스의 `getAnalyticsDimensions()` 오버라이드가 선언 지점입니다. 코어 차원은
 * `StatsDimension` 이 이미 고정해 두었으므로 이 등록부는 확장 차원만 다룹니다.
 *
 * 모은 결과에는 `core.analytics.registered_dimensions` 필터를 한 번 더 겁니다.
 */
class DimensionRegistry implements DimensionRegistryInterface
{
    use CollectsExtensionDeclarations;

    /**
     * 모은 차원 (요청 수명 동안 유지)
     *
     * @var array<string, DimensionDefinition>|null
     */
    private ?array $dimensions = null;

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
        if ($this->dimensions !== null) {
            return $this->dimensions;
        }

        $this->warnings = [];
        $dimensions = [];

        foreach ($this->collectDeclarations('getAnalyticsDimensions') as $source) {
            foreach ($this->normalize($source) as $definition) {
                $dimensions[$definition->storageKey()] = $definition;
            }
        }

        $filtered = ExtensionFilter::apply('core.analytics.registered_dimensions', $dimensions);

        $this->dimensions = [];
        foreach (is_array($filtered) ? $filtered : $dimensions as $key => $definition) {
            if ($definition instanceof DimensionDefinition) {
                $this->dimensions[$definition->storageKey()] = $definition;
            } else {
                $this->warnings[] = "core.analytics.registered_dimensions: 정의 객체가 아닌 항목을 버렸습니다 ({$key}).";
            }
        }

        return $this->dimensions;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(string $name): array
    {
        $all = $this->all();

        if (isset($all[$name])) {
            return [$all[$name], null];
        }

        $matches = array_values(array_filter(
            $all,
            fn (DimensionDefinition $definition): bool => $definition->key === $name,
        ));

        if ($matches === []) {
            return [null, "등록되지 않은 분류 기준: {$name}"];
        }

        if (count($matches) > 1) {
            $candidates = implode(', ', array_map(fn (DimensionDefinition $d) => $d->storageKey(), $matches));

            return [null, "분류 기준 키가 모호합니다: {$name} — {$candidates} 중 하나로 보내야 합니다."];
        }

        return [$matches[0], null];
    }

    /**
     * {@inheritDoc}
     */
    public function findByStatsDimension(string $statsDimension): ?DimensionDefinition
    {
        if (! str_starts_with($statsDimension, 'ext:')) {
            return null;
        }

        return $this->all()[substr($statsDimension, 4)] ?? null;
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
        $this->dimensions = null;
        $this->warnings = [];
    }

    /**
     * 공급자 하나의 선언을 정의 객체로 정규화합니다.
     *
     * @param  array{type: string, identifier: string, instance: object, items: array<int|string, mixed>}  $source  공급자 선언
     * @return array<int, DimensionDefinition> 통과한 정의
     */
    private function normalize(array $source): array
    {
        $identifier = $source['identifier'];
        $definitions = [];
        $seen = [];

        foreach ($source['items'] as $index => $item) {
            $where = "{$identifier}/dimensions[{$index}]";

            // 한 항목의 형식 혼동(문자열 자리에 배열·객체 등)이 던지면 그 항목만 버린다 — 등록부가
            // 멈추면 그날 집계·수집 진단·목표 기록이 함께 멈추고 원인도 보이지 않는다.
            try {
                if (! is_array($item)) {
                    $this->warnings[] = "{$where}: 분류 기준 선언은 배열이어야 합니다.";

                    continue;
                }

                $key = $item['key'] ?? null;
                if (! self::isValidDeclarationKey($key)) {
                    $this->warnings[] = "{$where}: key 는 영소문자·숫자·밑줄 1~50자여야 합니다 — ".var_export($key, true);

                    continue;
                }

                if (isset($seen[$key])) {
                    $this->warnings[] = "{$identifier}:{$key}: 같은 키가 두 번 선언되어 뒤의 것을 버렸습니다.";

                    continue;
                }

                $label = LocalizedText::normalize($item['label'] ?? null);
                if ($label === []) {
                    $this->warnings[] = "{$identifier}:{$key}: label 이 없습니다.";

                    continue;
                }

                $scope = (string) ($item['scope'] ?? 'action');
                if (! in_array($scope, DimensionDefinition::SCOPES, true)) {
                    $this->warnings[] = "{$identifier}:{$key}: 알 수 없는 scope — {$scope} (허용: ".implode('|', DimensionDefinition::SCOPES).')';

                    continue;
                }

                $valueType = (string) ($item['value_type'] ?? 'string');
                if (! in_array($valueType, DimensionDefinition::VALUE_TYPES, true)) {
                    $this->warnings[] = "{$identifier}:{$key}: 알 수 없는 value_type — {$valueType}";

                    continue;
                }

                $values = [];
                if ($valueType === 'enum') {
                    if (! is_array($item['values'] ?? null) || $item['values'] === []) {
                        $this->warnings[] = "{$identifier}:{$key}: enum 은 values 가 필요합니다.";

                        continue;
                    }

                    $values = array_values(array_map('strval', $item['values']));
                }

                $cardinality = $item['max_cardinality'] ?? DimensionDefinition::DEFAULT_MAX_CARDINALITY;
                if (! is_int($cardinality) || $cardinality < 1) {
                    $this->warnings[] = "{$identifier}:{$key}: max_cardinality 는 1 이상 정수여야 합니다.";

                    continue;
                }

                if ($cardinality > DimensionDefinition::CARDINALITY_CEILING) {
                    $this->warnings[] = "{$identifier}:{$key}: max_cardinality 를 "
                        .DimensionDefinition::CARDINALITY_CEILING.' 으로 낮췄습니다.';
                    $cardinality = DimensionDefinition::CARDINALITY_CEILING;
                }

                $resolver = $this->resolver($source['instance'], $item['label_resolver'] ?? null, "{$identifier}:{$key}");

                $seen[$key] = true;
                $definitions[] = new DimensionDefinition(
                    key: $key,
                    ownerType: $source['type'],
                    ownerIdentifier: $identifier,
                    scope: $scope,
                    label: $label,
                    valueType: $valueType,
                    values: $values,
                    maxCardinality: $cardinality,
                    pii: (bool) ($item['pii'] ?? false),
                    labelResolver: $resolver,
                );
            } catch (\Throwable $e) {
                $this->warnings[] = "{$where}: 분류 기준 선언 형식 오류 — {$e->getMessage()}";

                continue;
            }
        }

        return $definitions;
    }

    /**
     * 라벨 풀이 메서드를 호출 가능한 형태로 묶습니다.
     *
     * 메서드가 없으면 경고만 남기고 풀이 없이 등록합니다 — 차원 자체는 원값으로 쓸 수 있으므로
     * 풀이 함수 이름 오타 하나로 수집까지 막을 이유가 없습니다.
     *
     * @param  object  $instance  확장 인스턴스
     * @param  mixed  $method  선언된 메서드 이름
     * @param  string  $where  경고 위치
     * @return Closure|null 풀이 함수
     */
    private function resolver(object $instance, mixed $method, string $where): ?Closure
    {
        if ($method === null || $method === '') {
            return null;
        }

        if (! is_string($method) || ! method_exists($instance, $method)) {
            $this->warnings[] = "{$where}: label_resolver 메서드를 찾지 못했습니다 — ".var_export($method, true);

            return null;
        }

        return fn (array $values): mixed => $instance->{$method}($values);
    }
}
