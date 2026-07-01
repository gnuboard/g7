<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../../../ModuleTestCase.php';

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 대시보드 API 테스트
 *
 * /api/modules/sirsoft-ecommerce/admin/dashboard/* 4 엔드포인트
 *  - overview / sales-graph / recent-reviews / pending-inquiries
 *  - 가드: admin 미들웨어 + permission:admin,sirsoft-ecommerce.dashboard.view
 */
class DashboardApiTest extends ModuleTestCase
{
    private User $adminUser;

    private User $normalUser;

    private const BASE = '/api/modules/sirsoft-ecommerce/admin/dashboard';

    protected function setUp(): void
    {
        parent::setUp();

        EcommerceStat::query()->delete();
        ProductReview::query()->forceDelete();
        ProductInquiry::query()->forceDelete();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.dashboard.view']);
        $this->normalUser = $this->createUser();
    }

    // ========== overview ==========

    #[Test]
    public function test_overview_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/overview')->assertStatus(401);
    }

    #[Test]
    public function test_overview_rejects_non_admin_user(): void
    {
        $this->actingAs($this->normalUser)->getJson(self::BASE.'/overview')->assertStatus(403);
    }

    #[Test]
    public function test_overview_rejects_admin_without_dashboard_permission(): void
    {
        $adminNoPerm = $this->createAdminUser();

        $this->actingAs($adminNoPerm)->getJson(self::BASE.'/overview')->assertStatus(403);
    }

    #[Test]
    public function test_overview_returns_today_buckets_for_admin(): void
    {
        EcommerceStat::create([
            'date' => CarbonImmutable::today()->toDateString(),
            'sales_quantity' => 5,
            'sales_amount' => 50000,
            'option_status_counts' => ['payment_complete' => 3, 'shipping' => 2],
        ]);

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/overview')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['pending_payment', 'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'cancellations', 'returns']])
            ->assertJsonPath('data.payment_complete', 3)
            ->assertJsonPath('data.shipping', 2);
    }

    // ========== sales-graph ==========

    #[Test]
    public function test_sales_graph_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/sales-graph')->assertStatus(401);
    }

    #[Test]
    public function test_sales_graph_returns_structure_for_admin(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/sales-graph')
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['days', 'total_quantity', 'total_sales', 'quantity_change', 'sales_change', 'updated_at', 'updated_at_display'],
            ]);
    }

    // ========== recent-reviews ==========

    #[Test]
    public function test_recent_reviews_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/recent-reviews')->assertStatus(401);
    }

    #[Test]
    public function test_recent_reviews_returns_data_for_admin(): void
    {
        ProductReview::factory()->create();

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/recent-reviews')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => [['id', 'product_id', 'product_name', 'rating', 'author_name', 'created_at']]]);
    }

    #[Test]
    public function test_recent_reviews_rejects_invalid_limit(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/recent-reviews?limit=999')
            ->assertStatus(422);
    }

    // ========== pending-inquiries ==========

    #[Test]
    public function test_pending_inquiries_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/pending-inquiries')->assertStatus(401);
    }

    #[Test]
    public function test_pending_inquiries_returns_items_and_total_for_admin(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create(['is_answered' => false, 'product_id' => $product->id]);
        ProductInquiry::factory()->create(['is_answered' => true, 'product_id' => $product->id]);

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/pending-inquiries')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['items' => [['id', 'inquirable_id']], 'total', 'board_slug']])
            ->assertJsonPath('data.total', 1);
    }

    #[Test]
    public function test_pending_inquiries_rejects_invalid_limit(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/pending-inquiries?limit=0')
            ->assertStatus(422);
    }
}
