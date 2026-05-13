<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 상품 1:1 문의 수정/삭제/답변 API Feature 테스트 (사용자)
 *
 * PUT  /api/modules/sirsoft-ecommerce/user/inquiries/{id}   - 문의 수정
 * DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{id} - 문의 삭제
 * POST /api/modules/sirsoft-ecommerce/user/inquiries/{id}/reply    - 답변 등록
 * PUT  /api/modules/sirsoft-ecommerce/user/inquiries/{id}/reply    - 답변 수정
 * DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{id}/reply  - 답변 삭제
 */
class UserProductInquiryControllerTest extends ModuleTestCase
{
    private \App\Models\User $user;

    private Product $product;

    private ProductInquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->product = Product::factory()->create();

        // inquiry board_slug 설정 (createReply/updateReply/deleteReply 등에서 필요)
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-inquiry-board');

        // 다른 모듈(sirsoft-board 등) 의 ServiceProvider 가 등록한 inquiry.* 필터가
        // ModuleTestCase snapshot 에 의해 잔존하여 test mock 과 충돌하는 cross-module
        // contamination 을 차단.
        foreach ([
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.update',
            'sirsoft-ecommerce.inquiry.get_settings',
            'sirsoft-ecommerce.inquiry.get_by_ids',
            'sirsoft-ecommerce.inquiry.store_validation_rules',
            'sirsoft-ecommerce.inquiry.update_validation_rules',
            'sirsoft-board.post.get_by_ids',
        ] as $hook) {
            HookManager::clearFilter($hook);
        }

        // 게시판 훅 모킹 — 빈 데이터 반환
        HookManager::addFilter(
            'sirsoft-board.post.get_by_ids',
            fn () => [],
            priority: 1
        );

        // 답변 작성 훅 모킹
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            fn () => ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'],
            priority: 1
        );

        $this->inquiry = ProductInquiry::factory()->create([
            'user_id'    => $this->user->id,
            'product_id' => $this->product->id,
            'is_answered' => false,
        ]);
    }

    // ========================================
    // update() — 문의 수정
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의를_수정할_수_없다(): void
    {
        $response = $this->putJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}",
            ['content' => '수정된 내용입니다 자세히 작성']
        );

        $response->assertUnauthorized();
    }

    #[Test]
    public function 본인_문의를_수정할_수_있다(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}",
                ['content' => '수정된 문의 내용입니다.']
            );

        $response->assertOk();
    }

    #[Test]
    public function 타인의_문의는_수정할_수_없다(): void
    {
        $otherUser = $this->createUser();

        $response = $this->actingAs($otherUser)
            ->putJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}",
                ['content' => '타인이 수정하려는 내용']
            );

        $response->assertStatus(403);
    }

    #[Test]
    public function 존재하지_않는_문의_수정_시_404를_반환한다(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson(
                '/api/modules/sirsoft-ecommerce/user/inquiries/99999',
                ['content' => '수정 내용입니다 자세하게 작성']
            );

        $response->assertNotFound();
    }

    // ========================================
    // destroy() — 문의 삭제
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의를_삭제할_수_없다(): void
    {
        $response = $this->deleteJson(
            "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}"
        );

        $response->assertUnauthorized();
    }

    #[Test]
    public function 본인_문의를_삭제할_수_있다(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}"
            );

        $response->assertOk();
        $this->assertDatabaseMissing('ecommerce_product_inquiries', ['id' => $this->inquiry->id]);
    }

    #[Test]
    public function 타인의_문의는_삭제할_수_없다(): void
    {
        $otherUser = $this->createUser();

        $response = $this->actingAs($otherUser)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}"
            );

        $response->assertStatus(403);
        $this->assertDatabaseHas('ecommerce_product_inquiries', ['id' => $this->inquiry->id]);
    }

    // ========================================
    // reply() — 답변 등록 (관리자 권한 필요)
    // ========================================

    #[Test]
    public function 권한_없는_사용자는_답변을_등록할_수_없다(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
                ['content' => '답변 내용입니다 친절하게 작성']
            );

        $response->assertStatus(403);
    }

    #[Test]
    public function 권한_있는_사용자는_답변을_등록할_수_있다(): void
    {
        $manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);

        $response = $this->actingAs($manager)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
                ['content' => '안녕하세요. 문의 주셔서 감사합니다.']
            );

        $response->assertStatus(201);
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id'          => $this->inquiry->id,
            'is_answered' => true,
        ]);
    }

    // ========================================
    // updateReply() — 답변 수정 (관리자 권한 필요)
    // ========================================

    #[Test]
    public function 권한_없는_사용자는_답변을_수정할_수_없다(): void
    {
        $answeredInquiry = ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$answeredInquiry->id}/reply",
                ['content' => '수정된 답변 내용입니다 자세히']
            );

        $response->assertStatus(403);
    }

    #[Test]
    public function 권한_있는_사용자는_답변을_수정할_수_있다(): void
    {
        $manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);
        $answeredInquiry = ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($manager)
            ->putJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$answeredInquiry->id}/reply",
                ['content' => '수정된 답변 내용입니다.']
            );

        $response->assertOk();
    }

    // ========================================
    // destroyReply() — 답변 삭제 (관리자 권한 필요)
    // ========================================

    #[Test]
    public function 권한_없는_사용자는_답변을_삭제할_수_없다(): void
    {
        $answeredInquiry = ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$answeredInquiry->id}/reply"
            );

        $response->assertStatus(403);
    }

    #[Test]
    public function 권한_있는_사용자는_답변을_삭제할_수_있다(): void
    {
        $manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);
        $answeredInquiry = ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($manager)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$answeredInquiry->id}/reply"
            );

        $response->assertOk();
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id'          => $answeredInquiry->id,
            'is_answered' => false,
        ]);
    }
}
