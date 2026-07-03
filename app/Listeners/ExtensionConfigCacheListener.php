<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Support\ConfigCacheHelper;
use Illuminate\Support\Facades\Log;

/**
 * 확장 활성화/비활성화 시 config 캐시를 재생성하는 리스너.
 *
 * 확장이 활성화·비활성화되면 다음 요청부터 그 확장의 config/settings 가 부팅 시
 * 주입 대상에 포함/제외된다. config:cache 가 켜진 환경에서는 optimizeSystem 이
 * 캐시를 만든 시점의 "활성 확장 스냅샷" 이 stale 해지므로, 라이프사이클 직후
 * config 캐시를 재빌드해 캐시가 최신 활성 목록을 반영하도록 한다.
 *
 * install/uninstall/update 는 ExtensionManager::updateComposerAutoload() 가 오토로드·훅
 * 캐시와 나란히 config 캐시를 재생성하므로 여기서 구독하지 않는다(이중 재생성 회피).
 * 이 리스너는 updateComposerAutoload 를 거치지 않는 activate/deactivate 만 담당한다.
 *
 * @see \App\Extension\ExtensionManager::updateComposerAutoload()
 * @see \App\Support\ConfigCacheHelper
 */
class ExtensionConfigCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * activate/deactivate 만 구독한다 (install/uninstall/update 는 updateComposerAutoload 담당).
     *
     * @return array<string, array{method: string, priority: int}> 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        $hooks = [];
        $hookNames = [
            'core.modules.activated',
            'core.modules.after_deactivate',
            'core.plugins.activated',
            'core.plugins.after_deactivate',
            'core.templates.activated',
            'core.templates.after_deactivate',
        ];

        foreach ($hookNames as $hookName) {
            $hooks[$hookName] = [
                'method' => 'onExtensionToggled',
                'priority' => 30,
                // config 캐시 재생성은 인프라 작업이라 큐로 미루면 안 된다. Action 훅의 기본
                // 등록은 큐 디스패치(HookListenerRegistrar) 이므로, sync 를 명시하지 않으면
                // 워커 미가동 환경에서 config:cache 가 재생성되지 않는다. 또한 확장 토글은
                // CLI 커맨드(plugin:deactivate 등)로도 수행되며, 커맨드 프로세스는 실행 후
                // 즉시 종료되어 큐에 쌓인 작업을 처리할 주체가 없다. 반드시 동기 실행한다.
                'sync' => true,
            ];
        }

        return $hooks;
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드).
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 확장 활성화/비활성화 직후 config 캐시를 재생성합니다.
     *
     * @param  mixed  ...$args  훅 인자 (확장 식별자 등)
     */
    public function onExtensionToggled(...$args): void
    {
        try {
            ConfigCacheHelper::rebuild();
        } catch (\Throwable $e) {
            Log::warning('[Config] 확장 토글 후 config 캐시 재생성 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
