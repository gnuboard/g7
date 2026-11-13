<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Support\Positions\PositionDefinition;

/**
 * 노출 위치 레지스트리 — 위치 목록의 단일 판정 지점.
 *
 * 필터 `sirsoft-hero_section.position.registered_positions` 로 위치 정의 배열을 모아 검증·정렬한다. 내장 위치(사이트 홈·쇼핑몰 메인,
 * 모듈이 켜져 있으면 게시판·상품·카테고리)는 `BuiltinPositionListener` 가 같은 필터에 맨 앞으로 넣고, 제3 확장은 같은 필터로 더한다.
 * 어긋난 정의(키 형식 위반·이미 있는 키·이름 없음·해석기 계약 위반)는 버리고 경고를 남긴다 — 화면을 깨지 않는다. 같은 키는 먼저 온 쪽이 이긴다.
 * 결과는 이 인스턴스 안에서만 기억한다(요청마다 다시 모은다 — 모듈을 켜고 끄면 바로 따른다).
 */
class PositionRegistry
{
    /**
     * 위치 정의를 모으는 필터 이름(공개 확장 계약)
     */
    public const FILTER = 'sirsoft-hero_section.position.registered_positions';

    /**
     * 위치를 고르지 않았을 때 쓰는 위치(사이트 홈 — 늘 등록되는 내장 정적 위치)
     */
    public const DEFAULT_POSITION = 'home_hero';

    /**
     * 검증·정렬한 위치 정의(키 순서 = 탭 순서)
     *
     * @var array<string, PositionDefinition>|null
     */
    private ?array $definitions = null;

    /**
     * 등록된 위치 정의를 탭 순서로 반환합니다.
     *
     * @return array<string, PositionDefinition> 위치 키 => 정의
     */
    public function all(): array
    {
        return $this->definitions ??= $this->collect();
    }

    /**
     * 위치 정의를 찾습니다.
     *
     * @param  string  $key  위치 키
     * @return PositionDefinition|null 정의 (등록되지 않았으면 null)
     */
    public function find(string $key): ?PositionDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 등록된 위치인지 반환합니다.
     *
     * @param  string  $key  위치 키
     * @return bool 등록돼 있으면 true
     */
    public function isRegistered(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * 동적 위치(대상이 있는 위치)인지 반환합니다.
     *
     * @param  string  $key  위치 키
     * @return bool 동적 위치면 true
     */
    public function isDynamic(string $key): bool
    {
        return $this->find($key)?->isDynamic() ?? false;
    }

    /**
     * 등록된 위치 키 목록을 반환합니다.
     *
     * @return array<int, string> 위치 키
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * 동적 위치의 대상 해석기를 만듭니다.
     *
     * @param  string  $key  위치 키
     * @return TargetResolverInterface|null 해석기 (정적 위치이거나 등록되지 않았으면 null)
     */
    public function resolver(string $key): ?TargetResolverInterface
    {
        $resolver = $this->find($key)?->resolver;

        return $resolver === null ? null : app($resolver);
    }

    /**
     * 필터로 정의를 모아 검증·정렬합니다.
     *
     * @return array<string, PositionDefinition> 위치 키 => 정의
     */
    private function collect(): array
    {
        // 훅 이름을 글자 그대로 적는다 — 확장 문서 생성기가 발행 위치를 리터럴 호출로만 찾는다(값은 self::FILTER 와 같다)
        $raw = HookManager::applyFilters('sirsoft-hero_section.position.registered_positions', []);
        $definitions = [];
        $index = 0;
        foreach (is_array($raw) ? $raw : [] as $entry) {
            $definition = $this->validate($entry, $definitions);
            if ($definition !== null) {
                $definitions[$definition->key] = ['definition' => $definition, 'index' => $index++];
            }
        }

        uasort($definitions, fn (array $a, array $b) => [$a['definition']->order, $a['index']] <=> [$b['definition']->order, $b['index']]);

        return array_map(fn (array $item) => $item['definition'], $definitions);
    }

    /**
     * 정의 한 건을 검증합니다 — 어긋나면 경고를 남기고 null.
     *
     * @param  mixed  $entry  필터가 돌려준 항목
     * @param  array<string, mixed>  $accepted  지금까지 받은 정의
     * @return PositionDefinition|null 정의
     */
    private function validate(mixed $entry, array $accepted): ?PositionDefinition
    {
        if (! is_array($entry)) {
            return $this->reject('정의가 배열이 아닙니다', $entry);
        }

        $key = $entry['key'] ?? null;
        if (! is_string($key) || preg_match(PositionDefinition::KEY_PATTERN, $key) !== 1) {
            return $this->reject('위치 키 형식이 맞지 않습니다', $key);
        }
        if (isset($accepted[$key])) {
            return $this->reject('이미 등록된 위치 키입니다', $key);
        }
        if (! self::isLabel($entry['label'] ?? null)) {
            return $this->reject('위치 이름이 없습니다', $key);
        }

        $target = $entry['target'] ?? null;
        if ($target !== null) {
            $resolver = is_array($target) ? ($target['resolver'] ?? null) : null;
            if (! is_array($target) || ! is_string($target['type'] ?? null) || $target['type'] === '' || ! self::isLabel($target['label'] ?? null)
                || ! is_string($resolver) || ! is_subclass_of($resolver, TargetResolverInterface::class)) {
                return $this->reject('대상 정의(type·label·resolver)가 맞지 않습니다', $key);
            }
            $routeParam = $target['route_param'] ?? null;
            if ($routeParam !== null && (! is_string($routeParam) || preg_match(PositionDefinition::ROUTE_PARAM_PATTERN, $routeParam) !== 1)) {
                return $this->reject('대상 경로 매개변수(route_param) 형식이 맞지 않습니다', $key);
            }
        }

        return new PositionDefinition(
            key: $key,
            label: $entry['label'],
            order: is_int($entry['order'] ?? null) ? $entry['order'] : 1000,
            targetType: $target['type'] ?? null,
            targetLabel: $target['label'] ?? null,
            resolver: $target['resolver'] ?? null,
            routeParam: $target['route_param'] ?? null,
        );
    }

    /**
     * 이름 형식(문구 키 문자열 또는 비어 있지 않은 언어별 객체)인지 확인합니다.
     *
     * @param  mixed  $label  이름
     * @return bool 형식이 맞으면 true
     */
    private static function isLabel(mixed $label): bool
    {
        return (is_string($label) && $label !== '')
            || (is_array($label) && $label !== [] && array_filter($label, fn ($v) => is_string($v) && $v !== '') !== []);
    }

    /**
     * 버린 정의를 경고로 남깁니다.
     *
     * @param  string  $reason  이유
     * @param  mixed  $subject  정의 또는 키
     * @return null 늘 null
     */
    private function reject(string $reason, mixed $subject): null
    {
        Log::warning('히어로섹션 위치 정의를 버렸습니다: '.$reason, ['subject' => is_scalar($subject) ? $subject : get_debug_type($subject)]);

        return null;
    }
}
