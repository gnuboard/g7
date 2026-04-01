<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductReviewService 단위 테스트
 *
 * canWrite, createReview, deleteReview, bulkDelete, saveReply, deleteReply 비즈니스 로직을 검증합니다.
 */
class ProductReviewServiceTest extends ModuleTestCase
{
    private ProductReviewService $service;

    /** @var \Mockery\MockInterface&ProductReviewRepositoryInterface */
    private $repository;

    /** @var \Mockery\MockInterface&OrderOptionRepositoryInterface */
    private $orderOptionRepository;

    /** @var \Mockery\MockInterface&StorageInterface */
    private $storage;

    /** @var \Mockery\MockInterface&EcommerceSettingsService */
    private $settingsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(ProductReviewRepositoryInterface::class);
        $this->orderOptionRepository = Mockery::mock(OrderOptionRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->settingsService = Mockery::mock(EcommerceSettingsService::class);

        $this->service = new ProductReviewService(
            $this->repository,
            $this->orderOptionRepository,
            $this->storage,
            $this->settingsService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // canWrite() 테스트
    // ========================================

    #[Test]
    public function test_can_write_returns_false_when_order_option_not_found(): void
    {
        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(999)
            ->andThrow(new ModelNotFoundException);

        $result = $this->service->canWrite(1, 999);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('order_option_not_found', $result['reason']);
    }

    #[Test]
    public function test_can_write_returns_false_when_not_own_order(): void
    {
        $order = new Order;
        $order->user_id = 99;

        $orderOption = Mockery::mock(OrderOption::class)->makePartial();
        $orderOption->shouldReceive('load')->with('order')->andReturnSelf();
        $orderOption->shouldReceive('getAttribute')->with('order')->andReturn($order);

        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(1)
            ->andReturn($orderOption);

        $result = $this->service->canWrite(1, 1);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('not_own_order', $result['reason']);
    }

    #[Test]
    public function test_can_write_returns_false_when_not_confirmed(): void
    {
        $order = new Order;
        $order->user_id = 1;

        $orderOption = Mockery::mock(OrderOption::class)->makePartial();
        $orderOption->shouldReceive('load')->with('order')->andReturnSelf();
        $orderOption->shouldReceive('getAttribute')->with('order')->andReturn($order);
        $orderOption->option_status = OrderStatusEnum::DELIVERED;

        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(1)
            ->andReturn($orderOption);

        $result = $this->service->canWrite(1, 1);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('not_confirmed', $result['reason']);
    }

    #[Test]
    public function test_can_write_returns_false_when_already_written(): void
    {
        $order = new Order;
        $order->user_id = 1;

        $orderOption = Mockery::mock(OrderOption::class)->makePartial();
        $orderOption->shouldReceive('load')->with('order')->andReturnSelf();
        $orderOption->shouldReceive('getAttribute')->with('order')->andReturn($order);
        $orderOption->option_status = OrderStatusEnum::CONFIRMED;
        $orderOption->confirmed_at = null;

        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(1)
            ->andReturn($orderOption);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review.write_deadline_days', Mockery::any())
            ->andReturn(90);

        $this->repository
            ->shouldReceive('findByOrderOptionId')
            ->with(1)
            ->andReturn(new ProductReview);

        $result = $this->service->canWrite(1, 1);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('already_written', $result['reason']);
    }

    #[Test]
    public function test_can_write_returns_true_when_eligible(): void
    {
        $order = new Order;
        $order->user_id = 1;

        $orderOption = Mockery::mock(OrderOption::class)->makePartial();
        $orderOption->shouldReceive('load')->with('order')->andReturnSelf();
        $orderOption->shouldReceive('getAttribute')->with('order')->andReturn($order);
        $orderOption->option_status = OrderStatusEnum::CONFIRMED;
        $orderOption->confirmed_at = null;

        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(1)
            ->andReturn($orderOption);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review.write_deadline_days', Mockery::any())
            ->andReturn(90);

        $this->repository
            ->shouldReceive('findByOrderOptionId')
            ->with(1)
            ->andReturn(null);

        $result = $this->service->canWrite(1, 1);

        $this->assertTrue($result['can_write']);
        $this->assertNull($result['reason']);
    }

    #[Test]
    public function test_can_write_returns_false_when_deadline_passed(): void
    {
        $order = new Order;
        $order->user_id = 1;

        $orderOption = Mockery::mock(OrderOption::class)->makePartial();
        $orderOption->shouldReceive('load')->with('order')->andReturnSelf();
        $orderOption->shouldReceive('getAttribute')->with('order')->andReturn($order);
        $orderOption->option_status = OrderStatusEnum::CONFIRMED;
        $orderOption->confirmed_at = now()->subDays(100);

        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(1)
            ->andReturn($orderOption);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->with('review.write_deadline_days', Mockery::any())
            ->andReturn(90);

        $result = $this->service->canWrite(1, 1);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('deadline_passed', $result['reason']);
    }

    // ========================================
    // createReview() 테스트
    // ========================================

    #[Test]
    public function test_create_review_throws_when_not_eligible(): void
    {
        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->with(999)
            ->andThrow(new ModelNotFoundException);

        $this->expectException(\RuntimeException::class);

        $this->service->createReview(1, [
            'product_id' => 1,
            'order_option_id' => 999,
            'rating' => 5,
            'content' => '테스트 리뷰입니다.',
        ]);
    }

    #[Test]
    public function test_create_review_succeeds_when_eligible(): void
    {
        // canWrite에 필요한 mock
        $order = new Order;
        $order->user_id = 1;

        $orderOption = Mockery::mock(OrderOption::class)->makePartial();
        $orderOption->shouldReceive('load')->with('order')->andReturnSelf();
        $orderOption->shouldReceive('getAttribute')->with('order')->andReturn($order);
        $orderOption->shouldReceive('getAttribute')->with('option_snapshot')->andReturn(['color' => '블랙']);
        $orderOption->option_status = OrderStatusEnum::CONFIRMED;
        $orderOption->confirmed_at = null;
        $orderOption->option_snapshot = ['color' => '블랙'];

        $this->orderOptionRepository
            ->shouldReceive('findOrFail')
            ->andReturn($orderOption);

        $this->settingsService
            ->shouldReceive('getSetting')
            ->andReturn(90);

        $this->repository
            ->shouldReceive('findByOrderOptionId')
            ->andReturn(null);

        // create에 필요한 mock
        $createdReview = new ProductReview([
            'product_id' => 1,
            'order_option_id' => 1,
            'user_id' => 1,
            'rating' => 5,
            'content' => '테스트 리뷰입니다.',
            'status' => ReviewStatus::VISIBLE->value,
        ]);
        $createdReview->id = 1;

        $this->repository
            ->shouldReceive('create')
            ->once()
            ->andReturn($createdReview);

        $result = $this->service->createReview(1, [
            'product_id' => 1,
            'order_option_id' => 1,
            'rating' => 5,
            'content' => '테스트 리뷰입니다.',
        ]);

        $this->assertInstanceOf(ProductReview::class, $result);
        $this->assertEquals(5, $result->rating);
    }

    // ========================================
    // updateStatus() 테스트
    // ========================================

    #[Test]
    public function test_update_status_delegates_to_repository(): void
    {
        $review = new ProductReview;
        $review->id = 1;

        $updatedReview = new ProductReview;
        $updatedReview->id = 1;
        $updatedReview->status = ReviewStatus::HIDDEN;

        $this->repository
            ->shouldReceive('update')
            ->with($review, ['status' => 'hidden'])
            ->once()
            ->andReturn($updatedReview);

        $result = $this->service->updateStatus($review, 'hidden');

        $this->assertEquals(ReviewStatus::HIDDEN, $result->status);
    }

    // ========================================
    // saveReply() 테스트
    // ========================================

    #[Test]
    public function test_save_reply_sets_replied_at_for_new_reply(): void
    {
        $review = new ProductReview;
        $review->id = 1;
        $review->replied_at = null;

        $updatedReview = new ProductReview;
        $updatedReview->id = 1;
        $updatedReview->reply_content = '감사합니다.';

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function ($r, $data) {
                return $data['reply_content'] === '감사합니다.'
                    && $data['reply_admin_id'] === 10
                    && $data['replied_at'] !== null
                    && $data['reply_updated_at'] === null;
            })
            ->andReturn($updatedReview);

        $result = $this->service->saveReply($review, 10, ['reply_content' => '감사합니다.']);

        $this->assertEquals('감사합니다.', $result->reply_content);
    }

    #[Test]
    public function test_save_reply_sets_reply_updated_at_for_existing_reply(): void
    {
        $repliedAt = now()->subDay();

        $review = new ProductReview;
        $review->id = 1;
        $review->replied_at = $repliedAt;

        $updatedReview = new ProductReview;
        $updatedReview->id = 1;
        $updatedReview->reply_content = '수정된 답변입니다.';

        $capturedData = null;

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function ($r, $data) use (&$capturedData) {
                $capturedData = $data;

                return true;
            })
            ->andReturn($updatedReview);

        $result = $this->service->saveReply($review, 10, ['reply_content' => '수정된 답변입니다.']);

        $this->assertEquals('수정된 답변입니다.', $capturedData['reply_content']);
        $this->assertEquals(10, $capturedData['reply_admin_id']);
        $this->assertNotNull($capturedData['reply_updated_at']);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $capturedData['replied_at']);
        $this->assertEquals('수정된 답변입니다.', $result->reply_content);
    }

    // ========================================
    // deleteReply() 테스트
    // ========================================

    #[Test]
    public function test_delete_reply_clears_all_reply_fields(): void
    {
        $review = new ProductReview;
        $review->id = 1;

        $clearedReview = new ProductReview;
        $clearedReview->id = 1;
        $clearedReview->reply_content = null;

        $this->repository
            ->shouldReceive('update')
            ->once()
            ->withArgs(function ($r, $data) {
                return $data['reply_content'] === null
                    && $data['reply_content_mode'] === 'text'
                    && $data['reply_admin_id'] === null
                    && $data['replied_at'] === null
                    && $data['reply_updated_at'] === null;
            })
            ->andReturn($clearedReview);

        $result = $this->service->deleteReply($review);

        $this->assertNull($result->reply_content);
    }

    // ========================================
    // bulkUpdateStatus() 테스트
    // ========================================

    #[Test]
    public function test_bulk_update_status_delegates_to_repository(): void
    {
        $this->repository
            ->shouldReceive('bulkUpdateStatus')
            ->with([1, 2, 3], 'hidden')
            ->once()
            ->andReturn(3);

        $result = $this->service->bulkUpdateStatus([1, 2, 3], 'hidden');

        $this->assertEquals(3, $result);
    }
}
