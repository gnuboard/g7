<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 관리자 상품 1:1 문의 관리 API Feature 테스트
 *
 * DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{id}          - 문의 삭제
 * POST   /api/modules/sirsoft-ecommerce/admin/inquiries/{id}/reply    - 답변 등록
 * PUT    /api/modules/sirsoft-ecommerce/admin/inquiries/{id}/reply    - 답변 수정
 * DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{id}/reply    - 답변 삭제
 */
class AdminProductInquiryControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/inquiries';

    private User $adminUser;

    private Product $product;

    private User $inquiryUser;

    private ProductInquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.inquiries.update',
            'sirsoft-ecommerce.inquiries.delete',
        ]);
        $this->inquiryUser = $this->createUser();
        $this->product = Product::factory()->create();

        // inquiry board_slug 설정
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-inquiry-board');

        // 다른 모듈(sirsoft-board 등) 의 ServiceProvider 가 등록한 inquiry.* 필터가
        // ModuleTestCase snapshot 에 의해 잔존하여 test mock 과 충돌하는 cross-module
        // contamination 을 차단. 본 테스트는 board 모듈 동작이 아닌 ecommerce 컨트롤러
        // 자체 동작만 검증하므로, mock 의 단순 반환값으로 전체 chain 을 대체.
        foreach ([
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.get_settings',
            'sirsoft-ecommerce.inquiry.store_validation_rules',
            'sirsoft-ecommerce.inquiry.update_validation_rules',
        ] as $hook) {
            HookManager::clearFilter($hook);
        }

        // 게시판 훅 모킹
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.delete',
            fn () => true,
            priority: 1
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.update_reply',
            fn () => true,
            priority: 1
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.delete_reply',
            fn () => true,
            priority: 1
        );

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            fn () => ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'],
            priority: 1
        );

        $this->inquiry = ProductInquiry::factory()->create([
            'user_id'     => $this->inquiryUser->id,
            'product_id'  => $this->product->id,
            'is_answered' => false,
        ]);
    }

    // ========================================
    // destroy() — 문의 삭제
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의를_삭제할_수_없다(): void
    {
        $response = $this->deleteJson("{$this->apiBase}/{$this->inquiry->id}");

        $response->assertUnauthorized();
    }

    #[Test]
    public function 관리자는_문의를_삭제할_수_있다(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$this->inquiry->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('ecommerce_product_inquiries', ['id' => $this->inquiry->id]);
    }

    // ========================================
    // reply() — 답변 등록
    // ========================================

    #[Test]
    public function 비인증_사용자는_답변을_등록할_수_없다(): void
    {
        $response = $this->postJson(
            "{$this->apiBase}/{$this->inquiry->id}/reply",
            ['content' => '답변 내용입니다 친절하게']
        );

        $response->assertUnauthorized();
    }

    #[Test]
    public function 관리자는_미답변_문의에_답변을_등록할_수_있다(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(
                "{$this->apiBase}/{$this->inquiry->id}/reply",
                ['content' => '안녕하세요. 문의 주셔서 감사합니다.']
            );

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id'          => $this->inquiry->id,
            'is_answered' => true,
        ]);
    }

    #[Test]
    public function 존재하지_않는_문의에_답변_등록_시_422를_반환한다(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson(
                "{$this->apiBase}/99999/reply",
                ['content' => '답변 내용입니다 친절하게']
            );

        $response->assertStatus(422);
    }

    // ========================================
    // updateReply() — 답변 수정
    // ========================================

    #[Test]
    public function 관리자는_답변을_수정할_수_있다(): void
    {
        $answeredInquiry = ProductInquiry::factory()->create([
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson(
                "{$this->apiBase}/{$answeredInquiry->id}/reply",
                ['content' => '수정된 답변 내용입니다.']
            );

        $response->assertOk();
    }

    #[Test]
    public function 비관리자는_답변을_수정할_수_없다(): void
    {
        $normalUser = $this->createUser();
        $answeredInquiry = ProductInquiry::factory()->create([
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($normalUser)
            ->putJson(
                "{$this->apiBase}/{$answeredInquiry->id}/reply",
                ['content' => '수정 시도 내용입니다 자세히']
            );

        $response->assertForbidden();
    }

    // ========================================
    // destroyReply() — 답변 삭제
    // ========================================

    #[Test]
    public function 관리자는_답변을_삭제할_수_있다(): void
    {
        $answeredInquiry = ProductInquiry::factory()->create([
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$answeredInquiry->id}/reply");

        $response->assertOk();
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id'          => $answeredInquiry->id,
            'is_answered' => false,
        ]);
    }

    #[Test]
    public function 비관리자는_답변을_삭제할_수_없다(): void
    {
        $normalUser = $this->createUser();
        $answeredInquiry = ProductInquiry::factory()->create([
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($normalUser)
            ->deleteJson("{$this->apiBase}/{$answeredInquiry->id}/reply");

        $response->assertForbidden();
    }
}
