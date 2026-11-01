<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Models\Module as ModuleRegistration;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceDashboardService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 모듈이 꺼진 사이트의 대시보드 미답변 문의 (#822 G2)
 *
 * 상품 문의는 게시판 모듈의 글로 저장되고, 대시보드 미답변 문의 카드의 항목·「전체 보기」는
 * 게시판 관리자 화면(`/admin/board/{slug}/post/{id}`)으로 간다. 게시판 모듈이 꺼져 있으면
 * 그 화면이 없으므로 서버가 문의 항목을 내지 않는다 — 이전에는 문의 게시판 설정(board_slug)만
 * 보고 링크를 그려, 누르면 없는 화면으로 갔다.
 */
class DashboardInquiryBoardInactiveTest extends ModuleTestCase
{
    private User $adminUser;

    private const ENDPOINT = '/api/modules/sirsoft-ecommerce/admin/dashboard/pending-inquiries';

    /**
     * 문의 잔여 행을 비우고 미답변 문의 1건·답변 문의 1건과 권한 있는 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        ProductInquiry::query()->forceDelete();

        $product = Product::factory()->create();
        ProductInquiry::factory()->create(['is_answered' => false, 'product_id' => $product->id]);
        ProductInquiry::factory()->create(['is_answered' => true, 'product_id' => $product->id]);

        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.dashboard.view']);
    }

    /**
     * 게시판 모듈 등록 행의 상태를 정하고 활성 목록 캐시를 비웁니다.
     *
     * @param  ExtensionStatus|null  $status  게시판 모듈 상태 (null 이면 미설치 — 행을 지운다)
     * @return void 반환값 없음
     */
    private function setBoardModuleStatus(?ExtensionStatus $status): void
    {
        if ($status === null) {
            ModuleRegistration::where('identifier', 'sirsoft-board')->delete();
        } else {
            ModuleRegistration::updateOrCreate(
                ['identifier' => 'sirsoft-board'],
                [
                    'vendor' => 'sirsoft',
                    'name' => ['ko' => '게시판', 'en' => 'Board'],
                    'status' => $status->value,
                    'version' => '1.2.2',
                ]
            );
        }

        ModuleManager::invalidateModuleStatusCache();
    }

    /**
     * 게시판 모듈이 비활성이면 미답변 문의 API 가 항목·건수·게시판 slug 를 비우고 board_active=false 를 낸다.
     *
     * @scenario module_state=active, board_module_state=inactive, data_state=has_inquiries, user_role=admin_with_dashboard_permission
     *
     * @effects pending_inquiries_hidden_when_board_module_inactive
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_inquiries_are_empty_when_board_module_is_inactive(): void
    {
        $this->setBoardModuleStatus(ExtensionStatus::Inactive);

        $this->actingAs($this->adminUser)->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.board_slug', null)
            ->assertJsonPath('data.board_active', false);
    }

    /**
     * 게시판 모듈이 설치되지 않은 사이트도 같다.
     *
     * @scenario module_state=active, board_module_state=not_installed, data_state=has_inquiries, user_role=admin_with_dashboard_permission
     *
     * @effects pending_inquiries_hidden_when_board_module_inactive
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_inquiries_are_empty_when_board_module_is_not_installed(): void
    {
        $this->setBoardModuleStatus(null);

        $result = $this->app->make(EcommerceDashboardService::class)->getPendingInquiries(5);

        $this->assertCount(0, $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertNull($result['board_slug']);
        $this->assertFalse($result['board_active']);
    }

    /**
     * 게시판 모듈이 활성이면 지금처럼 미답변 문의만 센 항목·건수를 낸다(board_active=true).
     *
     * @scenario module_state=active, board_module_state=active, data_state=has_inquiries, user_role=admin_with_dashboard_permission
     *
     * @effects pending_inquiries_api_counts_only_unanswered_inquiries
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_pending_inquiries_are_listed_when_board_module_is_active(): void
    {
        $this->setBoardModuleStatus(ExtensionStatus::Active);

        $this->actingAs($this->adminUser)->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.board_active', true);
    }
}
