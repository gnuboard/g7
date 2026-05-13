<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 마이페이지 문의 목록 API Feature 테스트
 *
 * GET /api/modules/sirsoft-ecommerce/user/inquiries
 * - 인증 필요, 본인 문의만 조회
 * - is_answered 필터 지원
 * - search (상품명 검색) 지원
 */
class UserInquiryControllerTest extends ModuleTestCase
{
    private \App\Models\User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();

        // sirsoft-board.post.get_by_ids 훅 모킹 — 빈 배열 반환
        HookManager::addFilter(
            'sirsoft-board.post.get_by_ids',
            fn () => [],
            priority: 1
        );

        // sirsoft-board.board.get_secret_mode 훅 모킹
        HookManager::addFilter(
            'sirsoft-board.board.get_secret_mode',
            fn () => 'disabled',
            priority: 1
        );
    }

    // ========================================
    // 기본 조회 테스트
    // ========================================

    #[Test]
    public function 비인증_사용자는_문의_목록에_접근할_수_없다(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        $response->assertUnauthorized();
    }

    #[Test]
    public function 인증된_사용자는_본인_문의_목록을_조회할_수_있다(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create([
            'user_id'    => $this->user->id,
            'product_id' => $product->id,
            'is_answered' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertCount(1, $data['items']);
    }

    #[Test]
    public function 다른_사용자의_문의는_조회되지_않는다(): void
    {
        $otherUser = $this->createUser();
        $product = Product::factory()->create();
        ProductInquiry::factory()->create([
            'user_id'    => $otherUser->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.items'));
    }

    // ========================================
    // is_answered 필터 테스트
    // ========================================

    #[Test]
    public function is_answered_1_필터로_답변완료_문의만_조회된다(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $product->id,
            'is_answered' => true,
        ]);
        ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $product->id,
            'is_answered' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries?is_answered=1');

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['is_answered']);
    }

    #[Test]
    public function is_answered_0_필터로_답변대기_문의만_조회된다(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $product->id,
            'is_answered' => true,
        ]);
        ProductInquiry::factory()->create([
            'user_id'     => $this->user->id,
            'product_id'  => $product->id,
            'is_answered' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries?is_answered=0');

        $response->assertOk();
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['is_answered']);
    }

    // ========================================
    // search 필터 테스트 (상품명 스냅샷 검색)
    // ========================================

    #[Test]
    public function search_파라미터로_상품명_스냅샷을_검색할_수_있다(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create([
            'user_id'               => $this->user->id,
            'product_id'            => $product->id,
            'product_name_snapshot' => ['ko' => '사과 1박스', 'en' => 'Apple Box'],
        ]);
        ProductInquiry::factory()->create([
            'user_id'               => $this->user->id,
            'product_id'            => $product->id,
            'product_name_snapshot' => ['ko' => 'USB 충전 케이블', 'en' => 'USB Cable'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries?search=사과');

        $response->assertOk();
        $items = $response->json('data.items');
        // 검색 결과가 1건이어야 함 (USB 케이블은 제외)
        $this->assertCount(1, $items);
        // product_name은 로케일에 따라 ko 또는 en이 반환될 수 있으므로 둘 중 하나 포함 확인
        $productName = $items[0]['product_name'];
        $this->assertTrue(
            str_contains($productName, '사과') || str_contains($productName, 'Apple'),
            "product_name '{$productName}'에 '사과' 또는 'Apple'이 포함되어야 합니다."
        );
    }

    #[Test]
    public function search_파라미터가_없으면_전체_문의가_조회된다(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->count(3)->create([
            'user_id'    => $this->user->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        $response->assertOk();
        $this->assertCount(3, $response->json('data.items'));
    }

    // ========================================
    // 페이지네이션 메타 테스트
    // ========================================

    #[Test]
    public function 응답에_페이지네이션_메타가_포함된다(): void
    {
        $product = Product::factory()->create();
        ProductInquiry::factory()->create([
            'user_id'    => $this->user->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        $response->assertOk();
        $meta = $response->json('data.meta');
        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('last_page', $meta);
    }

    #[Test]
    public function per_page_기본값은_10이다(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-ecommerce/user/inquiries');

        $response->assertOk();
        $this->assertEquals(10, $response->json('data.meta.per_page'));
    }
}
