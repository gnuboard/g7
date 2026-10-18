<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Contracts\Extension\StorageInterface;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewImageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 사용자 리뷰 이미지 API Feature 테스트
 *
 * ReviewImageController의 업로드(store) / 삭제(destroy) 기능을 검증합니다.
 * StorageInterface는 Mock으로 대체하여 실제 파일 저장 없이 테스트합니다.
 */
class ReviewImageControllerTest extends ModuleTestCase
{
    private User $user;

    private Product $product;

    private OrderOption $orderOption;

    private ProductReview $review;

    /** @var MockInterface&StorageInterface */
    private $storageMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();

        // user/reviews 라우트에 필요한 user-products.read 권한 부여
        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-ecommerce.user-products.read'],
            [
                'name' => ['ko' => '상품 조회', 'en' => 'View Products'],
                'type' => PermissionType::User,
            ]
        );
        $userRole = Role::where('identifier', 'user')->first();
        $userRole->permissions()->syncWithoutDetaching([$permission->id]);

        $this->product = Product::factory()->onSale()->create();

        $order = Order::factory()->confirmed()->forUser($this->user)->create();
        $this->orderOption = OrderOption::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);

        $this->review = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        // StorageInterface Mock 바인딩 (실제 파일 시스템 사용 안 함)
        $this->storageMock = Mockery::mock(StorageInterface::class);
        $this->storageMock->allows('put')->andReturn(true)->byDefault();
        $this->storageMock->allows('delete')->andReturn(true)->byDefault();
        $this->storageMock->allows('exists')->andReturn(true)->byDefault();
        $this->storageMock->allows('getDisk')->andReturn('local')->byDefault();
        // url 메서드는 더 이상 사용하지 않음 (download_url로 대체)

        $this->app->instance(
            StorageInterface::class,
            $this->storageMock
        );

        // ProductReviewImageService에 Mock Storage 명시적 주입
        $this->app->when(ProductReviewImageService::class)
            ->needs(StorageInterface::class)
            ->give(fn () => $this->storageMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // store() — 이미지 업로드
    // ========================================

    #[Test]
    public function test_user_can_upload_review_image(): void
    {
        // Given
        $file = UploadedFile::fake()->image('review.jpg', 800, 600);

        // When
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images",
                ['image' => $file]
            );

        // Then
        $response->assertStatus(201)
            ->assertJsonPath('data.review_id', $this->review->id);

        $this->assertDatabaseHas('ecommerce_product_review_images', [
            'review_id' => $this->review->id,
        ]);
    }

    #[Test]
    public function test_upload_requires_image_field(): void
    {
        // When: image 필드 없음
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images",
                []
            );

        // Then
        $response->assertUnprocessable();
    }

    #[Test]
    public function test_upload_rejects_non_image_file(): void
    {
        // Given: PDF 파일
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        // When
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images",
                ['image' => $file]
            );

        // Then
        $response->assertUnprocessable();
    }

    #[Test]
    public function test_upload_rejects_oversized_file(): void
    {
        // Given: 10MB 초과 (10241KB)
        $file = UploadedFile::fake()->image('big.jpg')->size(10241);

        // When
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images",
                ['image' => $file]
            );

        // Then
        $response->assertUnprocessable();
    }

    #[Test]
    public function test_upload_forbidden_for_others_review(): void
    {
        // Given: 다른 사용자의 리뷰
        $anotherUser = $this->createUser();
        $anotherOrder = Order::factory()->confirmed()->forUser($anotherUser)->create();
        $anotherOption = OrderOption::factory()->create([
            'order_id' => $anotherOrder->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);
        $othersReview = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $anotherOption->id,
            'user_id' => $anotherUser->id,
        ]);

        $file = UploadedFile::fake()->image('review.jpg');

        // When: 현재 사용자가 다른 사람 리뷰에 업로드 시도
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$othersReview->id}/images",
                ['image' => $file]
            );

        // Then
        $response->assertForbidden();
    }

    #[Test]
    public function test_upload_returns_422_when_max_images_exceeded(): void
    {
        // Given: 이미 최대 5장 업로드된 상태
        ProductReviewImage::factory()->count(5)->create([
            'review_id' => $this->review->id,
        ]);

        $file = UploadedFile::fake()->image('extra.jpg');

        // When: 6번째 이미지 업로드 시도
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images",
                ['image' => $file]
            );

        // Then: RuntimeException → 422
        $response->assertStatus(422);
    }

    #[Test]
    public function test_upload_requires_authentication(): void
    {
        // Given
        $file = UploadedFile::fake()->image('review.jpg');

        // When: 비로그인
        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images",
            ['image' => $file]
        );

        // Then
        $response->assertUnauthorized();
    }

    // ========================================
    // destroy() — 이미지 삭제
    // ========================================

    #[Test]
    public function test_user_can_delete_own_review_image(): void
    {
        // Given
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);

        // When
        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images/{$image->id}"
            );

        // Then
        $response->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('ecommerce_product_review_images', ['id' => $image->id]);
    }

    #[Test]
    public function test_delete_forbidden_for_others_review_image(): void
    {
        // Given: 다른 사용자의 리뷰 이미지
        $anotherUser = $this->createUser();
        $anotherOrder = Order::factory()->confirmed()->forUser($anotherUser)->create();
        $anotherOption = OrderOption::factory()->create([
            'order_id' => $anotherOrder->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);
        $othersReview = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $anotherOption->id,
            'user_id' => $anotherUser->id,
        ]);
        $othersImage = ProductReviewImage::factory()->create([
            'review_id' => $othersReview->id,
        ]);

        // When: 현재 사용자가 다른 사람 이미지 삭제 시도
        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$othersReview->id}/images/{$othersImage->id}"
            );

        // Then
        $response->assertForbidden();
    }

    #[Test]
    public function test_delete_returns_404_when_image_belongs_to_different_review(): void
    {
        // Given: 다른 리뷰에 속한 이미지
        $anotherOrder = Order::factory()->confirmed()->forUser($this->user)->create();
        $anotherOption = OrderOption::factory()->create([
            'order_id' => $anotherOrder->id,
            'product_id' => $this->product->id,
            'option_status' => OrderStatusEnum::CONFIRMED,
        ]);
        $anotherReview = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $anotherOption->id,
            'user_id' => $this->user->id,
        ]);
        $imageFromAnotherReview = ProductReviewImage::factory()->create([
            'review_id' => $anotherReview->id,
        ]);

        // When: 내 리뷰 URL에 다른 리뷰 이미지 ID를 전달
        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images/{$imageFromAnotherReview->id}"
            );

        // Then
        $response->assertNotFound();
    }

    #[Test]
    public function test_delete_returns_404_for_nonexistent_image(): void
    {
        // When
        $response = $this->actingAs($this->user)
            ->deleteJson(
                "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images/99999"
            );

        // Then
        $response->assertNotFound();
    }

    #[Test]
    public function test_delete_requires_authentication(): void
    {
        // Given
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);

        // When: 비로그인
        $response = $this->deleteJson(
            "/api/modules/sirsoft-ecommerce/user/reviews/{$this->review->id}/images/{$image->id}"
        );

        // Then
        $response->assertUnauthorized();
    }

    // ========================================
    // download() — 해시 기반 공개 서빙 (KVE-2026-1914 S-2)
    // ========================================

    /**
     * @scenario resource=review_image, parent_state=restricted
     *
     * @effects hidden_review_image_download_blocked
     */
    #[Test]
    public function test_download_blocks_hidden_review_image(): void
    {
        // Given: 관리자가 숨긴(HIDDEN) 리뷰의 이미지
        $hiddenReview = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::HIDDEN->value,
        ]);
        $image = ProductReviewImage::factory()->create([
            'review_id' => $hiddenReview->id,
        ]);

        // When: 해시로 이미지 다운로드 시도
        $response = $this->get(
            "/api/modules/sirsoft-ecommerce/review-image/{$image->hash}"
        );

        // Then: 숨김 리뷰 이미지는 서빙 차단(404)
        $response->assertNotFound();
    }

    /**
     * @scenario resource=review_image, parent_state=public
     *
     * @effects visible_review_image_download_still_served
     */
    #[Test]
    public function test_download_serves_visible_review_image(): void
    {
        // Given: 전시중(VISIBLE) 리뷰의 이미지 + storage response mock
        $image = ProductReviewImage::factory()->create([
            'review_id' => $this->review->id,
        ]);
        $this->storageMock->allows('response')->andReturn(
            new StreamedResponse(fn () => null, 200)
        );

        // When
        $response = $this->get(
            "/api/modules/sirsoft-ecommerce/review-image/{$image->hash}"
        );

        // Then: VISIBLE 리뷰 이미지는 상태 게이트를 통과(404 아님)
        $this->assertNotSame(404, $response->getStatusCode(), 'VISIBLE 리뷰 이미지는 서빙되어야 합니다');
    }

    // ==========================================
    // 서명 download URL (숨김 리뷰 <img> 썸네일 렌더 경로)
    //
    // 브라우저 <img src> 는 Authorization 헤더를 실을 수 없어, 숨김 게이트 도입 후
    // 관리자 리뷰 화면의 숨김 리뷰 이미지 썸네일이 무인증 요청 → 404 로 깨졌다.
    // 게이트를 통과한 응답 직렬화(ProductReviewResource)가 한시 서명 URL 을 발급하고,
    // 서빙 엔드포인트가 유효 서명을 허용한다 — 무서명 게이트는 종전과 동일하다.
    // (게시판·페이지 첨부 preview 와 동일 설계)
    // ==========================================

    /**
     * 숨김 리뷰 이미지를 만들고 서명 download URL 을 돌려주는 헬퍼
     *
     * @param  int  $minutes  유효 시간(분, 음수면 만료된 URL)
     * @return string 상대경로 서명 URL
     */
    private function hiddenImageSignedUrl(int $minutes = 30): string
    {
        $hiddenReview = ProductReview::factory()->create([
            'product_id' => $this->product->id,
            'order_option_id' => $this->orderOption->id,
            'user_id' => $this->user->id,
            'status' => ReviewStatus::HIDDEN->value,
        ]);
        $image = ProductReviewImage::factory()->create([
            'review_id' => $hiddenReview->id,
        ]);

        return URL::temporarySignedRoute(
            'api.modules.sirsoft-ecommerce.review-image.download',
            now()->addMinutes($minutes),
            ['hash' => $image->hash],
            absolute: false
        );
    }

    /**
     * 유효한 한시 서명 download URL 은 무인증(<img>) 요청도 숨김 게이트를 통과한다.
     *
     * @scenario resource=review_image, parent_state=restricted
     *
     * @effects hidden_review_image_download_allowed_with_valid_signature
     */
    #[Test]
    public function test_download_allows_hidden_review_image_with_valid_signature(): void
    {
        $this->storageMock->allows('response')->andReturn(
            new StreamedResponse(fn () => null, 200)
        );

        $response = $this->get($this->hiddenImageSignedUrl());

        $this->assertNotSame(404, $response->getStatusCode(), '유효 서명 URL 은 숨김 게이트를 통과해야 합니다');
    }

    /**
     * 변조된 서명 download URL 은 종전과 동일하게 숨김 게이트에 차단된다 (404).
     *
     * @scenario resource=review_image, parent_state=restricted
     *
     * @effects hidden_review_image_download_blocked_with_tampered_signature
     */
    #[Test]
    public function test_download_blocks_hidden_review_image_with_tampered_signature(): void
    {
        // signature 쿼리 값의 마지막 8자를 뒤집어 변조한다
        $tampered = preg_replace_callback(
            '/(signature=)([0-9a-f]+)/',
            fn ($m) => $m[1].substr($m[2], 0, -8).strrev(substr($m[2], -8)),
            $this->hiddenImageSignedUrl()
        );

        $this->get($tampered)->assertNotFound();
    }

    /**
     * 만료된 서명 download URL 은 숨김 게이트에 차단된다 (404, 한시성 보장).
     *
     * @scenario resource=review_image, parent_state=restricted
     *
     * @effects hidden_review_image_download_blocked_with_expired_signature
     */
    #[Test]
    public function test_download_blocks_hidden_review_image_with_expired_signature(): void
    {
        $this->get($this->hiddenImageSignedUrl(minutes: -1))->assertNotFound();
    }
}
