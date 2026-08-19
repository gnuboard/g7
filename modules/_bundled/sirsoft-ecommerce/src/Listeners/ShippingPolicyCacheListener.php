<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;

/**
 * 배송정책 변경 시 해석기 캐시 무효화 리스너
 *
 * `ShippingPolicyResolver` 는 싱글톤이라 기본 배송정책을 요청 내 1회만 조회한다.
 * 그래서 한 요청이 배송정책을 바꾼 뒤에도 그 요청 안에서는 변경 전 정책이 계속 쓰인다.
 *
 * 무효화 호출을 서비스 메서드마다 흩어 놓지 않고 이 리스너 한 곳에 모은다 — 배송정책을
 * 바꾸는 지점은 이미 전부 `after_*` 훅을 발화하므로, 새 변경 경로가 생겨도 같은 훅만
 * 발화하면 무효화가 자동으로 따라온다.
 */
class ShippingPolicyCacheListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int}> 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        $hooks = [];

        foreach ([
            'after_create',
            'after_update',
            'after_delete',
            'after_bulk_delete',
            'after_toggle_active',
            'after_bulk_toggle_active',
            'after_set_default',
        ] as $event) {
            $hooks['sirsoft-ecommerce.shipping_policy.'.$event] = [
                'method' => 'onShippingPolicyChanged',
                'priority' => 5,
            ];
        }

        return $hooks;
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        $this->onShippingPolicyChanged(...$args);
    }

    /**
     * 배송정책이 바뀌면 해석기의 기본 배송정책 캐시를 비웁니다.
     *
     * 아직 해석기를 해석하지 않은 요청에서는 새로 만들 필요가 없다 — 처음 조회할 때
     * 이미 변경 후 값을 읽는다.
     *
     * @param  mixed  ...$args  훅 인자 (배송정책 또는 ID 목록 — 무효화는 대상과 무관)
     */
    public function onShippingPolicyChanged(...$args): void
    {
        if (! app()->resolved(ShippingPolicyResolver::class)) {
            return;
        }

        app(ShippingPolicyResolver::class)->flushCache();
    }
}
