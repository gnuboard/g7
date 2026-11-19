<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Support\Positions\PositionDefinition;
use Plugins\Sirsoft\HeroSection\Support\Positions\PositionFragment;
use Throwable;

/**
 * 등록된 위치의 출력 자리 채우기 — 다른 확장이 위치 등록 필터로 더한 위치는 히어로 조각 파일이 없다. 사용자 템플릿이 그 위치 키와
 * 같은 이름의 확장점을 두면, 레이아웃을 낼 때(`core.layout_extension.after_apply`) 그 자리에 내장 위치와 같은 출력 조각
 * ({@see PositionFragment})을 붙인다. 위치를 등록한 확장은 히어로 조각을 복사하지 않는다.
 *
 * 이미 그 위치의 히어로 슬라이더가 든 자리(내장 위치, 확장이 자기 조각을 둔 자리)는 건드리지 않는다 — 안쪽에 다른 확장점이 있으면
 * 그 안의 슬라이더는 그 자리 몫이라 세지 않는다. 자리 안의 자리, 코어가 확장점을 처리하는 반복 템플릿(표·카드 셀, 펼침 행, 하위 행)
 * 안의 자리도 같은 규칙으로 채운다. 편집기 모드(노드마다 출처 메타가 붙은
 * 레이아웃)도 건드리지 않는다 — 붙인 노드가 편집기 저장으로 템플릿 레이아웃에 섞이지 않게. 동적 위치는 대상 값 경로
 * (`target.route_param`)가 있어야 붙인다. 히어로가 꺼지면 이 리스너도 빠져 자리는 비고, 위치를 더한 확장이 꺼지면 위치가
 * 등록되지 않아 붙이지 않는다.
 */
class PositionOutputListener implements HookListenerInterface
{
    /**
     * 히어로 슬라이더 컴포넌트 이름 — 자리에 이미 있는지 볼 때 쓴다
     */
    private const SLIDER = 'sirsoft-hero_section.HeroSlider';

    /**
     * 이번 레이아웃에 이미 있던 데이터 소스 (id => 정의) — 만든 조각의 id 와 겹치는지 볼 때 쓴다
     *
     * @var array<string, array<string, mixed>>
     */
    private array $existingSources = [];

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => ['method' => 'fill', 'type' => 'filter', 'priority' => 50],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 레이아웃의 빈 위치 자리에 출력 조각을 붙입니다.
     *
     * @param  array<string, mixed>  $layout  확장이 적용된 레이아웃
     * @param  int  $templateId  템플릿 ID
     * @return array<string, mixed> 출력 조각을 붙인 레이아웃
     */
    public function fill(array $layout, int $templateId = 0): array
    {
        try {
            $definitions = app(PositionRegistry::class)->all();
            $this->existingSources = [];
            foreach (is_array($layout['data_sources'] ?? null) ? $layout['data_sources'] : [] as $source) {
                if (is_array($source) && is_string($source['id'] ?? null)) {
                    $this->existingSources[$source['id']] = $source;
                }
            }
            $dataSources = [];
            foreach (['components', 'modals'] as $tree) {
                if (isset($layout[$tree]) && is_array($layout[$tree])) {
                    $layout[$tree] = $this->walk($layout[$tree], $definitions, $dataSources);
                }
            }
        } catch (Throwable $e) {
            // 위치 출력 때문에 화면 전체를 깨지 않는다 — 원래 레이아웃을 그대로 낸다
            Log::warning('히어로섹션 위치 출력 조각을 붙이지 못했습니다', ['layout' => $layout['layout_name'] ?? null, 'error' => $e->getMessage()]);

            return $layout;
        }

        foreach ($dataSources as $dataSource) {
            $layout['data_sources'][] = $dataSource;
        }

        return $layout;
    }

    /**
     * 노드를 내려가며 위치 자리를 채웁니다 — 채운 자리 안쪽과 반복 템플릿 자리까지 내려간다.
     *
     * @param  array<int|string, mixed>  $nodes  노드 목록
     * @param  array<string, PositionDefinition>  $definitions  등록된 위치
     * @param  array<string, array<string, mixed>>  $dataSources  붙일 데이터 소스(id => 정의, 누적)
     * @return array<int|string, mixed> 채운 노드 목록
     */
    private function walk(array $nodes, array $definitions, array &$dataSources): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            $name = $node['name'] ?? null;
            if (($node['type'] ?? '') === 'extension_point' && is_string($name) && isset($definitions[$name])
                && ! isset($node['__source']) && ! $this->hasSlider($node['children'] ?? [], $name)) {
                $fragment = PositionFragment::for($definitions[$name]);
                if ($fragment !== null && $this->sourcesUsable($fragment['data_sources'], $name)) {
                    $props = is_array($node['props'] ?? null) ? $node['props'] : [];
                    $components = array_map(fn (array $component) => $props === [] ? $component : [...$component, 'extensionPointProps' => $props], $fragment['components']);
                    $node['children'] = [...(is_array($node['children'] ?? null) ? $node['children'] : []), ...$components];
                    // 같은 위치 자리가 여럿이면 데이터 소스는 한 번만 붙인다
                    foreach ($fragment['data_sources'] as $source) {
                        if (! isset($dataSources[$source['id']]) && ! isset($this->existingSources[$source['id']])) {
                            $dataSources[$source['id']] = $source;
                        }
                    }
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->walk($node['children'], $definitions, $dataSources);
            }
            if (isset($node['props']) && is_array($node['props'])) {
                $node['props'] = $this->walkTemplates($node['props'], $definitions, $dataSources);
            }
        }
        unset($node);

