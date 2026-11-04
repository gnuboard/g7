<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryImageController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductInquiryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductReviewController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\PublicCouponController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\CategoryImageService;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductInquiryService;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Services\ProductWishlistService;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * 쇼핑몰 화면 공개 API 가 알 수 없는 이유로 실패하면 원인을 서버 로그에 남기는지 검증한다
 *
 * 배경: 공개 컨트롤러의 광역 catch(Exception) 상당수가 아무것도 기록하지 않고 500(또는 400)만 돌려줘,
 * 상품 목록 API 가 실제 DB 오류(FULLTEXT 오류 191)로 500 을 낼 때 로그에 흔적이 남지 않았다.
 * 브라우저에서는 알 수 없는 예외를 일으킬 수 없어 장애 주입으로 잰다 — 컨트롤러가 받는 서비스만
 * 예외를 던지는 목으로 바꾸고(컨트롤러 바인딩에만 주입해 다른 경로의 같은 서비스는 실제 객체 그대로),
 * 응답 상태·본문이 종전과 같고 오류 로그가 예외 종류·메시지·위치를 담는지 단언한다.
 *
 * 시나리오 매니페스트(마커 아님 — 평문): tests/scenarios/public-api-unexpected-failure-log.yaml.
 */
class PublicApiUnexpectedFailureLogTest extends ModuleTestCase
{
    /**
     * 비회원 권한 캐시를 비웁니다.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * 공개 상품 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=product, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @param  string  $method  서비스에서 예외를 던질 메서드
     * @param  string  $httpMethod  HTTP 메서드
     * @param  string  $uri  요청 경로 (`{product}` 는 만든 상품 ID 로 바뀐다)
     * @param  string  $messageKey  응답 메시지 키
     * @param  string  $logMessage  오류 로그 메시지
     * @return void 없음
     */
    #[DataProvider('productEndpoints')]
    public function test_product_api_logs_unexpected_failure(string $method, string $httpMethod, string $uri, string $messageKey, string $logMessage): void
    {
        $this->assertUnexpectedFailureLogged(
            ProductController::class, ProductService::class, $method,
            $httpMethod, $uri, [], false, 500, $messageKey, $logMessage,
        );
    }

    /**
     * 공개 상품 API 케이스
     *
     * @return array<string, array{string, string, string, string, string}> 케이스 목록
     */
    public static function productEndpoints(): array
    {
        return [
            'index' => ['getPublicList', 'GET', '/products', 'messages.products.fetch_failed', 'products.index failed'],
            'popular' => ['getPopularProducts', 'GET', '/products/popular', 'messages.products.fetch_failed', 'products.popular failed'],
            'related' => ['getRelatedProducts', 'GET', '/products/{product}/related', 'messages.products.fetch_failed', 'products.related failed'],
            'new' => ['getNewProducts', 'GET', '/products/new', 'messages.products.fetch_failed', 'products.new failed'],
            'recent' => ['getProductsByIds', 'GET', '/products/recent?ids={product}', 'messages.products.fetch_failed', 'products.recent failed'],
            'show' => ['getDetail', 'GET', '/products/{product}', 'messages.products.fetch_failed', 'products.show failed'],
        ];
    }

    /**
     * 장바구니 키 발급·조회·개수 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=cart, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @param  string  $method  서비스에서 예외를 던질 메서드
     * @param  string  $httpMethod  HTTP 메서드
     * @param  string  $uri  요청 경로
     * @param  bool  $asUser  회원으로 요청하는지
     * @param  string  $messageKey  응답 메시지 키
     * @param  string  $logMessage  오류 로그 메시지
     * @return void 없음
     */
    #[DataProvider('cartEndpoints')]
    public function test_cart_api_logs_unexpected_failure(string $method, string $httpMethod, string $uri, bool $asUser, string $messageKey, string $logMessage): void
    {
        $this->assertUnexpectedFailureLogged(
            CartController::class, CartService::class, $method,
            $httpMethod, $uri, [], $asUser, 500, $messageKey, $logMessage,
        );
    }

    /**
     * 장바구니 API 케이스
     *
     * @return array<string, array{string, string, string, bool, string, string}> 케이스 목록
     */
    public static function cartEndpoints(): array
    {
        return [
            'issue_key' => ['issueCartKey', 'POST', '/cart/key', false, 'messages.cart.key_issue_failed', 'cart.issue_key failed'],
            'index' => ['getCartWithCalculation', 'GET', '/cart', true, 'messages.cart.fetch_failed', 'cart.index failed'],
            'count' => ['getItemCount', 'GET', '/cart/count', true, 'messages.cart.fetch_failed', 'cart.count failed'],
        ];
    }

