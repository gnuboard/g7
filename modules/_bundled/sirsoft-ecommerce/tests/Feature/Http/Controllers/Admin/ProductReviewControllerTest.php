<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
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

    /**
     * 읽기·수정·삭제 권한 관리자, 후기 작성 회원, 판매중 상품을 준비합니다.
     *
     * @return void 반환값 없음
     */
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
    }

    /**
     * 리뷰 하나를 생성하는 헬퍼
     *
     * 주문옵션당 활성 후기는 1건만 둘 수 있어(DB 유니크) 후기마다 새 주문옵션을 만든다.
     *
     * @param  array<string, mixed>  $overrides  기본값을 덮어쓸 후기 속성
     * @return ProductReview 생성된 후기
     */
    protected function createReview(array $overrides = []): ProductReview
    {
        return ProductReview::factory()->create(array_merge([
            'product_id' => $this->product->id,
            'order_option_id' => OrderOption::factory()->create(['product_id' => $this->product->id])->id,
            'user_id' => $this->reviewer->id,
            'status' => ReviewStatus::VISIBLE->value,
        ], $overrides));
    }

    // ========================================
    // index() — 목록 조회
    // ========================================

    /**
     * 관리자가 후기 목록을 조회하면 페이지 정보(meta)와 함께 전시·숨김 후기가 모두 내려오는지 확인합니다.
     *
     * @scenario operation=list
     *
     * @effects admin_review_list_returns_paginated_reviews
     *
     * @return void 반환값 없음
     */
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

    /**
     * 후기 읽기 권한이 없는 관리자의 목록 조회는 403 으로 막히는지 확인합니다.
     *
     * @scenario operation=list
     *
     * @effects admin_review_api_enforces_permission_per_action
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비로그인 상태의 목록 조회는 401 로 막히는지 확인합니다.
     *
     * @scenario operation=list
     *
     * @effects admin_review_api_requires_authentication
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_review_list_requires_authentication(): void
    {
        // When: 비로그인
        $response = $this->getJson($this->apiBase);

        // Then
        $response->assertUnauthorized();
    }

    /**
     * status=hidden 필터를 주면 숨김 후기만 내려오는지 확인합니다.
     *
     * @scenario operation=list
     *
     * @effects admin_review_list_filters_by_status_rating_reply_status
     *
     * @return void 반환값 없음
     */
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

    /**
     * rating=5 필터를 주면 별점 5 후기만 내려오는지 확인합니다.
     *
     * @scenario operation=list
     *
     * @effects admin_review_list_filters_by_status_rating_reply_status
     *
     * @return void 반환값 없음
     */
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

    /**
     * reply_status=unreplied 필터를 주면 답변 없는 후기만 내려오는지 확인합니다.
     *
     * @scenario operation=list
     *
     * @effects admin_review_list_filters_by_status_rating_reply_status
     *
     * @return void 반환값 없음
     */
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

    /**
     * 관리자가 후기 상세를 조회하면 요청한 후기가 내려오는지 확인합니다.
     *
     * @scenario operation=show
     *
     * @effects admin_review_detail_returns_requested_review
     *
     * @return void 반환값 없음
     */
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

    /**
     * 없는 후기 ID 의 상세 조회는 404 인지 확인합니다.
     *
     * @scenario operation=show
     *
     * @effects admin_review_missing_review_returns_404
     *
     * @return void 반환값 없음
     */
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

    /**
     * 전시 중 후기를 숨김으로 바꾸면 응답과 DB 모두 숨김 상태가 되는지 확인합니다.
     *
     * @scenario operation=update_status
     *
     * @effects admin_review_status_change_persisted
     *
     * @return void 반환값 없음
     */
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

    /**
     * 숨김 후기를 전시로 바꾸면 응답 상태가 전시가 되는지 확인합니다.
     *
     * @scenario operation=update_status
     *
     * @effects admin_review_status_change_persisted
     *
     * @return void 반환값 없음
     */
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

    /**
     * 허용되지 않은 상태 값으로 바꾸려 하면 422 인지 확인합니다.
     *
     * @scenario operation=update_status
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 읽기 권한만 있는 관리자의 상태 변경은 403 으로 막히는지 확인합니다.
     *
     * @scenario operation=update_status
     *
     * @effects admin_review_api_enforces_permission_per_action
     *
     * @return void 반환값 없음
     */
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

    /**
     * 답변 없는 후기에 첫 답변을 달면 내용·작성 관리자·replied_at 이 기록되고 reply_updated_at 은 비어 있는지 확인합니다.
     *
     * @scenario operation=save_reply
     *
     * @effects admin_review_reply_saved_with_first_reply_timestamp
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 답변이 있는 후기의 답변을 고치면 내용이 바뀌고 reply_updated_at 이 기록되는지 확인합니다.
     *
     * @scenario operation=save_reply
     *
     * @effects admin_review_reply_edit_records_updated_timestamp
     *
     * @return void 반환값 없음
     */
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

    /**
     * 빈 답변을 저장하려 하면 422 인지 확인합니다.
     *
     * @scenario operation=save_reply
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 2001자 답변을 저장하려 하면 422 인지 확인합니다.
     *
     * @scenario operation=save_reply
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 답변을 삭제하면 답변 내용·작성 관리자·두 시각이 모두 비워지는지 확인합니다.
     *
     * @scenario operation=delete_reply
     *
     * @effects admin_review_reply_cleared_on_delete
     *
     * @return void 반환값 없음
     */
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

    /**
     * 관리자가 후기를 삭제하면 소프트 삭제되는지 확인합니다.
     *
     * @scenario operation=delete_review
     *
     * @effects admin_review_soft_deleted_on_delete
     *
     * @return void 반환값 없음
     */
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

    /**
     * 삭제 권한이 없는(읽기·수정만 있는) 관리자의 후기 삭제는 403 으로 막히는지 확인합니다.
     *
     * @scenario operation=delete_review
     *
     * @effects admin_review_api_enforces_permission_per_action
     *
     * @return void 반환값 없음
     */
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

    /**
     * 없는 후기 ID 의 삭제는 404 인지 확인합니다.
     *
     * @scenario operation=delete_review
     *
     * @effects admin_review_missing_review_returns_404
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 숨김 처리가 모든 대상 후기에 반영되고 updated_count 가 대상 수인지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_bulk_status_change_applies_to_every_id
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 삭제가 모든 대상 후기를 소프트 삭제하고 deleted_count 가 대상 수인지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_bulk_delete_soft_deletes_every_id
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 처리 대상 ids 가 비어 있으면 ids 검증 오류(422)인지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 허용되지 않은 일괄 동작이면 action 검증 오류(422)인지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 상태 변경에 status 가 없으면 status 검증 오류(422)인지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 없는 후기 ID 가 일괄 대상에 들어 있으면 ids.0 검증 오류(422)인지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_invalid_input_rejected_with_422
     *
     * @return void 반환값 없음
     */
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

    /**
     * 읽기 권한만 있는 관리자의 일괄 처리는 403 으로 막히는지 확인합니다.
     *
     * @scenario operation=bulk
     *
     * @effects admin_review_api_enforces_permission_per_action
     *
     * @return void 반환값 없음
     */
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

    // ========================================
    // 이미지 download URL 직렬화 (숨김 리뷰 <img> 썸네일 렌더 계약)
    // ========================================

    /**
     * 숨김(HIDDEN) 리뷰의 관리자 상세 응답은 이미지에 한시 서명 download URL 을
     * 직렬화한다 — <img> 는 인증 헤더를 실을 수 없어, 무서명 URL 이면 관리자
     * 화면의 썸네일이 서빙 게이트(404)에 깨진다.
     *
     * @scenario resource=review_image, parent_state=restricted
     *
     * @effects admin_review_serializes_signed_download_url_for_hidden_review
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_admin_detail_serializes_signed_download_url_for_hidden_review(): void
    {
        // Given: 숨김 리뷰 + 이미지
        $review = $this->createReview(['status' => ReviewStatus::HIDDEN->value]);
        ProductReviewImage::factory()->create(['review_id' => $review->id]);

        // When
        $downloadUrl = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/{$review->id}")
            ->assertOk()
            ->json('data.images.0.download_url');

        // Then: 서명 쿼리가 실린 URL
        $this->assertIsString($downloadUrl);
        $this->assertStringContainsString('signature=', $downloadUrl, '숨김 리뷰 이미지는 서명 download URL 로 직렬화되어야 합니다');
    }

    /**
     * 전시중(VISIBLE) 리뷰의 이미지 download URL 은 종전과 동일한 무서명 URL 이다
     * (공개 콘텐츠에 만료성 URL 이 섞이는 회귀 방지 — CDN·캐시 안정성).
     *
     * @scenario resource=review_image, parent_state=public
     *
     * @effects visible_review_serializes_plain_download_url
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_admin_detail_serializes_plain_download_url_for_visible_review(): void
    {
        // Given: 전시중 리뷰 + 이미지
        $review = $this->createReview();
        $image = ProductReviewImage::factory()->create(['review_id' => $review->id]);

        // When
        $downloadUrl = $this->actingAs($this->adminUser)
            ->getJson("{$this->apiBase}/{$review->id}")
            ->assertOk()
            ->json('data.images.0.download_url');

        // Then: 기존 무서명 URL 그대로 (직접 URL 또는 API 폴백)
        $this->assertSame($image->download_url, $downloadUrl);
        $this->assertStringNotContainsString('signature=', (string) $downloadUrl);
    }
}
