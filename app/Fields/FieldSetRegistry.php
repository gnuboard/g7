<?php

namespace App\Fields;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\Log;

/**
 * 항목 집합 등록소.
 *
 * 코어 집합(`config/core.php` 의 `field_sets`)과 활성 확장의 `getFieldSets()` 선언을 처음 쓰일 때 모으고,
 * 필터 `core.field.filter_sets` 로 동적 등록을 받는다.
 *
 * @since 7.2.0
 */
class FieldSetRegistry
{
    /**
     * 모은 집합 (null 이면 아직 모으지 않음).
     *
     * @var array<string, FieldSet>|null
     */
    protected ?array $sets = null;

    /**
     * 집합을 직접 등록합니다 (테스트와 부팅 코드용).
     *
     * @param  FieldSet  $set  집합
     * @return void 반환값 없음
     */
    public function register(FieldSet $set): void
    {
        $this->all();
        $this->sets[$set->key] = $set;
    }

    /**
     * 집합을 반환합니다.
     *
     * @param  string  $key  집합 식별자
     * @return FieldSet|null 집합 (없으면 null)
     */
    public function get(string $key): ?FieldSet
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 집합이 있는지 반환합니다.
     *
     * @param  string  $key  집합 식별자
     * @return bool 있으면 true
     */
    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * 모든 집합을 반환합니다.
     *
     * @return array<string, FieldSet> 집합 식별자 => 집합
     */
    public function all(): array
    {
        if ($this->sets !== null) {
            return $this->sets;
        }

        $sets = [];
        foreach ((array) config('core.field_sets', []) as $data) {
            if (is_array($data) && isset($data['key'])) {
                $sets[$data['key']] = FieldSet::fromArray($data, 'core', 'core');
            }
        }

        foreach ($this->extensionDeclarations() as [$ownerType, $identifier, $declared]) {
            foreach ($declared as $data) {
                if (! is_array($data) || ! isset($data['key'])) {
                    continue;
                }
                if (isset($sets[$data['key']])) {
                    Log::warning('항목 집합 키 충돌 — 먼저 등록된 쪽을 유지합니다', ['key' => $data['key'], 'skipped' => $identifier]);

                    continue;
                }
                $sets[$data['key']] = FieldSet::fromArray($data, $ownerType, $identifier);
            }
        }

        $filtered = HookManager::applyFilters('core.field.filter_sets', $sets);
        $this->sets = [];
        foreach (is_array($filtered) ? $filtered : $sets as $key => $set) {
            if ($set instanceof FieldSet) {
                $this->sets[$set->key] = $set;
            }
        }

        return $this->sets;
    }

    /**
     * 모은 결과를 버립니다 (확장 활성 상태가 바뀐 뒤와 테스트용).
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->sets = null;
    }

    /**
     * 활성 확장의 집합 선언을 모읍니다.
     *
     * @return array<int, array{0: string, 1: string, 2: array<int, mixed>}> [종류, 식별자, 선언 목록]
     */
    protected function extensionDeclarations(): array
    {
        $declarations = [];
        try {
            foreach (app(ModuleManager::class)->getActiveModules() as $module) {
                if (method_exists($module, 'getFieldSets')) {
                    $declarations[] = ['module', $module->getIdentifier(), (array) $module->getFieldSets()];
                }
            }
            foreach (app(PluginManager::class)->getActivePlugins() as $plugin) {
                if (method_exists($plugin, 'getFieldSets')) {
                    $declarations[] = ['plugin', $plugin->getIdentifier(), (array) $plugin->getFieldSets()];
                }
            }
        } catch (\Throwable $e) {
            // 설치 전·마이그레이션 전 구간에서는 확장 목록을 읽지 못할 수 있다 — 코어 집합만으로 동작한다.
            Log::debug('항목 집합: 확장 선언을 읽지 못했습니다', ['error' => $e->getMessage()]);
        }

        return $declarations;
    }
}
