<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\PurchaseEligibilityService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 구매 자격 판정 서비스 테스트
 *
 * 구매 대상 제한(purchase_restriction / allowed_roles)이 회원/비회원 모두에게
 * 올바르게 적용되는지 검증합니다.
 */
class PurchaseEligibilityServiceTest extends ModuleTestCase
{
    private PurchaseEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PurchaseEligibilityService::class);
    }

    /**
     * 제한 없음 상품은 어떤 역할 조합이든 구매 가능합니다.
     */
    public function test_제한_없음_상품은_누구나_구매_가능(): void
    {
        $product = Product::factory()->create([
            'purchase_restriction' => 'none',
            'allowed_roles' => [],
        ]);

        // 빈 역할(비회원 무권한)이라도 통과
        $this->assertTrue($this->service->isPurchasableBy($product, []));
        $this->assertTrue($this->service->isPurchasableBy($product, [999]));
    }

    /**
     * 제한 상품은 허용 역할을 가진 사용자만 구매할 수 있습니다.
     */
    public function test_제한_상품은_허용_역할_보유_시_구매_가능(): void
    {
        $allowedRole = Role::create([
            'identifier' => 'vip-test',
            'name' => ['ko' => 'VIP', 'en' => 'VIP'],
        ]);

        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$allowedRole->id],
        ]);

        $this->assertTrue($this->service->isPurchasableBy($product, [$allowedRole->id]));
    }

    /**
     * 제한 상품은 허용 역할이 없는 사용자에게 차단됩니다.
     */
    public function test_제한_상품은_허용_역할_미보유_시_차단(): void
    {
        $allowedRole = Role::create([
            'identifier' => 'vip-test',
            'name' => ['ko' => 'VIP', 'en' => 'VIP'],
        ]);
        $otherRole = Role::create([
            'identifier' => 'other-test',
            'name' => ['ko' => '기타', 'en' => 'Other'],
        ]);

        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$allowedRole->id],
        ]);

        $this->assertFalse($this->service->isPurchasableBy($product, [$otherRole->id]));
        // 역할 없음(빈 배열)도 차단
        $this->assertFalse($this->service->isPurchasableBy($product, []));
    }

    /**
     * 제한 상품에서 허용 역할이 비어 있으면 아무도 구매할 수 없습니다.
     */
    public function test_제한_상품_허용_역할_비어있으면_전원_차단(): void
    {
        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [],
        ]);

        $this->assertFalse($this->service->isPurchasableBy($product, [1, 2, 3]));
    }

    /**
     * guest 역할이 허용된 제한 상품은 비회원이 구매할 수 있습니다.
     */
    public function test_guest_허용_상품은_비회원_구매_가능(): void
    {
        $guestRole = Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest']]
        );

        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$guestRole->id],
        ]);

        // 비회원(null) 역할 해석 → guest 역할 ID
        $guestRoleIds = $this->service->resolveRoleIds(null);

        $this->assertSame([$guestRole->id], $guestRoleIds);
        $this->assertTrue($this->service->isPurchasableBy($product, $guestRoleIds));
    }

    /**
     * guest 역할이 허용되지 않은 제한 상품은 비회원에게 차단됩니다.
     */
    public function test_guest_비허용_상품은_비회원_차단(): void
    {
        Role::firstOrCreate(
            ['identifier' => 'guest'],
            ['name' => ['ko' => '비회원', 'en' => 'Guest']]
        );
        $memberOnlyRole = Role::create([
            'identifier' => 'member-only-test',
            'name' => ['ko' => '회원전용', 'en' => 'Member Only'],
        ]);

        $product = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$memberOnlyRole->id],
        ]);

        $guestRoleIds = $this->service->resolveRoleIds(null);

        $this->assertFalse($this->service->isPurchasableBy($product, $guestRoleIds));
    }

    /**
     * 회원의 역할 해석은 보유한 모든 역할 ID를 반환합니다.
     */
    public function test_회원_역할_해석은_보유_역할_ID_반환(): void
    {
        $roleA = Role::create(['identifier' => 'role-a-test', 'name' => ['ko' => 'A', 'en' => 'A']]);
        $roleB = Role::create(['identifier' => 'role-b-test', 'name' => ['ko' => 'B', 'en' => 'B']]);

        $user = User::factory()->create();
        $user->roles()->attach([$roleA->id, $roleB->id]);

        $resolved = $this->service->resolveRoleIds($user->fresh());

        sort($resolved);
        $expected = [$roleA->id, $roleB->id];
        sort($expected);

        $this->assertSame($expected, $resolved);
    }

    /**
     * filterRestrictedProducts 는 구매 불가 상품만 가려냅니다.
     */
    public function test_filterRestrictedProducts_차단_상품만_반환(): void
    {
        $allowedRole = Role::create(['identifier' => 'vip-test', 'name' => ['ko' => 'VIP', 'en' => 'VIP']]);

        $openProduct = Product::factory()->create(['purchase_restriction' => 'none', 'allowed_roles' => []]);
        $allowedProduct = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$allowedRole->id],
        ]);
        $blockedProduct = Product::factory()->create([
            'purchase_restriction' => 'restricted',
            'allowed_roles' => [$allowedRole->id],
        ]);

        // 사용자는 allowedRole 미보유 → blockedProduct/allowedProduct 모두 차단, openProduct 통과
        $products = new Collection([$openProduct, $allowedProduct, $blockedProduct]);
        $restricted = $this->service->filterRestrictedProducts($products, [9999]);

        $restrictedIds = array_map(fn (Product $p) => $p->id, $restricted);

        $this->assertContains($allowedProduct->id, $restrictedIds);
        $this->assertContains($blockedProduct->id, $restrictedIds);
        $this->assertNotContains($openProduct->id, $restrictedIds);
    }
}
