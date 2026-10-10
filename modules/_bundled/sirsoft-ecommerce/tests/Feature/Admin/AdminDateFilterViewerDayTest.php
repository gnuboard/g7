<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Http\Middleware\SetTimezone;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductInquiryFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductReviewFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\UserAddressFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductWishlistRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 이커머스 목록 날짜 필터의 「하루」 기준 회귀 테스트
 *
 * 결함: 화면에서 고른 날짜(Y-m-d)를 UTC 하루로 잘랐다(공용 `applyDateRangeFilter()` 또는
 * `Carbon::parse($date)->startOfDay()`). 목록은 날짜를 보는 사람의 시간대로 그리므로, 한국 시간
 * 새벽에 생긴 행이 목록에는 그날로 보이는데 그날로 거르면 빠지고 다음날 행이 섞였다.
 * 코어·게시판은 7.1.0 영역 C 후속에서 `applyViewerDateRangeFilter()` 로 고쳤고 이커머스는 호출
 * 교체가 남아 있었다. 쿠폰 유효기간·발급기간은 입력·표시가 사이트 표준시라 그 하루로 자른다.
 *
 * 공통 데이터: 2026-03-09 15:30 UTC = 2026-03-10 00:30 KST(그날 안),
 * 2026-03-10 15:30 UTC = 2026-03-11 00:30 KST(다음날). 2026-03-10 로 거르면 앞의 것만 남아야 한다.
 *
 * 시나리오 SSoT: tests/scenarios/admin-date-filter-viewer-day.yaml
 */
#[Group('ecommerce')]
class AdminDateFilterViewerDayTest extends ModuleTestCase
{
    private const DAY = '2026-03-10';

    private const INSIDE_UTC = '2026-03-09 15:30:00';

    private const OUTSIDE_UTC = '2026-03-10 15:30:00';

    private const RANGE = ['start_date' => self::DAY, 'end_date' => self::DAY];

