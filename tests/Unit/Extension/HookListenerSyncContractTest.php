<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 즉시 반영이 필요한 훅 리스너의 sync 선언 잠금
 *
 * 훅 리스너는 sync 를 선언하지 않으면 큐로 간다. 아래 표의 (리스너, 훅) 은 결과가 그 요청·그 트랜잭션에서 보여야
 * 하므로 sync 로 확정했다 — 요청 안 메모리 비우기, 공유 캐시 무효화, 같은 화면에 보이는 파생 데이터, 삭제 전
 * 데이터 정리, 같은 발송 사이클의 문맥 잇기. 누가 sync 를 빼면 이 테스트가 깨진다.
 *
 * 반대로 무거운 일(상세 재렌더·대량 재색인)을 맡는 큐 리스너는 sync 가 아니어야 한다.
 * 확장 리스너는 그 확장이 설치돼 클래스가 있을 때만 검사한다.
 */
class HookListenerSyncContractTest extends TestCase
{
    /**
     * sync 로 확정한 (리스너, 훅) 표
     *
     * @return array<string, array{0: string, 1: list<string>}> 리스너 FQCN 과 훅 목록
     */
    public static function syncContracts(): array
    {
        $shippingPolicy = array_map(fn (string $e) => 'sirsoft-ecommerce.shipping_policy.'.$e, [
            'after_create', 'after_update', 'after_delete', 'after_bulk_delete',
            'after_toggle_active', 'after_bulk_toggle_active', 'after_set_default',
        ]);

        return [
            'core NotificationLogListener' => ['App\\Listeners\\NotificationLogListener', [
                'core.notification.after_channel_send', 'core.notification.channel_send_failed', 'core.mail.send_skipped',
            ]],
            'core BroadcastNotificationListener' => ['App\\Listeners\\BroadcastNotificationListener', [
                'core.notification.after_channel_send',
            ]],
            'core SeoSettingsCacheListener' => ['App\\Listeners\\SeoSettingsCacheListener', [
                'core.settings.after_save', 'core.settings.after_set',
            ]],
            'ecommerce ShippingPolicyCacheListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\ShippingPolicyCacheListener', $shippingPolicy],
            'ecommerce CategoryTreeCacheListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\CategoryTreeCacheListener', [
                'sirsoft-ecommerce.category.after_create', 'sirsoft-ecommerce.category.after_update', 'sirsoft-ecommerce.category.after_delete',
                'sirsoft-ecommerce.category.after_toggle_status', 'sirsoft-ecommerce.category.after_reorder',
                'sirsoft-ecommerce.product.after_create', 'sirsoft-ecommerce.product.after_update', 'sirsoft-ecommerce.product.after_delete',
                'sirsoft-ecommerce.product.after_bulk_update',
            ]],
            'ecommerce SeoCategoryCacheListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\SeoCategoryCacheListener', [
                'sirsoft-ecommerce.category.after_create', 'sirsoft-ecommerce.category.after_update', 'sirsoft-ecommerce.category.after_delete',
            ]],
            'ecommerce SeoSettingsCacheListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\SeoSettingsCacheListener', [
                'core.module_settings.after_save',
            ]],
            'ecommerce SeoProductCacheListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\SeoProductCacheListener', [
                'sirsoft-ecommerce.product.after_create', 'sirsoft-ecommerce.product.after_update', 'sirsoft-ecommerce.product.after_delete',
            ]],
            'ecommerce SyncOptionGroupsListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\SyncOptionGroupsListener', [
                'sirsoft-ecommerce.product.after_options_sync', 'sirsoft-ecommerce.option.after_bulk_update',
            ]],
            'ecommerce SyncProductFromOptionListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\SyncProductFromOptionListener', [
                'sirsoft-ecommerce.product_option.after_bulk_stock_update', 'sirsoft-ecommerce.option.after_bulk_update',
            ]],
            'ecommerce AssignDefaultCurrencyOnRegisterListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\AssignDefaultCurrencyOnRegisterListener', [
                'core.auth.after_register', 'core.user.after_create',
            ]],
            'ecommerce AssignDefaultShippingCountryOnRegisterListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\AssignDefaultShippingCountryOnRegisterListener', [
                'core.auth.after_register', 'core.user.after_create',
            ]],
            'board SeoBoardCacheListener' => ['Modules\\Sirsoft\\Board\\Listeners\\SeoBoardCacheListener', [
                'sirsoft-board.post.after_create', 'sirsoft-board.post.after_update', 'sirsoft-board.post.after_delete',
                'sirsoft-board.post.after_blind', 'sirsoft-board.post.after_restore',
                'sirsoft-board.comment.after_create', 'sirsoft-board.comment.after_update', 'sirsoft-board.comment.after_delete',
                'sirsoft-board.comment.after_blind', 'sirsoft-board.comment.after_restore',
                'sirsoft-board.board.after_update', 'sirsoft-board.board.posts.before_force_delete', 'sirsoft-board.board.after_delete',
            ]],
            'board SeoBoardSettingsCacheListener' => ['Modules\\Sirsoft\\Board\\Listeners\\SeoBoardSettingsCacheListener', [
                'core.module_settings.after_save', 'sirsoft-board.settings.after_bulk_apply',
            ]],
            'page SeoPageCacheListener' => ['Modules\\Sirsoft\\Page\\Listeners\\SeoPageCacheListener', [
                'sirsoft-page.page.after_create', 'sirsoft-page.page.after_update', 'sirsoft-page.page.after_delete',
                'sirsoft-page.page.after_restore', 'sirsoft-page.page.after_publish',
            ]],
            'gdpr GdprUserDeleteListener' => ['Plugins\\Sirsoft\\Gdpr\\Listeners\\GdprUserDeleteListener', [
                'core.user.before_delete',
            ]],
            'marketing MarketingConsentListener' => ['Plugins\\Sirsoft\\Marketing\\Listeners\\MarketingConsentListener', [
                'core.user.after_create', 'core.user.after_update', 'core.user.before_delete',
            ]],
            'bizppurio LinkNotificationLogListener' => ['Plugins\\Sirsoft\\MessageBizppurio\\Listeners\\LinkNotificationLogListener', [
                'core.notification_log.after_log_sent', 'core.notification_log.after_log_failed',
            ]],
        ];
    }

    /**
     * 무거운 일을 맡아 큐로 돌아야 하는 리스너 표
     *
     * @return array<string, array{0: string}> 리스너 FQCN
     */
    public static function queuedContracts(): array
    {
        return [
            'ecommerce SeoProductDetailRegenerateListener' => ['Modules\\Sirsoft\\Ecommerce\\Listeners\\SeoProductDetailRegenerateListener'],
            'board SeoBoardRegenerateListener' => ['Modules\\Sirsoft\\Board\\Listeners\\SeoBoardRegenerateListener'],
        ];
    }

    /**
     * 표의 (리스너, 훅) 이 모두 구독돼 있고 sync 다.
     *
     * @scenario case=contract
     *
     * @effects sync_contract_locked
     *
     * @param  string  $listener  리스너 FQCN
     * @param  list<string>  $hooks  sync 여야 하는 훅
     * @return void 반환값 없음
     */
    #[DataProvider('syncContracts')]
    public function test_listener_hooks_are_synchronous(string $listener, array $hooks): void
    {
        if (! class_exists($listener)) {
            $this->markTestSkipped("{$listener} 가 설치돼 있지 않습니다.");
        }

        $subscribed = $listener::getSubscribedHooks();

        foreach ($hooks as $hook) {
            $this->assertArrayHasKey($hook, $subscribed, class_basename($listener)." 가 {$hook} 를 구독하지 않습니다.");
            $this->assertTrue(($subscribed[$hook]['sync'] ?? false) === true, class_basename($listener)." 의 {$hook} 가 sync 가 아닙니다 — 큐로 가면 즉시 반영이 깨집니다.");
        }
    }

    /**
     * 무거운 일을 맡은 리스너는 어떤 구독도 sync 가 아니다 — 저장 응답을 늦추지 않는다.
     *
     * @scenario case=contract
     *
     * @effects sync_contract_locked
     *
     * @param  string  $listener  리스너 FQCN
     * @return void 반환값 없음
     */
    #[DataProvider('queuedContracts')]
    public function test_heavy_listeners_stay_queued(string $listener): void
    {
        if (! class_exists($listener)) {
            $this->markTestSkipped("{$listener} 가 설치돼 있지 않습니다.");
        }

        $subscribed = $listener::getSubscribedHooks();
        $this->assertNotEmpty($subscribed);

        foreach ($subscribed as $hook => $config) {
            $this->assertFalse((bool) ($config['sync'] ?? false), class_basename($listener)." 의 {$hook} 는 큐로 돌아야 합니다.");
        }
    }
}
