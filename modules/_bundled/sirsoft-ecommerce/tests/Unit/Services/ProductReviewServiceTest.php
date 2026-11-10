<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;
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

    /** @var MockInterface&ProductReviewRepositoryInterface */
    private $repository;

    /** @var MockInterface&OrderOptionRepositoryInterface */
    private $orderOptionRepository;

    /** @var MockInterface&StorageInterface */
    private $storage;

    /** @var MockInterface&EcommerceSettingsService */
    private $settingsService;

    /** @var MockInterface&ProductReviewImageRepositoryInterface */
    private $imageRepository;

    /**
     * 저장소·저장소 드라이버·설정 서비스를 목으로 바꾸고 서비스를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Hook listener job 차단 (mock 모델 deserialize 시 null TypeError 방지)
        Queue::fake();

        $this->repository = Mockery::mock(ProductReviewRepositoryInterface::class);
        $this->orderOptionRepository = Mockery::mock(OrderOptionRepositoryInterface::class);
        $this->storage = Mockery::mock(StorageInterface::class);
        $this->settingsService = Mockery::mock(EcommerceSettingsService::class);
        $this->imageRepository = Mockery::mock(ProductReviewImageRepositoryInterface::class);

        $this->service = new ProductReviewService(
            $this->repository,
            $this->orderOptionRepository,
            $this->storage,
            $this->settingsService,
            $this->imageRepository,
            Mockery::mock(ProductRepositoryInterface::class)
        );
    }

    /**
     * Mockery 기대값을 검증하고 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // canWrite() 테스트
    // ========================================

    /**
     * 주문 옵션이 없으면 작성할 수 없습니다.
     *
     * @scenario operation=can_write
     *
     * @effects review_write_denied_when_ineligible
     *
     * @return void 반환값 없음
     */
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

    /**
     * 다른 회원의 주문 옵션이면 작성할 수 없습니다.
     *
     * @scenario operation=can_write
     *
     * @effects review_write_denied_when_ineligible
     *
     * @return void 반환값 없음
     */
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

    /**
     * 구매 확정 전 주문 옵션이면 작성할 수 없습니다.
     *
     * @scenario operation=can_write
     *
     * @effects review_write_denied_when_ineligible
     *
     * @return void 반환값 없음
     */
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

    /**
     * 같은 주문 옵션에 이미 쓴 후기가 있으면 작성할 수 없습니다.
     *
     * @scenario operation=can_write
     *
     * @effects review_write_denied_when_ineligible
     *
     * @return void 반환값 없음
     */
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
            ->with('review_settings.write_deadline_days', Mockery::any())
            ->andReturn(90);

        $this->repository
            ->shouldReceive('findByOrderOptionId')
            ->with(1)
            ->andReturn(new ProductReview);

        $result = $this->service->canWrite(1, 1);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('already_written', $result['reason']);
    }

    /**
     * 본인 주문의 구매 확정 옵션이고 기한 안이면 작성할 수 있습니다.
     *
     * @scenario operation=can_write
     *
     * @effects review_write_allowed_when_eligible
     *
     * @return void 반환값 없음
     */
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
            ->with('review_settings.write_deadline_days', Mockery::any())
            ->andReturn(90);

        $this->repository
            ->shouldReceive('findByOrderOptionId')
            ->with(1)
            ->andReturn(null);

        $result = $this->service->canWrite(1, 1);

        $this->assertTrue($result['can_write']);
        $this->assertNull($result['reason']);
    }

    /**
     * 작성 기한이 지나면 작성할 수 없습니다.
     *
     * @scenario operation=can_write
     *
     * @effects review_write_denied_when_ineligible
     *
     * @return void 반환값 없음
     */
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
            ->with('review_settings.write_deadline_days', Mockery::any())
            ->andReturn(90);

        $result = $this->service->canWrite(1, 1);

        $this->assertFalse($result['can_write']);
        $this->assertEquals('deadline_passed', $result['reason']);
    }

    // ========================================
    // createReview() 테스트
    // ========================================

    /**
     * 작성 자격이 없으면 후기 생성이 예외로 막힙니다.
     *
     * @scenario operation=create_review
     *
     * @effects review_write_denied_when_ineligible
     *
     * @return void 반환값 없음
     */
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

    /**
     * 작성 자격이 있으면 후기를 만듭니다.
     *
     * @scenario operation=create_review
     *
     * @effects review_write_allowed_when_eligible
     *
     * @return void 반환값 없음
     */
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

    /**
     * 상태 변경은 저장소에 위임합니다.
     *
     * @scenario operation=update_status
     *
     * @effects review_status_change_delegated
     *
     * @return void 반환값 없음
     */
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

    /**
     * 첫 답변이면 답변 시각을 기록합니다.
     *
     * @scenario operation=save_reply
     *
     * @effects review_reply_timestamps_tracked
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 답변이 있으면 답변 수정 시각을 기록합니다.
     *
     * @scenario operation=save_reply
     *
     * @effects review_reply_timestamps_tracked
     *
     * @return void 반환값 없음
     */
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
        $this->assertInstanceOf(Carbon::class, $capturedData['replied_at']);
        $this->assertEquals('수정된 답변입니다.', $result->reply_content);
    }

    // ========================================
    // deleteReply() 테스트
    // ========================================

    /**
     * 답변을 지우면 답변 내용·작성 관리자·시각을 비우고 형식을 text 로 되돌립니다.
     *
     * @scenario operation=delete_reply
     *
     * @effects review_reply_cleared_on_delete
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 상태 변경은 저장소에 위임합니다.
     *
     * @scenario operation=bulk_update_status
     *
     * @effects review_status_change_delegated
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_status_delegates_to_repository(): void
    {
        // 일괄 라우트는 라우트 모델이 없어 PermissionMiddleware 의 스코프 검사가 스킵되므로
        // 서비스가 대상을 조회해 스코프를 재적용한다 — 순수 위임이 아니다.
        $this->repository
            ->shouldReceive('getByIdsWithImages')
            ->with([1, 2, 3])
            ->once()
            ->andReturn(new EloquentCollection);

        $this->repository
            ->shouldReceive('bulkUpdateStatus')
            ->with([1, 2, 3], 'hidden')
            ->once()
            ->andReturn(3);

        $result = $this->service->bulkUpdateStatus([1, 2, 3], 'hidden');

        $this->assertEquals(3, $result);
    }
}
