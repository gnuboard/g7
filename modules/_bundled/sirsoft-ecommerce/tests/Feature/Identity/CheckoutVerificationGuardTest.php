<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\PermissionType;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\HookManager;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\IdentityPolicyService;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * checkout_verification purpose 의 결제 직전 가드 동작 검증.
 *
 * 검증 분리:
 *  - G1: 주문 생성 진입(HandlesOrderCreation::processOrderCreation, 재고검증 직후·주문생성 직전)이
 *        sirsoft-ecommerce.checkout.before_payment 훅을 발동하는지 (실경로 — PaymentService 더미 제거됨)
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

    /**
     * G1 — 주문 생성 진입(실경로)이 checkout.before_payment 훅을 발동하는지.
     *
     * @scenario policy=checkout, enabled=off, actor=member, verified_state=unverified
     *
     * @effects checkout_fires_for_self_member
     */
    public function test_order_creation_fires_checkout_before_payment_hook(): void
    {
        $product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);
        $option = ProductOption::create([
            'product_id' => $product->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '검정'],
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $cartKey = Str::uuid()->toString();
        TempOrder::create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'items' => [[
                'product_id' => $product->id,
                'product_option_id' => $option->id,
                'quantity' => 2,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 30000,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        $hookFired = false;
        $callback = function () use (&$hookFired) {
            $hookFired = true;
        };
        HookManager::addAction('sirsoft-ecommerce.checkout.before_payment', $callback, 10);

        try {
            $this->postJson(
                '/api/modules/sirsoft-ecommerce/user/orders',
                [
                    'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'guest@test.com'],
                    'shipping' => [
                        'recipient_name' => '김철수',
                        'recipient_phone' => '010-9876-5432',
                        'country_code' => 'KR',
                        'zipcode' => '12345',
                        'address' => '서울시 강남구 테헤란로 123',
                        'address_detail' => '101동 1001호',
                    ],
                    'payment_method' => PaymentMethodEnum::DBANK->value,
                    'expected_total_amount' => 30000,
                    'depositor_name' => '홍길동',
                    'dbank' => [
                        'bank_code' => 'KB',
                        'bank_name' => '국민은행',
                        'account_number' => '123-456-789012',
                        'account_holder' => '주식회사 테스트',
                    ],
                    'guest_lookup_password' => 'guest1234',
                    'guest_lookup_password_confirmation' => 'guest1234',
                ],
                ['X-Cart-Key' => $cartKey]
            );
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.checkout.before_payment', $callback);
        }

        $this->assertTrue($hookFired, '주문 생성 진입 시 checkout.before_payment 훅이 발동되어야 함');
    }

    /**
     * 통합 — 정책 ON + 미인증 사용자 + 실제 HTTP 주문 생성 요청이 428 로 차단되는지.
     *
     * 기존 G1(훅 발화)·코어 가드(enforce 예외)·리스너 구독 테스트는 각 조각만 따로 검증한다.
     * 이 테스트는 그 셋이 실제 HTTP 흐름에서 연결되어 최종적으로 428 이 나오는지를 통합 검증한다.
     * (검수: 조각 테스트는 green 인데 실제로 본인인증이 발동하지 않던 결함의 회귀 가드)
     *
     * @scenario policy=checkout, enabled=on, actor=member, verified_state=unverified, path=http_order_create
     *
     * @effects http_order_create_blocked_with_428_when_checkout_policy_enabled
     */
    public function test_http_order_creation_returns_428_when_checkout_policy_enabled_and_unverified(): void
    {
        // 결제 단계 본인인증 정책 활성화
        app(IdentityPolicyRepositoryInterface::class)->updateByKey(
            'sirsoft-ecommerce.checkout.before_pay',
            ['enabled' => true],
            ['enabled']
        );

        $product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-'.Str::random(8),
            'sku' => 'SKU-'.Str::random(8),
            'list_price' => 20000,
            'selling_price' => 15000,
            'currency_code' => 'KRW',
            'stock_quantity' => 100,
            'sales_status' => ProductSalesStatus::ON_SALE,
            'display_status' => ProductDisplayStatus::VISIBLE,
            'has_options' => true,
        ]);
        $option = ProductOption::create([
            'product_id' => $product->id,
            'option_code' => 'OPT-'.Str::random(8),
            'option_values' => ['색상' => '검정'],
            'sku' => 'SKU-'.Str::random(8),
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'safe_stock_quantity' => 5,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // 미인증 회원 actor (checkout 정책 applies_to=self) — 주문 생성 권한 부여
        // (user/orders 라우트는 permission:user,sirsoft-ecommerce.user-orders.create 미들웨어 보유.
        //  권한이 없으면 IDV 가드 도달 전 403 으로 막혀 통합 검증이 불가능하므로 권한을 부여한다.)
        /** @var User $user */
        $user = User::factory()->create();
        $orderCreatePerm = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-orders.create'],
            ['name' => ['ko' => '주문 생성', 'en' => 'Create Order'], 'type' => PermissionType::User]
        );
        $role = Role::create([
            'identifier' => 'order-create-test-'.$user->id,
            'name' => ['ko' => '주문생성 테스트', 'en' => 'Order Create Test'],
        ]);
        $role->permissions()->attach($orderCreatePerm->id);
        $user->roles()->attach($role->id);

        $cartKey = Str::uuid()->toString();
        TempOrder::create([
            'user_id' => $user->id,
            'cart_key' => $cartKey,
            'items' => [[
                'product_id' => $product->id,
                'product_option_id' => $option->id,
                'quantity' => 2,
            ]],
            'calculation_result' => [
                'items' => [[
                    'product_id' => $product->id,
                    'product_option_id' => $option->id,
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'subtotal' => 30000,
                    'final_amount' => 30000,
                ]],
                'summary' => [
                    'subtotal' => 30000,
                    'total_discount' => 0,
                    'total_shipping' => 0,
                    'payment_amount' => 30000,
                    'final_amount' => 30000,
                ],
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        $response = $this->actingAs($user)->postJson(
            '/api/modules/sirsoft-ecommerce/user/orders',
            [
                'orderer' => ['name' => '홍길동', 'phone' => '010-1234-5678', 'email' => 'guest@test.com'],
                'shipping' => [
                    'recipient_name' => '김철수',
                    'recipient_phone' => '010-9876-5432',
                    'country_code' => 'KR',
                    'zipcode' => '12345',
                    'address' => '서울시 강남구 테헤란로 123',
                    'address_detail' => '101동 1001호',
                ],
                'payment_method' => PaymentMethodEnum::DBANK->value,
                'expected_total_amount' => 30000,
                'depositor_name' => '홍길동',
                'dbank' => [
                    'bank_code' => 'KB',
                    'bank_name' => '국민은행',
                    'account_number' => '123-456-789012',
                    'account_holder' => '주식회사 테스트',
                ],
            ],
            ['X-Cart-Key' => $cartKey]
        );

        $response->assertStatus(428);
        $this->assertSame('identity_verification_required', $response->json('error_code'));
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
        $repo = app(IdentityPolicyRepositoryInterface::class);
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
     * L4-8 — checkout 정책(applies_to=self)은 관리자 actor 에게는 우회된다(예외 없음).
     *
     * @scenario policy=checkout, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects checkout_bypassed_for_admin_actor
     */
    public function test_enforce_bypasses_for_admin_actor_on_checkout_policy(): void
    {
        app(IdentityPolicyRepositoryInterface::class)->updateByKey(
            'sirsoft-ecommerce.checkout.before_pay',
            ['enabled' => true],
            ['enabled']
        );
        $policy = IdentityPolicy::query()
            ->where('key', 'sirsoft-ecommerce.checkout.before_pay')
            ->first();

        $admin = $this->createAdminUser();

        // applies_to=self 정책은 admin 에게 enforce 되지 않음 → 예외 없이 통과
        app(IdentityPolicyService::class)->enforce($policy, $admin, []);

        $this->assertTrue(true, '관리자 actor 는 checkout(self) 정책에서 우회되어야 함');
    }

    /**
     * Listener 동적 구독 — syncDynamicHookSubscriptions() 호출 후 모듈 hook target 이
     * HookManager 에 실제로 enforce 콜백으로 등록된다.
     *
     * (이전: getSubscribedHooks() 반환 목록만 검사 → 자동발견 시점 경합으로 실제 등록이 누락돼도
     *  통과하던 결함. 이제 HookManager 실제 등록 상태로 통합 검증한다.)
     */
    public function test_listener_subscribes_module_hook_targets_dynamically(): void
    {
        // setUp 에서 정책 sync 가 syncDynamicHookSubscriptions 를 트리거하지만,
        // 멱등 재동기화로 현재 정책 상태를 한 번 더 확정한다.
        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();

        $hooks = HookManager::getHooks();

        $this->assertArrayHasKey(
            'sirsoft-ecommerce.checkout.before_payment',
            $hooks,
            'Listener 가 모듈 hook target(checkout.before_payment)에 실제 등록되어야 함',
        );
        $this->assertNotEmpty(
            $hooks['sirsoft-ecommerce.checkout.before_payment'],
            'checkout.before_payment 훅에 enforce 콜백이 1개 이상 등록되어야 함',
        );
        $this->assertArrayHasKey(
            'sirsoft-ecommerce.payment.before_cancel',
            $hooks,
        );
    }

    /**
     * 멱등성 — syncDynamicHookSubscriptions() 를 반복 호출해도 동일 hook 에 enforce 콜백이
     * 중복 등록되지 않는다 (이중 428/이중 부작용 회귀 차단).
     */
    public function test_repeated_sync_does_not_double_register(): void
    {
        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();
        $first = HookManager::getHooks()['sirsoft-ecommerce.checkout.before_payment'] ?? [];
        $firstCount = count($first, COUNT_RECURSIVE) - count($first);

        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();
        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();
        $after = HookManager::getHooks()['sirsoft-ecommerce.checkout.before_payment'] ?? [];
        $afterCount = count($after, COUNT_RECURSIVE) - count($after);

        $this->assertSame($firstCount, $afterCount, '반복 동기화 후에도 콜백 수가 동일해야 함(멱등)');
    }
}