    /**
     * 공개 분류 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=category, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @param  string  $method  서비스에서 예외를 던질 메서드
     * @param  string  $uri  요청 경로
     * @param  string  $logMessage  오류 로그 메시지
     * @return void 없음
     */
    #[DataProvider('categoryEndpoints')]
    public function test_category_api_logs_unexpected_failure(string $method, string $uri, string $logMessage): void
    {
        $this->assertUnexpectedFailureLogged(
            CategoryController::class, CategoryService::class, $method,
            'GET', $uri, [], false, 500, 'messages.categories.fetch_failed', $logMessage,
        );
    }

    /**
     * 공개 분류 API 케이스
     *
     * @return array<string, array{string, string, string}> 케이스 목록
     */
    public static function categoryEndpoints(): array
    {
        return [
            'index' => ['getPublicCategoryTree', '/categories', 'categories.index failed'],
            'show' => ['getPublicCategoryBySlug', '/categories/fault-category', 'categories.show failed'],
        ];
    }

    /**
     * 분류 이미지 내려받기가 예상하지 못한 예외를 만나면 종전 응답(400)을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=category_image, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @return void 없음
     */
    public function test_category_image_download_logs_unexpected_failure(): void
    {
        $this->assertUnexpectedFailureLogged(
            CategoryImageController::class, CategoryImageService::class, 'download',
            'GET', '/category-image/abcdef123456', [], false, 400, 'exceptions.operation_failed',
            'category_image.download failed',
        );
    }

    /**
     * 공개 쇼핑몰 설정 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=settings, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @param  string  $method  서비스에서 예외를 던질 메서드
     * @param  string  $uri  요청 경로
     * @param  string  $logMessage  오류 로그 메시지
     * @return void 없음
     */
    #[DataProvider('settingsEndpoints')]
    public function test_settings_api_logs_unexpected_failure(string $method, string $uri, string $logMessage): void
    {
        $this->assertUnexpectedFailureLogged(
            EcommerceSettingsController::class, EcommerceSettingsService::class, $method,
            'GET', $uri, [], false, 500, 'messages.settings.fetch_failed', $logMessage,
        );
    }

    /**
     * 공개 쇼핑몰 설정 API 케이스
     *
     * @return array<string, array{string, string, string}> 케이스 목록
     */
    public static function settingsEndpoints(): array
    {
        return [
            'shipping' => ['getSettings', '/settings/shipping', 'settings.shipping failed'],
            'payment' => ['getPublicPaymentSettings', '/settings/payment', 'settings.payment failed'],
            'review' => ['getSettings', '/settings/review', 'settings.review failed'],
            'checkout' => ['getSettings', '/settings/checkout', 'settings.checkout failed'],
        ];
    }

    /**
     * 상품 문의 목록·작성 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=inquiry, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @param  string  $method  서비스에서 예외를 던질 메서드
     * @param  string  $httpMethod  HTTP 메서드
     * @param  array<string, mixed>  $payload  요청 본문
     * @param  bool  $asUser  회원으로 요청하는지
     * @param  string  $messageKey  응답 메시지 키
     * @param  string  $logMessage  오류 로그 메시지
     * @return void 없음
     */
    #[DataProvider('inquiryEndpoints')]
    public function test_inquiry_api_logs_unexpected_failure(string $method, string $httpMethod, array $payload, bool $asUser, string $messageKey, string $logMessage): void
    {
        $this->assertUnexpectedFailureLogged(
            ProductInquiryController::class, ProductInquiryService::class, $method,
            $httpMethod, '/products/{product}/inquiries', $payload, $asUser, 500, $messageKey, $logMessage,
        );
    }

    /**
     * 상품 문의 API 케이스
     *
     * @return array<string, array{string, string, array<string, mixed>, bool, string, string}> 케이스 목록
     */
    public static function inquiryEndpoints(): array
    {
        return [
            'index' => ['getProductInquiries', 'GET', [], false, 'messages.inquiries.fetch_failed', 'inquiry.index failed'],
            'store' => ['createInquiry', 'POST', ['content' => '장애 주입 문의'], true, 'messages.inquiries.create_failed', 'inquiry.store failed'],
        ];
    }

    /**
     * 상품 후기 목록 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=review, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @return void 없음
     */
    public function test_review_list_api_logs_unexpected_failure(): void
    {
        $this->assertUnexpectedFailureLogged(
            ProductReviewController::class, ProductReviewService::class, 'getProductReviews',
            'GET', '/products/{product}/reviews', [], false, 500, 'messages.reviews.fetch_failed',
            'review.index failed',
        );
    }

