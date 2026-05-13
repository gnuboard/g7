<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 관리자 상품 리뷰 관리 API Feature 테스트
 *
 * Admin ProductReviewController의 목록/상세/상태변경/답변/삭제/일괄처리 기능을 검증합니다.
 */
class ProductReviewControllerTest extends ModuleTestCase
{
    private string $apiBase = '/api/modules/sirsoft-ecommerce/admin/reviews';

    private User $adminUser;

    private Product $product;

    private User $reviewer;

    private OrderOption $orderOption;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.reviews.read',
            'sirsoft-ecommerce.reviews.update',
            'sirsoft-ecommerce.reviews.delete',
        ]);

        $this->reviewer = $this->createUser();
        $this->product = Product::factory()->onSale()->create();
        $this->orderOption = OrderOption::factory()->create([
            'product_id' => $this->product->id,
        ]);
    }

    /**
     * 리뷰 하나를 생성하는 헬퍼
     *
     * @param  array  $overrides
     * @return ProductReview
     */
    protected function createReview(array $overrides = []): ProductReview
    {
        return ProductReview::factory()->create(array_merge([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->reviewer->id,
            'status' => ReviewStatus::VISIBLE->value,
        ], $overrides));
    }

    // ========================================
    // index() — 목록 조회
    // ========================================

    #[Test]
    public function test_admin_can_fetch_review_list(): void
    {
        // Given
        $this->createReview();
        $this->createReview(['status' => ReviewStatus::HIDDEN->value]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->getJson($this->apiBase);

        // Then
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ])
            ->assertJsonPath('success', true);

        $this->assertGreaterThanOrEqual(2, count($response->json('data.data')));
    }

    #[Test]
    public function test_review_list_requires_read_permission(): void
    {
        // Given: 권한 없는 관리자
        $noPermUser = $this->createAdminUser([]);

        // When
        $response = $this->actingAs($noPermUser)
            ->getJson($this->apiBase);

        // Then
        $response->assertForbidden();
    }

    #[Test]
    public function test_review_list_requires_authentication(): void
    {
        // When: 비로그인
        $response = $this->getJson($this->apiBase);

        // Then
        $response->assertUnauthorized();
    }

    #[Test]
    public function test_review_list_can_filter_by_status(): void
    {
        // Given
        $this->createReview(['status' => ReviewStatus::VISIBLE->value]);
        $this->createReview(['status' => ReviewStatus::HIDDEN->value]);

        // When: hidden 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}?status=hidden");

        // Then: hidden만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        foreach ($data as $item) {
            $this->assertEquals('hidden', $item['status']);
        }
    }

    #[Test]
    public function test_review_list_can_filter_by_rating(): void
    {
        // Given
        $this->createReview(['rating' => 5]);
        $this->createReview(['rating' => 3]);

        // When: 별점 5 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}?rating=5");

        // Then
        $response->assertOk();
        $data = $response->json('data.data');
        foreach ($data as $item) {
            $this->assertEquals(5, $item['rating']);
        }
    }

    #[Test]
    public function test_review_list_can_filter_by_reply_status(): void
    {
        // Given: 답변 있는 리뷰, 없는 리뷰
        $this->createReview(['reply_content' => null, 'replied_at' => null]);
        $this->createReview([
            'reply_content' => '감사합니다.',
            'reply_admin_id' => $this->adminUser->id,
            'replied_at' => now(),
        ]);

        // When: 미답변 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}?reply_status=unreplied");

        // Then
        $response->assertOk();
        $data = $response->json('data.data');
        foreach ($data as $item) {
            $this->assertFalse($item['has_reply']);
        }
    }

    // ========================================
    // show() — 상세 조회
    // ========================================

    #[Test]
    public function test_admin_can_fetch_review_detail(): void
    {
        // Given
        $review = $this->createReview();

        // When
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/{$review->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $review->id);
    }

    #[Test]
    public function test_show_returns_404_for_nonexistent_review(): void
    {
        // When
        $response = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/99999");

        // Then
        $response->assertNotFound();
    }

    // ========================================
    // updateStatus() — 상태 변경
    // ========================================

    #[Test]
    public function test_admin_can_update_review_status_to_hidden(): void
    {
        // Given
        $review = $this->createReview(['status' => ReviewStatus::VISIBLE->value]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson("{$this->apiBase}/{$review->id}/status", ['status' => 'hidden']);

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'hidden');

        $this->assertEquals(ReviewStatus::HIDDEN->value, $review->fresh()->status->value);
    }

    #[Test]
    public function test_admin_can_update_review_status_to_visible(): void
    {
        // Given
        $review = $this->createReview(['status' => ReviewStatus::HIDDEN->value]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->patchJson("{$this->apiBase}/{$review->id}/status", ['status' => 'visible']);

        // Then
        $response->assertOk()
            ->assertJsonPath('data.status', 'visible');
    }

    #[Test]
    public function test_update_status_validates_invalid_value(): void
    {
        // Given
        $review = $this->createReview();

        // When: 유효하지 않은 status 값
        $response = $this->actingAs($this->adminUser)
            ->patchJson("{$this->apiBase}/{$review->id}/status", ['status' => 'invalid']);

        // Then
        $response->assertUnprocessable();
    }

    #[Test]
    public function test_update_status_requires_update_permission(): void
    {
        // Given: read 권한만 있는 관리자
        $readOnlyUser = $this->createAdminUser(['sirsoft-ecommerce.reviews.read']);
        $review = $this->createReview();

        // When
        $response = $this->actingAs($readOnlyUser)
            ->patchJson("{$this->apiBase}/{$review->id}/status", ['status' => 'hidden']);

        // Then
        $response->assertForbidden();
    }

    // ========================================
    // storeReply() — 판매자 답변 등록/수정
    // ========================================

    #[Test]
    public function test_admin_can_store_reply(): void
    {
        // Given
        $review = $this->createReview(['reply_content' => null, 'replied_at' => null]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/{$review->id}/reply", [
                'reply_content' => '소중한 리뷰 감사합니다.',
                'reply_content_mode' => 'text',
            ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_reply', true);

        $fresh = $review->fresh();
        $this->assertEquals('소중한 리뷰 감사합니다.', $fresh->reply_content);
        $this->assertEquals($this->adminUser->id, $fresh->reply_admin_id);
        $this->assertNotNull($fresh->replied_at);
        $this->assertNull($fresh->reply_updated_at);
    }

    #[Test]
    public function test_admin_can_update_existing_reply(): void
    {
        // Given: 이미 답변이 있는 리뷰
        $review = $this->createReview([
            'reply_content' => '기존 답변입니다.',
            'reply_admin_id' => $this->adminUser->id,
            'replied_at' => now()->subDay(),
        ]);

        // When: 답변 수정
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/{$review->id}/reply", [
                'reply_content' => '수정된 답변입니다.',
            ]);

        // Then
        $response->assertOk();
        $fresh = $review->fresh();
        $this->assertEquals('수정된 답변입니다.', $fresh->reply_content);
        $this->assertNotNull($fresh->reply_updated_at);
    }

    #[Test]
    public function test_store_reply_validates_empty_content(): void
    {
        // Given
        $review = $this->createReview();

        // When: 빈 답변
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/{$review->id}/reply", [
                'reply_content' => '',
            ]);

        // Then
        $response->assertUnprocessable();
    }

    #[Test]
    public function test_store_reply_validates_max_length(): void
    {
        // Given
        $review = $this->createReview();

        // When: 2001자 초과
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/{$review->id}/reply", [
                'reply_content' => str_repeat('a', 2001),
            ]);

        // Then
        $response->assertUnprocessable();
    }

    // ========================================
    // destroyReply() — 판매자 답변 삭제
    // ========================================

    #[Test]
    public function test_admin_can_destroy_reply(): void
    {
        // Given: 답변이 있는 리뷰
        $review = $this->createReview([
            'reply_content' => '삭제될 답변입니다.',
            'reply_admin_id' => $this->adminUser->id,
            'replied_at' => now(),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$review->id}/reply");

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_reply', false);

        $fresh = $review->fresh();
        $this->assertNull($fresh->reply_content);
        $this->assertNull($fresh->reply_admin_id);
        $this->assertNull($fresh->replied_at);
        $this->assertNull($fresh->reply_updated_at);
    }

    // ========================================
    // destroy() — 리뷰 삭제
    // ========================================

    #[Test]
    public function test_admin_can_delete_review(): void
    {
        // Given
        $review = $this->createReview();
        $reviewId = $review->id;

        // When
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/{$reviewId}");

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('ecommerce_product_reviews', ['id' => $reviewId]);
    }

    #[Test]
    public function test_delete_review_requires_delete_permission(): void
    {
        // Given: read + update 권한만 있는 관리자 (delete 없음)
        $noDeleteUser = $this->createAdminUser([
            'sirsoft-ecommerce.reviews.read',
            'sirsoft-ecommerce.reviews.update',
        ]);
        $review = $this->createReview();

        // When
        $response = $this->actingAs($noDeleteUser)
            ->deleteJson("{$this->apiBase}/{$review->id}");

        // Then
        $response->assertForbidden();
    }

    #[Test]
    public function test_delete_review_returns_404_for_nonexistent(): void
    {
        // When
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("{$this->apiBase}/99999");

        // Then
        $response->assertNotFound();
    }

    // ========================================
    // bulk() — 일괄 처리
    // ========================================

    #[Test]
    public function test_admin_can_bulk_change_status(): void
    {
        // Given
        $review1 = $this->createReview(['status' => ReviewStatus::VISIBLE->value]);
        $review2 = $this->createReview(['status' => ReviewStatus::VISIBLE->value]);

        // When: 일괄 숨김 처리
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => [$review1->id, $review2->id],
                'action' => 'change_status',
                'status' => 'hidden',
            ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 2);

        $this->assertEquals(ReviewStatus::HIDDEN->value, $review1->fresh()->status->value);
        $this->assertEquals(ReviewStatus::HIDDEN->value, $review2->fresh()->status->value);
    }

    #[Test]
    public function test_admin_can_bulk_delete(): void
    {
        // Given
        $review1 = $this->createReview();
        $review2 = $this->createReview();
        $ids = [$review1->id, $review2->id];

        // When
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => $ids,
                'action' => 'delete',
            ]);

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertSoftDeleted('ecommerce_product_reviews', ['id' => $review1->id]);
        $this->assertSoftDeleted('ecommerce_product_reviews', ['id' => $review2->id]);
    }

    #[Test]
    public function test_bulk_validates_empty_ids(): void
    {
        // When: 빈 ids 배열
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => [],
                'action' => 'delete',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
    }

    #[Test]
    public function test_bulk_validates_invalid_action(): void
    {
        // Given
        $review = $this->createReview();

        // When: 유효하지 않은 action
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => [$review->id],
                'action' => 'invalid_action',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);
    }

    #[Test]
    public function test_bulk_change_status_requires_status_field(): void
    {
        // Given
        $review = $this->createReview();

        // When: change_status인데 status 필드 누락
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => [$review->id],
                'action' => 'change_status',
                // status 누락
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    #[Test]
    public function test_bulk_validates_nonexistent_review_ids(): void
    {
        // When: 존재하지 않는 ID
        $response = $this->actingAs($this->adminUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => [99999],
                'action' => 'delete',
            ]);

        // Then
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ids.0']);
    }

    #[Test]
    public function test_bulk_requires_update_permission(): void
    {
        // Given: read 권한만
        $readOnlyUser = $this->createAdminUser(['sirsoft-ecommerce.reviews.read']);
        $review = $this->createReview();

        // When
        $response = $this->actingAs($readOnlyUser)
            ->postJson("{$this->apiBase}/bulk", [
                'ids' => [$review->id],
                'action' => 'change_status',
                'status' => 'hidden',
            ]);

        // Then
        $response->assertForbidden();
    }
}
