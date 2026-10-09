<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../../../ModuleTestCase.php';

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\EcommerceStat;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
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

    /**
     * 기준 시각을 고정하고 집계·리뷰·문의 잔여 행을 비운 뒤 권한 있는 관리자와 일반 회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 「오늘」 배지는 사이트 표준시(한국)의 하루 행을 읽는다. UTC 날짜와 사이트 날짜가 같은 시각으로 고정한다 —
        // 한국 0~9시(UTC 전날)에 돌리면 UTC 기준 오늘 행이 사이트 어제가 되어 시각 따라 깨진다.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 03:00:00', 'UTC'));

        EcommerceStat::query()->delete();
        OrderOption::query()->forceDelete();
        Order::query()->forceDelete();
        ProductReview::query()->forceDelete();
        ProductInquiry::query()->forceDelete();

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.dashboard.view']);
        $this->normalUser = $this->createUser();
    }

    // ========== overview ==========

    /**
     * 로그인하지 않은 요청은 오늘 주문 현황 API 에서 401 로 거부된다.
     *
     * @scenario module_state=active, data_state=no_orders, user_role=unauthenticated
     *
     * @effects dashboard_api_rejects_unauthenticated_request_with_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/overview')->assertStatus(401);
    }

    /**
     * 관리자가 아닌 회원은 오늘 주문 현황 API 에서 403 으로 거부된다.
     *
     * @scenario module_state=active, data_state=no_orders, user_role=non_admin
     *
     * @effects non_admin_blocked_from_dashboard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_rejects_non_admin_user(): void
    {
        $this->actingAs($this->normalUser)->getJson(self::BASE.'/overview')->assertStatus(403);
    }

    /**
     * 대시보드 조회 권한이 없는 관리자는 오늘 주문 현황 API 에서 403 으로 거부된다.
     *
     * @scenario module_state=active, data_state=no_orders, user_role=admin_without_dashboard_permission
     *
     * @effects dashboard_api_rejects_admin_without_dashboard_permission_with_403
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_rejects_admin_without_dashboard_permission(): void
    {
        $adminNoPerm = $this->createAdminUser();

        $this->actingAs($adminNoPerm)->getJson(self::BASE.'/overview')->assertStatus(403);
    }

    /**
     * 권한 있는 관리자는 오늘 상태 배지 일곱 칸(주문 수)을 받는다.
     *
     * @scenario module_state=active, data_state=has_orders, user_role=admin_with_dashboard_permission
     *
     * @effects today_order_status_badges_reflect_overview_api
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_overview_returns_today_buckets_for_admin(): void
    {
        // 오늘(사이트 하루) 결제완료 주문 3건·배송중 주문 2건 — 배지는 주문 수다(옵션 수량 아님)
        $today = CarbonImmutable::today()->toDateTimeString();
        Order::factory()->count(3)->create(['ordered_at' => $today, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        Order::factory()->count(2)->create(['ordered_at' => $today, 'order_status' => OrderStatusEnum::SHIPPING]);

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/overview')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['pending_payment', 'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'cancellations', 'returns']])
            ->assertJsonPath('data.payment_complete', 3)
            ->assertJsonPath('data.shipping', 2);
    }

    // ========== sales-graph ==========

    /**
     * 로그인하지 않은 요청은 판매 추세 API 에서 401 로 거부된다.
     *
     * @scenario module_state=active, data_state=no_previous_week, user_role=unauthenticated
     *
     * @effects dashboard_api_rejects_unauthenticated_request_with_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_sales_graph_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/sales-graph')->assertStatus(401);
    }

    /**
     * 권한 있는 관리자는 판매 추세 API 에서 7일 막대·합계·증감률·갱신 시각 필드를 받는다.
     *
     * @scenario module_state=active, data_state=no_previous_week, user_role=admin_with_dashboard_permission
     *
     * @effects sales_graph_api_payload_carries_days_totals_change_rates_and_updated_at
     *
     * @return void 반환값 없음
     */
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

    /**
     * 로그인하지 않은 요청은 최신 리뷰 API 에서 401 로 거부된다.
     *
     * @scenario module_state=active, data_state=no_reviews, user_role=unauthenticated
     *
     * @effects dashboard_api_rejects_unauthenticated_request_with_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_reviews_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/recent-reviews')->assertStatus(401);
    }

    /**
     * 권한 있는 관리자는 최신 리뷰 API 에서 상품명·평점·작성자가 담긴 리뷰 행을 받는다.
     *
     * @scenario module_state=active, data_state=has_reviews, user_role=admin_with_dashboard_permission
     *
     * @effects recent_reviews_api_returns_review_rows_with_product_and_author
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_reviews_returns_data_for_admin(): void
    {
        ProductReview::factory()->create();

        $this->actingAs($this->adminUser)->getJson(self::BASE.'/recent-reviews')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => [['id', 'product_id', 'product_name', 'rating', 'author_name', 'created_at']]]);
    }

    /**
     * 최신 리뷰 API 는 허용 범위를 넘는 limit 를 422 로 거부한다.
     *
     * @scenario module_state=active, data_state=no_reviews, user_role=admin_with_dashboard_permission
     *
     * @effects dashboard_list_api_rejects_out_of_range_limit_with_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_recent_reviews_rejects_invalid_limit(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/recent-reviews?limit=999')
            ->assertStatus(422);
    }

    // ========== pending-inquiries ==========

    /**
     * 로그인하지 않은 요청은 미답변 문의 API 에서 401 로 거부된다.
     *
     * @scenario module_state=active, data_state=no_inquiries, user_role=unauthenticated
     *
     * @effects dashboard_api_rejects_unauthenticated_request_with_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_inquiries_requires_authentication(): void
    {
        $this->getJson(self::BASE.'/pending-inquiries')->assertStatus(401);
    }

    /**
     * 권한 있는 관리자는 미답변 문의만 센 건수·항목과 게시판 slug 를 받는다.
     *
     * @scenario module_state=active, data_state=has_inquiries, user_role=admin_with_dashboard_permission
     *
     * @effects pending_inquiries_api_counts_only_unanswered_inquiries, pending_inquiries_payload_carries_the_board_slug_for_navigation
     *
     * @return void 반환값 없음
     */
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

    /**
     * 미답변 문의 API 는 허용 범위 밖의 limit(0) 를 422 로 거부한다.
     *
     * @scenario module_state=active, data_state=no_inquiries, user_role=admin_with_dashboard_permission
     *
     * @effects dashboard_list_api_rejects_out_of_range_limit_with_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_inquiries_rejects_invalid_limit(): void
    {
        $this->actingAs($this->adminUser)->getJson(self::BASE.'/pending-inquiries?limit=0')
            ->assertStatus(422);
    }
}
