<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
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

    /**
     * 선언된 정책 4종이 module 출처로 동기화되고(코어 공용 단언), 네 정책 모두 hook 범위(scope)로 저장된다.
     *
     * @effects declared_policies_sync_as_hook_scope_for_module_source
     *
     * @return void 반환값 없음
     */
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

    /**
     * 결제 시 확인 정책(checkout.before_pay)은 checkout_verification 목적·self 대상·grace 30분으로 동기화된다.
     *
     * @effects checkout_policy_declares_checkout_verification_purpose_for_self
     *
     * @return void 반환값 없음
     */
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

    /**
     * 모듈이 선언한 checkout_verification 목적이 본인인증 매니저에 등록되고, 허용 채널에 email 과 sms 가 모두 들어 있다.
     *
     * @effects checkout_verification_purpose_registers_email_and_sms_channels
     *
     * @return void 반환값 없음
     */
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
     *
     * @effects checkout_verification_purpose_label_resolves_via_module_lang
     *
     * @return void 반환값 없음
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
     * 이커머스 모듈의 checkout_verification 본인인증 문구가 알림 정의(category=identity)로 선언되고,
     * 알림 정의 동기화로 notification_definitions/templates 에 적재된다 (#699 — 옛 identity_message_* 대체).
     *
     * @effects checkout_verification_message_declared_as_identity_notification_definition
     *
     * @return void 반환값 없음
     */
    public function test_checkout_verification_message_definition_synced(): void
    {
        $module = new Module(
            'sirsoft-ecommerce',
            $this->getModuleBasePath(),
        );

        $this->assertSame([], $module->getIdentityMessages(), '7.1.0 부터 옛 계약(getIdentityMessages)으로는 선언하지 않는다');

        $declarations = array_values(array_filter(
            $module->getNotificationDefinitions(),
            fn (array $d) => ($d['type'] ?? null) === 'identity.purpose.checkout_verification'
        ));
        $this->assertCount(1, $declarations, 'checkout_verification 전용 본인인증 알림 정의가 선언되어야 함');
        $this->assertSame('identity', $declarations[0]['category']);
        $this->assertSame([], $declarations[0]['hooks']);

        $helper = app(NotificationSyncHelper::class);
        $data = array_merge($declarations[0], ['extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce']);
        $definition = $helper->syncDefinition($data);
        foreach ($data['templates'] as $template) {
            $helper->syncTemplate($definition->id, $template);
        }

        $this->assertDatabaseHas('notification_definitions', [
            'type' => 'identity.purpose.checkout_verification',
            'category' => 'identity',
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);
        $this->assertDatabaseHas('notification_templates', [
            'definition_id' => $definition->id,
            'channel' => 'mail',
        ]);
    }

    /**
     * checkout.before_pay 를 재동기화해도 운영자가 바꾼 enabled·grace_minutes 값이 선언 기본값으로 덮이지 않는다.
     *
     * @effects policy_user_overrides_preserved_on_resync
     *
     * @return void 반환값 없음
     */
    public function test_user_overrides_preserved_on_resync(): void
    {
        $this->assertIdentityPolicyUserOverridesPreserved(
            key: 'sirsoft-ecommerce.checkout.before_pay',
            overrides: ['enabled' => true, 'grace_minutes' => 60],
            syncCallback: fn () => $this->syncEcommerceIdentityPolicies(),
        );
    }

    /**
     * 이커머스 모듈이 선언한 본인인증 정책을 코어 동기화 헬퍼로 적재하고, 선언에서 빠진 옛 정책을 정리합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 정책을 동기화한 뒤 지정 키의 정책을 활성화해 돌려줍니다.
     *
     * @param  string  $key  활성화할 정책 키
     * @return IdentityPolicy 활성화된 정책
     */
    private function enabledPolicy(string $key): IdentityPolicy
    {
        $this->syncEcommerceIdentityPolicies();
        $policy = IdentityPolicy::where('key', $key)->first();
        $this->assertNotNull($policy);
        $policy->enabled = true;
        $policy->save();

        return $policy->fresh();
    }

    /**
     * 역할이 없는 일반 회원을 만듭니다.
     *
     * @return User 생성된 일반 회원
     */
    private function regularUser(): User
    {
        return User::factory()->create();
    }

    /**
     * 권한 시더를 실행하고 admin 역할을 붙인 슈퍼 관리자를 만듭니다.
     *
     * @return User 생성된 관리자
     */
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

    /**
     * D2 — checkout.before_pay 가 활성(self)일 때 인증 이력이 없는 일반 회원의 enforce 는 IdentityVerificationRequiredException 을 던진다.
     *
     * @scenario policy=checkout, enabled=on, actor=member, verified_state=unverified
     *
     * @effects checkout_policy_enforces_for_self_member
     *
     * @return void 반환값 없음
     */
    public function test_checkout_before_pay_enforces_for_self(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.checkout.before_pay');
        $service = app(IdentityPolicyService::class);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforce($policy, $this->regularUser(), []);
    }

    /**
     * D9 — checkout.before_pay 는 self 정책이라 활성 상태여도 관리자의 enforce 는 예외 없이 통과한다.
     *
     * @scenario policy=checkout, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects checkout_passes_for_admin
     *
     * @return void 반환값 없음
     */
    public function test_checkout_before_pay_skips_for_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.checkout.before_pay');
        $service = app(IdentityPolicyService::class);

        $service->enforce($policy, $this->adminUser(), []);
        $this->assertTrue(true);
    }

    /**
     * D10 — payment.approve 는 admin 정책이라 활성 상태여도 일반 회원의 enforce 는 예외 없이 통과한다.
     *
     * @effects approve_policy_skips_regular_user
     *
     * @return void 반환값 없음
     */
    public function test_payment_approve_skips_for_regular_user(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.payment.approve');
        $service = app(IdentityPolicyService::class);

        $service->enforce($policy, $this->regularUser(), []);
        $this->assertTrue(true);
    }

    /**
     * payment.confirm_deposit 는 admin 정책이라 활성 상태에서 인증 이력이 없는 관리자의 enforce 는 IdentityVerificationRequiredException 을 던진다.
     *
     * @scenario policy=confirm_deposit, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects confirm_deposit_policy_enforces_for_admin
     *
     * @return void 반환값 없음
     */
    public function test_payment_confirm_deposit_enforces_for_admin(): void
    {
        $policy = $this->enabledPolicy('sirsoft-ecommerce.payment.confirm_deposit');
        $service = app(IdentityPolicyService::class);

        $this->expectException(IdentityVerificationRequiredException::class);
        $service->enforce($policy, $this->adminUser(), []);
    }

    /**
     * D11 — payment.cancel 은 both 정책이라 인증 이력이 없으면 일반 회원과 관리자 모두의 enforce 가 예외를 던진다.
     *
     * @effects cancel_policy_enforces_for_self_and_admin
     *
     * @return void 반환값 없음
     */
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
     *
     * @effects verified_log_passes_enforce_until_grace_expires
     *
     * @param  string  $policyKey  검증할 정책 키
     * @param  string  $purpose  verified 로그에 기록할 인증 목적
     * @param  string  $userType  대상 사용자 유형 (admin 이면 관리자, 그 외는 일반 회원)
     * @param  int  $graceMinutes  정책 grace 분 (0 이면 만료 후 재차단 검증을 생략)
     * @return void 반환값 없음
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

    /**
     * 정책 4종의 (정책 키, 인증 목적, 대상 사용자 유형, grace 분) 조합을 공급합니다.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}> 케이스 이름별 테스트 인자
     */
    public static function ecommerceLifecycleProvider(): array
    {
        return [
            'payment.cancel (both, grace=0)' => ['sirsoft-ecommerce.payment.cancel', 'sensitive_action', 'self', 0],
            'payment.approve (admin, grace=0)' => ['sirsoft-ecommerce.payment.approve', 'sensitive_action', 'admin', 0],
            'payment.confirm_deposit (admin, grace=0)' => ['sirsoft-ecommerce.payment.confirm_deposit', 'sensitive_action', 'admin', 0],
            'checkout.before_pay (self, grace=30)' => ['sirsoft-ecommerce.checkout.before_pay', 'checkout_verification', 'self', 30],
        ];
    }

    /**
     * 지정 시각에 인증 완료된 본인인증 로그를 직접 기록합니다 (TestIdentityProvider 호출과 동등한 fixture).
     *
     * @param  User  $user  인증한 사용자
     * @param  string  $purpose  인증 목적
     * @param  Carbon  $when  인증 완료 시각
     * @return void 반환값 없음
     */
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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

    /**
     * D12 — checkout.before_pay(grace 30분)는 20분 전에 인증 완료된 로그가 있으면 enforce 를 예외 없이 통과시킨다.
     *
     * @scenario policy=checkout, enabled=on, actor=member, verified_state=within_grace
     *
     * @effects grace_window_skips_enforce
     *
     * @return void 반환값 없음
     */
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
