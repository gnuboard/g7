<?php

namespace App\Support\ExtensionDoc;

/**
 * 확장 의존 관계 수집기
 *
 * manifest 의 `dependencies` 선언을 정방향(내가 의존하는 확장)과 역방향(나에게 의존하는
 * 확장) 양쪽으로 해석합니다.
 *
 * 역방향이 이 수집기의 존재 이유입니다 — 운영자가 "이 확장을 끄면 무엇이 같이 죽는가" 를
 * 알아야 하는데, 그 정보는 어느 한 manifest 에도 없고 번들 전수를 교차 스캔해야만 나옵니다.
 * 확장명을 하드코딩하지 않고 인벤토리 스캔 결과에서 도출하므로 신규 확장이 자동 편입됩니다.
 */
class DependencyGraphCollector
{
    /**
     * @var array<int, array<string, mixed>>|null 전수 인벤토리 캐시
     */
    private ?array $universe = null;

    /**
     * @param  ExtensionInventory  $inventory  번들 확장 인벤토리
     */
    public function __construct(private readonly ExtensionInventory $inventory) {}

    /**
     * 확장의 의존 관계를 수집합니다.
     *
     * @param  array<string, mixed>  $record  ExtensionInventory 레코드
     * @return array{requires: array<int, array{type: string, id: string, constraint: string, bundled: bool}>, requiredBy: array<int, array{type: string, id: string, constraint: string}>, coreVersion: string|null}
     */
    public function collect(array $record): array
    {
        return [
            'requires' => $this->requires($record),
            'requiredBy' => $this->requiredBy($record),
            'coreVersion' => $this->coreConstraint($record),
        ];
    }

    /**
     * 이 확장이 의존하는 확장 목록을 반환합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array{type: string, id: string, constraint: string, bundled: bool}>
     */
    private function requires(array $record): array
    {
        $requires = [];

        foreach ($this->declaredDependencies($record) as $type => $entries) {
            foreach ($entries as $id => $constraint) {
                $requires[] = [
                    'type' => $type,
                    'id' => (string) $id,
                    'constraint' => (string) $constraint,
                    'bundled' => $this->isBundled($type, (string) $id),
                ];
            }
        }

        usort($requires, static fn (array $a, array $b): int => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

        return $requires;
    }

    /**
     * 이 확장에 의존하는 확장 목록을 반환합니다 (번들 전수 교차 스캔).
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array<int, array{type: string, id: string, constraint: string}>
     */
    private function requiredBy(array $record): array
    {
        $selfType = $this->pluralize((string) $record['type']);
        $selfId = (string) $record['id'];
        $dependents = [];

        foreach ($this->allExtensions() as $other) {
            if ($other['id'] === $selfId && $other['type'] === $record['type']) {
                continue;
            }

            $declared = $this->declaredDependencies($other);
            $constraint = $declared[$selfType][$selfId] ?? null;

            if ($constraint === null) {
                continue;
            }

            $dependents[] = [
                'type' => (string) $other['type'],
                'id' => (string) $other['id'],
                'constraint' => (string) $constraint,
            ];
        }

        usort($dependents, static fn (array $a, array $b): int => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

        return $dependents;
    }

    /**
     * manifest 의 코어 버전 제약을 읽습니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return string|null 코어 버전 제약 (없으면 null)
     */
    private function coreConstraint(array $record): ?string
    {
        $value = $record['manifest']['g7_version'] ?? ($record['manifest']['requires']['g7_version'] ?? null);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * manifest 의 dependencies 선언을 정규화합니다.
     *
     * @param  array<string, mixed>  $record  확장 레코드
     * @return array{modules: array<string, string>, plugins: array<string, string>}
     */
    private function declaredDependencies(array $record): array
    {
        $declared = $record['manifest']['dependencies'] ?? [];
        // templates 도 정규화한다 — pluralize() 가 'templates' 를 만드는데 여기에 그 키가
        // 없으면 템플릿의 역방향 의존(`requiredBy`)이 구조적으로 항상 빈 배열이 된다.
        $normalized = ['modules' => [], 'plugins' => [], 'templates' => []];

        if (! is_array($declared)) {
            return $normalized;
        }

        foreach (['modules', 'plugins', 'templates'] as $key) {
            $entries = $declared[$key] ?? [];
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $id => $constraint) {
                if (is_string($constraint)) {
                    $normalized[$key][(string) $id] = $constraint;
                }
            }
        }

        return $normalized;
    }

    /**
     * 유형 단수형을 manifest dependencies 의 복수 키로 바꿉니다.
     *
     * @param  string  $type  확장 유형
     * @return string 복수 키 (`modules` | `plugins` | `templates`)
     */
    private function pluralize(string $type): string
    {
        return $type.'s';
    }

    /**
     * 대상이 번들 확장인지 확인합니다.
     *
     * @param  string  $pluralType  복수 키
     * @param  string  $id  확장 식별자
     * @return bool 번들 여부
     */
    private function isBundled(string $pluralType, string $id): bool
    {
        $singular = rtrim($pluralType, 's');

        foreach ($this->allExtensions() as $ext) {
            if ($ext['type'] === $singular && $ext['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * 번들 확장 전수를 반환합니다 (1회 스캔 후 캐시).
     *
     * @return array<int, array<string, mixed>> 확장 레코드 목록
     */
    private function allExtensions(): array
    {
        if ($this->universe === null) {
            $this->universe = $this->inventory->collect('all');
        }

        return $this->universe;
    }
}
