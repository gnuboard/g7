<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Contracts\Extension\StorageInterface;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
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

    /** @var \Mockery\MockInterface&StorageInterface */
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
}
