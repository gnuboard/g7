<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 사용 한도 강제 테스트 (E1)
 *
 * 관리자가 설정한 사용 정책(min_use_amount / use_unit / max_use_percent / max_use_value)이
 * 주문 쓰기 경로에서 실제로 강제되는지 검증합니다.
 *
 * 수정 전에는 `UserMileageService::validateUsage()` 의 프로덕션 호출자가 0건이라
 * 잔액만 통과하면 정률 한도를 무시한 마일리지 사용이 그대로 결제까지 이어졌습니다.
 *
 * 방어선은 두 곳입니다.
 * 1) 임시주문 생성/수정 (TempOrderService) — 결제금액 확정 직후
 * 2) 주문 확정 (OrderProcessingService) — 임시주문 생성 이후의 설정 변경·조작 차단
 *
 * @scenario actor=member, change_mode=api
 *
 * @effects mileage_usage_limit_enforced_on_temp_order,
 *   mileage_usage_limit_enforced_on_order_create,
 *   mileage_preview_not_blocked_by_limit
 */
class MileageUsageLimitTest extends ModuleTestCase
{
    private string $settingsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsDir = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($this->settingsDir)) {
            mkdir($this->settingsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (['mileage.json', 'language_currency.json'] as $file) {
            $path = $this->settingsDir.'/'.$file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 마일리지 통화 규칙을 설정 파일로 기록합니다.
     *
     * @param  array  $ruleOverrides  통화 규칙 오버라이드
     */
    private function writeMileageRule(array $ruleOverrides = []): void
    {
        $rule = array_merge([
            'currency_code' => 'KRW',
            'point_value' => 1,
            'min_use_amount' => 0,
            'use_unit' => 1,
            'max_use_type' => 'percent',
            'max_use_percent' => 100,
            'max_use_value' => 0,
        ], $ruleOverrides);

        file_put_contents($this->settingsDir.'/mileage.json', json_encode([
            'enabled' => true,
            'default_earn_rate' => 0,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [$rule],
            'expiry_enabled' => false,
            'expiry_days' => 365,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        file_put_contents($this->settingsDir.'/language_currency.json', json_encode([
            'default_currency' => 'KRW',
        ], JSON_PRETTY_PRINT));
    }

    /**
     * 회원에게 마일리지를 지급합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $amount  지급 금액
     */
    private function grantMileage(int $userId, int $amount): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'description' => 'test grant',
            'expires_at' => null,
        ]);
    }

    /**
     * 판매중 상품 + 장바구니를 구성합니다.
     *
     * 결제금액을 정확히 통제해야 정률 한도를 검증할 수 있으므로 상품가를 명시 지정하고
     * 배송정책은 붙이지 않습니다(정책 없음 = KR 배송 가능 + 배송비 0).
     *
     * @param  User|null  $user  회원 (null 이면 비회원 장바구니)
     * @param  int  $sellingPrice  판매가
     * @param  string|null  $cartKey  비회원 장바구니 키
     */
    private function setupCart(?User $user, int $sellingPrice = 10000, ?string $cartKey = null): Cart
    {
        $product = ProductFactory::new()->create([
            'shipping_policy_id' => null,
            'list_price' => $sellingPrice,
            'selling_price' => $sellingPrice,
        ]);
        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'selling_price' => $sellingPrice,
            'price_adjustment' => 0,
            'stock_quantity' => 100,
        ]);

        $factory = CartFactory::new()->forOption($option);

        if ($user !== null) {
            return $factory->forUser($user)->create(['quantity' => 1]);
        }

        return $factory->create([
            'user_id' => null,
            'cart_key' => $cartKey,
            'quantity' => 1,
        ]);
    }

    /**
     * 회원 주문 생성 페이로드를 구성합니다.
     *
     * @param  int  $expectedTotalAmount  결제예정금액
     */
    private function memberOrderPayload(int $expectedTotalAmount): array
    {
        return [
            'orderer' => [
                'name' => '홍길동',
                'phone' => '010-1234-5678',
                'email' => 'member@test.com',
            ],
            'shipping' => [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9876-5432',
                'country_code' => 'KR',
                'zipcode' => '12345',
                'address' => '서울시 강남구 테헤란로 123',
                'address_detail' => '101동 1001호',
            ],
            'payment_method' => PaymentMethodEnum::DBANK->value,
            'expected_total_amount' => $expectedTotalAmount,
            'depositor_name' => '홍길동',
            'dbank' => [
                'bank_code' => 'KB',
                'bank_name' => '국민은행',
                'account_number' => '123-456-789012',
                'account_holder' => '주식회사 테스트',
            ],
        ];
    }

    // ========================================
    // 임시주문 경로 — 정률 상한 / 사용 단위 / 최소 사용액
    // ========================================

    /**
     * 정률 상한(max_use_percent)을 초과하는 사용 요청은 422 로 차단됩니다.
     *
     * 수정 전: 잔액만 확인해 201 로 통과 → 결제금액 전액을 마일리지로 충당 가능.
     */
    public function test_use_points_exceeding_max_use_percent_returns_422(): void
    {
        // Given: 결제금액의 30% 까지만 사용 가능한 설정 + 잔액 10,000 회원 + 결제금액 10,000
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 30]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        // When: 결제금액 전액(10,000)을 마일리지로 사용 시도
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 10000,
            ]);

        // Then: 422 + 사용 가능 최대 금액(3,000) 안내
        $response->assertStatus(422);
        $this->assertStringContainsString('3000', $response->json('errors.message') ?? '');
    }

    /**
     * 정률 상한과 정확히 같은 금액은 통과합니다 (경계).
     */
    public function test_use_points_exactly_max_use_percent_passes(): void
    {
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 30]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 3000,
            ]);

        $response->assertStatus(201);
    }

    /**
     * 정액 상한(max_use_value)을 초과하는 사용 요청은 422 로 차단됩니다.
     */
    public function test_use_points_exceeding_max_use_value_returns_422(): void
    {
        $this->writeMileageRule(['max_use_type' => 'fixed', 'max_use_value' => 2000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 5000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 사용 단위(use_unit)에 맞지 않는 사용 요청은 422 로 차단됩니다.
     */
    public function test_use_points_violating_use_unit_returns_422(): void
    {
        $this->writeMileageRule(['use_unit' => 1000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 1500,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 최소 사용액(min_use_amount) 미만 사용 요청은 422 로 차단됩니다.
     */
    public function test_use_points_below_min_use_amount_returns_422(): void
    {
        $this->writeMileageRule(['min_use_amount' => 5000]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 1000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 임시주문 수정(PUT) 경로에서도 동일하게 차단됩니다.
     */
    public function test_use_points_exceeding_limit_on_update_returns_422(): void
    {
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 30]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
            ])
            ->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/modules/sirsoft-ecommerce/checkout', [
                'use_points' => 10000,
            ]);

        $response->assertStatus(422);
    }

    // ========================================
    // 주문 확정 경로 — 2단 방어
    // ========================================

    /**
     * 임시주문 생성 후 한도 설정이 강화되면 주문 확정 시점에 차단됩니다.
     *
     * 임시주문 단계 검증만 두면 "먼저 담아둔 임시주문"으로 정책을 우회할 수 있습니다.
     */
    public function test_order_create_blocked_when_limit_tightened_after_temp_order(): void
    {
        // Given: 한도 100% 인 상태에서 결제금액 50,000 중 10,000 마일리지 사용 임시주문 생성
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 100]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 50000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 10000,
            ])
            ->assertStatus(201);

        // When: 관리자가 한도를 10%(=5,000) 로 낮춘 뒤 주문 확정 시도
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 10]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->memberOrderPayload(40000));

        // Then: 422 로 차단되고 주문이 생성되지 않는다
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'mileage_usage_not_allowed');
        $this->assertDatabaseCount('ecommerce_orders', 0);
    }

    /**
     * 한도 내 마일리지 사용 주문은 정상 생성되고, 주문 시점 정책이 스냅샷으로 고정됩니다.
     *
     * 통화·프로모션·배송정책과 동일하게, 이후 관리자가 한도를 바꿔도 그 주문이 어떤 정책
     * 아래 성립했는지 재현 가능해야 합니다.
     */
    public function test_order_create_passes_and_snapshots_usage_policy(): void
    {
        $this->writeMileageRule([
            'max_use_type' => 'percent',
            'max_use_percent' => 100,
            'min_use_amount' => 1000,
            'use_unit' => 10,
        ]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 50000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 10000,
            ])
            ->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/user/orders', $this->memberOrderPayload(40000));

        $response->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $snapshot = $order->mileage_policy_snapshot;

        $this->assertTrue($snapshot['usable']);
        $this->assertSame('KRW', $snapshot['currency']);
        $this->assertSame(1000, $snapshot['rule']['min_use_amount']);
        $this->assertSame(10, $snapshot['rule']['use_unit']);
        $this->assertSame('percent', $snapshot['rule']['max_use_type']);
        $this->assertSame(50000, $snapshot['payment_amount_basis']);
        $this->assertSame(50000, $snapshot['max_use_amount']);
        $this->assertSame(10000, $snapshot['used_points']);

        // 주문 이후 관리자가 한도를 낮춰도 이미 생성된 주문의 스냅샷은 불변
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 10]);
        // JSON 왕복에서 100.0 이 100 으로 표현될 수 있어 값 비교로 단언
        $this->assertEquals(100, $order->fresh()->mileage_policy_snapshot['rule']['max_use_percent']);
    }

    // ========================================
    // 무회귀 — 조회 경로 / 비회원 / 기본 설정
    // ========================================

    /**
     * 한도를 초과하게 된 임시주문이 있어도 체크아웃 조회는 200 을 유지합니다.
     *
     * 조회 경로에서 던지면 설정 변경 직후 사용자의 주문서 화면이 통째로 붕괴합니다.
     */
    public function test_checkout_preview_still_returns_200_when_limit_tightened(): void
    {
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 100]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 50000);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 10000,
            ])
            ->assertStatus(201);

        // When: 한도를 10% 로 낮춘 뒤 주문서 조회
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 10]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/modules/sirsoft-ecommerce/checkout');

        // Then: 조회는 정상 (쓰기 경로에서만 차단)
        $response->assertStatus(200);
    }

    /**
     * 비회원의 마일리지 사용 요청은 예외 없이 조용히 0 으로 처리됩니다.
     */
    public function test_guest_use_points_is_silently_ignored(): void
    {
        $this->writeMileageRule(['max_use_type' => 'percent', 'max_use_percent' => 10]);
        $cartKey = 'ck_'.str_repeat('e', 32);
        $cart = $this->setupCart(null, 10000, $cartKey);

        $response = $this->postJson(
            '/api/modules/sirsoft-ecommerce/checkout',
            ['item_ids' => [$cart->id], 'use_points' => 9000],
            ['X-Cart-Key' => $cartKey]
        );

        $response->assertStatus(201);
        $this->assertSame(0, (int) $response->json('data.calculation.summary.points_used'));
    }

    /**
     * 한도 미설정(기본 통화 규칙: 하한 0 / 단위 1 / 100%) 사이트는 기존과 동일하게 통과합니다.
     */
    public function test_unrestricted_settings_keep_previous_behavior(): void
    {
        $this->writeMileageRule();
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);
        $cart = $this->setupCart($user, 10000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/modules/sirsoft-ecommerce/checkout', [
                'item_ids' => [$cart->id],
                'use_points' => 7777,
            ]);

        $response->assertStatus(201);
        $this->assertSame(7777, (int) $response->json('data.calculation.summary.points_used'));
    }

    // ========================================
    // 안내값(getMaxUsable) 이 하한을 반영
    // ========================================

    /**
     * 사용 가능 최대가 최소 사용액에 못 미치면 안내값은 0 이어야 합니다.
     *
     * 안내값이 하한을 모르면 "화면이 허용한 값인데 결제에서 거부" 가 됩니다.
     */
    public function test_max_usable_is_zero_when_cap_below_min_use_amount(): void
    {
        // 결제금액 10,000 의 10% = 1,000 인데 최소 사용액이 5,000
        $this->writeMileageRule([
            'min_use_amount' => 5000,
            'max_use_type' => 'percent',
            'max_use_percent' => 10,
        ]);
        $user = $this->createUser();
        $this->grantMileage($user->id, 10000);

        $service = app(UserMileageService::class);

        $this->assertSame(0, $service->getMaxUsable($user->id, 10000));
    }
}
