<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Deletion;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\EcommerceUserProfile;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Models\SearchPreset;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 완전 삭제 시 이커머스 회원 소유 데이터 명시 삭제
 *
 * 종전에는 회원 행을 지우면 DB 외래키의 연쇄 삭제가 프리셋·찜·장바구니·쿠폰 발급·배송지·리뷰·프로필을 지웠다.
 * 그 경로는 훅·로그를 남기지 않고 리뷰 이미지 파일을 저장소에 고아로 남기며, 쿠폰 발급 수량을 되돌리지 않았다.
 * 이제 `core.user.before_delete` 리스너가 회원 행보다 먼저 명시적으로 지운다. 시험은 외래키 검사를 끈 채
 * 코어 회원 삭제를 실행해, 연쇄 삭제 없이도 고아가 0건인지 잰다.
 *
 * 시나리오 매니페스트: `tests/scenarios/parent-delete-explicit-children.yaml`
 */
class UserDeletionCleanupTest extends ModuleTestCase
{
    private StorageInterface $storage;

    /**
     * 큐·디스크를 가짜로 바꾸고 리뷰 서비스가 쓰는 저장소를 잡아 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        Storage::fake('modules');

        $this->storage = (new \ReflectionProperty(ProductReviewService::class, 'storage'))
            ->getValue(app(ProductReviewService::class));
    }

    /**
     * 외래키 검사를 끈 채 코어 회원 삭제를 실행하면 회원 소유 행이 하나도 남지 않고 주문은 남는다.
     *
     * @scenario case=user_delete
     *
     * @effects user_delete_leaves_no_owned_rows, user_delete_keeps_order_history
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_leaves_no_owned_rows_without_foreign_keys(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);

        SearchPreset::create([
            'user_id' => $user->id,
            'target_screen' => 'products',
            'preset_name' => '내 프리셋',
            'conditions' => ['keyword' => 'a'],
            'sort_order' => 0,
            'is_default' => false,
        ]);
        ProductWishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);
        Cart::create(['quantity' => 1, 'user_id' => $user->id, 'product_id' => $product->id, 'product_option_id' => $option->id]);
        foreach ([1, 2] as $n) {
            TempOrder::create(['user_id' => $user->id, 'items' => [], 'calculation_input' => [], 'calculation_result' => [], 'expires_at' => now()->addHour()]);
        }
        UserAddress::factory()->create(['user_id' => $user->id]);
        EcommerceUserProfile::create(['user_id' => $user->id, 'preferred_currency' => 'KRW']);
        $coupon = $this->coupon();
        $this->issue($coupon, $user, CouponIssueRecordStatus::AVAILABLE);
        $review = ProductReview::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->withoutForeignKeys(fn () => app(UserService::class)->deleteUser($user));

        foreach ([
            'ecommerce_search_presets',
            'ecommerce_product_wishlists',
            'ecommerce_carts',
            'ecommerce_temp_orders',
            'ecommerce_promotion_coupon_issues',
            'ecommerce_user_addresses',
            'ecommerce_product_reviews',
            'ecommerce_user_profiles',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), "{$table} 에 삭제된 회원의 행이 남았다");
        }
        $this->assertSame(0, DB::table('ecommerce_product_review_images')->where('review_id', $review->id)->count());
        $this->assertNull(User::query()->find($user->id), '회원 행은 지워진다');
        $this->assertNotNull(Order::withTrashed()->find($order->id), '주문은 거래 기록이라 남는다');
    }

    /**
     * 회원 삭제는 지워진 발급 가운데 쓰지 않은 것만큼 쿠폰 발급 수량을 되돌리고, 다른 회원의 발급분은 그대로 둔다.
     *
     * 취소된 발급은 취소 때 이미 수량에서 빠졌고, 사용한 발급은 한정 수량을 실제로 소진했으므로 되돌리지 않는다.
     *
     * @scenario case=user_delete
     *
     * @effects user_delete_releases_coupon_issued_count
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_releases_coupon_issued_count(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $coupon = $this->coupon();

        $this->issue($coupon, $user, CouponIssueRecordStatus::AVAILABLE);
        $this->issue($coupon, $user, CouponIssueRecordStatus::USED);
        $this->issue($coupon, $user, CouponIssueRecordStatus::CANCELLED);
        $kept = $this->issue($coupon, $other, CouponIssueRecordStatus::AVAILABLE);
        // 미취소 3건(회원 2 — 미사용 1·사용 1 — + 다른 회원 1)이 수량을 차지한다
        $coupon->update(['issued_count' => 3]);

        $this->withoutForeignKeys(fn () => app(UserService::class)->deleteUser($user));

        $this->assertSame(2, (int) $coupon->fresh()->issued_count, '삭제된 회원의 미사용 1건만 되돌린다(사용분은 계속 센다)');
        $this->assertNotNull(CouponIssue::query()->find($kept->id), '다른 회원의 발급분은 남는다');
        $this->assertSame(0, CouponIssue::query()->where('user_id', $user->id)->count());
    }

    /**
     * 회원 삭제는 그 회원 리뷰(휴지통 포함)의 이미지 행과 파일까지 지운다.
     *
     * @scenario case=user_delete
     *
     * @effects user_delete_removes_review_image_files
     *
     * @return void 반환값 없음
     */
    public function test_user_delete_removes_review_image_files(): void
    {
        $user = User::factory()->create();
        $review = ProductReview::factory()->create(['user_id' => $user->id]);
        $trashed = ProductReview::factory()->create(['user_id' => $user->id]);
        $trashed->delete();

        $image = $this->reviewImage($review);
        $trashedImage = $this->reviewImage($trashed);

        $this->withoutForeignKeys(fn () => app(UserService::class)->deleteUser($user));

        $this->assertNull(ProductReview::withTrashed()->find($review->id));
        $this->assertNull(ProductReview::withTrashed()->find($trashed->id), '휴지통에 든 리뷰도 지운다');
        $this->assertNull(ProductReviewImage::withTrashed()->find($image->id));
        $this->assertNull(ProductReviewImage::withTrashed()->find($trashedImage->id));
        $this->assertFalse($this->storage->exists('images', $image->path), '리뷰 이미지 파일을 지운다');
        $this->assertFalse($this->storage->exists('images', $trashedImage->path));
    }

