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

    #[Test]
    public function 존재하지_않는_상품_조회_시_빈_목록을_반환한다(): void
    {
        // board_slug 없으면 상품 존재 여부와 무관하게 빈 목록 반환
        app(EcommerceSettingsService::class)->setSetting('inquiry.board_slug', null);

        $response = $this->getJson(
            '/api/modules/sirsoft-ecommerce/products/99999/inquiries'
        );

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'items' => [],
                    'meta' => ['inquiry_available' => false],
                ],
            ]);
    }

    // ========================================
    // store() — 문의 작성
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의를_작성할_수_없다(): void
    {
        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/products/{$this->product->id}/inquiries",
            ['content' => '문의 내용입니다.']
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
                ['content' => '문의 내용입니다.']
            );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id'],
            ]);

        $this->assertDatabaseHas('ecommerce_product_inquiries', [
            'product_id' => $this->product->id,
            'user_id'    => $user->id,
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
