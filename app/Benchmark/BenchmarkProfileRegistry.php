<?php

namespace App\Benchmark;

use App\Benchmark\DTO\BenchmarkProfile;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Enums\BenchmarkAxis;

/**
 * 계측 프로파일 레지스트리 — 코어 config + 활성 확장 선언 수집
 *
 * 계측 대상은 "지금 이 설치본에 실제로 존재하는 것"이어야 하므로, 커맨드에 대상을
 * 하드코딩하지 않고 소유자가 선언한 것을 런타임에 모읍니다. 코어는
 * `config/benchmark.php`, 확장은 `getBenchmarkProfiles()` 오버라이드가 선언 지점입니다
 * (채널·알림 정의 등 기존 확장 선언 훅과 동일한 수집 모델).
 *
 * 확장 선언은 격리 호출합니다 — 확장 하나의 잘못된 선언이 전체 목록을 날리면 계측 자체를
 * 못 하게 되므로, 실패한 선언만 사유와 함께 `warnings()` 로 드러내고 나머지는 살립니다.
 * 조용히 버리지는 않습니다(버려진 선언이 곧 계측 사각이 됩니다).
 */
class BenchmarkProfileRegistry
{
    /**
     * 수집된 프로파일 (정규화 키 → 프로파일)
     *
     * @var array<string, BenchmarkProfile>|null
     */
    private ?array $profiles = null;

    /**
     * 수집 중 발생한 선언 오류 메시지
     *
     * @var array<int, string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly ModuleManagerInterface $moduleManager,
        private readonly PluginManagerInterface $pluginManager,
    ) {}

    /**
     * 모든 프로파일을 정규화 키 기준으로 반환합니다.
     *
     * @return array<string, BenchmarkProfile> 정규화 키 → 프로파일
     */
    public function all(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $profiles = [];

        foreach ($this->collectCore() as $profile) {
            $profiles[$profile->qualifiedKey()] = $profile;
        }

        foreach ($this->collectExtensions() as $profile) {
            $profiles[$profile->qualifiedKey()] = $profile;
        }

        return $this->profiles = $profiles;
    }

    /**
     * 특정 축의 프로파일만 반환합니다.
     *
     * @param  BenchmarkAxis  $axis  대상 축
     * @return array<string, BenchmarkProfile> 정규화 키 → 프로파일
     */
    public function byAxis(BenchmarkAxis $axis): array
    {
        return array_filter($this->all(), fn (BenchmarkProfile $profile) => $profile->axis === $axis);
    }

    /**
     * 키로 프로파일을 해석합니다.
     *
     * 정규화 키(`sirsoft-ecommerce/orders`)를 우선 대조하고, 없으면 짧은 키로 찾습니다.
     * 짧은 키가 둘 이상의 확장에서 선언돼 모호하면 후보 목록을 사유로 돌려줍니다 —
     * 임의로 하나를 고르면 어느 확장의 목록을 잰 것인지 알 수 없게 됩니다.
     *
     * @param  string  $key  프로파일 키 (정규화 키 또는 짧은 키)
     * @return array{0: BenchmarkProfile|null, 1: string|null} [찾은 프로파일, 실패 사유]
     */
    public function resolve(string $key): array
    {
        $profiles = $this->all();

        if (isset($profiles[$key])) {
            return [$profiles[$key], null];
        }

        $matches = array_filter($profiles, fn (BenchmarkProfile $profile) => $profile->key === $key);

        if ($matches === []) {
            return [null, "등록되지 않은 프로파일: {$key} (--list-profiles 로 목록 확인)"];
        }

        if (count($matches) > 1) {
            $candidates = implode(', ', array_keys($matches));

            return [null, "프로파일 키가 모호합니다: {$key} — 다음 중 하나로 지정하세요: {$candidates}"];
        }

        return [reset($matches), null];
    }

    /**
     * 키로 프로파일을 찾습니다. (해석 실패 시 null)
     *
     * 사유가 필요하면 `resolve()` 를 씁니다.
     *
     * @param  string  $key  프로파일 키 (정규화 키 또는 짧은 키)
     * @return BenchmarkProfile|null 찾은 프로파일
     */
    public function find(string $key): ?BenchmarkProfile
    {
        return $this->resolve($key)[0];
    }

