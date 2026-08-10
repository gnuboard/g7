<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 공개 상품 1:1 문의 API Feature 테스트
 *
 * GET  /api/modules/sirsoft-ecommerce/products/{productId}/inquiries - 문의 목록 조회 (비회원 포함)
 * POST /api/modules/sirsoft-ecommerce/products/{productId}/inquiries - 문의 작성 (회원 전용)
 */
class PublicProductInquiryControllerTest extends ModuleTestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create();

        // 다른 모듈(sirsoft-board 등) 의 ServiceProvider 가 등록한 inquiry.* 필터가
        // ModuleTestCase snapshot 에 의해 잔존하여 test mock 과 충돌하는 cross-module
        // contamination 을 차단.
        foreach ([
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.get_settings',
            'sirsoft-ecommerce.inquiry.get_by_ids',
            'sirsoft-ecommerce.inquiry.store_validation_rules',
            'sirsoft-ecommerce.inquiry.update_validation_rules',
        ] as $hook) {
            HookManager::clearFilter($hook);
        }
    }

    // ========================================
    // index() — 문의 목록 조회
    // ========================================

    #[Test]
    public function 비회원도_문의_목록을_조회할_수_있다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [],
            priority: 1
        );

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'items',
                    'meta' => ['inquiry_available', 'board_settings', 'total', 'current_page', 'per_page', 'last_page'],
                ],
            ]);
    }

    /**
     * 작성자 이름은 회원 계정에서 배치로 모아 온 뒤 마스킹되어 내려간다.
     *
     * 이름은 행마다 조회하면 N+1 이 되므로 표시할 ID 만 모아 한 번에 읽는다
     * (`UserRepositoryInterface::getNamesByIds`). 이 경로가 비면 목록의 작성자
     * 칸이 게시글 스냅샷 이름으로 조용히 대체되어, 개명 후에도 옛 이름이 남는다.
     */
    #[Test]
    public function 문의_목록의_작성자_이름은_회원_계정에서_읽어_마스킹된다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        $user = $this->createUser();
        $user->forceFill(['name' => '홍길동'])->save();

        $pivot = ProductInquiry::create([
            'product_id' => $this->product->id,
            'inquirable_type' => 'board_post',
            'inquirable_id' => 777,
            'user_id' => $user->id,
        ]);

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_settings',
            fn ($defaults) => $defaults,
            priority: 1
        );
        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            fn () => [[
                'id' => $pivot->inquirable_id,
                'user_id' => $user->id,
                // 게시글 스냅샷에는 다른 이름이 들어 있다 — 회원 계정 이름이 이겨야 한다.
                'author_name' => '스냅샷이름',
                'title' => '문의 제목',
                'is_secret' => false,
            ]],
            priority: 1
        );

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk();

        // 존재 확정 후 값 비교 — 항목이 비면 이름 비교는 아무것도 증명하지 못한다.
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('홍*동', $items[0]['author_name']);
    }

    #[Test]
    public function board_slug_미설정_시_빈_목록과_inquiry_available_false를_반환한다(): void
    {
        // board_slug를 null로 초기화
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', null);

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries"
        );

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'items' => [],
                    'meta' => [
                        'inquiry_available' => false,
                        'total' => 0,
                    ],
                ],
            ]);
    }

    /**
     * 존재하지 않는 상품은 404 를 반환한다.
     *
     * 상품 파라미터는 라우트 모델 바인딩(product_code 또는 id)으로 해석되므로, 없는 상품은
     * 컨트롤러에 진입하기 전에 404 가 된다 (#450 상품코드 라우팅 전환). 문의 게시판 설정 여부와
     * 무관하다 — 설정 미비로 빈 목록을 주는 경우는 **존재하는 상품**에 한하며 그 계약은
     * `board_slug_미설정_시_빈_목록과_inquiry_available_false를_반환한다` 가 담당한다.
     */
    #[Test]
    public function 존재하지_않는_상품_조회_시_404를_반환한다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', null);

        $this->getJson('/api/modules/sirsoft-ecommerce/products/99999/inquiries')
            ->assertNotFound();
    }

    // ========================================
    // store() — 문의 작성
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의를_작성할_수_없다(): void
    {
        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
            ['content' => '문의 내용입니다 자세하게']
        );

        $response->assertUnauthorized();
    }

    #[Test]
    public function 로그인_사용자는_문의를_작성할_수_있다(): void
    {
        $user = $this->createUser();

        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', 'test-board');

        HookManager::addFilter(
            'sirsoft-ecommerce.inquiry.create',
            fn () => ['post_id' => 999, 'inquirable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post'],
            priority: 1
        );

        $response = $this->actingAs($user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
                ['content' => '문의 내용입니다 자세하게']
            );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id'],
            ]);

        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'product_id' => $this->product->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function 필수_필드_content_없이_요청_시_422를_반환한다(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
                [] // content 누락
            );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }
}