    /**
     * 상품 상세의 내려받을 수 있는 쿠폰 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=coupon, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @return void 없음
     */
    public function test_downloadable_coupons_api_logs_unexpected_failure(): void
    {
        $this->assertUnexpectedFailureLogged(
            PublicCouponController::class, UserCouponService::class, 'getProductDownloadableCoupons',
            'GET', '/products/{product}/downloadable-coupons', [], false, 500,
            'messages.coupon.downloadable_fetch_failed', 'products.downloadable_coupons failed',
        );
    }

    /**
     * 찜 토글·목록·삭제 API 가 예상하지 못한 예외를 만나면 같은 응답을 내고 원인을 오류 로그로 남긴다.
     *
     * @scenario controller=wishlist, failure=unexpected_exception
     *
     * @effects unexpected_failure_logged_with_cause, response_status_and_body_unchanged
     *
     * @param  string  $method  서비스에서 예외를 던질 메서드
     * @param  string  $httpMethod  HTTP 메서드
     * @param  string  $uri  요청 경로
     * @param  array<string, mixed>  $payload  요청 본문
     * @param  string  $messageKey  응답 메시지 키
     * @param  string  $logMessage  오류 로그 메시지
     * @return void 없음
     */
    #[DataProvider('wishlistEndpoints')]
    public function test_wishlist_api_logs_unexpected_failure(string $method, string $httpMethod, string $uri, array $payload, string $messageKey, string $logMessage): void
    {
        $this->assertUnexpectedFailureLogged(
            WishlistController::class, ProductWishlistService::class, $method,
            $httpMethod, $uri, $payload, true, 500, $messageKey, $logMessage,
        );
    }

    /**
     * 찜 API 케이스
     *
     * @return array<string, array{string, string, string, array<string, mixed>, string, string}> 케이스 목록
     */
    public static function wishlistEndpoints(): array
    {
        return [
            'toggle' => ['toggle', 'POST', '/wishlist/toggle', ['product_id' => '{product}'], 'messages.wishlist.toggle_failed', 'wishlist.toggle failed'],
            'index' => ['getByUser', 'GET', '/wishlist', [], 'messages.wishlist.retrieve_failed', 'wishlist.index failed'],
            'destroy' => ['destroy', 'DELETE', '/wishlist/1', [], 'messages.wishlist.delete_failed', 'wishlist.destroy failed'],
        ];
    }

    /**
     * 컨트롤러가 받는 서비스의 한 메서드가 예외를 던지게 하고, 응답이 종전과 같으며 원인이 오류 로그에 남는지 단언합니다.
     *
     * @param  class-string  $controller  대상 공개 컨트롤러
     * @param  class-string  $service  컨트롤러 생성자가 받는 서비스
     * @param  string  $method  예외를 던질 서비스 메서드
     * @param  string  $httpMethod  HTTP 메서드
     * @param  string  $uri  모듈 API 기준 경로 (`{product}` 는 만든 상품 ID 로 바뀐다)
     * @param  array<string, mixed>  $payload  요청 본문 (`{product}` 값은 만든 상품 ID 로 바뀐다)
     * @param  bool  $asUser  회원으로 요청하는지
     * @param  int  $status  기대 상태코드
     * @param  string  $messageKey  기대 응답 메시지 키 (모듈 도메인)
     * @param  string  $logMessage  기대 오류 로그 메시지
     * @return void 없음
     */
    private function assertUnexpectedFailureLogged(
        string $controller,
        string $service,
        string $method,
        string $httpMethod,
        string $uri,
        array $payload,
        bool $asUser,
        int $status,
        string $messageKey,
        string $logMessage,
    ): void {
        $product = Product::factory()->onSale()->create();
        $fault = 'injected fault '.$logMessage;

        $mock = Mockery::mock($service);
        $mock->shouldReceive($method)->andThrow(new RuntimeException($fault));
        // 목은 이 컨트롤러에만 넣는다 — 같은 서비스를 쓰는 미들웨어·요청 검증·다른 경로는 실제 객체 그대로다.
        $this->app->bind($controller, fn () => new $controller($mock));
        Log::spy();

        $uri = str_replace('{product}', (string) $product->id, $uri);
        $payload = array_map(fn ($v) => $v === '{product}' ? $product->id : $v, $payload);

        $request = $asUser ? $this->actingAs($this->createUser()) : $this;
        $response = $request->json($httpMethod, '/api/modules/sirsoft-ecommerce'.$uri, $payload);

        $response->assertStatus($status);
        $response->assertExactJson([
            'success' => false,
            'message' => __('sirsoft-ecommerce::'.$messageKey),
        ]);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use ($logMessage, $fault): bool {
            return $message === $logMessage
                && ($context['exception'] ?? null) === RuntimeException::class
                && ($context['message'] ?? null) === $fault
                && str_contains((string) ($context['file'] ?? ''), ':');
        })->once();
    }

    /**
     * 테스트 뒤 Mockery 기대를 정리합니다.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
