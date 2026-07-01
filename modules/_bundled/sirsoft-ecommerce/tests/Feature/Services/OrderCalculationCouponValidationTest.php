<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Services;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponIssueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품쿠폰 검증 강화 테스트 (U13b/MP06)
 *
 * - 문제1: min_order_amount 를 적용 대상 옵션 소계 기준으로 검증
 * - 문제2: per_user_limit 을 주문 단계에서 used_at 기준 2축(과거사용+주문내중복) 차단
 */
class OrderCalculationCouponValidationTest extends ModuleTestCase
{
    protected OrderCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestSettings();
        $this->service = app(OrderCalculationService::class);
    }

    protected function tearDown(): void
    {
        foreach (['language_currency.json', 'mileage.json'] as $f) {
            $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/'.$f);
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * 테스트용 통화/마일리지 설정을 격리 경로에 저장합니다.
     */
    protected function setupTestSettings(): void
    {
        $settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($settingsPath)) {
            mkdir($settingsPath, 0755, true);
        }

        file_put_contents(
            $settingsPath.'/language_currency.json',
            json_encode([
                'default_language' => 'ko',
                'default_currency' => 'KRW',
                'currencies' => [
                    [
                        'code' => 'KRW',
                        'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'],
                        'exchange_rate' => null,
                        'rounding_unit' => '1',
                        'rounding_method' => 'floor',
                        'is_default' => true,
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 테스트용 상품/옵션을 생성합니다.
     *
     * @param  int  $price  판매가
     * @return array{0: \Modules\Sirsoft\Ecommerce\Models\Product, 1: \Modules\Sirsoft\Ecommerce\Models\ProductOption}
     */
    protected function createProductWithOption(int $price = 50000): array
    {
        $product = ProductFactory::new()->create([
            'tax_status' => 'taxable',
            'selling_price' => $price,
            'list_price' => $price,
        ]);

        $option = ProductOptionFactory::new()->forProduct($product)->create([
            'price_adjustment' => 0,
            'stock_quantity' => 100,
            'is_default' => true,
        ]);

        return [$product, $option];
    }

    /**
     * 상품 쿠폰 + 발급 내역을 생성합니다.
     *
     * @param  int  $userId  발급 대상 사용자 ID
     * @param  int  $minOrderAmount  최소 주문금액
     * @param  int  $perUserLimit  1인 사용 한도 (0=무제한)
     * @param  int  $discountValue  정액 할인값
     * @return CouponIssue
     */
    protected function makeProductCouponIssue(int $userId, int $minOrderAmount = 0, int $perUserLimit = 0, int $discountValue = 1000): CouponIssue
    {
        $coupon = Coupon::create([
            'code' => 'PC'.uniqid(),
            'name' => ['ko' => '상품 쿠폰', 'en' => 'Product Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => $discountValue,
            'min_order_amount' => $minOrderAmount,
            'per_user_limit' => $perUserLimit,
            'is_combinable' => true,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    // ========================================
    // 문제1: min_order_amount 적용 상품 소계 기준
    // ========================================

    /**
     * 적용 상품 소계가 미달이면 전체 합계가 충족해도 차단된다.
     */
    public function test_product_coupon_min_amount_enforced_on_applicable_item(): void
    {
        $user = User::factory()->create();
        [$productA, $optionA] = $this->createProductWithOption(10000); // 적용 대상(소계 10,000)
        [$productB, $optionB] = $this->createProductWithOption(50000); // 비적용(합계만 키움)

        // min=50,000 쿠폰을 소계 10,000 인 옵션A 에만 적용
        $issue = $this->makeProductCouponIssue($user->id, minOrderAmount: 50000, perUserLimit: 0);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            itemCoupons: [$optionA->id => [$issue->id]],
            userId: $user->id,
        );

        $result = $this->service->calculate($input);

        $codes = array_map(fn ($e) => $e->code, $result->validationErrors);
        $this->assertContains('min_amount', $codes, '적용 소계 미달 → min_amount 차단');
    }

    /**
     * 적용 상품 소계가 충족하면 정상 적용된다.
     */
    public function test_product_coupon_min_amount_passes_when_applicable_meets(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption(50000);

        $issue = $this->makeProductCouponIssue($user->id, minOrderAmount: 30000, perUserLimit: 0, discountValue: 5000);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            itemCoupons: [$option->id => [$issue->id]],
            userId: $user->id,
        );

        $result = $this->service->calculate($input);

        $this->assertEmpty($result->validationErrors);
        $this->assertEquals(5000, $result->summary->productCouponDiscount);
    }

    // ========================================
    // 문제2: per_user_limit (축1 과거사용 + 축2 주문내중복)
    // ========================================

    /**
     * 축1: 과거 사용 1건 + limit=1 → 차단.
     */
    public function test_per_user_limit_axis1_prior_used_blocked(): void
    {
        $user = User::factory()->create();
        [$product, $option] = $this->createProductWithOption(50000);

        $issue = $this->makeProductCouponIssue($user->id, minOrderAmount: 0, perUserLimit: 1);

        // 과거 사용 1건: 동일 coupon_id 로 used_at 세팅된 다른 발급건
        CouponIssue::create([
            'coupon_id' => $issue->coupon_id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subDay(),
            'used_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            itemCoupons: [$option->id => [$issue->id]],
            userId: $user->id,
        );

        $result = $this->service->calculate($input);

        $codes = array_map(fn ($e) => $e->code, $result->validationErrors);
        $this->assertContains('per_user_limit', $codes);
    }

    /**
     * 축2: 한 주문 내 동일 쿠폰을 두 라인 적용 → 둘째 라인 차단.
     */
    public function test_per_user_limit_axis2_within_order_blocked(): void
    {
        $user = User::factory()->create();
        [$productA, $optionA] = $this->createProductWithOption(50000);
        [$productB, $optionB] = $this->createProductWithOption(50000);

        $issue = $this->makeProductCouponIssue($user->id, minOrderAmount: 0, perUserLimit: 1);

        // 동일 발급 ID 를 두 옵션 라인에 지정
        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            itemCoupons: [
                $optionA->id => [$issue->id],
                $optionB->id => [$issue->id],
            ],
            userId: $user->id,
        );

        $result = $this->service->calculate($input);

        $codes = array_map(fn ($e) => $e->code, $result->validationErrors);
        $this->assertContains('per_user_limit', $codes, '주문 내 중복 → 둘째 라인 per_user_limit 차단');
    }

    /**
     * per_user_limit=0(무제한)은 차단하지 않는다.
     */
    public function test_per_user_limit_unlimited_never_blocks(): void
    {
        $user = User::factory()->create();
        [$productA, $optionA] = $this->createProductWithOption(50000);
        [$productB, $optionB] = $this->createProductWithOption(50000);

        $issue = $this->makeProductCouponIssue($user->id, minOrderAmount: 0, perUserLimit: 0);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
                new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
            ],
            itemCoupons: [
                $optionA->id => [$issue->id],
                $optionB->id => [$issue->id],
            ],
            userId: $user->id,
        );

        $result = $this->service->calculate($input);

        $codes = array_map(fn ($e) => $e->code, $result->validationErrors);
        $this->assertNotContains('per_user_limit', $codes);
    }

    /**
     * 비회원(userId null)은 축1(과거 사용) 조회를 skip하고 축2만 적용한다.
     */
    public function test_per_user_limit_guest_skips_axis1(): void
    {
        // 회원에게 발급된 쿠폰을 비회원 컨텍스트로 계산 (userId null)
        $owner = User::factory()->create();
        [$product, $option] = $this->createProductWithOption(50000);

        $issue = $this->makeProductCouponIssue($owner->id, minOrderAmount: 0, perUserLimit: 1);

        // 과거 사용 1건 존재하지만 비회원은 축1 조회 skip → 단일 라인은 통과
        CouponIssue::create([
            'coupon_id' => $issue->coupon_id,
            'user_id' => $owner->id,
            'status' => CouponIssueRecordStatus::USED,
            'issued_at' => now()->subDay(),
            'used_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $input = new CalculationInput(
            items: [
                new CalculationItem(productId: $product->id, productOptionId: $option->id, quantity: 1),
            ],
            itemCoupons: [$option->id => [$issue->id]],
            userId: null,
        );

        $result = $this->service->calculate($input);

        $codes = array_map(fn ($e) => $e->code, $result->validationErrors);
        $this->assertNotContains('per_user_limit', $codes, '비회원 단일 라인은 축1 skip → 통과');
    }

    // ========================================
    // Repository 단위
    // ========================================

    /**
     * getUserUsedCountForCoupon 은 used_at 세팅 건만 센다.
     */
    public function test_repository_counts_only_used_at_set(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'RC'.uniqid(),
            'name' => ['ko' => '카운트 쿠폰', 'en' => 'Count Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'target_scope' => CouponTargetScope::ALL,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'per_user_limit' => 0,
            'is_combinable' => true,
            'started_at' => now()->subDay(),
            'ended_at' => now()->addDay(),
            'is_active' => true,
        ]);

        // 사용 완료 2건
        foreach (range(1, 2) as $i) {
            CouponIssue::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'status' => CouponIssueRecordStatus::USED,
                'issued_at' => now()->subDay(),
                'used_at' => now()->subHour(),
                'expires_at' => now()->addDay(),
            ]);
        }
        // 미사용 1건 (used_at null)
        CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => CouponIssueRecordStatus::AVAILABLE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $repo = app(CouponIssueRepositoryInterface::class);

        $this->assertEquals(2, $repo->getUserUsedCountForCoupon($user->id, $coupon->id));
        $this->assertEquals(0, $repo->getUserUsedCountForCoupon($user->id, 999999));
    }
}
