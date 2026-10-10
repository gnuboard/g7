<?php

namespace App\Listeners\Analytics;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\Analytics\ExtensionPurgeService;

/**
 * 확장 제거 시 통계 데이터 정리 리스너
 *
 * 확장을 **데이터까지 지우며** 제거할 때만 그 확장이 남긴 전환과 집계 차원을 지웁니다.
 * 운영자가 데이터 보존을 선택했으면 건드리지 않습니다 — 잠시 껐다 다시 켜는 경우
 * 과거 수치가 사라지면 안 되기 때문입니다.
 *
 * 모듈과 플러그인은 훅 인자의 **순서가 다릅니다**:
 * - `core.modules.after_uninstall($moduleName, $moduleInfo, $deleteData)`
 * - `core.plugins.after_uninstall($pluginName, $deleteData, $result)`
 *
 * 한 메서드로 받으면 두 번째 인자를 `$deleteData` 로 오해해 **보존을 선택한 모듈의
 * 데이터를 지우게 됩니다**(모듈의 두 번째 인자는 정보 배열이라 truthy 다). 그래서
 * 종류별로 메서드를 나눕니다.
 */
class PurgeExtensionDataListener implements HookListenerInterface
{
    /**
     * 리스너를 생성합니다.
     *
     * @param  ExtensionPurgeService  $purge  확장 통계 정리기
     */
    public function __construct(private readonly ExtensionPurgeService $purge) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 구독 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.modules.after_uninstall' => ['method' => 'handleModule', 'priority' => 20],
            'core.plugins.after_uninstall' => ['method' => 'handlePlugin', 'priority' => 20],
        ];
    }

    /**
     * 모듈 제거를 처리합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($moduleName, $moduleInfo, $deleteData)
     * @return void
     */
    public function handleModule(...$args): void
    {
        $this->purgeFor('module', $args[0] ?? null, $args[2] ?? false);
    }

    /**
     * 플러그인 제거를 처리합니다.
     *
     * @param  mixed  ...$args  훅 인자 ($pluginName, $deleteData, $result)
     * @return void
     */
    public function handlePlugin(...$args): void
    {
        $this->purgeFor('plugin', $args[0] ?? null, $args[1] ?? false);
    }

    /**
     * 훅 인자를 판정해 정리를 실행합니다.
     *
     * @param  string  $type  확장 종류 (module, plugin)
     * @param  mixed  $identifier  확장 식별자
     * @param  mixed  $deleteData  데이터까지 지우는지 여부
     * @return void
     */
    private function purgeFor(string $type, mixed $identifier, mixed $deleteData): void
    {
        if ($deleteData !== true || ! is_string($identifier) || $identifier === '') {
            return;
        }

        $this->purge->purge($type, $identifier);
    }

    /**
     * 훅 리스너 기본 진입점입니다 (이 리스너는 종류별 메서드를 씁니다).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}
}
