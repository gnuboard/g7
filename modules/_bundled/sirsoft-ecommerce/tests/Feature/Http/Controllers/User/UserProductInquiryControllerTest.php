<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Extension\HookManager;
use App\Models\User;
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
    private User $user;

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
            'sirsoft-ecommerce.inquiry.count_replies',
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

        // 잔여 답변 수 훅 모킹 — 기본값 0 (답변 삭제 시 답변완료 해제 경로)
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.count_replies',
            fn () => 0,
            priority: 1
        );

        $this->inquiry = ProductInquiry::factory()->create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'is_answered' => false,
        ]);
    }

    // ========================================
    // index() — 문의 목록 (마이페이지)
    // ========================================

    /**
     * 마이페이지 문의 목록의 상품 링크가 상품 id 가 아닌 product_code 로 나간다 (#450).
     *
     * 회귀: getUserInquiries 의 product.url 이 id 로 하드코딩되어 있어 클릭 시
     * /shop/products/{id} 로 이동하던 버그. product_code 기준으로 수정.
     */
    #[Test]
    public function 문의_목록의_상품_링크는_product_code_로_나간다(): void
    {
        // Given: product_code 를 가진 상품에 대한 본인 문의 (setUp 의 $this->inquiry)
        $this->assertNotEmpty($this->product->product_code);

        // When: 마이페이지 문의 목록 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        // Then: 응답의 product.url 과 product.product_code 가 상품코드 기준
        // (getUserInquiries 응답 구조는 data.items[] + data.meta)
        $response->assertOk();
        $item = collect($response->json('data.items'))
            ->firstWhere('id', $this->inquiry->id);

        $this->assertNotNull($item, '본인 문의가 목록에 포함되어야 합니다.');
        $this->assertSame($this->product->product_code, $item['product']['product_code']);
        $this->assertStringContainsString(
            '/products/'.$this->product->product_code,
            $item['product']['url'],
            '상품 링크는 product_code 기준이어야 합니다.'
        );
        $this->assertStringNotContainsString(
            '/products/'.$this->product->id,
            $item['product']['url'],
            '상품 링크에 상품 id 가 노출되면 안 됩니다.'
        );
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
        // SoftDeletes 도입(#107 후속) — 게시판 복원 경로와의 대칭을 위해 피벗은 소프트 삭제된다
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $this->inquiry->id]);
    }

    /**
     * SoftDeletes 계약 명시 — 삭제된 피벗 행은 DB 에 남되(deleted_at 세팅)
     * 기본 스코프 조회에서는 제외되어야 한다. 게시판에서 질문 글을 복원하는
     * 경로가 피벗을 되살릴 수 있어야 하므로 하드 삭제가 아니다.
     */
    #[Test]
    public function 문의_삭제_시_피벗이_소프트_삭제된다(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}"
            );

        $response->assertOk();

        // 행 자체는 잔존 + deleted_at 세팅
        $this->assertDatabaseHas('ecommerce_product_inquiries', ['id' => $this->inquiry->id]);
        $this->assertSoftDeleted('ecommerce_product_inquiries', ['id' => $this->inquiry->id]);

        // 기본 스코프에서는 보이지 않고, withTrashed 로만 조회된다
        $this->assertNull(ProductInquiry::find($this->inquiry->id));
        $trashed = ProductInquiry::withTrashed()->find($this->inquiry->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
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
            'id' => $this->inquiry->id,
            'is_answered' => true,
        ]);
    }

    /**
     * 단일 답변 정책 (user 경로) — 이미 답변완료인 문의에는 재등록이 422 로
     * 거부되고, 거부 시 게시판 create 훅은 호출 자체가 없어야 한다.
     * admin 경로와 동일 가드가 user 경로에도 대칭 적용되는지 고정한다.
     */
    #[Test]
    public function 이미_답변된_문의에_답변_재등록_시_422를_반환한다(): void
    {
        $manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);
        $answeredInquiry = ProductInquiry::factory()->answered()->create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
        ]);

        // setUp 의 기본 모킹을 걷어내고 호출 횟수를 계수하는 훅으로 교체
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.create');
        $createCalls = 0;
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            function () use (&$createCalls) {
                $createCalls++;

                return ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'];
            },
            priority: 1
        );

        $response = $this->actingAs($manager)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$answeredInquiry->id}/reply",
                ['content' => '중복 답변 재등록 시도 내용입니다.']
            );

        // user 컨트롤러는 operation_failed_reason(':reason') 래핑으로 사유 원문을 그대로 노출한다
        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-ecommerce::messages.inquiries.reply_already_exists'),
            $response->json('message')
        );
        $this->assertSame(0, $createCalls, '재등록 거부 시 게시판 create 훅은 호출되지 않아야 합니다.');
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id' => $answeredInquiry->id,
            'is_answered' => true,
        ]);
    }

    /**
     * 경합 경로의 중복도 사유가 보존된다 (회귀 — 운영 실측 제보).
     *
     * 동시 등록 경합에서는 피벗 is_answered 가 아직 false 라 1차 방어를 통과하고,
     * 게시판 리스너의 2차 방어(실데이터 기준)가 중복을 감지한다. 종전에는 리스너가
     * null 을 돌려줘 서비스가 훅 무응답과 구분하지 못했고, "답변 등록에 실패했습니다"
     * (reply_failed, 500) 로 위장됐다. 리스너는 중복 마커(['duplicate' => true])를
     * 돌려주고 서비스는 이를 reply_already_exists(422) 로 변환해야 한다.
     */
    #[Test]
    public function 경합_중복_감지_시에도_이미_답변됨_사유가_노출된다(): void
    {
        $manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);

        // 피벗은 미답변(1차 방어 통과) — 게시판 실데이터에만 답변이 존재하는 경합 상태 재현
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.create');
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            fn () => ['duplicate' => true],
            priority: 1
        );

        $response = $this->actingAs($manager)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
                ['content' => '경합 중복 답변 등록 시도 내용입니다.']
            );

        $response->assertStatus(422);
        $this->assertSame(
            __('sirsoft-ecommerce::messages.inquiries.reply_already_exists'),
            $response->json('message'),
            '경합 중복도 일반 실패가 아니라 "이미 등록된 답변" 사유로 안내되어야 합니다.'
        );
    }

    /**
     * 서비스가 클라이언트 IP 를 게시판 훅 payload 에 주입한다 (답변 경로).
     *
     * 게시판 Listener 는 `request()->ip()` 를 참조하지 않고 payload 의 ip_address 만 쓴다.
     * 그 경계는 **요청 경계인 서비스가 IP 를 실어 보낼 때만** 성립하는데, 생성 경로
     * (`createInquiry`)와 답변 경로(`createReply`)는 서로 다른 메서드라 주입 코드도 각각
     * 있다. 생성 경로만 단언하면 답변 경로의 주입을 지워도 스위트가 green 이고 답변 IP 가
     * 조용히 0.0.0.0 으로 기록된다.
     *
     * @effects service_injects_client_ip_into_reply_hook_payload
     */
    #[Test]
    public function 서비스가_답변_훅_payload_에_클라이언트_ip_를_주입한다(): void
    {
        $manager = $this->createAdminUser(['sirsoft-ecommerce.inquiries.update']);

        // setUp 의 기본 모킹을 걷어내고 payload 를 캡처하는 훅으로 교체한다.
        HookManager::clearFilter('sirsoft-ecommerce.inquiry.create');

        $capturedIp = null;
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            function ($result, $slug, $data) use (&$capturedIp) {
                $capturedIp = $data['ip_address'] ?? null;

                return ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'];
            },
            priority: 1
        );

        // 요청 IP 를 비-0.0.0.0 으로 세팅한다 — 기본 request 로는 폴백값과 구분되지 않아
        // 주입 코드를 지워도 단언이 통과한다(무증상 green).
        $this->actingAs($manager)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$this->inquiry->id}/reply",
                ['content' => '답변 내용입니다 친절하게 작성']
            )
            ->assertStatus(201);

        $this->assertSame(
            '203.0.113.77',
            $capturedIp,
            '서비스가 답변 작성 시에도 요청 IP 를 게시판 훅 payload 로 주입해야 합니다'
        );
    }

    // ========================================
    // updateReply() — 답변 수정 (관리자 권한 필요)
    // ========================================

    #[Test]
    public function 권한_없는_사용자는_답변을_수정할_수_없다(): void
    {
        $answeredInquiry = ProductInquiry::factory()->create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
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
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
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
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
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
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'is_answered' => true,
        ]);

        $response = $this->actingAs($manager)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/inquiries/{$answeredInquiry->id}/reply"
            );

        $response->assertOk();
        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'id' => $answeredInquiry->id,
            'is_answered' => false,
        ]);
    }
}
