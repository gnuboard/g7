<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\Helpers\IdentityMessageSyncHelper;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use App\Testing\Concerns\AssertsIdentityPolicyDeclaration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 이커머스 모듈의 IdentityPolicy / IdentityPurpose 선언이
 * 코어 IdentityPolicySyncHelper / IdentityVerificationManager 와 정합한지 검증.
 */
class EcommerceIdentityPolicyDeclarationTest extends ModuleTestCase
{
    use AssertsIdentityPolicyDeclaration;

    private const DECLARED_KEYS = [
        'sirsoft-ecommerce.payment.cancel',
        'sirsoft-ecommerce.payment.approve',
        'sirsoft-ecommerce.payment.confirm_deposit',
        'sirsoft-ecommerce.checkout.before_pay',
    ];

    public function test_identity_policies_are_synced_for_module_source(): void
    {
        $this->assertIdentityPoliciesSyncedForExtension(
            extensionType: 'module',
            extensionIdentifier: 'sirsoft-ecommerce',
            declaredKeys: self::DECLARED_KEYS,
            syncCallback: fn () => $this->syncEcommerceIdentityPolicies(),
        );

        foreach (self::DECLARED_KEYS as $key) {
            $policy = IdentityPolicy::query()->where('key', $key)->first();
            $this->assertSame('hook', $policy->scope->value);
        }
    }

    public function test_checkout_before_pay_uses_checkout_verification_purpose(): void
    {
        $this->syncEcommerceIdentityPolicies();

        $policy = IdentityPolicy::query()
            ->where('key', 'sirsoft-ecommerce.checkout.before_pay')
            ->first();

        $this->assertNotNull($policy);
        $this->assertSame('checkout_verification', $policy->purpose);
        $this->assertSame('self', $policy->applies_to->value);
        $this->assertSame(30, (int) $policy->grace_minutes);
    }

    public function test_checkout_verification_purpose_registered(): void
    {
        $manager = app(IdentityVerificationManager::class);

        // 모듈 부팅 시 등록되는 통상 흐름과 다르게, 단위 테스트에서는 직접 등록.
        $module = new Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );
        $manager->registerDeclaredPurposes($module->getIdentityPurposes());