        return $nodes;
    }

    /**
     * 반복 템플릿 자리를 내려갑니다 — 코어가 확장점을 처리하는 자리와 같다(표·카드 열의 `cellChildren`, `expandChildren`, `subRowChildren`).
     *
     * @param  array<string, mixed>  $props  컴포넌트 props
     * @param  array<string, PositionDefinition>  $definitions  등록된 위치
     * @param  array<string, array<string, mixed>>  $dataSources  붙일 데이터 소스(id => 정의, 누적)
     * @return array<string, mixed> 채운 props
     */
    private function walkTemplates(array $props, array $definitions, array &$dataSources): array
    {
        foreach (['columns', 'cardColumns'] as $columnsKey) {
            foreach (is_array($props[$columnsKey] ?? null) ? $props[$columnsKey] : [] as $index => $column) {
                if (is_array($column) && is_array($column['cellChildren'] ?? null)) {
                    $props[$columnsKey][$index]['cellChildren'] = $this->walk($column['cellChildren'], $definitions, $dataSources);
                }
            }
        }
        foreach (['expandChildren', 'subRowChildren'] as $templateKey) {
            if (is_array($props[$templateKey] ?? null) && array_is_list($props[$templateKey])) {
                $props[$templateKey] = $this->walk($props[$templateKey], $definitions, $dataSources);
            }
        }

        return $props;
    }

    /**
     * 조각의 데이터 소스를 이 레이아웃에 쓸 수 있는지 반환합니다 — 같은 id 의 다른 데이터 소스(다른 주소·다른 위치)가 이미 있으면
     * 자동 슬라이더가 그 응답을 읽게 되므로 그 자리는 채우지 않고 경고를 남긴다.
     *
     * @param  array<int, array<string, mixed>>  $sources  조각의 데이터 소스
     * @param  string  $position  위치 키
     * @return bool 쓸 수 있으면 true
     */
    private function sourcesUsable(array $sources, string $position): bool
    {
        foreach ($sources as $source) {
            $existing = $this->existingSources[$source['id']] ?? null;
            if ($existing !== null && (($existing['endpoint'] ?? null) !== ($source['endpoint'] ?? null) || ($existing['params']['position'] ?? null) !== $position)) {
                Log::warning('히어로섹션 위치 자리를 채우지 않았습니다 — 같은 id 의 다른 데이터 소스가 레이아웃에 있습니다', ['position' => $position, 'data_source' => $source['id']]);

                return false;
            }
        }

        return true;
    }

    /**
     * 자리 안에 그 위치를 그리는 히어로 슬라이더가 있는지 반환합니다 — 위치를 바인딩 식으로 준 슬라이더도 그 자리 것으로 보고,
     * 반복 템플릿(표·카드 셀, 펼침 행, 하위 행) 안까지 본다. 안쪽 확장점은 그 자리 몫이라 내려가지 않는다.
     *
     * @param  mixed  $nodes  노드 목록
     * @param  string  $position  위치 키
     * @return bool 있으면 true
     */
    private function hasSlider(mixed $nodes, string $position): bool
    {
        if (! is_array($nodes)) {
            return false;
        }
        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['type'] ?? '') === 'extension_point') {
                continue;
            }
            if (($node['name'] ?? null) === self::SLIDER) {
                $given = $node['props']['position'] ?? null;
                if ($given === $position || (is_string($given) && str_contains($given, '{{'))) {
                    return true;
                }
            }
            if ($this->hasSlider($node['children'] ?? null, $position) || $this->hasSliderInTemplates($node['props'] ?? null, $position)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 반복 템플릿 안에 그 위치를 그리는 히어로 슬라이더가 있는지 반환합니다.
     *
     * @param  mixed  $props  컴포넌트 props
     * @param  string  $position  위치 키
     * @return bool 있으면 true
     */
    private function hasSliderInTemplates(mixed $props, string $position): bool
    {
        if (! is_array($props)) {
            return false;
        }
        foreach (['columns', 'cardColumns'] as $columnsKey) {
            foreach (is_array($props[$columnsKey] ?? null) ? $props[$columnsKey] : [] as $column) {
                if (is_array($column) && $this->hasSlider($column['cellChildren'] ?? null, $position)) {
                    return true;
                }
            }
        }
        foreach (['expandChildren', 'subRowChildren'] as $templateKey) {
            if ($this->hasSlider($props[$templateKey] ?? null, $position)) {
                return true;
            }
        }

        return false;
    }
}
