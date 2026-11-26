<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\ActivityLog;
use Database\Seeders\Sample\ThumbnailMeasureKit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderPrivacyRetentionService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;

/**
 * 이미지 썸네일 브라우저 실측 — 이커머스 데이터 정리.
 *
 * 대상은 실측 시더의 표식이 맞는 것만이다 — 주문번호 접두 `THUMB-MEAS-` + (실측 작성자 회원의 주문 또는 주문자 이름 표지),
 * 상품 코드 접두 `THUMBMEASURE` + 이름 표지, 카테고리 슬러그 `thumb-measure` + 이름 표지, 이름 표지가 있는 배송정책.
 *
 * 순서 (참조가 먼저 사라져야 지울 수 있다):
 *   1. 주문 — 개인정보 보유기간 영구 삭제와 같은 `OrderPrivacyRetentionService::purge`. 주문옵션에 묶인 리뷰와 그
 *      이미지 파일, 클레임 신청서, 첨부 파일까지 저장소 드라이버 delete 로 지운다(권한 썸네일 위치 파생본 실측).
 *   2. 상품 — 관리자 화면과 같은 `ProductService::delete`(이미지 디렉터리 삭제 → 공개 썸네일 위치 파생본 실측).
 *   3. 카테고리 — `CategoryService::deleteCategory`(배너 파일 → 행).
 *   4. 배송정책 — 관리자 화면과 같은 `ShippingPolicyService::delete`(국가별 설정, 회수지, 삭제 훅), 그리고 지운 행을
 *      가리키는 활동 로그.
 *
 * 실행 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan module:seed sirsoft-ecommerce --class="Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 */
class ThumbnailMeasureCleanupSeeder extends Seeder
{
    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.cleanup_start'));

        ThumbnailMeasureKit::guard();

        (new ThumbnailMeasureKit)->silenceOutbound();
        $counts = $this->purge();

        ThumbnailMeasureKit::table(
            $this->command,
            __('sirsoft-ecommerce::messages.seeders.thumbnail_measure.cleanup_title'),
            ThumbnailMeasureKit::labels(array_keys($counts), __('sirsoft-ecommerce::messages.seeders.thumbnail_measure.cleanup_headers')),
            [array_values($counts)]
        );

