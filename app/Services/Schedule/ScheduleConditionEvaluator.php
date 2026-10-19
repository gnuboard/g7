<?php

namespace App\Services\Schedule;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Schedule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 스케줄 실행 조건(`enabled_condition`) 해석기.
 *
 * 조건 형식 (여러 개면 AND):
 * ```
 * [{source: core_setting|module_setting|plugin_setting|config, key, operator: truthy|not_empty|equals, value?, default}]
 * ```
 *
 * - core_setting   — `g7_core_settings($key, $default)` (설정 서비스 경유 — env 잠금 우회 문제 없음)
 * - module_setting — `module_setting(소유 모듈, $key, $default)`
 * - plugin_setting — `plugin_setting(소유 플러그인, $key, $default)`
 * - config         — `config($key, $default)`
 *
 * 모듈·플러그인 설정의 식별자는 조건의 `identifier` 가 있으면 그것을, 없으면 행의 소유 확장을 쓴다.
 * 형식이 잘못된 조건은 "미충족" 으로 본다(실행하지 않는 쪽이 안전하다).
 *
 * @since 7.2.0
 */
class ScheduleConditionEvaluator
{
    /** @var array<int, string> 허용 source */
    public const SOURCES = ['core_setting', 'module_setting', 'plugin_setting', 'config'];

    /** @var array<int, string> 허용 operator */
    public const OPERATORS = ['truthy', 'not_empty', 'equals'];

    /**
     * @param  ConfigRepositoryInterface  $configRepository  코어 설정 저장소 (기본값 키 존재 확인용)
     */
    public function __construct(
        private readonly ConfigRepositoryInterface $configRepository,
    ) {}

    /**
     * 스케줄의 모든 실행 조건이 충족되는지 판정합니다.
     *
     * @param  Schedule  $schedule  판정할 스케줄
     * @return bool 조건이 없거나 모두 충족하면 true
     */
    public function passes(Schedule $schedule): bool
    {
        return $this->unmetConditions($schedule) === [];
    }

    /**
     * 충족되지 않은 조건 목록을 반환합니다 (화면 배지용).
     *
     * @param  Schedule  $schedule  판정할 스케줄
     * @return array<int, array{source: string, key: string, operator: string}> 미충족 조건 목록
     */
    public function unmetConditions(Schedule $schedule): array
    {
        $conditions = $schedule->enabled_condition;
        if (! is_array($conditions) || $conditions === []) {
            return [];
        }

        $unmet = [];
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->isWellFormed($condition)) {
                Log::warning('[ScheduleCondition] 형식이 잘못된 실행 조건 — 미충족으로 판정', [
                    'schedule_id' => $schedule->id,
                    'condition' => $condition,
                ]);
                $unmet[] = $this->summarize(is_array($condition) ? $condition : []);

                continue;
            }

