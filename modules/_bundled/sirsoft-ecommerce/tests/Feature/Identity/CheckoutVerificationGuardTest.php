<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Identity;

use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\HookManager;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use App\Services\IdentityPolicyService;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\PaymentService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * checkout_verification purpose 의 결제 직전 가드 동작 검증.
 *
 * 검증 분리:
 *  - G1: PaymentService::requestPayment 가 sirsoft-ecommerce.checkout.before_payment 훅을 발동하는지
 *  - 코어 가드: IdentityPolicyService::enforce() 가 정책 활성+미인증 시 IdentityVerificationRequiredException throw
 *  - Listener 동적 구독: EnforceIdentityPolicyListener::getSubscribedHooks() 가 모듈 hook target 을 자동 포함
 *
 * 프론트 인터셉트(G2/G4) 는 vitest 영역에서 별도 검증.
 */
class CheckoutVerificationGuardTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $helper = app(IdentityPolicySyncHelper::class);
        $module = new \Modules\Sirsoft\Ecommerce\Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );
        $declaredKeys = [];
        foreach ($module->getIdentityPolicies() as $policy) {
            $helper->syncPolicy(array_merge($policy, [
                'source_type' => 'module',
                'source_identifier' => 'sirsoft-ecommerce',
            ]));
            $declaredKeys[] = $policy['key'];
        }
        $helper->cleanupStalePolicies('module', 'sirsoft-ecommerce', $declaredKeys);
    }

    /**
     * G1 — 결제 진입 훅이 발동되는지 (Listener 등록 여부와 무관, HookManager 호출 자체 검증).
     */
    public function test_request_payment_fires_checkout_before_payment_hook(): void
    {
        $hookFired = false;
        $callback = function () use (&$hookFired) {
            $hookFired = true;
        };
        HookManager::addAction('sirsoft-ecommerce.checkout.before_payment', $callback, 10);

        try {
            app(PaymentService::class)->requestPayment(new Order, ['method' => 'card']);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.checkout.before_payment', $callback);
        }

        $this->assertTrue($hookFired, 'PaymentService::requestPayment 진입 시 checkout.before_payment 훅이 발동되어야 함');
    }

    /**
     * 코어 가드 — 정책 비활성: enforce() 가 예외 없이 통과.
     */
    public function test_enforce_passes_when_policy_disabled(): void
    {
        $policy = IdentityPolicy::query()
            ->where('key', 'sirsoft-ecommerce.checkout.before_pay')
            ->first();
        $this->assertNotNull($policy);
        $this->assertFalse((bool) $policy->enabled, '기본값은 enabled=false');

        // 정책이 비활성이면 enforce 는 즉시 통과
        app(IdentityPolicyService::class)->enforce($policy, null, []);

        $this->assertTrue(true);
    }

    /**
     * 코어 가드 — 정책 활성 + 미인증 사용자: enforce() 가 IdentityVerificationRequiredException throw.
     */
    public function test_enforce_throws_when_policy_enabled_and_unverified(): void
    {
        // 정책 활성화
        $repo = app(\App\Contracts\Repositories\IdentityPolicyRepositoryInterface::class);
        $repo->updateByKey('sirsoft-ecommerce.checkout.before_pay', [
            'enabled' => true,
        ], ['enabled']);

        $policy = IdentityPolicy::query()
            ->where('key', 'sirsoft-ecommerce.checkout.before_pay')
            ->first();
        $this->assertTrue((bool) $policy->enabled);

        $this->expectException(IdentityVerificationRequiredException::class);
        app(IdentityPolicyService::class)->enforce($policy, null, []);
    }

    /**
     * Listener 동적 구독 — getSubscribedHooks() 가 module hook target 을 자동 포함.
     */
    public function test_listener_subscribes_module_hook_targets_dynamically(): void
    {
        $subscribed = EnforceIdentityPolicyListener::getSubscribedHooks();
        $hookNames = array_keys($subscribed);

        $this->assertContains(
            'sirsoft-ecommerce.checkout.before_payment',
            $hookNames,
            'Listener 가 모듈 declarative 정책의 hook target 을 동적 구독해야 함',
        );
        $this->assertContains(
            'sirsoft-ecommerce.payment.before_cancel',
            $hookNames,
        );
    }
}