    /**
     * 외래키 검사를 끈 채 콜백을 실행하고 반드시 다시 켭니다.
     *
     * @param  callable  $callback  실행할 삭제
     * @return void 반환값 없음
     */
    private function withoutForeignKeys(callable $callback): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * 수량 한정 쿠폰을 하나 만듭니다.
     *
     * @return Coupon 쿠폰
     */
    private function coupon(): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '회원 삭제 쿠폰', 'en' => 'User delete coupon'],
            'target_type' => 'order_amount',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'issue_method' => 'direct',
            'issue_condition' => 'manual',
            'issue_status' => 'issuing',
            'total_quantity' => 10,
            'issued_count' => 0,
            'per_user_limit' => 5,
            'valid_type' => 'period',
            'is_combinable' => false,
        ]);
    }

    /**
     * 쿠폰 발급 건을 하나 만듭니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @param  User  $user  받는 회원
     * @param  CouponIssueRecordStatus  $status  발급 상태
     * @return CouponIssue 발급 건
     */
    private function issue(Coupon $coupon, User $user, CouponIssueRecordStatus $status): CouponIssue
    {
        return CouponIssue::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'UD'.uniqid(),
            'status' => $status,
            'issued_at' => now(),
            'expired_at' => now()->addDays(30),
        ]);
    }

    /**
     * 리뷰 이미지 행과 실제 파일을 만듭니다.
     *
     * @param  ProductReview  $review  리뷰
     * @return ProductReviewImage 이미지 행
     */
    private function reviewImage(ProductReview $review): ProductReviewImage
    {
        $image = ProductReviewImage::factory()->create([
            'review_id' => $review->id,
            'disk' => $this->storage->getDisk(),
            'path' => 'reviews/'.$review->id.'/'.uniqid('user-delete-').'.jpg',
        ]);
        $this->storage->put('images', $image->path, 'jpg');

        return $image;
    }
}
