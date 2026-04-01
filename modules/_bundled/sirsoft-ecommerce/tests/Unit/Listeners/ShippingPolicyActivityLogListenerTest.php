<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\ShippingPolicyActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ShippingPolicyActivityLogListener 테스트
 *
 * 배송정책 활동 로그 리스너의 모든 훅 메서드를 검증합니다.
 * - 로그 기록 (5개): create, update, delete, toggle_active, set_default
 */
class ShippingPolicyActivityLogListenerTest extends ModuleTestCase
{
    private ShippingPolicyActivityLogListener $listener;

    private $logChannel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('request', Request::create('/api/admin/sirsoft-ecommerce/test'));
        $this->listener = new ShippingPolicyActivityLogListener();
        $this->logChannel = Mockery::mock(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturn($this->logChannel);
        Log::shouldReceive('error')->byDefault();
    }

    // ═══════════════════════════════════════════
    // getSubscribedHooks
    // ═══════════════════════════════════════════

    public function test_getSubscribedHooks_returns_all_hooks(): void
    {
        $hooks = ShippingPolicyActivityLogListener::getSubscribedHooks();

        $this->assertCount(5, $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_create', $hooks);
        $this->assertArrayNotHasKey('sirsoft-ecommerce.shipping_policy.before_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_update', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_delete', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_toggle_active', $hooks);
        $this->assertArrayHasKey('sirsoft-ecommerce.shipping_policy.after_set_default', $hooks);
    }

    // ═══════════════════════════════════════════
    // 이벤트 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handleAfterCreate_logs_activity(): void
    {
        $policy = $this->createPolicyMock(1, 'Express Shipping');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_policy.create'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_policy_create'
                    && $context['description_params']['shipping_policy_id'] === 1
                    && isset($context['loggable'])
                    && $context['properties']['name'] === 'Express Shipping';
            });

        $this->listener->handleAfterCreate($policy);
    }

    public function test_handleAfterUpdate_logs_activity_with_changes(): void
    {
        $policy = $this->createPolicyMock(2, 'Updated Policy');
        $policy->shouldReceive('toArray')->andReturn(['id' => 2, 'name' => 'Updated Policy']);

        $snapshot = ['id' => 2, 'name' => 'Original Policy'];

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_policy.update'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_policy_update'
                    && $context['description_params']['shipping_policy_id'] === 2
                    && isset($context['loggable'])
                    && array_key_exists('changes', $context);
            });

        $this->listener->handleAfterUpdate($policy, $snapshot);
    }

    public function test_handleAfterUpdate_without_snapshot(): void
    {
        $policy = $this->createPolicyMock(99, 'No Snapshot');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_policy.update'
                    && $context['changes'] === null;
            });

        $this->listener->handleAfterUpdate($policy, null);
    }

    public function test_handleAfterDelete_logs_activity(): void
    {
        $policyId = 5;

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) use ($policyId) {
                return $action === 'shipping_policy.delete'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_policy_delete'
                    && $context['description_params']['shipping_policy_id'] === $policyId
                    && $context['properties']['shipping_policy_id'] === $policyId
                    && ! isset($context['loggable']);
            });

        $this->listener->handleAfterDelete($policyId);
    }

    public function test_handleAfterToggleActive_logs_activity(): void
    {
        $policy = $this->createPolicyMock(3, 'Toggle Policy');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_policy.toggle_active'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_policy_toggle_active'
                    && $context['description_params']['shipping_policy_id'] === 3
                    && isset($context['loggable']);
            });

        $this->listener->handleAfterToggleActive($policy);
    }

    public function test_handleAfterSetDefault_logs_activity(): void
    {
        $policy = $this->createPolicyMock(4, 'Default Policy');

        $this->logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function ($action, $context) {
                return $action === 'shipping_policy.set_default'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'sirsoft-ecommerce::activity_log.description.shipping_policy_set_default'
                    && $context['description_params']['shipping_policy_id'] === 4
                    && isset($context['loggable']);
            });

        $this->listener->handleAfterSetDefault($policy);
    }

    // ═══════════════════════════════════════════
    // 에러 핸들링 테스트
    // ═══════════════════════════════════════════

    public function test_logActivity_catches_exception_and_logs_error(): void
    {
        $this->logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Exception('Write failure'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'shipping_policy.delete'
                    && $context['error'] === 'Write failure';
            });

        $this->listener->handleAfterDelete(1);
    }

    // ═══════════════════════════════════════════
    // handle 기본 핸들러 테스트
    // ═══════════════════════════════════════════

    public function test_handle_does_nothing(): void
    {
        $this->listener->handle('arg1', 'arg2');
        $this->assertTrue(true);
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    private function createPolicyMock(int $id, ?string $name = null): ShippingPolicy
    {
        $policy = Mockery::mock(ShippingPolicy::class)->makePartial();
        $policy->forceFill(['id' => $id, 'name' => $name]);
        $policy->shouldReceive('getKey')->andReturn($id);
        $policy->shouldReceive('getMorphClass')->andReturn('shipping_policy');

        return $policy;
    }

}