            if (! $this->conditionPasses($condition, $this->ownerIdentifierFor($schedule, $condition))) {
                $unmet[] = $this->summarize($condition);
            }
        }

        return $unmet;
    }

    /**
     * 조건의 키가 실제로 해석 가능한지 확인합니다 (카탈로그·선언 순회 검사용).
     *
     * 형식이 맞고, 읽을 설정 키가 그 원천의 기본값 정의에 존재해야 true.
     * - core_setting: `config/settings/defaults.json` 의 defaults
     * - module_setting/plugin_setting: 해당 확장 `config/settings/defaults.json` 의 defaults 또는
     *   `getConfigValues()`(설정 서비스가 실행 시점에 기본값으로 병합하는 원천 — 예: gdpr 은 여기에만 있다)
     * - config: 설정 저장소에 키가 있음
     *
     * @param  array<string, mixed>  $condition  조건 1건
     * @param  string|null  $ownerIdentifier  모듈·플러그인 조건의 소유 식별자 (조건에 identifier 가 없을 때)
     * @return bool 해석 가능 여부
     */
    public function isResolvable(array $condition, ?string $ownerIdentifier = null): bool
    {
        if (! $this->isWellFormed($condition)) {
            return false;
        }

        $key = (string) $condition['key'];
        $identifier = isset($condition['identifier']) ? (string) $condition['identifier'] : $ownerIdentifier;

        return match ($condition['source']) {
            'core_setting' => Arr::has($this->configRepository->getDefaults(), $key),
            'config' => Config::has($key),
            'module_setting' => $identifier !== null
                && $this->extensionHasSetting(app(ModuleManager::class)->getModule($identifier), $key),
            'plugin_setting' => $identifier !== null
                && $this->extensionHasSetting(app(PluginManager::class)->getPlugin($identifier), $key),
            default => false,
        };
    }

    /**
     * 조건 1건의 형식이 올바른지 확인합니다.
     *
     * @param  array<string, mixed>  $condition  조건 1건
     * @return bool 형식 적합 여부
     */
    public function isWellFormed(array $condition): bool
    {
        if (! in_array($condition['source'] ?? null, self::SOURCES, true)) {
            return false;
        }
        if (! is_string($condition['key'] ?? null) || $condition['key'] === '') {
            return false;
        }
        if (! in_array($condition['operator'] ?? null, self::OPERATORS, true)) {
            return false;
        }

        return $condition['operator'] !== 'equals' || array_key_exists('value', $condition);
    }

    /**
     * 조건 1건을 평가합니다.
     *
     * @param  array<string, mixed>  $condition  형식이 검증된 조건
     * @param  string|null  $identifier  모듈·플러그인 설정 식별자
     * @return bool 충족 여부
     */
    private function conditionPasses(array $condition, ?string $identifier): bool
    {
        $actual = $this->resolveValue($condition, $identifier);

        return match ($condition['operator']) {
            'truthy' => (bool) $actual,
            'not_empty' => $actual !== null && $actual !== '' && $actual !== [],
            'equals' => $this->looselyEquals($actual, $condition['value']),
            default => false,
        };
    }

    /**
     * 조건이 가리키는 현재 값을 읽습니다.
     *
     * @param  array<string, mixed>  $condition  형식이 검증된 조건
     * @param  string|null  $identifier  모듈·플러그인 설정 식별자
     * @return mixed 현재 값 (읽지 못하면 조건의 default)
     */
    private function resolveValue(array $condition, ?string $identifier): mixed
    {
        $key = (string) $condition['key'];
        $default = $condition['default'] ?? null;

        try {
            return match ($condition['source']) {
                'core_setting' => g7_core_settings($key, $default),
                'module_setting' => $identifier !== null ? module_setting($identifier, $key, $default) : $default,
                'plugin_setting' => $identifier !== null ? plugin_setting($identifier, $key, $default) : $default,
                'config' => config($key, $default),
                default => $default,
            };
        } catch (Throwable $e) {
            Log::warning('[ScheduleCondition] 조건 값 조회 실패 — default 사용', [
                'source' => $condition['source'],
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * 설정 저장 표기 차이(문자열 "1"·불리언 등)를 흡수해 비교합니다.
     *
     * @param  mixed  $actual  현재 값
     * @param  mixed  $expected  기대 값
     * @return bool 같은 값이면 true
     */
    private function looselyEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($expected)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $expected;
        }
        if (is_scalar($actual) && is_scalar($expected)) {
            return (string) $actual === (string) $expected;
        }

        return $actual === $expected;
    }

    /**
     * 모듈·플러그인 조건의 설정 식별자를 고릅니다.
     *
     * @param  Schedule  $schedule  스케줄
     * @param  array<string, mixed>  $condition  조건 1건
     * @return string|null 식별자 (코어·직접 등록 행에서 identifier 없는 확장 조건이면 null)
     */
    private function ownerIdentifierFor(Schedule $schedule, array $condition): ?string
    {
        if (isset($condition['identifier']) && is_string($condition['identifier'])) {
            return $condition['identifier'];
        }

        $owner = $schedule->extension_type;
        if ($owner === ExtensionOwnerType::Module || $owner === ExtensionOwnerType::Plugin) {
            return (string) $schedule->extension_identifier;
        }

        return null;
    }

    /**
     * 확장 설정 기본값에 키가 있는지 확인합니다.
     *
     * 실행 시점 해석(module_setting/plugin_setting → 설정 서비스)과 같은 원천을 본다:
     * defaults.json 의 defaults 블록과 `getConfigValues()`.
     *
     * @param  ModuleInterface|PluginInterface|null  $extension  확장 인스턴스 (없으면 false)
     * @param  string  $key  dot 설정 키
     * @return bool 키 존재 여부
     */
    private function extensionHasSetting(ModuleInterface|PluginInterface|null $extension, string $key): bool
    {
        if ($extension === null) {
            return false;
        }

        if (Arr::has($this->extensionDefaults($extension->getSettingsDefaultsPath()), $key)) {
            return true;
        }

        try {
            return Arr::has($extension->getConfigValues(), $key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 확장 defaults.json 의 defaults 블록을 읽습니다.
     *
     * @param  string|null  $path  defaults.json 절대 경로
     * @return array<string, mixed> defaults 블록 (없으면 빈 배열)
     */
    private function extensionDefaults(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded['defaults'] ?? null) ? $decoded['defaults'] : [];
    }

    /**
     * 화면 배지용 조건 요약을 만듭니다.
     *
     * @param  array<string, mixed>  $condition  조건 1건
     * @return array{source: string, key: string, operator: string} 요약
     */
    private function summarize(array $condition): array
    {
        return [
            'source' => (string) ($condition['source'] ?? ''),
            'key' => (string) ($condition['key'] ?? ''),
            'operator' => (string) ($condition['operator'] ?? ''),
        ];
    }
}
