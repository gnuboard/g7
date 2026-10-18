<?php

namespace Tests\Unit\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Listeners\ExtensionConfigCacheListener;
use Tests\TestCase;

/**
 * ExtensionConfigCacheListener 회귀 테스트.
 *
 * 확장 활성화/비활성화 직후 config 캐시를 재생성하는 리스너.
 * install/uninstall/update 는 ExtensionManager::updateComposerAutoload() 가 담당하므로,
 * 이 리스너는 activate/deactivate 만 구독해야 한다(이중 재생성 회피). 구독 훅 집합이
 * 바뀌면 config 캐시가 재생성되지 않거나(누락) 두 번 재생성되는(낭비) 회귀가 발생한다.
 */
class ExtensionConfigCacheListenerTest extends TestCase
{
    /**
     * 리스너가 훅 리스너 계약을 구현한다.
     *
     * @scenario case=subscription_contract
     *
     * @effects config_cache_listener_subscribes_toggle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_implements_hook_listener_interface(): void
    {
        $this->assertInstanceOf(
            HookListenerInterface::class,
            new ExtensionConfigCacheListener
        );
    }

    /**
     * 정규 활성화·비활성화 훅만 구독한다 — 옛 activated 는 deprecated 라 구독하지 않는다.
     *
     * @scenario case=subscription_contract
     *
     * @effects config_cache_listener_subscribes_toggle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_subscribes_only_activate_and_deactivate_hooks(): void
    {
        $hooks = ExtensionConfigCacheListener::getSubscribedHooks();

        $expected = [
            'core.modules.after_activate',
            'core.modules.after_deactivate',
            'core.plugins.after_activate',
            'core.plugins.after_deactivate',
            'core.templates.after_activate',
            'core.templates.after_deactivate',
        ];

        $this->assertEqualsCanonicalizing($expected, array_keys($hooks));
    }

    /**
     * 설치·제거·업데이트는 오토로드 갱신이 맡으므로 구독하지 않는다.
     *
     * @scenario case=subscription_contract
     *
     * @effects config_cache_listener_subscribes_toggle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_does_not_subscribe_install_uninstall_update_hooks(): void
    {
        // install/uninstall/update 는 updateComposerAutoload 가 config 캐시를 재생성하므로
        // 이 리스너가 구독하면 이중 재생성이 된다. 구독 목록에서 제외되어야 한다.
        $hookNames = array_keys(ExtensionConfigCacheListener::getSubscribedHooks());

        foreach (['installed', 'updated'] as $lifecycle) {
            foreach (['modules', 'plugins', 'templates'] as $type) {
                $this->assertNotContains(
                    "core.{$type}.{$lifecycle}",
                    $hookNames,
                    "core.{$type}.{$lifecycle} 는 updateComposerAutoload 가 담당하므로 이 리스너가 구독하면 안 됩니다."
                );
            }
        }
    }

    /**
     * 모든 구독이 같은 재생성 메서드로 간다.
     *
     * @scenario case=subscription_contract
     *
     * @effects config_cache_listener_subscribes_toggle_hooks
     *
     * @return void 반환값 없음
     */
    public function test_all_hooks_route_to_on_extension_toggled(): void
    {
        $hooks = ExtensionConfigCacheListener::getSubscribedHooks();

        foreach ($hooks as $hookName => $config) {
            $this->assertSame('onExtensionToggled', $config['method'], "{$hookName} 은 onExtensionToggled 로 라우팅되어야 합니다.");
            $this->assertArrayHasKey('priority', $config);
        }
    }

    /**
     * 설정 캐시 재생성은 인프라 부수효과라 모든 구독이 동기 실행이다.
     *
     * @scenario case=sync_contract
     *
     * @effects config_cache_rebuild_runs_synchronously
     *
     * @return void 반환값 없음
     */
    public function test_all_hooks_are_synchronous(): void
    {
        // config 캐시 재생성은 인프라 작업이라 큐로 미루면 안 된다. Action 훅의 기본 등록은
        // 큐 디스패치(HookListenerRegistrar::applySubscribedHooks) 이므로, sync 플래그가
        // 없으면 워커 미가동 환경(대부분의 소규모 사이트)에서 config:cache 가 영영 재생성되지
        // 않는다. 게다가 CLI 커맨드(plugin:deactivate 등)는 실행 후 프로세스가 종료되어 큐에
        // 쌓인 작업을 처리할 주체가 없다. 모든 구독 훅은 sync=true 여야 한다.
        $hooks = ExtensionConfigCacheListener::getSubscribedHooks();

        foreach ($hooks as $hookName => $config) {
            $this->assertArrayHasKey('sync', $config, "{$hookName} 은 sync 플래그가 있어야 합니다 (config 캐시 재생성은 동기 실행 필수).");
            $this->assertTrue($config['sync'], "{$hookName} 은 sync=true 여야 합니다 — 큐로 미루면 워커 미가동 시 config 캐시가 재생성되지 않습니다.");
        }
    }
}
