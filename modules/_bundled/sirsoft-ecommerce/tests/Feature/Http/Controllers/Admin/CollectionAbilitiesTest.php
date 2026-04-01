<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ProductNoticeTemplate;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Collection-level abilities 통합 테스트
 *
 * 각 Collection에 HasAbilityCheck trait이 적용되어
 * abilities 키가 API 응답에 올바르게 포함되는지 검증합니다.
 */
class CollectionAbilitiesTest extends ModuleTestCase
{
    private User $fullPermissionUser;

    private User $readOnlyUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 모든 권한을 가진 사용자
        $this->fullPermissionUser = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.products.update',
            'sirsoft-ecommerce.products.delete',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.orders.update',
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
            'sirsoft-ecommerce.promotion-coupon.delete',
            'sirsoft-ecommerce.shipping-policies.read',
            'sirsoft-ecommerce.shipping-policies.create',
            'sirsoft-ecommerce.shipping-policies.update',
            'sirsoft-ecommerce.shipping-policies.delete',
            'sirsoft-ecommerce.brands.read',
            'sirsoft-ecommerce.brands.create',
            'sirsoft-ecommerce.brands.update',
            'sirsoft-ecommerce.brands.delete',
            'sirsoft-ecommerce.categories.read',
            'sirsoft-ecommerce.categories.create',
            'sirsoft-ecommerce.categories.update',
            'sirsoft-ecommerce.categories.delete',
            'sirsoft-ecommerce.product-notice-templates.read',
            'sirsoft-ecommerce.product-notice-templates.create',
            'sirsoft-ecommerce.product-notice-templates.update',
            'sirsoft-ecommerce.product-notice-templates.delete',
            'sirsoft-ecommerce.product-common-infos.read',
            'sirsoft-ecommerce.product-common-infos.create',
            'sirsoft-ecommerce.product-common-infos.update',
            'sirsoft-ecommerce.product-common-infos.delete',
            'sirsoft-ecommerce.product-labels.read',
            'sirsoft-ecommerce.product-labels.create',
            'sirsoft-ecommerce.product-labels.update',
            'sirsoft-ecommerce.product-labels.delete',
            'sirsoft-ecommerce.settings.read',
            'sirsoft-ecommerce.settings.update',
        ]);

        // 읽기 전용 사용자
        $this->readOnlyUser = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.shipping-policies.read',
            'sirsoft-ecommerce.brands.read',
            'sirsoft-ecommerce.categories.read',
            'sirsoft-ecommerce.product-notice-templates.read',
            'sirsoft-ecommerce.product-common-infos.read',
            'sirsoft-ecommerce.product-labels.read',
            'sirsoft-ecommerce.settings.read',
        ]);
    }

    // =========================================================================
    // ProductCollection abilities
    // =========================================================================

    #[Test]
    public function test_product_list_includes_abilities_for_full_permission_user(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    #[Test]
    public function test_product_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/products');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    // =========================================================================
    // OrderCollection abilities
    // =========================================================================

    #[Test]
    public function test_order_list_includes_abilities_for_full_permission_user(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_update']);
    }

    #[Test]
    public function test_order_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_update']);
    }

    // =========================================================================
    // CouponCollection abilities
    // =========================================================================

    #[Test]
    public function test_coupon_list_includes_abilities_for_full_permission_user(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    #[Test]
    public function test_coupon_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    // =========================================================================
    // ShippingPolicyCollection abilities
    // =========================================================================

    #[Test]
    public function test_shipping_policy_list_includes_abilities(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/shipping-policies');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    #[Test]
    public function test_shipping_policy_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/shipping-policies');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    // =========================================================================
    // ProductNoticeTemplateCollection abilities
    // =========================================================================

    #[Test]
    public function test_notice_template_list_includes_abilities(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    #[Test]
    public function test_notice_template_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-notice-templates');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    // =========================================================================
    // ProductCommonInfoCollection abilities
    // =========================================================================

    #[Test]
    public function test_common_info_list_includes_abilities(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    #[Test]
    public function test_common_info_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-common-infos');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    // =========================================================================
    // ProductLabelCollection abilities
    // =========================================================================

    #[Test]
    public function test_label_list_includes_abilities(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    #[Test]
    public function test_label_list_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/product-labels');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_create']);
        $this->assertFalse($data['abilities']['can_update']);
        $this->assertFalse($data['abilities']['can_delete']);
    }

    // =========================================================================
    // EcommerceSettings abilities
    // =========================================================================

    #[Test]
    public function test_settings_includes_abilities_for_full_permission_user(): void
    {
        $token = $this->fullPermissionUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/settings');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_update']);
    }

    #[Test]
    public function test_settings_read_only_user_has_false_abilities(): void
    {
        $token = $this->readOnlyUser->createToken('test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/settings');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertFalse($data['abilities']['can_update']);
    }
}
