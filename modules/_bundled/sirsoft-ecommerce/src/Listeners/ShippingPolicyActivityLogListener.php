<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 배송정책 활동 로그 리스너
 *
 * 배송정책의 생성, 수정, 삭제, 활성화 전환, 기본 설정, 일괄 작업 시
 * Log::channel('activity')를 통해 활동 로그를 기록합니다.
 */
class ShippingPolicyActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.shipping_policy.after_create' => ['method' => 'handleAfterCreate', 'priority' => 20],
            'sirsoft-ecommerce.shipping_policy.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
            'sirsoft-ecommerce.shipping_policy.after_delete' => ['method' => 'handleAfterDelete', 'priority' => 20],
            'sirsoft-ecommerce.shipping_policy.after_toggle_active' => ['method' => 'handleAfterToggleActive', 'priority' => 20],
            'sirsoft-ecommerce.shipping_policy.after_set_default' => ['method' => 'handleAfterSetDefault', 'priority' => 20],
            // bulk_delete, bulk_toggle_active는 EcommerceAdminActivityLogListener에서 처리 (중복 방지)
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // 이벤트 핸들러
    // ═══════════════════════════════════════════

    /**
     * 배송정책 생성 후 로그 기록
     *
     * @param ShippingPolicy $shippingPolicy 생성된 배송정책
     * @return void
     */
    public function handleAfterCreate(ShippingPolicy $shippingPolicy): void
    {
        $this->logActivity('shipping_policy.create', [

            'loggable' => $shippingPolicy,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_create',
            'description_params' => ['shipping_policy_id' => $shippingPolicy->id],
            'properties' => ['name' => $shippingPolicy->name ?? null],
        ]);
    }

    /**
     * 배송정책 수정 후 로그 기록
     *
     * @param ShippingPolicy $shippingPolicy 수정된 배송정책
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     * @return void
     */
    public function handleAfterUpdate(ShippingPolicy $shippingPolicy, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($shippingPolicy, $snapshot);

        $this->logActivity('shipping_policy.update', [

            'loggable' => $shippingPolicy,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_update',
            'description_params' => ['shipping_policy_id' => $shippingPolicy->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 배송정책 삭제 후 로그 기록
     *
     * after_delete 훅은 $shippingPolicyId (int)만 전달합니다.
     *
     * @param int $shippingPolicyId 삭제된 배송정책 ID
     * @return void
     */
    public function handleAfterDelete(int $shippingPolicyId): void
    {
        $this->logActivity('shipping_policy.delete', [

            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_delete',
            'description_params' => ['shipping_policy_id' => $shippingPolicyId],
            'properties' => ['shipping_policy_id' => $shippingPolicyId],
        ]);
    }

    /**
     * 배송정책 활성화 전환 후 로그 기록
     *
     * @param ShippingPolicy $shippingPolicy 배송정책
     * @return void
     */
    public function handleAfterToggleActive(ShippingPolicy $shippingPolicy): void
    {
        $this->logActivity('shipping_policy.toggle_active', [

            'loggable' => $shippingPolicy,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_toggle_active',
            'description_params' => ['shipping_policy_id' => $shippingPolicy->id],
        ]);
    }

    /**
     * 배송정책 기본 설정 후 로그 기록
     *
     * @param ShippingPolicy $shippingPolicy 기본으로 설정된 배송정책
     * @return void
     */
    public function handleAfterSetDefault(ShippingPolicy $shippingPolicy): void
    {
        $this->logActivity('shipping_policy.set_default', [

            'loggable' => $shippingPolicy,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.shipping_policy_set_default',
            'description_params' => ['shipping_policy_id' => $shippingPolicy->id],
        ]);
    }

}