    /**
     * 수집 중 무시된 선언의 사유 목록을 반환합니다.
     *
     * @return array<int, string> 경고 메시지
     */
    public function warnings(): array
    {
        $this->all();

        return $this->warnings;
    }

    /**
     * 코어 프로파일을 수집합니다.
     *
     * @return array<int, BenchmarkProfile> 코어 프로파일 목록
     */
    private function collectCore(): array
    {
        $declared = config('benchmark.profiles', []);

        return $this->normalize(is_array($declared) ? $declared : [], 'core', 'core');
    }

    /**
     * 활성 모듈/플러그인 프로파일을 수집합니다.
     *
     * @return array<int, BenchmarkProfile> 확장 프로파일 목록
     */
    private function collectExtensions(): array
    {
        $profiles = [];

        foreach ($this->moduleManager->getActiveModules() as $module) {
            $profiles = array_merge(
                $profiles,
                $this->normalize($this->extract($module), 'module', $module->getIdentifier())
            );
        }

        foreach ($this->pluginManager->getActivePlugins() as $plugin) {
            $profiles = array_merge(
                $profiles,
                $this->normalize($this->extract($plugin), 'plugin', $plugin->getIdentifier())
            );
        }

        return $profiles;
    }

    /**
     * 확장의 `getBenchmarkProfiles()` 선언을 격리 호출로 읽습니다.
     *
     * @param  object  $extension  확장 인스턴스
     * @return array<string, mixed> 선언 배열 (실패 시 빈 배열)
     */
    private function extract(object $extension): array
    {
        if (! method_exists($extension, 'getBenchmarkProfiles')) {
            return [];
        }

        try {
            $declared = $extension->getBenchmarkProfiles();
        } catch (\Throwable $e) {
            $this->warnings[] = sprintf(
                '%s: getBenchmarkProfiles() 호출 실패 — %s',
                method_exists($extension, 'getIdentifier') ? $extension->getIdentifier() : $extension::class,
                $e->getMessage()
            );

            return [];
        }

        return is_array($declared) ? $declared : [];
    }

    /**
     * 선언 배열을 검증해 프로파일 객체로 정규화합니다.
     *
     * @param  array<string, mixed>  $declared  선언 배열 (키 → 정의)
     * @param  string  $sourceKind  출처 종류 (core|module|plugin)
     * @param  string  $sourceIdentifier  출처 식별자
     * @return array<int, BenchmarkProfile> 정규화된 프로파일 목록
     */
    private function normalize(array $declared, string $sourceKind, string $sourceIdentifier): array
    {
        $profiles = [];

        foreach ($declared as $key => $definition) {
            if (! is_string($key) || $key === '') {
                $this->warnings[] = "{$sourceIdentifier}: 프로파일 키는 빈 문자열이 아닌 문자열이어야 합니다.";

                continue;
            }

            if (! is_array($definition)) {
                $this->warnings[] = "{$sourceIdentifier}/{$key}: 프로파일 정의는 배열이어야 합니다.";

                continue;
            }

            $axis = BenchmarkAxis::tryFrom((string) ($definition['type'] ?? ''));

            if ($axis === null) {
                $this->warnings[] = sprintf(
                    '%s/%s: 알 수 없는 type — %s (허용: %s)',
                    $sourceIdentifier,
                    $key,
                    var_export($definition['type'] ?? null, true),
                    implode('|', BenchmarkAxis::values())
                );

                continue;
            }

            $missing = array_values(array_filter(
                $axis->requiredOptions(),
                fn (array $group) => array_filter(
                    $group,
                    fn (string $option) => isset($definition[$option])
                ) === []
            ));

            if ($missing !== []) {
                $this->warnings[] = sprintf(
                    '%s/%s: %s 축 필수 옵션 누락 — %s',
                    $sourceIdentifier,
                    $key,
                    $axis->value,
                    implode(', ', array_map(fn (array $group) => implode('|', $group), $missing))
                );

                continue;
            }

            $label = $definition['label'] ?? null;
            unset($definition['type'], $definition['label']);

            $profiles[] = new BenchmarkProfile(
                key: $key,
                axis: $axis,
                sourceKind: $sourceKind,
                sourceIdentifier: $sourceIdentifier,
                options: $definition,
                label: is_string($label) ? $label : null,
            );
        }

        return $profiles;
    }
}
