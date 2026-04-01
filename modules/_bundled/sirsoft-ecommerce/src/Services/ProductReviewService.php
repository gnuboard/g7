<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;

/**
 * 상품 리뷰 서비스
 *
 * 상품 리뷰 생성, 수정, 삭제, 답변 등의 비즈니스 로직을 처리합니다.
 */
class ProductReviewService
{
    /**
     * ProductReviewService 생성자
     *
     * @param  ProductReviewRepositoryInterface  $repository  리뷰 리포지토리
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 리포지토리
     * @param  StorageInterface  $storage  모듈 스토리지 드라이버
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     */
    public function __construct(
        protected ProductReviewRepositoryInterface $repository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected StorageInterface $storage,
        protected EcommerceSettingsService $settingsService
    ) {}

    /**
     * 관리자용 리뷰 목록 조회 (통계 포함)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator
     */
    public function getAdminList(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 상품 공개 리뷰 목록 조회 (별점 통계 포함)
     *
     * @param  int  $productId  상품 ID
     * @param  array  $filters  필터 조건 (sort, photo_only)
     * @param  int  $perPage  페이지당 개수
     * @return array{reviews: LengthAwarePaginator, rating_stats: array}
     */
    public function getProductReviews(int $productId, array $filters = [], int $perPage = 10): array
    {
        return [
            'reviews' => $this->repository->findByProduct($productId, $filters, $perPage),
            'rating_stats' => $this->repository->getRatingStats($productId),
            'option_filters' => $this->repository->getOptionFilters($productId),
            'total_count' => $this->repository->getTotalCount($productId),
        ];
    }

    /**
     * 리뷰 작성 가능 여부 확인
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $orderOptionId  주문 옵션 ID
     * @return array{can_write: bool, reason: string|null}
     */
    public function canWrite(int $userId, int $orderOptionId): array
    {
        try {
            $orderOption = $this->orderOptionRepository->findOrFail($orderOptionId);
            $orderOption->load('order');
        } catch (ModelNotFoundException $e) {
            return ['can_write' => false, 'reason' => 'order_option_not_found'];
        }

        // 본인 주문 여부 확인
        if ($orderOption->order->user_id !== $userId) {
            return ['can_write' => false, 'reason' => 'not_own_order'];
        }

        // 구매확정 여부 확인
        if ($orderOption->option_status !== OrderStatusEnum::CONFIRMED) {
            return ['can_write' => false, 'reason' => 'not_confirmed'];
        }

        // 작성 기간 확인
        $deadlineDays = (int) $this->settingsService->getSetting(
            'review.write_deadline_days',
            config('ecommerce.review.write_deadline_days', 90)
        );

        if ($orderOption->confirmed_at && $deadlineDays > 0) {
            $deadline = $orderOption->confirmed_at->addDays($deadlineDays);
            if (now()->gt($deadline)) {
                return ['can_write' => false, 'reason' => 'deadline_passed'];
            }
        }

        // 중복 작성 확인
        $existing = $this->repository->findByOrderOptionId($orderOptionId);
        if ($existing) {
            return ['can_write' => false, 'reason' => 'already_written'];
        }

        return ['can_write' => true, 'reason' => null];
    }

    /**
     * 리뷰 생성
     *
     * @param  int  $userId  작성자 ID
     * @param  array  $data  리뷰 데이터
     * @return ProductReview
     */
    public function createReview(int $userId, array $data): ProductReview
    {
        // canWrite 사전 검증
        $eligibility = $this->canWrite($userId, $data['order_option_id']);
        if (! $eligibility['can_write']) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.reviews.cannot_write', ['reason' => $eligibility['reason']])
            );
        }

        // option_snapshot: 주문 옵션에서 복사
        $orderOption = $this->orderOptionRepository->findOrFail($data['order_option_id']);
        $optionSnapshot = $orderOption->option_snapshot ?? [];

        HookManager::doAction('sirsoft-ecommerce.product-review.before_create', $data);

        $review = $this->repository->create([
            'product_id' => $data['product_id'],
            'order_option_id' => $data['order_option_id'],
            'user_id' => $userId,
            'rating' => $data['rating'],
            'content' => $data['content'],
            'content_mode' => $data['content_mode'] ?? 'text',
            'option_snapshot' => $optionSnapshot,
            'status' => ReviewStatus::VISIBLE->value,
        ]);

        Log::info('상품 리뷰 작성 완료', [
            'review_id' => $review->id,
            'user_id' => $userId,
            'product_id' => $review->product_id,
        ]);