        $this->command?->info(__('sirsoft-ecommerce::messages.seeders.thumbnail_measure.cleanup_done'));
    }

    /**
     * 실측 데이터를 지웁니다 (생성 시더의 재실행 멱등에도 쓴다).
     *
     * @return array<string, int> 삭제 건수
     */
    public function purge(): array
    {
        $counts = [
            'orders' => 0, 'reviews' => 0, 'review_images' => 0, 'claims' => 0, 'claim_images' => 0,
            'products' => 0, 'product_images' => 0, 'categories' => 0, 'category_images' => 0,
            'shipping_policies' => 0, 'activity_logs' => 0,
        ];
        $logTargets = [];

        $previousUser = Auth::user();
        Auth::setUser(ThumbnailMeasureKit::superAdmin());

        try {
            // 1. 주문 (리뷰, 클레임 포함) — 접두만으로 고르면 같은 접두를 쓴 남의 주문까지 지운다. 실측 작성자 회원의 주문이거나
            //    주문자 이름에 표지가 있어야 한다(코어 정리가 회원을 먼저 지웠어도 주문자 이름 표지로 찾는다)
            $orders = Order::withTrashed()
                ->where('order_number', 'like', ThumbnailMeasureSeeder::ORDER_PREFIX.'%')
                ->where(fn ($query) => $query
                    ->whereHas('user', fn ($user) => $user->where('email', ThumbnailMeasureKit::AUTHOR_EMAIL))
                    ->orWhereHas('addresses', fn ($address) => $address->where('orderer_name', 'like', '%'.ThumbnailMeasureKit::MARK.'%')))
                ->get();
            foreach ($orders as $order) {
                $optionIds = $order->options()->pluck('id')->all();
                $reviews = ProductReview::withTrashed()->whereIn('order_option_id', $optionIds ?: [0])->get(['id']);
                $reviewImageIds = ProductReviewImage::query()->whereIn('review_id', $reviews->pluck('id')->all() ?: [0])->pluck('id')->all();
                $claimImages = app(ClaimAttachmentService::class)->imagesForOrder($order);
                $returnIds = OrderReturn::query()->where('order_id', $order->id)->pluck('id')->all();

                app(OrderPrivacyRetentionService::class)->purge($order);

                $counts['orders']++;
                $counts['reviews'] += $reviews->count();
                $counts['review_images'] += count($reviewImageIds);
                $counts['claims'] += count($returnIds);
                $counts['claim_images'] += $claimImages->count();

                $this->collect($logTargets, Order::class, [$order->id]);
                $this->collect($logTargets, ProductReview::class, $reviews->pluck('id')->all());
                $this->collect($logTargets, ProductReviewImage::class, $reviewImageIds);
                $this->collect($logTargets, OrderReturn::class, $returnIds);
                $this->collect($logTargets, ClaimImage::class, $claimImages->pluck('id')->all());
            }

            // 2. 상품
            $products = Product::withTrashed()
                ->where('product_code', 'like', ThumbnailMeasureSeeder::PRODUCT_CODE_PREFIX.'%')
                ->get()
                ->filter(fn (Product $product) => ThumbnailMeasureKit::hasMark($product->name));

            foreach ($products as $product) {
                $imageIds = ProductImage::withTrashed()->where('product_id', $product->id)->pluck('id')->all();

                app(ProductService::class)->delete($product);

                // 상품 삭제는 이미지 행을 소프트 삭제로 남긴다 — 파일은 이미 지워졌으므로 실측 찌꺼기 행만 걷는다
                ProductImage::withTrashed()->whereIn('id', $imageIds ?: [0])->forceDelete();

                $counts['products']++;
                $counts['product_images'] += count($imageIds);
                $this->collect($logTargets, Product::class, [$product->id]);
                $this->collect($logTargets, ProductImage::class, $imageIds);
            }

            // 3. 카테고리
            $categories = Category::query()
                ->where('slug', ThumbnailMeasureSeeder::CATEGORY_SLUG)
                ->get()
                ->filter(fn (Category $category) => ThumbnailMeasureKit::hasMark($category->name));

            foreach ($categories as $category) {
                $imageIds = CategoryImage::query()->where('category_id', $category->id)->pluck('id')->all();

                app(CategoryService::class)->deleteCategory($category->id);

                $counts['categories']++;
                $counts['category_images'] += count($imageIds);
                $this->collect($logTargets, Category::class, [$category->id]);
                $this->collect($logTargets, CategoryImage::class, $imageIds);
            }

            // 4. 배송정책 — 이름 표지로 고른다. 이름은 JSON 이라 저장 표현(유니코드 이스케이프)에 SQL LIKE 를 걸지 않고
            //    디코드한 값으로 거른다(배송정책은 수십 건 규모)
            $policies = ShippingPolicy::query()->get()->filter(fn (ShippingPolicy $policy) => ThumbnailMeasureKit::hasMark($policy->name));

            foreach ($policies as $policy) {
                if (Product::withTrashed()->where('shipping_policy_id', $policy->id)->exists()) {
                    continue;
                }

                app(ShippingPolicyService::class)->delete($policy);
                $counts['shipping_policies']++;
                $this->collect($logTargets, ShippingPolicy::class, [$policy->id]);
            }
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        foreach ($logTargets as $morph => $ids) {
            $counts['activity_logs'] += ActivityLog::query()->where('loggable_type', $morph)->whereIn('loggable_id', $ids)->delete();
        }

        return $counts;
    }

    /**
     * 활동 로그 정리 대상을 모읍니다.
     *
     * @param  array<string, list<int>>  $targets  모프 타입 ⇒ 행 번호 (참조로 채운다)
     * @param  class-string  $model  모델 클래스
     * @param  list<int|string>  $ids  행 번호
     * @return void 반환값 없음
     */
    private function collect(array &$targets, string $model, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $morph = (new $model)->getMorphClass();
        $targets[$morph] = array_values(array_unique(array_merge($targets[$morph] ?? [], array_map('intval', $ids))));
    }
}
