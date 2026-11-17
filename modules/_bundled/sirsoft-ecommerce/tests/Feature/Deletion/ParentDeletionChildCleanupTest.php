<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Deletion;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOption;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Models\ProductWishlist;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품·옵션·추가옵션·주문·카테고리 삭제 시 하위 행 명시 삭제 (#888)
 *
 * 종전에는 상품 삭제가 장바구니·찜·쿠폰 적용 상품·리뷰를, 옵션 동기화가 그 옵션을 담은 장바구니를, 추가옵션
 * 동기화가 선택지를, 주문 삭제가 주문옵션 리뷰를, 카테고리 삭제가 쿠폰 적용 카테고리를 DB 외래키의 연쇄 삭제에
 * 맡겼다. 리뷰 이미지 파일은 저장소에 고아로 남고 장바구니 삭제 훅도 나가지 않았다. 시험은 외래키 검사를 끈 채
 * 삭제를 실행해, 연쇄 삭제 없이도 하위 행이 0건인지 잰다.
 *
 * 시나리오 매니페스트: `tests/scenarios/parent-delete-explicit-children.yaml`
 */
class ParentDeletionChildCleanupTest extends ModuleTestCase
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
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('modules');

        $this->storage = (new \ReflectionProperty(ProductReviewService::class, 'storage'))
            ->getValue(app(ProductReviewService::class));
    }

    /**
     * 외래키 검사를 끈 채 상품을 지우면 장바구니·찜·쿠폰 적용 상품·리뷰·리뷰 이미지가 하나도 남지 않는다.
     *
     * @scenario case=product_delete
     *
     * @effects product_delete_leaves_no_child_rows, product_delete_removes_review_image_files
     *
     * @return void 반환값 없음
     */
    public function test_product_delete_leaves_no_child_rows_without_foreign_keys(): void
    {
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);
        $shopper = User::factory()->create();

        Cart::create(['quantity' => 1, 'user_id' => $shopper->id, 'product_id' => $product->id, 'product_option_id' => $option->id]);
        ProductWishlist::create(['user_id' => $shopper->id, 'product_id' => $product->id]);
        $coupon = $this->coupon();
        DB::table('ecommerce_promotion_coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $product->id, 'type' => 'include']);
        // 주문 없이 등록한 후기(이관) — 주문 이력이 있는 상품은 삭제가 막히므로 상품 삭제가 만나는 리뷰는 이것뿐이다
        $review = ProductReview::factory()->create(['product_id' => $product->id, 'order_option_id' => null, 'user_id' => null, 'author_name' => '이관 작성자']);
        $image = $this->reviewImage($review);

        $this->withoutForeignKeys(fn () => app(ProductService::class)->delete($product));

        foreach ([
            'ecommerce_carts',
            'ecommerce_product_wishlists',
            'ecommerce_promotion_coupon_products',
            'ecommerce_product_reviews',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->where('product_id', $product->id)->count(), "{$table} 에 삭제된 상품의 행이 남았다");
        }
        $this->assertSame(0, DB::table('ecommerce_product_review_images')->where('review_id', $review->id)->count());
        $this->assertFalse($this->storage->exists('images', $image->path), '이관 후기 이미지 파일을 지운다');
    }

    /**
     * 상품 삭제는 장바구니 선택 삭제 훅을 지운 행 ID 로 발화한다.
     *
     * @scenario case=product_delete
     *
     * @effects product_delete_fires_cart_delete_hook
     *
     * @return void 반환값 없음
     */
    public function test_product_delete_fires_cart_delete_hook(): void
    {
        $product = Product::factory()->create();
        $option = ProductOption::factory()->create(['product_id' => $product->id]);
        $cart = Cart::create(['quantity' => 1, 'product_id' => $product->id, 'product_option_id' => $option->id]);

        $received = [];
        $listener = function (array $ids, int $deletedCount) use (&$received) {
            $received[] = [$ids, $deletedCount];
        };
        HookManager::addAction('sirsoft-ecommerce.cart.after_delete_multiple', $listener);

        try {
            app(ProductService::class)->delete($product);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.cart.after_delete_multiple', $listener);
        }

        $this->assertSame([[[$cart->id], 1]], $received);
    }

    /**
     * 옵션 동기화가 옵션을 지우면 그 옵션을 담은 장바구니만 지우고 남은 옵션의 장바구니는 둔다.
     *
     * @scenario case=option_sync
     *
     * @effects option_sync_removes_carts_of_removed_option
     *
     * @return void 반환값 없음
     */
    public function test_option_sync_removes_carts_of_removed_option(): void
    {
        $product = Product::factory()->create();
        $kept = ProductOption::factory()->create(['product_id' => $product->id]);
        $removed = ProductOption::factory()->create(['product_id' => $product->id]);
        $keptCart = Cart::create(['quantity' => 1, 'product_id' => $product->id, 'product_option_id' => $kept->id]);
        $removedCart = Cart::create(['quantity' => 1, 'product_id' => $product->id, 'product_option_id' => $removed->id]);

        $this->withoutForeignKeys(fn () => $this->callProtected('syncOptions', $product, [['id' => $kept->id]]));

        $this->assertNull(ProductOption::query()->find($removed->id));
        $this->assertNull(Cart::query()->find($removedCart->id), '사라진 옵션을 담은 장바구니를 지운다');
        $this->assertSame(0, Cart::query()->where('product_option_id', $removed->id)->count());
        $this->assertNotNull(Cart::query()->find($keptCart->id), '남은 옵션의 장바구니는 둔다');
    }

    /**
     * 추가옵션 동기화가 그룹을 지우면 그 그룹의 선택지가 하나도 남지 않는다.
     *
     * @scenario case=additional_option_sync
     *
     * @effects additional_option_sync_removes_values
     *
     * @return void 반환값 없음
     */
    public function test_additional_option_sync_removes_values_of_removed_group(): void
    {
        $product = Product::factory()->create();
        $group = ProductAdditionalOption::create([
            'product_id' => $product->id,
            'name' => ['ko' => '포장', 'en' => 'Wrapping'],
            'is_required' => false,
            'sort_order' => 0,
        ]);
        foreach (['선물 포장', '기본 포장'] as $index => $name) {
            ProductAdditionalOptionValue::create([
                'additional_option_id' => $group->id,
                'name' => ['ko' => $name, 'en' => $name],
                'price_adjustment' => 0,
                'is_default' => $index === 0,
                'is_active' => true,
                'allow_custom_text' => false,
                'sort_order' => $index,
            ]);
        }

        $this->withoutForeignKeys(fn () => $this->callProtected('syncAdditionalOptions', $product, []));

        $this->assertNull(ProductAdditionalOption::query()->find($group->id));
        $this->assertSame(0, ProductAdditionalOptionValue::query()->where('additional_option_id', $group->id)->count(), '선택지 고아가 남았다');
    }

    /**
     * 외래키 검사를 끈 채 주문을 지우면 주문옵션 리뷰·이미지 행·파일이 하나도 남지 않는다.
     *
     * @scenario case=order_delete
     *
     * @effects order_delete_removes_option_reviews_with_files
     *
     * @return void 반환값 없음
     */
    public function test_order_delete_removes_option_reviews_with_files(): void
    {
        $order = Order::factory()->create();
        $option = OrderOption::factory()->create(['order_id' => $order->id]);
        $review = ProductReview::factory()->create(['order_option_id' => $option->id, 'product_id' => $option->product_id]);
        $image = $this->reviewImage($review);

        $this->withoutForeignKeys(fn () => app(OrderService::class)->delete($order));

        $this->assertSame(0, DB::table('ecommerce_order_options')->where('order_id', $order->id)->count());
        $this->assertSame(0, DB::table('ecommerce_product_reviews')->where('order_option_id', $option->id)->count(), '주문옵션 리뷰 고아가 남았다');
        $this->assertSame(0, DB::table('ecommerce_product_review_images')->where('review_id', $review->id)->count());
        $this->assertFalse($this->storage->exists('images', $image->path), '리뷰 이미지 파일을 지운다');
    }

    /**
     * 외래키 검사를 끈 채 카테고리를 지우면 쿠폰 적용 카테고리 연결이 하나도 남지 않고 다른 카테고리 연결은 둔다.
     *
     * @scenario case=category_delete
     *
     * @effects category_delete_removes_coupon_category_links
     *
     * @return void 반환값 없음
     */
    public function test_category_delete_removes_coupon_category_links(): void
    {
        $category = $this->category('삭제 카테고리');
        $other = $this->category('남는 카테고리');
        $coupon = $this->coupon();
        DB::table('ecommerce_promotion_coupon_categories')->insert([
            ['coupon_id' => $coupon->id, 'category_id' => $category->id, 'type' => 'include'],
            ['coupon_id' => $coupon->id, 'category_id' => $other->id, 'type' => 'exclude'],
        ]);

        $this->withoutForeignKeys(fn () => app(CategoryService::class)->deleteCategory($category->id));

        $this->assertNull(Category::query()->find($category->id));
        $this->assertSame(0, DB::table('ecommerce_promotion_coupon_categories')->where('category_id', $category->id)->count(), '쿠폰 적용 카테고리 고아가 남았다');
        $this->assertSame(1, DB::table('ecommerce_promotion_coupon_categories')->where('category_id', $other->id)->count());
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
     * 상품 서비스의 보호 메서드(옵션·추가옵션 동기화)를 상품 수정과 같은 트랜잭션 안에서 부릅니다.
     *
     * @param  string  $method  메서드 이름
     * @param  Product  $product  대상 상품
     * @param  array<int, array<string, mixed>>  $items  동기화할 항목
     * @return void 반환값 없음
     */
    private function callProtected(string $method, Product $product, array $items): void
    {
        $service = app(ProductService::class);
        $reflection = new \ReflectionMethod($service, $method);

        DB::transaction(fn () => $reflection->invoke($service, $product, $items));
    }

    /**
     * 쿠폰을 하나 만듭니다.
     *
     * @return Coupon 쿠폰
     */
    private function coupon(): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '연결 정리 쿠폰', 'en' => 'Link cleanup coupon'],
            'target_type' => 'product_amount',
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
            'target_scope' => 'products',
        ]);
    }

    /**
     * 하위 카테고리·상품이 없는 카테고리를 만듭니다.
     *
     * @param  string  $name  카테고리 이름
     * @return Category 카테고리
     */
    private function category(string $name): Category
    {
        return Category::create([
            'name' => ['ko' => $name, 'en' => $name],
            'path' => '',
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => true,
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
            'path' => 'reviews/'.$review->id.'/'.uniqid('parent-delete-').'.jpg',
        ]);
        $this->storage->put('images', $image->path, 'jpg');

        return $image;
    }
}