        HookManager::doAction('sirsoft-ecommerce.product-review.after_create', $review);

        return $review;
    }

    /**
     * 리뷰 상태 변경
     *
     * @param  ProductReview  $review  리뷰 모델
     * @param  string  $status  변경할 상태값
     * @return ProductReview
     */
    public function updateStatus(ProductReview $review, string $status): ProductReview
    {
        return $this->repository->update($review, ['status' => $status]);
    }

    /**
     * 판매자 답변 저장 (등록/수정)
     *
     * @param  ProductReview  $review  리뷰 모델
     * @param  int  $adminId  답변 관리자 ID
     * @param  array  $data  답변 데이터
     * @return ProductReview
     */
    public function saveReply(ProductReview $review, int $adminId, array $data): ProductReview
    {
        $isUpdate = ! is_null($review->replied_at);

        return $this->repository->update($review, [
            'reply_content' => $data['reply_content'],
            'reply_content_mode' => $data['reply_content_mode'] ?? 'text',
            'reply_admin_id' => $adminId,
            'replied_at' => $isUpdate ? $review->replied_at : now(),
            'reply_updated_at' => $isUpdate ? now() : null,
        ]);
    }

    /**
     * 판매자 답변 삭제
     *
     * @param  ProductReview  $review  리뷰 모델
     * @return ProductReview
     */
    public function deleteReply(ProductReview $review): ProductReview
    {
        return $this->repository->update($review, [
            'reply_content' => null,
            'reply_content_mode' => 'text',
            'reply_admin_id' => null,
            'replied_at' => null,
            'reply_updated_at' => null,
        ]);
    }

    /**
     * 리뷰 삭제 (이미지 파일 포함)
     *
     * @param  ProductReview  $review  리뷰 모델 (images 관계 로드됨)
     * @return bool
     */
    public function deleteReview(ProductReview $review): bool
    {
        HookManager::doAction('sirsoft-ecommerce.product-review.before_delete', $review);

        return DB::transaction(function () use ($review) {
            // 이미지 파일 삭제 (StorageInterface 사용)
            foreach ($review->images as $image) {
                if ($this->storage->exists('images', $image->path)) {
                    $this->storage->delete('images', $image->path);
                }
            }

            // 이미지 레코드 삭제 (명시적, CASCADE 의존 금지)
            $review->images()->delete();

            // 리뷰 삭제
            $result = $this->repository->delete($review);

            Log::info('상품 리뷰 삭제 완료', ['review_id' => $review->id]);

            HookManager::doAction('sirsoft-ecommerce.product-review.after_delete', $review);

            return $result;
        });
    }

    /**
     * 리뷰 일괄 상태 변경
     *
     * @param  array  $ids  리뷰 ID 배열
     * @param  string  $status  변경할 상태값
     * @return int 변경된 건수
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        return $this->repository->bulkUpdateStatus($ids, $status);
    }

    /**
     * 리뷰 일괄 삭제 (이미지 파일 포함, N+1 방지)
     *
     * @param  array  $ids  리뷰 ID 배열
     * @return int 삭제된 건수
     */
    public function bulkDelete(array $ids): int
    {
        $reviews = $this->repository->getByIdsWithImages($ids);

        // 삭제 전 스냅샷 캡처 (after_bulk_delete 훅에 전달)
        $snapshots = $reviews->keyBy('id')->map->toArray()->all();

        HookManager::doAction('sirsoft-ecommerce.product-review.before_bulk_delete', $ids, $reviews);

        return DB::transaction(function () use ($reviews, $ids, $snapshots) {
            // 이미지 파일 일괄 삭제
            foreach ($reviews as $review) {
                foreach ($review->images as $image) {
                    if ($this->storage->exists('images', $image->path)) {
                        $this->storage->delete('images', $image->path);
                    }
                }
            }

            // 이미지 레코드 일괄 삭제 (명시적, CASCADE 의존 금지)
            $reviewImageIds = $reviews->flatMap(fn ($r) => $r->images->pluck('id'))->all();
            if (! empty($reviewImageIds)) {
                \Modules\Sirsoft\Ecommerce\Models\ProductReviewImage::whereIn('id', $reviewImageIds)->delete();
            }

            // 리뷰 일괄 소프트 삭제
            $count = ProductReview::whereIn('id', $ids)->delete();

            HookManager::doAction('sirsoft-ecommerce.product-review.after_bulk_delete', $ids, $snapshots);

            return $count;
        });
    }
}