    /**
     * 보는 사람의 시간대를 한국 시간으로 둡니다(SetTimezone 미들웨어가 요청마다 심는 값).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(SetTimezone::TIMEZONE_KEY, 'Asia/Seoul');
    }

    /**
     * 장바구니 관리 목록의 담은 날짜 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_carts
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_cart_created_range_follows_viewer_day(): void
    {
        $user = User::factory()->create();
        $inside = $this->stamp(CartFactory::new()->create(['user_id' => $user->id]), self::INSIDE_UTC);
        $outside = $this->stamp(CartFactory::new()->create(['user_id' => $user->id]), self::OUTSIDE_UTC);

        $page = app(CartRepositoryInterface::class)->paginateForAdmin(['user_id' => $user->id] + self::RANGE, 100);

        $this->assertSame([$inside], $this->ids($page->items()), "inside={$inside}, outside={$outside}");
    }

    /**
     * 찜 관리 목록의 찜한 날짜 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_wishlists
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_wishlist_created_range_follows_viewer_day(): void
    {
        $user = User::factory()->create();
        $inside = $this->stamp(ProductWishlist::create(['user_id' => $user->id, 'product_id' => ProductFactory::new()->create()->id]), self::INSIDE_UTC);
        $this->stamp(ProductWishlist::create(['user_id' => $user->id, 'product_id' => ProductFactory::new()->create()->id]), self::OUTSIDE_UTC);

        $page = app(ProductWishlistRepositoryInterface::class)->paginateForAdmin(['user_id' => $user->id] + self::RANGE, 100);

        $this->assertSame([$inside], $this->ids($page->items()));
    }

    /**
     * 배송지 관리 목록의 등록일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_user_addresses
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_address_created_range_follows_viewer_day(): void
    {
        $user = User::factory()->create();
        $inside = $this->stamp(UserAddressFactory::new()->create(['user_id' => $user->id]), self::INSIDE_UTC);
        $this->stamp(UserAddressFactory::new()->create(['user_id' => $user->id]), self::OUTSIDE_UTC);

        $page = app(UserAddressRepositoryInterface::class)->paginateForAdmin(['user_id' => $user->id] + self::RANGE, 100);

        $this->assertSame([$inside], $this->ids($page->items()));
    }

    /**
     * 상품 문의 목록(관리 목록·필터 목록)의 작성일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_inquiries
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_inquiry_created_range_follows_viewer_day(): void
    {
        $user = User::factory()->create();
        $product = ProductFactory::new()->create();
        $inside = $this->stamp(ProductInquiryFactory::new()->create(['user_id' => $user->id, 'product_id' => $product->id]), self::INSIDE_UTC);
        $outside = $this->stamp(ProductInquiryFactory::new()->create(['user_id' => $user->id, 'product_id' => $product->id]), self::OUTSIDE_UTC);

        $repository = app(ProductInquiryRepositoryInterface::class);

        $admin = $repository->paginateForAdmin(['user_id' => $user->id] + self::RANGE, 100);
        $this->assertSame([$inside], $this->ids($admin->items()));

        $filtered = $this->ids($repository->getListWithFilters(['date_from' => self::DAY, 'date_to' => self::DAY], 100)->items());
        $this->assertContains($inside, $filtered);
        $this->assertNotContains($outside, $filtered);
    }

    /**
     * 상품 목록의 등록일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_products
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_product_created_range_follows_viewer_day(): void
    {
        $inside = $this->stamp(ProductFactory::new()->create(), self::INSIDE_UTC);
        $outside = $this->stamp(ProductFactory::new()->create(), self::OUTSIDE_UTC);

        $ids = $this->ids(app(ProductRepositoryInterface::class)->getListWithFilters(['date_type' => 'created_at'] + self::RANGE, 100)->items());

        $this->assertContains($inside, $ids);
        $this->assertNotContains($outside, $ids);
    }

    /**
     * 주문 목록의 주문일 필터와 클레임 신청일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_orders
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_order_ordered_and_claim_requested_range_follow_viewer_day(): void
    {
        $inside = OrderFactory::new()->create();
        $outside = OrderFactory::new()->create();
        DB::table($inside->getTable())->where('id', $inside->id)->update(['ordered_at' => self::INSIDE_UTC]);
        DB::table($outside->getTable())->where('id', $outside->id)->update(['ordered_at' => self::OUTSIDE_UTC]);

        $repository = app(OrderRepositoryInterface::class);

        $ordered = $this->ids($repository->getListWithFilters(['date_type' => 'ordered_at'] + self::RANGE, 100)->items());
        $this->assertContains($inside->id, $ordered);
        $this->assertNotContains($outside->id, $ordered);

        $this->stamp(OrderReturnFactory::new()->create(['order_id' => $inside->id]), self::INSIDE_UTC);
        $this->stamp(OrderReturnFactory::new()->create(['order_id' => $outside->id]), self::OUTSIDE_UTC);

        $claimed = $this->ids($repository->getListWithFilters([
            'claim_requested_from' => self::DAY,
            'claim_requested_to' => self::DAY,
        ], 100)->items());
        $this->assertContains($inside->id, $claimed);
        $this->assertNotContains($outside->id, $claimed);
    }

    /**
     * 클레임 관리 목록과 구매자 신청내역의 신청일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_claims
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_claim_requested_range_follows_viewer_day(): void
    {
        $buyer = User::factory()->create();
        $order = OrderFactory::new()->create(['user_id' => $buyer->id]);
        $inside = $this->stamp(OrderReturnFactory::new()->create(['order_id' => $order->id]), self::INSIDE_UTC);
        $outside = $this->stamp(OrderReturnFactory::new()->create(['order_id' => $order->id]), self::OUTSIDE_UTC);

        $repository = app(OrderReturnRepositoryInterface::class);

        $admin = $this->ids($repository->paginateForAdmin(['from' => self::DAY, 'to' => self::DAY], 100)->items());
        $this->assertContains($inside, $admin);
        $this->assertNotContains($outside, $admin);

        $this->assertSame(1, $repository->countForUser($buyer->id, ['from' => self::DAY, 'to' => self::DAY]));
    }

    /**
     * 마일리지 내역의 일시 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_mileage_transactions
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_mileage_transaction_created_range_follows_viewer_day(): void
    {
        $user = User::factory()->create();
        $inside = $this->stamp($this->mileage($user), self::INSIDE_UTC);
        $this->stamp($this->mileage($user), self::OUTSIDE_UTC);

        $page = app(MileageTransactionRepositoryInterface::class)->paginateWithFilters(['user_id' => $user->id] + self::RANGE, 100);

        $this->assertSame([$inside], $this->ids($page->items()));
    }

    /**
     * 리뷰 관리 목록의 작성일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_reviews
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_review_created_range_follows_viewer_day(): void
    {
        $inside = $this->stamp(ProductReviewFactory::new()->create(), self::INSIDE_UTC);
        $outside = $this->stamp(ProductReviewFactory::new()->create(), self::OUTSIDE_UTC);

        $ids = $this->ids(app(ProductReviewRepositoryInterface::class)->getListWithFilters(self::RANGE, 100)->items());

        $this->assertContains($inside, $ids);
        $this->assertNotContains($outside, $ids);
    }

    /**
     * 쿠폰 목록의 등록일은 보는 사람의 하루, 유효기간·발급기간은 사이트 표준시 하루로 자른다.
     *
     * 유효기간·발급기간은 입력(`fromSiteDateStartOfDay`·`fromSiteDateTime`)과 표시
     * (`formatDateStringForSite`)가 모두 사이트 표준시라, 보는 사람 시간대가 달라도 사이트 날짜로
     * 걸러야 목록에 보이는 날짜와 맞는다. 보는 사람을 UTC 로 두어 두 기준이 갈리게 한다.
     *
     * @scenario surface=admin_coupons
     *
     * @effects ecommerce_date_filters_follow_viewer_timezone_day, coupon_validity_filters_follow_site_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_admin_coupon_created_follows_viewer_day_and_validity_follows_site_day(): void
    {
        $repository = app(CouponRepositoryInterface::class);

        $created = ['created_start_date' => self::DAY, 'created_end_date' => self::DAY];
        $inside = $this->stamp($this->coupon(), self::INSIDE_UTC);
        $outside = $this->stamp($this->coupon(), self::OUTSIDE_UTC);

        $ids = $this->ids($repository->paginate($created, 200)->items());
        $this->assertContains($inside, $ids);
        $this->assertNotContains($outside, $ids);

        // 사이트 = 한국 시간, 보는 사람 = UTC.
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        app()->instance(SetTimezone::TIMEZONE_KEY, 'UTC');

        // 사이트 2026-03-10 하루 = 2026-03-09 15:00 ~ 2026-03-10 14:59:59 UTC.
        $siteDay = $this->coupon([
            'valid_from' => '2026-03-09 15:00:00',
            'valid_to' => '2026-03-10 14:59:59',
            'issue_from' => '2026-03-09 15:00:00',
            'issue_to' => '2026-03-10 14:59:59',
        ])->id;
        // 사이트 2026-03-11 05:00 에 끝나는 쿠폰 — 사이트 날짜로는 3월 11일이다.
        $siteNextDay = $this->coupon([
            'valid_from' => '2026-03-10 20:00:00',
            'valid_to' => '2026-03-10 20:00:00',
            'issue_from' => '2026-03-10 20:00:00',
            'issue_to' => '2026-03-10 20:00:00',
        ])->id;

        foreach (['valid', 'issue'] as $axis) {
            $from = $this->ids($repository->paginate(["{$axis}_start_date" => self::DAY], 200)->items());
            $this->assertContains($siteDay, $from, "{$axis}_start_date 는 사이트 하루 시작부터");

            $to = $this->ids($repository->paginate(["{$axis}_end_date" => self::DAY], 200)->items());
            $this->assertContains($siteDay, $to, "{$axis}_end_date 는 사이트 하루 끝까지");
            $this->assertNotContains($siteNextDay, $to, "{$axis}_end_date 는 사이트 다음날을 넣지 않는다");
        }
    }

    /**
     * 행의 created_at 을 UTC 시각으로 고정합니다.
     *
     * @param  Model  $model  대상 모델
     * @param  string  $utc  UTC 시각 (Y-m-d H:i:s)
     * @return int 모델 ID
     */
    private function stamp(Model $model, string $utc): int
    {
        DB::table($model->getTable())->where($model->getKeyName(), $model->getKey())->update(['created_at' => $utc]);

        return (int) $model->getKey();
    }

    /**
     * 목록 항목의 ID 를 정수 배열로 뽑습니다.
     *
     * @param  array<int, mixed>  $items  목록 항목
     * @return array<int, int> ID 목록
     */
    private function ids(array $items): array
    {
        return collect($items)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * 마일리지 적립 거래를 하나 만듭니다.
     *
     * @param  User  $user  회원
     * @return MileageTransaction 거래
     */
    private function mileage(User $user): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $user->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000,
            'remaining_amount' => 1000,
            'balance_after' => 1000,
        ]);
    }

    /**
     * 쿠폰을 하나 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return Coupon 쿠폰
     */
    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '날짜 필터 쿠폰', 'en' => 'Date filter coupon'],
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'issue_status' => 'issuing',
            'total_quantity' => 10,
            'issued_count' => 0,
            'per_user_limit' => 1,
            'valid_type' => 'period',
            'is_combinable' => false,
        ], $overrides));
    }
}
