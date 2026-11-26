<?php

namespace App\Identity;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\LocalizedValue;
use Illuminate\Support\Facades\Log;

/**
 * 본인인증 「사용자 지정」 범위의 확장 지점(체크포인트) 레지스트리 (7.2.1)
 *
 * 관리자가 본인인증 정책의 범위를 「사용자 지정」(scope=custom)으로 고르면 대상(target)은 이 레지스트리에 등록된 키만 된다.
 * 키는 활성 모듈·플러그인이 `getIdentityCheckpoints()` 로 선언하고, 그 확장의 코드가
 * `IdentityPolicyService::enforceCheckpoint($key, $user)` 로 강제한다. 선언되지 않은 키로 만든 정책은 어디서도 강제되지
 * 않으므로 저장을 막는다(종전에는 자유 입력이라 저장은 되는데 아무 일도 없었다).
 *
 * 선언은 요청 단위로 한 번만 모은다(scoped 바인딩). DB 는 쓰지 않는다. 마지막에 필터 `core.identity.filter_checkpoints` 를 거친다.
 */
class IdentityCheckpointRegistry
{
    /** @var array<string, array{key: string, name: array<string, string>, description: array<string, string>, source_type: string, source_identifier: string}>|null 수집 결과 */
    private ?array $checkpoints = null;

    /**
     * @param  ModuleManager  $modules  모듈 관리자
     * @param  PluginManager  $plugins  플러그인 관리자
     */
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly PluginManager $plugins,
    ) {}

    /**
     * 등록된 체크포인트 전체를 키 순으로 반환합니다.
     *
     * @return array<string, array{key: string, name: array<string, string>, description: array<string, string>, source_type: string, source_identifier: string}> 키 => 선언
     */
    public function all(): array
    {
        if ($this->checkpoints !== null) {
            return $this->checkpoints;
        }

        $collected = [];

        foreach ($this->modules->getActiveModules() as $module) {
            $this->collect($collected, 'module', (string) $module->getIdentifier(), $this->declarationsOf($module));
        }

        foreach ($this->plugins->getActivePlugins() as $plugin) {
            $this->collect($collected, 'plugin', (string) $plugin->getIdentifier(), $this->declarationsOf($plugin));
        }

        $filtered = HookManager::applyFilters('core.identity.filter_checkpoints', $collected);
        if (is_array($filtered)) {
            $normalized = [];
            foreach ($filtered as $key => $checkpoint) {
                if (is_array($checkpoint) && is_string($checkpoint['key'] ?? $key) && ($checkpoint['key'] ?? $key) !== '') {
                    $checkpointKey = (string) ($checkpoint['key'] ?? $key);
                    $normalized[$checkpointKey] = $this->normalize($checkpointKey, $checkpoint, (string) ($checkpoint['source_type'] ?? 'custom'), (string) ($checkpoint['source_identifier'] ?? 'custom'));
                }
            }
            $collected = $normalized;
        }

        ksort($collected);

        return $this->checkpoints = $collected;
    }

    /**
     * 키가 등록되어 있는지 확인합니다.
     *
     * @param  string|null  $key  체크포인트 키
     * @return bool 등록되어 있으면 true
     */
    public function has(?string $key): bool
    {
        return is_string($key) && $key !== '' && array_key_exists($key, $this->all());
    }

    /**
     * 체크포인트 하나를 반환합니다.
     *
     * @param  string  $key  체크포인트 키
     * @return array<string, mixed>|null 선언 (없으면 null)
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 화면·API 용 목록을 지금 언어로 반환합니다.
     *
     * @return array<int, array{key: string, name: string, description: string, source_type: string, source_identifier: string}> 목록
     */
    public function toList(): array
    {
        return array_values(array_map(fn (array $checkpoint) => [
            'key' => $checkpoint['key'],
            'name' => LocalizedValue::pick($checkpoint['name']) ?? $checkpoint['key'],
            'description' => LocalizedValue::pick($checkpoint['description']) ?? '',
            'source_type' => $checkpoint['source_type'],
            'source_identifier' => $checkpoint['source_identifier'],
        ], $this->all()));
    }

    /**
     * 다음 조회가 다시 모으도록 수집 결과를 비웁니다. (확장 활성 상태가 바뀐 같은 요청·테스트용)
     *
     * @return void 반환값 없음
     */
    public function flush(): void
    {
        $this->checkpoints = null;
    }

    /**
     * 확장의 선언을 안전하게 읽습니다.
     *
     * @param  object  $extension  모듈·플러그인 인스턴스
     * @return array<int, mixed> 선언 목록
     */
    private function declarationsOf(object $extension): array
    {
        if (! method_exists($extension, 'getIdentityCheckpoints')) {
            return [];
        }

        try {
            $declared = $extension->getIdentityCheckpoints();

            return is_array($declared) ? $declared : [];
        } catch (\Throwable $e) {
            Log::warning('본인인증 체크포인트 선언 읽기 실패', [
                'extension' => $extension::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 선언 목록을 수집 결과에 더합니다. 같은 키가 이미 있으면 먼저 등록한 쪽을 둔다.
     *
     * @param  array<string, array<string, mixed>>  $collected  수집 결과 (참조로 갱신)
     * @param  string  $sourceType  출처 유형 (module|plugin)
     * @param  string  $sourceIdentifier  출처 확장 식별자
     * @param  array<int, mixed>  $declarations  선언 목록
     * @return void 반환값 없음
     */
    private function collect(array &$collected, string $sourceType, string $sourceIdentifier, array $declarations): void
    {
        foreach ($declarations as $declaration) {
            $key = is_array($declaration) ? ($declaration['key'] ?? null) : null;
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9_\-]*(\.[a-z0-9_\-]+)+$/', $key)) {
                continue;
            }

            if (isset($collected[$key])) {
                Log::warning('본인인증 체크포인트 키 중복 — 먼저 선언한 확장을 둔다', [
                    'key' => $key,
                    'kept' => $collected[$key]['source_identifier'],
                    'ignored' => $sourceIdentifier,
                ]);

                continue;
            }

            $collected[$key] = $this->normalize($key, $declaration, $sourceType, $sourceIdentifier);
        }
    }

    /**
     * 선언 하나를 표준 모양으로 맞춥니다.
     *
     * @param  string  $key  체크포인트 키
     * @param  array<string, mixed>  $declaration  선언
     * @param  string  $sourceType  출처 유형
     * @param  string  $sourceIdentifier  출처 식별자
     * @return array{key: string, name: array<string, string>, description: array<string, string>, source_type: string, source_identifier: string} 선언
     */
    private function normalize(string $key, array $declaration, string $sourceType, string $sourceIdentifier): array
    {
        $texts = static function (mixed $value) use ($key): array {
            if (is_string($value) && $value !== '') {
                return ['ko' => $value, 'en' => $value];
            }

            return is_array($value) ? array_filter($value, 'is_string') : [];
        };

        return [
            'key' => $key,
            'name' => $texts($declaration['name'] ?? null) ?: ['ko' => $key, 'en' => $key],
            'description' => $texts($declaration['description'] ?? null),
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
        ];
    }
}