        $purposes = $manager->getAllPurposes();
        $this->assertArrayHasKey('checkout_verification', $purposes);
        $this->assertContains('email', $purposes['checkout_verification']['allowed_channels'] ?? []);
        $this->assertContains('sms', $purposes['checkout_verification']['allowed_channels'] ?? []);
    }

    /**
     * 모듈 i18n 표준 (docs/extension/module-i18n.md) 적용 검증:
     * `getIdentityPurposes()` 의 label/description 이 `sirsoft-ecommerce::identity.purposes.*`
     * lang 키 형식이며, `__()` 로 해석되어 ko/en 라벨이 정상 반환되는지 확인.
     */
    public function test_checkout_verification_purpose_label_resolves_via_module_lang(): void
    {
        $module = new Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );

        $purposes = $module->getIdentityPurposes();
        $meta = $purposes['checkout_verification'];

        // module.php 가 lang 키 문자열을 반환하는지
        $this->assertSame(
            'sirsoft-ecommerce::identity.purposes.checkout_verification.label',
            $meta['label']
        );
        $this->assertSame(
            'sirsoft-ecommerce::identity.purposes.checkout_verification.description',
            $meta['description']
        );

        // 한국어 로케일에서 라벨 해석
        app()->setLocale('ko');
        $this->assertSame('결제 시 본인 확인', __($meta['label']));
        $this->assertSame(
            '결제 진행 전 성인/본인 확인이 필요한 경우 사용됩니다.',
            __($meta['description'])
        );

        // 영어 로케일 fallback
        app()->setLocale('en');
        $this->assertSame('Checkout Verification', __($meta['label']));
        $this->assertSame(
            'Used when adult/identity verification is required before checkout.',
            __($meta['description'])
        );
    }

    /**
     * 이커머스 모듈이 getIdentityMessages() 로 checkout_verification purpose 메시지를 선언하고,
     * IdentityMessageSyncHelper 로 동기화 시 DB(identity_message_definitions) 에 적재되는지 검증.
     */
    public function test_checkout_verification_message_definition_synced(): void
    {
        $module = new Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );

        $messages = $module->getIdentityMessages();
        $this->assertNotEmpty($messages, 'checkout_verification 전용 메시지가 선언되어야 함');

        $helper = app(IdentityMessageSyncHelper::class);
        foreach ($messages as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-ecommerce';
            $definition = $helper->syncDefinition($data);
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }

        $this->assertDatabaseHas('identity_message_definitions', [
            'provider_id' => 'g7:core.mail',
            'scope_value' => 'checkout_verification',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);

        $defId = IdentityMessageDefinition::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->where('scope_value', 'checkout_verification')
            ->value('id');

        $this->assertDatabaseHas('identity_message_templates', [
            'definition_id' => $defId,
            'channel' => 'mail',
        ]);
    }

    public function test_user_overrides_preserved_on_resync(): void
    {
        $this->assertIdentityPolicyUserOverridesPreserved(
            key: 'sirsoft-ecommerce.checkout.before_pay',
            overrides: ['enabled' => true, 'grace_minutes' => 60],
            syncCallback: fn () => $this->syncEcommerceIdentityPolicies(),
        );
    }

    private function syncEcommerceIdentityPolicies(): void
    {
        $helper = app(IdentityPolicySyncHelper::class);
        $module = new Module(
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

    // ===========================================================================
    // Part B-2/B-3 enforce 매트릭스 + 라이프사이클 (이커머스 정책 4건)
    // ===========================================================================

    private function enabledPolicy(string $key): IdentityPolicy
    {
        $this->syncEcommerceIdentityPolicies();
        $policy = IdentityPolicy::where('key', $key)->first();
        $this->assertNotNull($policy);
        $policy->enabled = true;
        $policy->save();

        return $policy->fresh();
    }

    private function regularUser(): User
    {
        return User::factory()->create();
    }

    private function adminUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        return $admin->fresh();
    }

    /** D2 — checkout.before_pay 가 enabled+self 일 때 일반 사용자에게 enforce 발동 */
    public function test_checkout_before_pay_enforces_for_self(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.checkout.before_pay');
        $service = app(IdentityPolicyService::class);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforce($policy, $this->regularUser(), []);
    }

    /** D9 — checkout.before_pay 가 self 정책이라 admin 은 우회 */
    public function test_checkout_before_pay_skips_for_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.checkout.before_pay');
        $service = app(IdentityPolicyService::class);

        $service->enforce($policy, $this->adminUser(), []);
        $this->assertTrue(true);
    }

    /** D10 — payment.approve / confirm_deposit 는 admin 정책이라 일반 사용자 우회 */
    public function test_payment_approve_skips_for_regular_user(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.payment.approve');
        $service = app(IdentityPolicyService::class);

        $service->enforce($policy, $this->regularUser(), []);
        $this->assertTrue(true);
    }

    public function test_payment_confirm_deposit_enforces_for_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.payment.confirm_deposit');
        $service = app(IdentityPolicyService::class);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforce($policy, $this->adminUser(), []);
    }

    /** D11 — payment.cancel 는 both, self/admin 양쪽 enforce */
    public function test_payment_cancel_enforces_for_both_self_and_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.payment.cancel');
        $service = app(IdentityPolicyService::class);

        try {
            $service->enforce($policy, $this->regularUser(), []);
            $this->fail('payment.cancel both 정책이 self 에 throw 해야 함');
        } catch (IdentityVerificationRequiredException) {
            $this->assertTrue(true);
        }

        try {
            $service->enforce($policy, $this->adminUser(), []);
            $this->fail('payment.cancel both 정책이 admin 에 throw 해야 함');
        } catch (IdentityVerificationRequiredException) {
            $this->assertTrue(true);
        }
    }

    /**
     * 이커머스 4개 정책 라이프사이클 매트릭스 — Service-level.
     *
     * 1) 인증 이력 없을 때 enforce throws
     * 2) verified 로그 fixture 작성 (TestIdentityProvider 호출과 동등)
     * 3) verified 직후 enforce 통과
     * 4) (grace>0) 시간 경과 후 enforce 다시 throw
     */
    #[DataProvider('ecommerceLifecycleProvider')]
    public function test_ecommerce_policy_full_service_lifecycle(string $policyKey, string $purpose, string $userType, int $graceMinutes): void
    {
        $user = $userType === 'admin' ? $this->adminUser() : $this->regularUser();
        $policy = $this->enabledPolicy($policyKey);
        $service = app(IdentityPolicyService::class);

        try {
            $service->enforce($policy, $user, []);
            $this->fail("정책 '{$policyKey}' 가 인증 이력 없을 때 throw 해야 함");
        } catch (IdentityVerificationRequiredException $e) {
            $this->assertSame($policyKey, $e->policyKey);
        }

        $this->seedVerifiedLog($user, $purpose, Carbon::now());

        $service->enforce($policy, $user->fresh(), []);
        $this->assertTrue(true, 'verified 직후 enforce 통과');

        if ($graceMinutes > 0) {
            Carbon::setTestNow(Carbon::now()->addMinutes($graceMinutes + 1));
            try {
                $service->enforce($policy, $user->fresh(), []);
                $this->fail("정책 '{$policyKey}' grace+1 분 경과 후 throw 해야 함");
            } catch (IdentityVerificationRequiredException $e) {
                $this->assertSame($policyKey, $e->policyKey);
            } finally {
                Carbon::setTestNow();
            }
        }
    }

    public static function ecommerceLifecycleProvider(): array
    {
        return [
            'payment.cancel (both, grace=0)' => ['sirsoft-ecommerce.payment.cancel', 'sensitive_action', 'self', 0],
            'payment.approve (admin, grace=0)' => ['sirsoft-ecommerce.payment.approve', 'sensitive_action', 'admin', 0],
            'payment.confirm_deposit (admin, grace=0)' => ['sirsoft-ecommerce.payment.confirm_deposit', 'sensitive_action', 'admin', 0],
            'checkout.before_pay (self, grace=30)' => ['sirsoft-ecommerce.checkout.before_pay', 'checkout_verification', 'self', 30],
        ];
    }

    private function seedVerifiedLog(User $user, string $purpose, Carbon $when): void
    {
        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => $purpose,
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    // ===========================================================================
    // 실경로 훅 발화 검증 (A8) — 정책 target 훅이 실제 액션 경로에서 발화되어야 enforce 가 작동
    // ===========================================================================

    /**
     * 결제 취소 실경로(OrderCancellationService::cancelOrder)가 payment.before_cancel 훅을 발화한다.
     *
     * 발화 지점이 DB 트랜잭션 전이므로, 마커 예외를 던지는 리스너로 발화 자체를 입증한다.
     *
     * @scenario policy=cancel, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects cancel_path_fires_when_enabled_and_unverified, dummy_payment_hooks_removed
     */
    public function test_cancel_path_fires_before_cancel_hook(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        OrderPaymentFactory::new()->forOrder($order)->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $marker = new \RuntimeException('__before_cancel_fired__');
        $cb = function () use ($marker) {
            throw $marker;
        };
        HookManager::addAction('sirsoft-ecommerce.payment.before_cancel', $cb, 1);

        try {
            app(OrderCancellationService::class)
                ->cancelOrder($order->fresh());
            $this->fail('payment.before_cancel 훅이 발화되어 마커 예외가 전파되어야 함');
        } catch (\RuntimeException $e) {
            $this->assertSame('__before_cancel_fired__', $e->getMessage());
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.payment.before_cancel', $cb);
        }
    }

    /**
     * 무통장 입금확인 실경로(OrderProcessingService::confirmManualDeposit)가
     * payment.before_confirm_deposit 훅을 발화한다.
     *
     * @scenario policy=confirm_deposit, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects confirm_deposit_fires_on_dedicated_action_when_enabled
     */
    public function test_confirm_deposit_path_fires_hook(): void
    {
        $order = OrderFactory::new()->create([
            'order_status' => OrderStatusEnum::PENDING_PAYMENT,
        ]);
        OrderPaymentFactory::new()->forOrder($order)->create([
            'payment_method' => PaymentMethodEnum::DBANK,
        ]);

        $fired = false;
        $cb = function () use (&$fired) {
            $fired = true;
        };
        HookManager::addAction('sirsoft-ecommerce.payment.before_confirm_deposit', $cb, 1);

        try {
            app(OrderProcessingService::class)
                ->confirmManualDeposit($order->fresh(), (float) $order->total_due_amount, '홍길동');
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.payment.before_confirm_deposit', $cb);
        }

        $this->assertTrue($fired, 'confirm_deposit 전용 액션이 payment.before_confirm_deposit 훅을 발화해야 함');
    }

    /**
     * 정책 비활성 시 실경로가 정상 동작(enforce 미발동) — 회귀 무영향.
     *
     * @scenario policy=cancel, enabled=off, actor=admin, verified_state=unverified
     *
     * @effects disabled_policy_keeps_existing_flow
     */
    public function test_disabled_policy_keeps_existing_flow(): void
    {
        // 정책 동기화만 하고 활성화하지 않음 (기본 enabled=false)
        $this->syncEcommerceIdentityPolicies();
        $policy = IdentityPolicy::where('key', 'sirsoft-ecommerce.payment.cancel')->first();
        $this->assertFalse((bool) $policy->enabled);

        // enforce 가 비활성 정책에 대해 즉시 통과
        app(IdentityPolicyService::class)->enforce($policy, $this->adminUser(), []);
        $this->assertTrue(true);
    }

    /** D12 — checkout.before_pay grace=30 윈도우 내 인증 이력이 있으면 enforce skip */
    public function test_checkout_before_pay_grace_window_skips(): void
    {
        $user = $this->regularUser();
        $policy = $this->enabledPolicy('sirsoft-ecommerce.checkout.before_pay');
        $service = app(IdentityPolicyService::class);

        $when = Carbon::now()->subMinutes(20); // grace=30 이내
        IdentityVerificationLog::create([
            'id' => Str::uuid()->toString(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'checkout_verification',
            'channel' => 'email',
            'user_id' => $user->id,
            'target_hash' => hash('sha256', mb_strtolower($user->email)),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'verified_at' => $when,
            'expires_at' => $when->copy()->addMinutes(15),
            'created_at' => $when,
            'updated_at' => $when,
        ]);

        $service->enforce($policy, $user->fresh(), []);
        $this->assertTrue(true);
    }
}
