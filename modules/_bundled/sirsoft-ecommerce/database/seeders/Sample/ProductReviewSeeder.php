<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Contracts\Extension\StorageInterface;
use App\Extension\ModuleManager;
use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;

/**
 * 상품 리뷰 더미 데이터 시더
 */
class ProductReviewSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 병렬 다운로드 배치 크기
     */
    private const DOWNLOAD_BATCH_SIZE = 10;

    /**
     * 리뷰 작성 비율 (구매확정 주문 옵션 중 리뷰를 작성하는 비율, %)
     */
    private const REVIEW_RATE = 60;

    /**
     * 관리자 답변 작성 비율 (리뷰 중 답변이 달리는 비율, %)
     */
    private const REPLY_RATE = 40;

    /**
     * 포토 리뷰 비율 (리뷰 중 이미지를 첨부하는 비율, %)
     */
    private const PHOTO_RATE = 30;

    /**
     * 스토리지 드라이버 인스턴스
     */
    private StorageInterface $storage;

    /**
     * 별점 분포 (합계 100)
     */
    private array $ratingDistribution = [
        5 => 50,
        4 => 25,
        3 => 15,
        2 => 5,
        1 => 5,
    ];

    /**
     * 리뷰 내용 샘플
     */
    private array $reviewContents = [
        '정말 마음에 드는 상품이에요! 품질도 좋고 배송도 빨랐습니다.',
        '기대 이상으로 좋은 상품입니다. 재구매 의향 있어요.',
        '가격 대비 품질이 훌륭합니다. 강력 추천!',
        '포장도 꼼꼼하고 상품 상태도 완벽했습니다.',
        '생각보다 훨씬 좋네요. 주변에도 추천할 예정입니다.',
        '색상이 사진과 동일하고 품질도 만족스럽습니다.',
        '배송이 빠르고 상품도 좋아요. 또 구매하고 싶어요.',
        '디자인이 예쁘고 실용적입니다. 만족합니다.',
        '보통이에요. 크게 불만은 없지만 특별히 만족스럽지도 않아요.',
        '배송은 빨랐는데 상품 품질이 조금 아쉽습니다.',
        '사진과 약간 다르지만 전반적으로 괜찮아요.',
        '가격 대비 그럭저럭 괜찮습니다.',
        '생각보다 작아서 조금 실망했어요.',
        '포장이 허술하게 왔어요. 상품은 멀쩡합니다.',
    ];

    /**
     * 관리자 답변 샘플
     */
    private array $replyContents = [
        '소중한 리뷰 감사드립니다! 항상 최선을 다하겠습니다.',
        '좋은 평가 감사드립니다. 앞으로도 좋은 상품으로 보답하겠습니다.',
        '불편하셨던 점 죄송합니다. 개선을 위해 노력하겠습니다.',
        '소중한 의견 감사드립니다. 더욱 발전하는 모습 보여드리겠습니다.',
        '구매해 주셔서 감사합니다! 다음에도 좋은 상품으로 찾아뵙겠습니다.',
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('상품 리뷰 더미 데이터 생성을 시작합니다.');

        // 스토리지 드라이버 초기화
        $this->storage = app(ModuleManager::class)
            ->getModule('sirsoft-ecommerce')
            ->getStorage();

        $this->deleteExistingReviews();
        $this->createReviews();

        $count = ProductReview::count();
        $this->command->info("상품 리뷰 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 리뷰 삭제
     *
     * @return void
     */
    private function deleteExistingReviews(): void
    {
        $imageCount = ProductReviewImage::withTrashed()->count();
        if ($imageCount > 0) {
            // 스토리지에서 리뷰 이미지 폴더 전체 삭제
            if ($this->storage->exists('images', 'reviews')) {
                $this->storage->deleteDirectory('images', 'reviews');
                $this->command->line('  - 스토리지에서 리뷰 이미지 폴더를 삭제했습니다.');
            }
            ProductReviewImage::withTrashed()->forceDelete();
        }

        $deletedCount = ProductReview::withTrashed()->count();

        if ($deletedCount > 0) {
            ProductReview::withTrashed()->forceDelete();
            $this->command->warn("기존 리뷰 데이터 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 리뷰 생성
     *
     * @return void
     */
    private function createReviews(): void
    {
        // 배송완료 + 구매확정 주문 옵션 조회 (테스트용: 더 많은 리뷰 생성)
        $confirmedOptions = OrderOption::with(['order.user', 'product'])
            ->whereIn('option_status', [OrderStatusEnum::DELIVERED, OrderStatusEnum::CONFIRMED])
            ->whereNotNull('product_id')
            ->get();

        if ($confirmedOptions->isEmpty()) {
            $this->command->warn('배송완료/구매확정 주문 옵션이 없습니다. OrderSeeder를 먼저 실행하는 것을 권장합니다.');

            return;
        }

        // 관리자 계정 조회 (답변 작성용)
        $admin = User::where('is_super', true)->first();

        $reviewRate = $this->getSeederCount('review_rate', self::REVIEW_RATE);
        $created = 0;

        $progressBar = $this->command->getOutput()->createProgressBar($confirmedOptions->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        foreach ($confirmedOptions as $orderOption) {
            // 리뷰 작성 비율에 따라 스킵
            if (rand(1, 100) > $reviewRate) {
                $progressBar->advance();
                continue;
            }

            // 이미 리뷰가 있는 주문 옵션 스킵
            $exists = ProductReview::withTrashed()
                ->where('order_option_id', $orderOption->id)
                ->exists();

            if ($exists) {
                $progressBar->advance();
                continue;
            }

            $user = $orderOption->order?->user;

            if (! $user) {
                $progressBar->advance();
                continue;
            }

            $rating = $this->getRandomRating();
            $status = ReviewStatus::VISIBLE;

            // 낮은 별점(1~2)은 숨김 처리 30% 확률
            if ($rating <= 2 && rand(1, 100) <= 30) {
                $status = ReviewStatus::HIDDEN;
            }

            $reviewData = [
                'product_id' => $orderOption->product_id,
                'order_option_id' => $orderOption->id,
                'user_id' => $user->id,
                'rating' => $rating,
                'content' => $this->getRandomContent($rating),
                'content_mode' => 'text',
                'option_snapshot' => json_encode($orderOption->option_snapshot ?? []),
                'status' => $status,
                'created_at' => $orderOption->updated_at?->addDays(rand(1, 14)) ?? now()->subDays(rand(1, 30)),
            ];

            // 관리자 답변 (REPLY_RATE % 확률)
            if ($admin && rand(1, 100) <= self::REPLY_RATE) {
                $reviewData['reply_content'] = $this->replyContents[array_rand($this->replyContents)];
                $reviewData['reply_content_mode'] = 'text';
                $reviewData['reply_admin_id'] = $admin->id;
                $reviewData['replied_at'] = now()->subDays(rand(1, 7));
                // 50% 확률로 답변 수정 이력 생성
                $reviewData['reply_updated_at'] = rand(0, 1) ? now()->subDays(rand(0, 3)) : null;
            }

            $review = ProductReview::create($reviewData);

            // 포토 리뷰 생성 (PHOTO_RATE % 확률, 1~3장)
            if (rand(1, 100) <= self::PHOTO_RATE) {
                $this->createReviewImages($review, $user->id);
            }

            $created++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->line("  - 구매확정 옵션 {$confirmedOptions->count()}건 중 {$created}건 리뷰 생성");
    }

    /**
     * 리뷰 이미지를 picsum.photos에서 다운로드하여 스토리지에 저장합니다.
     *
     * @param  ProductReview  $review  리뷰 모델
     * @param  int  $userId  업로더 사용자 ID
     * @return void
     */
    private function createReviewImages(ProductReview $review, int $userId): void
    {
        $imageCount = rand(1, 3);
        $seeds = [];

        for ($i = 0; $i < $imageCount; $i++) {
            $seeds[] = [
                'index' => $i,
                'seed' => crc32("review-{$review->id}-{$i}"),
            ];
        }

        $responses = Http::pool(function (Pool $pool) use ($seeds) {
            foreach ($seeds as $item) {
                $pool->as((string) $item['index'])
                    ->withoutVerifying()
                    ->timeout(30)
                    ->get("https://picsum.photos/seed/{$item['seed']}/600/600");
            }
        });

        foreach ($seeds as $item) {
            $response = $responses[(string) $item['index']] ?? null;

            if (! $response || ! $response->successful()) {
                continue;
            }

            $content = $response->body();
            $filename = Str::uuid().'.jpg';
            $path = "reviews/{$review->id}/{$filename}";

            $this->storage->put('images', $path, $content);

            ProductReviewImage::create([
                'review_id' => $review->id,
                'original_filename' => "review_image_{$item['index']}.jpg",
                'stored_filename' => $filename,
                'disk' => $this->storage->getDisk(),
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'file_size' => strlen($content),
                'width' => 600,
                'height' => 600,
                'is_thumbnail' => $item['index'] === 0,
                'sort_order' => $item['index'],
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * 별점 분포에 따라 랜덤 별점 반환
     *
     * @return int
     */
    private function getRandomRating(): int
    {
        $rand = rand(1, 100);
        $cumulative = 0;

        foreach ($this->ratingDistribution as $rating => $percentage) {
            $cumulative += $percentage;
            if ($rand <= $cumulative) {
                return $rating;
            }
        }

        return 5;
    }

    /**
     * 별점에 맞는 리뷰 내용 반환
     *
     * @param  int  $rating
     * @return string
     */
    private function getRandomContent(int $rating): string
    {
        // 별점 4~5: 긍정적 내용 (0~7번 인덱스)
        // 별점 3: 중립 내용 (8~11번 인덱스)
        // 별점 1~2: 부정적 내용 (12~13번 인덱스)
        $range = match (true) {
            $rating >= 4 => [0, 7],
            $rating === 3 => [8, 11],
            default => [12, 13],
        };

        return $this->reviewContents[rand($range[0], $range[1])];
    }
}
