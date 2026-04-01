<?php

use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\BrandController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CategoryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\CouponController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceMailTemplateController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\EcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\OrderController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductCommonInfoController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductController as AdminProductController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductLabelController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductNoticeTemplateController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductOptionController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\SearchPresetController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ClaimReasonController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingCarrierController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ShippingPolicyController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CartController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryController as PublicCategoryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryImageController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CheckoutController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\EcommerceSettingsController as PublicEcommerceSettingsController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductController as PublicProductController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductImageController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\PublicCouponController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\WishlistController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Shop\PaymentConfigController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductInquiryController as AdminProductInquiryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ProductReviewController as AdminProductReviewController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductInquiryController as PublicProductInquiryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductReviewController as PublicProductReviewController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\OrderController as UserOrderController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductInquiryController as UserProductInquiryController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\ProductReviewController as UserProductReviewController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\ReviewImageController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserAddressController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserCouponController;
use Modules\Sirsoft\Ecommerce\Http\Controllers\User\UserMileageController;

/*
|--------------------------------------------------------------------------
| Ecommerce Module API Routes
|--------------------------------------------------------------------------
|
| 주의: ModuleRouteServiceProvider가 자동으로 prefix를 적용합니다.
| - URL prefix: 'api/modules/sirsoft-ecommerce'
| - Name prefix: 'api.modules.sirsoft-ecommerce.'
|
*/

/*
|--------------------------------------------------------------------------
| Public API Routes (인증 불필요)
|--------------------------------------------------------------------------
*/
// 공개 카테고리 API
// GET /api/modules/sirsoft-ecommerce/categories - 공개 카테고리 트리 조회
// GET /api/modules/sirsoft-ecommerce/categories/{slug} - slug로 공개 카테고리 상세 조회
Route::prefix('categories')->group(function () {
    Route::get('/', [PublicCategoryController::class, 'index'])
        ->name('categories.index');

    Route::get('/{slug}', [PublicCategoryController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('categories.show');
});

// 공개 상품 API
// GET /api/modules/sirsoft-ecommerce/products - 공개 상품 목록 조회
// GET /api/modules/sirsoft-ecommerce/products/popular - 인기 상품 조회
// GET /api/modules/sirsoft-ecommerce/products/new - 신상품 조회
// GET /api/modules/sirsoft-ecommerce/products/recent - 최근 본 상품 조회
// GET /api/modules/sirsoft-ecommerce/products/{id} - 공개 상품 상세 조회
Route::prefix('products')->middleware(['optional.sanctum', 'permission:user,sirsoft-ecommerce.user-products.read'])->group(function () {
    Route::get('/', [PublicProductController::class, 'index'])
        ->name('products.index');

    // 특수 엔드포인트 (와일드카드보다 먼저 정의)
    Route::get('/popular', [PublicProductController::class, 'popular'])
        ->name('products.popular');

    Route::get('/new', [PublicProductController::class, 'new'])
        ->name('products.new');

    Route::get('/recent', [PublicProductController::class, 'recent'])
        ->name('products.recent');

    Route::get('/{id}', [PublicProductController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('products.show');

    Route::get('/{productId}/downloadable-coupons', [PublicCouponController::class, 'downloadableCoupons'])
        ->where('productId', '[0-9]+')
        ->name('products.downloadable-coupons');

    // 상품 공개 리뷰 목록 및 별점 통계 조회
    Route::get('/{productId}/reviews', [PublicProductReviewController::class, 'index'])
        ->whereNumber('productId')
        ->name('products.reviews.index');

    // 상품 1:1 문의 목록 조회 (비회원 포함)
    // GET /api/modules/sirsoft-ecommerce/products/{productId}/inquiries
    Route::get('/{productId}/inquiries', [PublicProductInquiryController::class, 'index'])
        ->whereNumber('productId')
        ->name('products.inquiries.index');

    // 상품 1:1 문의 작성 (회원 전용)
    // POST /api/modules/sirsoft-ecommerce/products/{productId}/inquiries
    Route::post('/{productId}/inquiries', [PublicProductInquiryController::class, 'store'])
        ->whereNumber('productId')
        ->middleware('auth:sanctum')
        ->name('products.inquiries.store');
});

// 상품 이미지 서빙 API
// GET /api/modules/sirsoft-ecommerce/product-image/{hash} - 상품 이미지 다운로드
Route::get('product-image/{hash}', [ProductImageController::class, 'download'])
    ->where('hash', '[a-zA-Z0-9]{12}')
    ->name('product-image.download');

// 카테고리 이미지 서빙 API
// GET /api/modules/sirsoft-ecommerce/category-image/{hash} - 카테고리 이미지 다운로드
Route::get('category-image/{hash}', [CategoryImageController::class, 'download'])
    ->where('hash', '[a-zA-Z0-9]{12}')
    ->name('category-image.download');

// 리뷰 이미지 서빙 API (인증 불필요 - 해시 기반 공개 서빙)
// GET /api/modules/sirsoft-ecommerce/review-image/{hash} - 리뷰 이미지 다운로드
Route::get('review-image/{hash}', [ReviewImageController::class, 'download'])
    ->where('hash', '[a-zA-Z0-9]{12}')
    ->name('review-image.download');

// 장바구니 API (비회원/회원 모두 사용 가능)
// POST   /api/modules/sirsoft-ecommerce/cart/key - cart_key 발급 (비회원용)
// GET    /api/modules/sirsoft-ecommerce/cart - 장바구니 조회
// POST   /api/modules/sirsoft-ecommerce/cart - 장바구니 담기 (단일/복수 items[] 배열)
// PATCH  /api/modules/sirsoft-ecommerce/cart/{id}/quantity - 수량 변경
// PUT    /api/modules/sirsoft-ecommerce/cart/{id}/option - 옵션 변경
// DELETE /api/modules/sirsoft-ecommerce/cart/{id} - 단일 삭제
// DELETE /api/modules/sirsoft-ecommerce/cart - 선택 삭제 (body: {ids: []})
// DELETE /api/modules/sirsoft-ecommerce/cart/all - 전체 삭제
// POST   /api/modules/sirsoft-ecommerce/cart/merge - 비회원→회원 병합
// GET    /api/modules/sirsoft-ecommerce/cart/count - 아이템 수 조회
Route::prefix('cart')->middleware('optional.sanctum')->group(function () {
    // cart_key 발급 (비회원용) - 와일드카드보다 먼저 정의
    Route::post('/key', [CartController::class, 'issueCartKey'])
        ->name('cart.key');

    // 아이템 수 조회 - 와일드카드보다 먼저 정의
    Route::get('/count', [CartController::class, 'count'])
        ->name('cart.count');

    // 비회원→회원 병합 - 와일드카드보다 먼저 정의
    Route::post('/merge', [CartController::class, 'merge'])
        ->name('cart.merge');

    // 전체 삭제 - 와일드카드보다 먼저 정의
    Route::delete('/all', [CartController::class, 'destroyAll'])
        ->name('cart.destroy-all');

    // 장바구니 조회 (GET)
    Route::get('/', [CartController::class, 'index'])
        ->name('cart.index');

    // 장바구니 조회 (POST - selected_ids가 많을 때 사용)
    Route::post('/query', [CartController::class, 'index'])
        ->name('cart.query');

    // 장바구니 담기
    Route::post('/', [CartController::class, 'store'])
        ->name('cart.store');

    // 선택 삭제 (body: {ids: []})
    Route::delete('/', [CartController::class, 'destroyMultiple'])
        ->name('cart.destroy-multiple');

    // 수량 변경
    Route::patch('/{id}/quantity', [CartController::class, 'updateQuantity'])
        ->whereNumber('id')
        ->name('cart.update-quantity');

    // 옵션 변경
    Route::put('/{id}/option', [CartController::class, 'changeOption'])
        ->whereNumber('id')
        ->name('cart.change-option');

    // 단일 삭제
    Route::delete('/{id}', [CartController::class, 'destroy'])
        ->whereNumber('id')
        ->name('cart.destroy');
});

// 체크아웃 API (임시 주문)
// POST   /api/modules/sirsoft-ecommerce/checkout - 임시 주문 생성
// GET    /api/modules/sirsoft-ecommerce/checkout - 임시 주문 조회
// PUT    /api/modules/sirsoft-ecommerce/checkout - 임시 주문 업데이트 (쿠폰/마일리지 재계산)
// DELETE /api/modules/sirsoft-ecommerce/checkout - 임시 주문 삭제
// POST   /api/modules/sirsoft-ecommerce/checkout/extend - 임시 주문 만료 연장
Route::prefix('checkout')->middleware('optional.sanctum')->group(function () {
    // 임시 주문 생성
    Route::post('/', [CheckoutController::class, 'store'])
        ->name('checkout.store');

    // 임시 주문 조회
    Route::get('/', [CheckoutController::class, 'show'])
        ->name('checkout.show');

    // 임시 주문 업데이트 (쿠폰/마일리지 변경)
    Route::put('/', [CheckoutController::class, 'update'])
        ->name('checkout.update');

    // 임시 주문 삭제
    Route::delete('/', [CheckoutController::class, 'destroy'])
        ->name('checkout.destroy');

    // 임시 주문 만료 연장
    Route::post('/extend', [CheckoutController::class, 'extend'])
        ->name('checkout.extend');
});

// 공개 설정 API (체크아웃에서 필요한 설정 조회)
// GET /api/modules/sirsoft-ecommerce/settings/shipping - 배송 설정 조회
// GET /api/modules/sirsoft-ecommerce/settings/payment - 결제 설정 조회
// GET /api/modules/sirsoft-ecommerce/settings/checkout - 체크아웃 설정 조회 (배송+결제)
Route::prefix('settings')->group(function () {
    Route::get('/shipping', [PublicEcommerceSettingsController::class, 'shipping'])
        ->name('settings.shipping');

    Route::get('/payment', [PublicEcommerceSettingsController::class, 'payment'])
        ->name('settings.payment');

    Route::get('/checkout', [PublicEcommerceSettingsController::class, 'checkout'])
        ->name('settings.checkout');
});

// 결제 취소 기록 API (PG 결제창 닫기 시 호출, 비회원 지원)
// POST /api/modules/sirsoft-ecommerce/orders/{orderNumber}/cancel-payment
Route::post('orders/{orderNumber}/cancel-payment', [UserOrderController::class, 'cancelPayment'])
    ->middleware('optional.sanctum')
    ->name('orders.cancel-payment');

// PG 결제 클라이언트 설정 API (프론트엔드 SDK 키 조회)
// GET /api/modules/sirsoft-ecommerce/payments/client-config/{provider} - PG사별 클라이언트 설정
Route::get('payments/client-config/{provider}', [PaymentConfigController::class, 'clientConfig'])
    ->name('payments.client-config');

/*
|--------------------------------------------------------------------------
| Authenticated User API Routes (인증 사용자)
|--------------------------------------------------------------------------
*/
// 찜(위시리스트) API
// POST /api/modules/sirsoft-ecommerce/wishlist/toggle - 찜 토글
// GET  /api/modules/sirsoft-ecommerce/wishlist - 찜 목록 조회
// DELETE /api/modules/sirsoft-ecommerce/wishlist/{id} - 찜 삭제
Route::prefix('wishlist')->middleware('auth:sanctum')->group(function () {
    Route::post('/toggle', [WishlistController::class, 'toggle'])
        ->name('wishlist.toggle');

    Route::get('/', [WishlistController::class, 'index'])
        ->name('wishlist.index');

    Route::delete('/{id}', [WishlistController::class, 'destroy'])
        ->name('wishlist.destroy');
});

// 사용자 쿠폰함/마일리지 API
// GET  /api/modules/sirsoft-ecommerce/user/coupons - 쿠폰함 목록
// GET  /api/modules/sirsoft-ecommerce/user/coupons/available - 사용 가능 쿠폰 목록
// GET  /api/modules/sirsoft-ecommerce/user/mileage - 마일리지 잔액 조회
// GET  /api/modules/sirsoft-ecommerce/user/mileage/max-usable - 사용 가능 최대 마일리지
Route::prefix('user')->middleware('auth:sanctum')->group(function () {
    Route::prefix('coupons')->group(function () {
        Route::get('/', [UserCouponController::class, 'index'])
            ->name('user.coupons.index');

        Route::get('/available', [UserCouponController::class, 'available'])
            ->name('user.coupons.available');

        Route::get('/downloadable', [UserCouponController::class, 'downloadable'])
            ->name('user.coupons.downloadable');

        Route::post('/{couponId}/download', [UserCouponController::class, 'download'])
            ->where('couponId', '[0-9]+')
            ->name('user.coupons.download');
    });

    // 마일리지 API
    Route::prefix('mileage')->group(function () {
        Route::get('/', [UserMileageController::class, 'balance'])
            ->name('user.mileage.balance');

        Route::get('/max-usable', [UserMileageController::class, 'maxUsable'])
            ->name('user.mileage.max-usable');
    });

    // 주문 API
    // GET  /api/modules/sirsoft-ecommerce/user/orders - 주문 목록 조회 (마이페이지)
    // GET  /api/modules/sirsoft-ecommerce/user/orders/{id} - ID로 주문 상세 조회
    // GET  /api/modules/sirsoft-ecommerce/user/orders/{orderNumber} - 주문번호로 조회
    // POST /api/modules/sirsoft-ecommerce/user/orders - 주문 생성 (결제하기)
    Route::prefix('orders')->group(function () {
        Route::get('/', [UserOrderController::class, 'index'])
            ->name('user.orders.index');

        Route::get('/{id}', [UserOrderController::class, 'show'])
            ->whereNumber('id')
            ->name('user.orders.show-by-id');

        Route::get('/{orderNumber}', [UserOrderController::class, 'showByOrderNumber'])
            ->name('user.orders.show');

        Route::post('/', [UserOrderController::class, 'store'])
            ->middleware('permission:user,sirsoft-ecommerce.user-orders.create')
            ->name('user.orders.store');

        // POST /api/modules/sirsoft-ecommerce/user/orders/{id}/cancel - 주문 취소 (마이페이지)
        Route::post('/{id}/cancel', [UserOrderController::class, 'cancel'])
            ->whereNumber('id')
            ->middleware('permission:user,sirsoft-ecommerce.user-orders.cancel')
            ->name('user.orders.cancel');

        // POST /api/modules/sirsoft-ecommerce/user/orders/{id}/estimate-refund - 환불 예상금액 조회
        Route::post('/{id}/estimate-refund', [UserOrderController::class, 'estimateRefund'])
            ->whereNumber('id')
            ->middleware('permission:user,sirsoft-ecommerce.user-orders.cancel')
            ->name('user.orders.estimate-refund');

        // PUT /api/modules/sirsoft-ecommerce/user/orders/{id}/shipping-address - 배송지 변경
        Route::put('/{id}/shipping-address', [UserOrderController::class, 'updateShippingAddress'])
            ->whereNumber('id')
            ->name('user.orders.update-shipping-address');

        // POST /api/modules/sirsoft-ecommerce/user/orders/{id}/options/{optionId}/confirm - 구매확정
        Route::post('/{id}/options/{optionId}/confirm', [UserOrderController::class, 'confirmOption'])
            ->whereNumber('id')
            ->whereNumber('optionId')
            ->middleware('permission:user,sirsoft-ecommerce.user-orders.confirm')
            ->name('user.orders.confirm-option');
    });

    // 클래임 사유 조회 API (사용자용)
    // GET /api/modules/sirsoft-ecommerce/user/claim-reasons - 사용자 선택 가능한 사유 목록
    Route::get('/claim-reasons', [ClaimReasonController::class, 'userSelectableReasons'])
        ->middleware('permission:user,sirsoft-ecommerce.user-orders.cancel')
        ->name('user.claim-reasons.index');

    // 리뷰 API (인증 사용자)
    // GET    /api/modules/sirsoft-ecommerce/user/reviews/can-write/{orderOptionId} - 작성 가능 여부
    // POST   /api/modules/sirsoft-ecommerce/user/reviews - 리뷰 작성
    // DELETE /api/modules/sirsoft-ecommerce/user/reviews/{review} - 내 리뷰 삭제
    // POST   /api/modules/sirsoft-ecommerce/user/reviews/{review}/images - 이미지 업로드
    // DELETE /api/modules/sirsoft-ecommerce/user/reviews/{review}/images/{image} - 이미지 삭제
    Route::prefix('reviews')->group(function () {
        // 작성 가능 여부 확인 - 와일드카드보다 먼저 정의
        Route::get('/can-write/{orderOptionId}', [UserProductReviewController::class, 'canWrite'])
            ->whereNumber('orderOptionId')
            ->name('user.reviews.can-write');

        Route::post('/', [UserProductReviewController::class, 'store'])
            ->middleware('permission:user,sirsoft-ecommerce.user-reviews.write')
            ->name('user.reviews.store');

        Route::delete('/{review}', [UserProductReviewController::class, 'destroy'])
            ->middleware('permission:user,sirsoft-ecommerce.user-reviews.write')
            ->whereNumber('review')
            ->name('user.reviews.destroy');

        // 리뷰 이미지 관리
        Route::post('/{review}/images', [ReviewImageController::class, 'store'])
            ->middleware('permission:user,sirsoft-ecommerce.user-reviews.write')
            ->whereNumber('review')
            ->name('user.reviews.images.store');

        Route::delete('/{review}/images/{image}', [ReviewImageController::class, 'destroy'])
            ->middleware('permission:user,sirsoft-ecommerce.user-reviews.write')
            ->whereNumber('review')
            ->whereNumber('image')
            ->name('user.reviews.images.destroy');
    });

    // 상품 1:1 문의 마이페이지 / 수정 / 삭제 / 답변 API
    // GET    /api/modules/sirsoft-ecommerce/user/inquiries
    // PUT    /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}
    // DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}
    // POST   /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply
    // PUT    /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply
    // DELETE /api/modules/sirsoft-ecommerce/user/inquiries/{inquiryId}/reply
    Route::prefix('inquiries')->group(function () {
        Route::get('/', [UserProductInquiryController::class, 'index'])
            ->name('user.inquiries.index');

        Route::put('/{inquiryId}', [UserProductInquiryController::class, 'update'])
            ->whereNumber('inquiryId')
            ->name('user.inquiries.update');

        Route::delete('/{inquiryId}', [UserProductInquiryController::class, 'destroy'])
            ->whereNumber('inquiryId')
            ->name('user.inquiries.destroy');

        Route::post('/{inquiryId}/reply', [UserProductInquiryController::class, 'reply'])
            ->whereNumber('inquiryId')
            ->name('user.inquiries.reply');

        Route::put('/{inquiryId}/reply', [UserProductInquiryController::class, 'updateReply'])
            ->whereNumber('inquiryId')
            ->name('user.inquiries.reply.update');

        Route::delete('/{inquiryId}/reply', [UserProductInquiryController::class, 'destroyReply'])
            ->whereNumber('inquiryId')
            ->name('user.inquiries.reply.destroy');
    });

    // 배송지 관리 API
    // GET    /api/modules/sirsoft-ecommerce/user/addresses - 배송지 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/user/addresses - 배송지 등록
    // GET    /api/modules/sirsoft-ecommerce/user/addresses/{id} - 배송지 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/user/addresses/{id} - 배송지 수정
    // DELETE /api/modules/sirsoft-ecommerce/user/addresses/{id} - 배송지 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/user/addresses/{id}/default - 기본 배송지 설정
    Route::prefix('addresses')->group(function () {
        Route::get('/', [UserAddressController::class, 'index'])
            ->name('user.addresses.index');

        Route::post('/', [UserAddressController::class, 'store'])
            ->name('user.addresses.store');

        Route::get('/{id}', [UserAddressController::class, 'show'])
            ->whereNumber('id')
            ->name('user.addresses.show');

        Route::put('/{id}', [UserAddressController::class, 'update'])
            ->whereNumber('id')
            ->name('user.addresses.update');

        Route::delete('/{id}', [UserAddressController::class, 'destroy'])
            ->whereNumber('id')
            ->name('user.addresses.destroy');

        Route::patch('/{id}/default', [UserAddressController::class, 'setDefault'])
            ->whereNumber('id')
            ->name('user.addresses.set-default');
    });
});

/*
|--------------------------------------------------------------------------
| Admin API Routes (관리자 인증 필요)
|--------------------------------------------------------------------------
*/
// 관리자 영역 라우트
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {

    // 환경설정 API
    // GET  /api/modules/sirsoft-ecommerce/admin/settings - 전체 설정 조회
    // PUT  /api/modules/sirsoft-ecommerce/admin/settings - 설정 저장
    // GET  /api/modules/sirsoft-ecommerce/admin/settings/{category} - 카테고리별 조회
    Route::get('settings', [EcommerceSettingsController::class, 'index'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('admin.settings.index');

    Route::put('settings', [EcommerceSettingsController::class, 'store'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
        ->name('admin.settings.store');

    // PUT  /api/modules/sirsoft-ecommerce/admin/settings/banks - 은행 목록만 저장
    Route::put('settings/banks', [EcommerceSettingsController::class, 'storeBanks'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
        ->name('admin.settings.store-banks');

    Route::get('settings/{category}', [EcommerceSettingsController::class, 'show'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('admin.settings.show');

    Route::post('settings/clear-cache', [EcommerceSettingsController::class, 'clearCache'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
        ->name('admin.settings.clear-cache');

    // 메일 템플릿 관리
    // GET   /api/modules/sirsoft-ecommerce/admin/mail-templates - 목록 조회
    // PUT   /api/modules/sirsoft-ecommerce/admin/mail-templates/{ecommerceMailTemplate} - 수정
    // PATCH /api/modules/sirsoft-ecommerce/admin/mail-templates/{ecommerceMailTemplate}/toggle-active - 활성 토글
    // POST  /api/modules/sirsoft-ecommerce/admin/mail-templates/{ecommerceMailTemplate}/reset - 기본값 복원
    Route::prefix('mail-templates')->group(function () {
        Route::get('/', [EcommerceMailTemplateController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->name('admin.mail-templates.index');

        Route::put('{ecommerceMailTemplate}', [EcommerceMailTemplateController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->name('admin.mail-templates.update');

        Route::patch('{ecommerceMailTemplate}/toggle-active', [EcommerceMailTemplateController::class, 'toggleActive'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->name('admin.mail-templates.toggle-active');

        Route::post('preview', [EcommerceMailTemplateController::class, 'preview'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->name('admin.mail-templates.preview');

        Route::post('{ecommerceMailTemplate}/reset', [EcommerceMailTemplateController::class, 'reset'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->name('admin.mail-templates.reset');
    });

    // 배송정책 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/shipping-policies - 배송정책 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/shipping-policies - 배송정책 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/shipping-policies/active - 활성 배송정책 목록
    // GET    /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id} - 배송정책 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id} - 배송정책 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id} - 배송정책 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/shipping-policies/{id}/toggle-active - 사용여부 토글
    // DELETE /api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk - 일괄 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/shipping-policies/bulk-toggle-active - 일괄 사용여부 변경
    Route::prefix('shipping-policies')->group(function () {
        Route::get('/', [ShippingPolicyController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.read')
            ->name('admin.shipping-policies.index');

        Route::post('/', [ShippingPolicyController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.create')
            ->name('admin.shipping-policies.store');

        // 활성 배송정책 목록 (Select 옵션용) - 와일드카드보다 먼저 정의
        Route::get('/active', [ShippingPolicyController::class, 'activeList'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.read')
            ->name('admin.shipping-policies.active');

        // 일괄 삭제 - 와일드카드보다 먼저 정의
        Route::delete('/bulk', [ShippingPolicyController::class, 'bulkDestroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.delete')
            ->name('admin.shipping-policies.bulk-destroy');

        // 일괄 사용여부 변경 - 와일드카드보다 먼저 정의
        Route::patch('/bulk-toggle-active', [ShippingPolicyController::class, 'bulkToggleActive'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->name('admin.shipping-policies.bulk-toggle-active');

        Route::get('/{id}', [ShippingPolicyController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.read')
            ->whereNumber('id')
            ->name('admin.shipping-policies.show');

        Route::put('/{id}', [ShippingPolicyController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->whereNumber('id')
            ->name('admin.shipping-policies.update');

        Route::delete('/{id}', [ShippingPolicyController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.delete')
            ->whereNumber('id')
            ->name('admin.shipping-policies.destroy');

        Route::patch('/{id}/toggle-active', [ShippingPolicyController::class, 'toggleActive'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->whereNumber('id')
            ->name('admin.shipping-policies.toggle-active');

        Route::patch('/{id}/set-default', [ShippingPolicyController::class, 'setDefault'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->whereNumber('id')
            ->name('admin.shipping-policies.set-default');
    });

    // 배송사 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/shipping-carriers           - 배송사 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/shipping-carriers           - 배송사 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/shipping-carriers/active    - 활성 배송사 목록 (Select용)
    // GET    /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}      - 배송사 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}      - 배송사 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}      - 배송사 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/shipping-carriers/{id}/toggle-status - 상태 토글
    Route::prefix('shipping-carriers')->group(function () {
        Route::get('/', [ShippingCarrierController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->name('admin.shipping-carriers.index');

        Route::post('/', [ShippingCarrierController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->name('admin.shipping-carriers.store');

        // 활성 배송사 목록 (Select 옵션용) - 와일드카드보다 먼저 정의
        Route::get('/active', [ShippingCarrierController::class, 'active'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->name('admin.shipping-carriers.active');

        Route::get('/{id}', [ShippingCarrierController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->whereNumber('id')
            ->name('admin.shipping-carriers.show');

        Route::put('/{id}', [ShippingCarrierController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->whereNumber('id')
            ->name('admin.shipping-carriers.update');

        Route::delete('/{id}', [ShippingCarrierController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->whereNumber('id')
            ->name('admin.shipping-carriers.destroy');

        Route::patch('/{id}/toggle-status', [ShippingCarrierController::class, 'toggleStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->whereNumber('id')
            ->name('admin.shipping-carriers.toggle-status');
    });

    // 클래임 사유 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/claim-reasons           - 사유 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/claim-reasons           - 사유 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/claim-reasons/active    - 활성 사유 목록 (Select용)
    // GET    /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}      - 사유 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}      - 사유 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}      - 사유 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/claim-reasons/{id}/toggle-status - 상태 토글
    Route::prefix('claim-reasons')->group(function () {
        Route::get('/', [ClaimReasonController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->name('admin.claim-reasons.index');

        Route::post('/', [ClaimReasonController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->name('admin.claim-reasons.store');

        // 활성 사유 목록 (Select 옵션용) - 와일드카드보다 먼저 정의
        Route::get('/active', [ClaimReasonController::class, 'active'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->name('admin.claim-reasons.active');

        Route::get('/{id}', [ClaimReasonController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
            ->whereNumber('id')
            ->name('admin.claim-reasons.show');

        Route::put('/{id}', [ClaimReasonController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->whereNumber('id')
            ->name('admin.claim-reasons.update');

        Route::delete('/{id}', [ClaimReasonController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->whereNumber('id')
            ->name('admin.claim-reasons.destroy');

        Route::patch('/{id}/toggle-status', [ClaimReasonController::class, 'toggleStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.settings.update')
            ->whereNumber('id')
            ->name('admin.claim-reasons.toggle-status');
    });

    // 추가배송비 템플릿 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/extra-fee-templates - 템플릿 목록 조회
    // GET    /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/active-settings - 활성 템플릿 (배송정책용)
    // POST   /api/modules/sirsoft-ecommerce/admin/extra-fee-templates - 템플릿 생성
    // POST   /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk - 일괄 등록 (CSV/Excel)
    // GET    /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id} - 템플릿 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id} - 템플릿 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id} - 템플릿 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id}/toggle-active - 사용여부 토글
    // DELETE /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk - 일괄 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active - 일괄 사용여부 변경
    Route::prefix('extra-fee-templates')->group(function () {
        Route::get('/', [ExtraFeeTemplateController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.read')
            ->name('admin.extra-fee-templates.index');

        // 활성 템플릿 목록 (배송정책 연동용) - 와일드카드보다 먼저 정의
        Route::get('/active-settings', [ExtraFeeTemplateController::class, 'activeSettings'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.read')
            ->name('admin.extra-fee-templates.active-settings');

        // 일괄 등록 (CSV/Excel 업로드) - 와일드카드보다 먼저 정의
        Route::post('/bulk', [ExtraFeeTemplateController::class, 'bulkStore'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.create')
            ->name('admin.extra-fee-templates.bulk-store');

        // 일괄 삭제 - 와일드카드보다 먼저 정의
        Route::delete('/bulk', [ExtraFeeTemplateController::class, 'bulkDestroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.delete')
            ->name('admin.extra-fee-templates.bulk-destroy');

        // 일괄 사용여부 변경 - 와일드카드보다 먼저 정의
        Route::patch('/bulk-toggle-active', [ExtraFeeTemplateController::class, 'bulkToggleActive'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->name('admin.extra-fee-templates.bulk-toggle-active');

        Route::post('/', [ExtraFeeTemplateController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.create')
            ->name('admin.extra-fee-templates.store');

        Route::get('/{id}', [ExtraFeeTemplateController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.read')
            ->whereNumber('id')
            ->name('admin.extra-fee-templates.show');

        Route::put('/{id}', [ExtraFeeTemplateController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->whereNumber('id')
            ->name('admin.extra-fee-templates.update');

        Route::delete('/{id}', [ExtraFeeTemplateController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.delete')
            ->whereNumber('id')
            ->name('admin.extra-fee-templates.destroy');

        Route::patch('/{id}/toggle-active', [ExtraFeeTemplateController::class, 'toggleActive'])
            ->middleware('permission:admin,sirsoft-ecommerce.shipping-policies.update')
            ->whereNumber('id')
            ->name('admin.extra-fee-templates.toggle-active');
    });

    // 상품 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/products - 상품 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/products - 상품 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/products/{id} - 상품 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/products/{product} - 상품 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/products/{product} - 상품 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/products/bulk-update - 통합 일괄 업데이트
    // PATCH  /api/modules/sirsoft-ecommerce/admin/products/bulk-status - 일괄 상태 변경 (deprecated)
    // PATCH  /api/modules/sirsoft-ecommerce/admin/products/bulk-price - 일괄 가격 변경 (deprecated)
    // PATCH  /api/modules/sirsoft-ecommerce/admin/products/bulk-stock - 일괄 재고 변경 (deprecated)
    Route::prefix('products')->group(function () {
        Route::get('/', [AdminProductController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.read')
            ->name('admin.products.index');

        Route::post('/', [AdminProductController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.create')
            ->name('admin.products.store');

        // 상품코드 자동 생성
        // POST /api/modules/sirsoft-ecommerce/admin/products/generate-code
        Route::post('/generate-code', [AdminProductController::class, 'generateCode'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.create')
            ->name('admin.products.generate-code');

        // 상품코드로 조회
        // GET /api/modules/sirsoft-ecommerce/admin/products/by-code/{code}
        Route::get('/by-code/{code}', [AdminProductController::class, 'showByCode'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.read')
            ->name('admin.products.show-by-code');

        // 상품코드로 수정
        // PUT /api/modules/sirsoft-ecommerce/admin/products/by-code/{code}
        Route::put('/by-code/{code}', [AdminProductController::class, 'updateByCode'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.update-by-code');

        // ID 또는 상품코드(product_code)로 조회 가능
        Route::get('/{identifier}', [AdminProductController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.read')
            ->where('identifier', '[0-9a-zA-Z]+')
            ->name('admin.products.show');

        // 상품 폼 데이터 조회 (수정 모드용)
        // GET /api/modules/sirsoft-ecommerce/admin/products/{product}/form
        Route::get('/{product}/form', [AdminProductController::class, 'showForForm'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.read')
            ->where('product', '[0-9a-zA-Z]+')
            ->name('admin.products.show-for-form');

        // 상품 복사용 데이터 조회
        // GET /api/modules/sirsoft-ecommerce/admin/products/{product}/copy
        Route::get('/{product}/copy', [AdminProductController::class, 'showForCopy'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.read')
            ->where('product', '[0-9a-zA-Z]+')
            ->name('admin.products.show-for-copy');

        // 상품 삭제 가능 여부 확인
        // GET /api/modules/sirsoft-ecommerce/admin/products/{product}/can-delete
        Route::get('/{product}/can-delete', [AdminProductController::class, 'canDelete'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.delete')
            ->where('product', '[0-9a-zA-Z]+')
            ->name('admin.products.can-delete');

        // 상품 활동 로그 조회
        // GET /api/modules/sirsoft-ecommerce/admin/products/{product}/logs
        Route::get('/{product}/logs', [AdminProductController::class, 'logs'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.read')
            ->where('product', '[0-9a-zA-Z]+')
            ->name('admin.products.logs');

        Route::put('/{product}', [AdminProductController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.update');

        Route::delete('/{product}', [AdminProductController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.delete')
            ->name('admin.products.destroy');

        // 통합 일괄 업데이트 (일괄 변경 + 개별 인라인 수정 동시 처리)
        Route::patch('/bulk-update', [AdminProductController::class, 'bulkUpdate'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.bulk-update');

        // 기존 일괄 처리 (deprecated - 하위 호환성 유지)
        Route::patch('/bulk-status', [AdminProductController::class, 'bulkUpdateStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.bulk-status');

        Route::patch('/bulk-price', [AdminProductController::class, 'bulkUpdatePrice'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.bulk-price');

        Route::patch('/bulk-stock', [AdminProductController::class, 'bulkUpdateStock'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.bulk-stock');

        // 상품 이미지 관리
        // POST   /api/modules/sirsoft-ecommerce/admin/products/images - 임시 업로드
        // POST   /api/modules/sirsoft-ecommerce/admin/products/{productId}/images - 상품에 직접 업로드
        // DELETE /api/modules/sirsoft-ecommerce/admin/products/images/{id} - 이미지 삭제
        // PATCH  /api/modules/sirsoft-ecommerce/admin/products/images/reorder - 순서 변경
        // PATCH  /api/modules/sirsoft-ecommerce/admin/products/{productId}/images/{imageId}/thumbnail - 대표 이미지 설정
        Route::post('/images', [AdminProductController::class, 'uploadImage'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.images.upload-temp');

        Route::post('/{productId}/images', [AdminProductController::class, 'uploadImage'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->whereNumber('productId')
            ->name('admin.products.images.upload');

        Route::delete('/images/{id}', [AdminProductController::class, 'deleteImage'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->whereNumber('id')
            ->name('admin.products.images.delete');

        Route::patch('/images/reorder', [AdminProductController::class, 'reorderImages'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.products.images.reorder');

        Route::patch('/{productId}/images/{imageId}/thumbnail', [AdminProductController::class, 'setThumbnail'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->whereNumber('productId')
            ->whereNumber('imageId')
            ->name('admin.products.images.set-thumbnail');
    });

    // 상품 옵션 일괄 처리 API
    // PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-update - 통합 일괄 업데이트
    // PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-price - 옵션 일괄 가격 변경 (deprecated)
    // PATCH /api/modules/sirsoft-ecommerce/admin/options/bulk-stock - 옵션 일괄 재고 변경 (deprecated)
    Route::prefix('options')->group(function () {
        // 통합 일괄 업데이트 (상품 미체크 시 옵션만 개별 처리)
        Route::patch('/bulk-update', [ProductOptionController::class, 'bulkUpdate'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.options.bulk-update');

        // 기존 일괄 처리 (deprecated - 하위 호환성 유지)
        Route::patch('/bulk-price', [ProductOptionController::class, 'bulkUpdatePrice'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.options.bulk-price');

        Route::patch('/bulk-stock', [ProductOptionController::class, 'bulkUpdateStock'])
            ->middleware('permission:admin,sirsoft-ecommerce.products.update')
            ->name('admin.options.bulk-stock');
    });

    // 카테고리 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/categories - 카테고리 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/categories - 카테고리 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/categories/{id} - 카테고리 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/categories/{category} - 카테고리 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/categories/{category} - 카테고리 삭제
    // POST   /api/modules/sirsoft-ecommerce/admin/categories/images - 이미지 업로드 (temp_key)
    // POST   /api/modules/sirsoft-ecommerce/admin/categories/{categoryId}/images - 이미지 업로드
    // DELETE /api/modules/sirsoft-ecommerce/admin/categories/images/{id} - 이미지 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/categories/images/reorder - 이미지 순서 변경
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.read')
            ->name('admin.categories.index');

        Route::post('/', [CategoryController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.create')
            ->name('admin.categories.store');

        // 카테고리 트리 조회 (상품 등록 폼용) - 와일드카드보다 먼저 정의
        // GET /api/modules/sirsoft-ecommerce/admin/categories/tree
        Route::get('/tree', [CategoryController::class, 'tree'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.read')
            ->name('admin.categories.tree');

        Route::get('/{id}', [CategoryController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.read')
            ->where('id', '[0-9]+')
            ->name('admin.categories.show');

        // 카테고리 순서 변경 (와일드카드 라우트보다 먼저 정의해야 함)
        // PUT /api/modules/sirsoft-ecommerce/admin/categories/order - 순서 변경
        Route::put('/order', [CategoryController::class, 'reorder'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->name('admin.categories.reorder');

        Route::put('/{category}', [CategoryController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->name('admin.categories.update');

        Route::delete('/{category}', [CategoryController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.delete')
            ->name('admin.categories.destroy');

        // 카테고리 상태 토글
        // PATCH /api/modules/sirsoft-ecommerce/admin/categories/{id}/toggle-status - 상태 토글
        Route::patch('/{id}/toggle-status', [CategoryController::class, 'toggleStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->where('id', '[0-9]+')
            ->name('admin.categories.toggle-status');

        // 카테고리 이미지 관리
        Route::post('/images', [CategoryController::class, 'uploadImage'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->name('admin.categories.images.upload-temp');

        Route::post('/{categoryId}/images', [CategoryController::class, 'uploadImage'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->where('categoryId', '[0-9]+')
            ->name('admin.categories.images.upload');

        Route::delete('/images/{id}', [CategoryController::class, 'deleteImage'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->where('id', '[0-9]+')
            ->name('admin.categories.images.delete');

        Route::patch('/images/reorder', [CategoryController::class, 'reorderImages'])
            ->middleware('permission:admin,sirsoft-ecommerce.categories.update')
            ->name('admin.categories.images.reorder');
    });

    // 브랜드 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/brands - 브랜드 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/brands - 브랜드 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/brands/{id} - 브랜드 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/brands/{brand} - 브랜드 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/brands/{brand} - 브랜드 삭제
    Route::prefix('brands')->group(function () {
        Route::get('/', [BrandController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.brands.read')
            ->name('admin.brands.index');

        Route::post('/', [BrandController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.brands.create')
            ->name('admin.brands.store');

        Route::get('/{id}', [BrandController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.brands.read')
            ->where('id', '[0-9]+')
            ->name('admin.brands.show');

        Route::put('/{brand}', [BrandController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.brands.update')
            ->name('admin.brands.update');

        Route::delete('/{brand}', [BrandController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.brands.delete')
            ->name('admin.brands.destroy');

        // 브랜드 상태 토글
        // PATCH /api/modules/sirsoft-ecommerce/admin/brands/{id}/toggle-status - 상태 토글
        Route::patch('/{id}/toggle-status', [BrandController::class, 'toggleStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.brands.update')
            ->where('id', '[0-9]+')
            ->name('admin.brands.toggle-status');
    });

    // 검색 프리셋 API
    // GET    /api/modules/sirsoft-ecommerce/admin/presets - 프리셋 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/presets - 프리셋 생성
    // PUT    /api/modules/sirsoft-ecommerce/admin/presets/{preset} - 프리셋 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/presets/{preset} - 프리셋 삭제
    Route::prefix('presets')->group(function () {
        Route::get('/', [SearchPresetController::class, 'index'])
            ->name('admin.presets.index');

        Route::post('/', [SearchPresetController::class, 'store'])
            ->name('admin.presets.store');

        Route::put('/{preset}', [SearchPresetController::class, 'update'])
            ->whereNumber('preset')
            ->name('admin.presets.update');

        Route::delete('/{preset}', [SearchPresetController::class, 'destroy'])
            ->whereNumber('preset')
            ->name('admin.presets.destroy');
    });

    // 상품 라벨 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/product-labels - 라벨 목록
    // GET    /api/modules/sirsoft-ecommerce/admin/product-labels/{id} - 라벨 상세
    // POST   /api/modules/sirsoft-ecommerce/admin/product-labels - 라벨 생성
    // PUT    /api/modules/sirsoft-ecommerce/admin/product-labels/{id} - 라벨 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/product-labels/{id} - 라벨 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/product-labels/{id}/toggle-status - 상태 토글
    Route::prefix('product-labels')->group(function () {
        Route::get('/', [ProductLabelController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-labels.read')
            ->name('admin.product-labels.index');

        Route::post('/', [ProductLabelController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-labels.create')
            ->name('admin.product-labels.store');

        Route::get('/{id}', [ProductLabelController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-labels.read')
            ->whereNumber('id')
            ->name('admin.product-labels.show');

        Route::put('/{id}', [ProductLabelController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-labels.update')
            ->whereNumber('id')
            ->name('admin.product-labels.update');

        Route::delete('/{id}', [ProductLabelController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-labels.delete')
            ->whereNumber('id')
            ->name('admin.product-labels.destroy');

        Route::patch('/{id}/toggle-status', [ProductLabelController::class, 'toggleStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-labels.update')
            ->whereNumber('id')
            ->name('admin.product-labels.toggle-status');
    });

    // 상품정보제공고시 템플릿 API
    // GET    /api/modules/sirsoft-ecommerce/admin/product-notice-templates - 템플릿 목록
    // GET    /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id} - 템플릿 상세
    // POST   /api/modules/sirsoft-ecommerce/admin/product-notice-templates - 템플릿 생성
    // PUT    /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id} - 템플릿 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id} - 템플릿 삭제
    // POST   /api/modules/sirsoft-ecommerce/admin/product-notice-templates/{id}/copy - 템플릿 복사
    Route::prefix('product-notice-templates')->group(function () {
        Route::get('/', [ProductNoticeTemplateController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-notice-templates.read')
            ->name('admin.product-notice-templates.index');

        Route::get('/{id}', [ProductNoticeTemplateController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-notice-templates.read')
            ->whereNumber('id')
            ->name('admin.product-notice-templates.show');

        Route::post('/', [ProductNoticeTemplateController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-notice-templates.create')
            ->name('admin.product-notice-templates.store');

        Route::put('/{id}', [ProductNoticeTemplateController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-notice-templates.update')
            ->whereNumber('id')
            ->name('admin.product-notice-templates.update');

        Route::delete('/{id}', [ProductNoticeTemplateController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-notice-templates.delete')
            ->whereNumber('id')
            ->name('admin.product-notice-templates.destroy');

        Route::post('/{id}/copy', [ProductNoticeTemplateController::class, 'copy'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-notice-templates.create')
            ->whereNumber('id')
            ->name('admin.product-notice-templates.copy');
    });

    // 공통정보 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/product-common-infos - 공통정보 목록
    // GET    /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id} - 공통정보 상세
    // POST   /api/modules/sirsoft-ecommerce/admin/product-common-infos - 공통정보 생성
    // PUT    /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id} - 공통정보 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id} - 공통정보 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/product-common-infos/{id}/toggle-active - 사용 여부 토글
    Route::prefix('product-common-infos')->group(function () {
        Route::get('/', [ProductCommonInfoController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-common-infos.read')
            ->name('admin.product-common-infos.index');

        Route::get('/{id}', [ProductCommonInfoController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-common-infos.read')
            ->whereNumber('id')
            ->name('admin.product-common-infos.show');

        Route::post('/', [ProductCommonInfoController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-common-infos.create')
            ->name('admin.product-common-infos.store');

        Route::put('/{id}', [ProductCommonInfoController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-common-infos.update')
            ->whereNumber('id')
            ->name('admin.product-common-infos.update');

        Route::delete('/{id}', [ProductCommonInfoController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-common-infos.delete')
            ->whereNumber('id')
            ->name('admin.product-common-infos.destroy');

        Route::patch('/{id}/toggle-active', [ProductCommonInfoController::class, 'toggleActive'])
            ->middleware('permission:admin,sirsoft-ecommerce.product-common-infos.update')
            ->whereNumber('id')
            ->name('admin.product-common-infos.toggle-active');
    });

    // 주문 관리 API
    // GET    /api/modules/sirsoft-ecommerce/admin/orders - 주문 목록 조회
    // GET    /api/modules/sirsoft-ecommerce/admin/orders/{order} - 주문 상세 조회
    // PATCH  /api/modules/sirsoft-ecommerce/admin/orders/{order} - 주문 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/orders/{order} - 주문 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/orders/bulk - 주문 일괄 변경
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
            ->name('admin.orders.index');

        // 일괄 변경 (와일드카드보다 먼저 정의)
        Route::patch('/bulk', [OrderController::class, 'bulkUpdate'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
            ->name('admin.orders.bulk');

        Route::get('/{order}', [OrderController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
            ->name('admin.orders.show');

        Route::patch('/{order}', [OrderController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
            ->name('admin.orders.update');

        Route::delete('/{order}', [OrderController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.delete')
            ->name('admin.orders.destroy');

        // 주문 활동 로그 조회
        // GET /api/modules/sirsoft-ecommerce/admin/orders/{order}/logs
        Route::get('/{order}/logs', [OrderController::class, 'logs'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
            ->name('admin.orders.logs');

        // 주문 이메일 발송
        // POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/send-email
        Route::post('/{order}/send-email', [OrderController::class, 'sendEmail'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
            ->name('admin.orders.send-email');

        // 주문 옵션 일괄 상태 변경 (수량 분할 지원)
        // PATCH /api/modules/sirsoft-ecommerce/admin/orders/{order}/options/bulk-status
        Route::patch('/{order}/options/bulk-status', [OrderController::class, 'bulkChangeOptionStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
            ->name('admin.orders.options.bulk-status');

        // 환불 예상금액 조회
        // POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/estimate-refund
        Route::post('/{order}/estimate-refund', [OrderController::class, 'estimateRefund'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
            ->name('admin.orders.estimate-refund');

        // 주문 취소 (전체취소/부분취소)
        // POST /api/modules/sirsoft-ecommerce/admin/orders/{order}/cancel
        Route::post('/{order}/cancel', [OrderController::class, 'cancelOrder'])
            ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
            ->name('admin.orders.cancel');
    });

    // 리뷰 관리 API (관리자)
    // GET    /api/modules/sirsoft-ecommerce/admin/reviews - 리뷰 목록 조회
    // GET    /api/modules/sirsoft-ecommerce/admin/reviews/{review} - 리뷰 상세 조회
    // PATCH  /api/modules/sirsoft-ecommerce/admin/reviews/{review}/status - 리뷰 상태 변경
    // POST   /api/modules/sirsoft-ecommerce/admin/reviews/{review}/reply - 답변 등록/수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/reviews/{review}/reply - 답변 삭제
    // DELETE /api/modules/sirsoft-ecommerce/admin/reviews/{review} - 리뷰 삭제
    // POST   /api/modules/sirsoft-ecommerce/admin/reviews/bulk - 일괄 처리
    Route::prefix('reviews')->group(function () {
        Route::get('/', [AdminProductReviewController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.read')
            ->name('admin.reviews.index');

        // 일괄 처리 (와일드카드보다 먼저 정의)
        Route::post('/bulk', [AdminProductReviewController::class, 'bulk'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.update')
            ->name('admin.reviews.bulk');

        Route::get('/{review}', [AdminProductReviewController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.read')
            ->whereNumber('review')
            ->name('admin.reviews.show');

        Route::patch('/{review}/status', [AdminProductReviewController::class, 'updateStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.update')
            ->whereNumber('review')
            ->name('admin.reviews.update-status');

        Route::post('/{review}/reply', [AdminProductReviewController::class, 'storeReply'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.update')
            ->whereNumber('review')
            ->name('admin.reviews.reply.store');

        Route::delete('/{review}/reply', [AdminProductReviewController::class, 'destroyReply'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.update')
            ->whereNumber('review')
            ->name('admin.reviews.reply.destroy');

        Route::delete('/{review}', [AdminProductReviewController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.reviews.delete')
            ->whereNumber('review')
            ->name('admin.reviews.destroy');
    });

    // GET    /api/modules/sirsoft-ecommerce/admin/promotion-coupons - 쿠폰 목록 조회
    // POST   /api/modules/sirsoft-ecommerce/admin/promotion-coupons - 쿠폰 생성
    // GET    /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id} - 쿠폰 상세 조회
    // PUT    /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id} - 쿠폰 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id} - 쿠폰 삭제
    // PATCH  /api/modules/sirsoft-ecommerce/admin/promotion-coupons/bulk-status - 일괄 상태 변경
    // GET    /api/modules/sirsoft-ecommerce/admin/promotion-coupons/{id}/issues - 발급 내역 조회
    Route::prefix('promotion-coupons')->group(function () {
        Route::get('/', [CouponController::class, 'index'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.read')
            ->name('admin.promotion-coupons.index');

        Route::post('/', [CouponController::class, 'store'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.create')
            ->name('admin.promotion-coupons.store');

        // 일괄 상태 변경 (와일드카드보다 먼저 정의)
        Route::patch('/bulk-status', [CouponController::class, 'bulkUpdateStatus'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.update')
            ->name('admin.promotion-coupons.bulk-status');

        Route::get('/{id}', [CouponController::class, 'show'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.read')
            ->whereNumber('id')
            ->name('admin.promotion-coupons.show');

        Route::put('/{id}', [CouponController::class, 'update'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.update')
            ->whereNumber('id')
            ->name('admin.promotion-coupons.update');

        Route::delete('/{id}', [CouponController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.delete')
            ->whereNumber('id')
            ->name('admin.promotion-coupons.destroy');

        // 발급 내역 조회
        Route::get('/{id}/issues', [CouponController::class, 'issues'])
            ->middleware('permission:admin,sirsoft-ecommerce.promotion-coupon.read')
            ->whereNumber('id')
            ->name('admin.promotion-coupons.issues');
    });

    // 상품 1:1 문의 관리 API (관리자)
    // POST   /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply   - 답변 등록
    // PUT    /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply   - 답변 수정
    // DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}/reply   - 답변 삭제
    // DELETE /api/modules/sirsoft-ecommerce/admin/inquiries/{inquiryId}         - 문의 삭제
    Route::prefix('inquiries')->group(function () {
        Route::post('/{inquiryId}/reply', [AdminProductInquiryController::class, 'reply'])
            ->middleware('permission:admin,sirsoft-ecommerce.inquiries.update')
            ->whereNumber('inquiryId')
            ->name('admin.inquiries.reply');

        Route::put('/{inquiryId}/reply', [AdminProductInquiryController::class, 'updateReply'])
            ->middleware('permission:admin,sirsoft-ecommerce.inquiries.update')
            ->whereNumber('inquiryId')
            ->name('admin.inquiries.reply.update');

        Route::delete('/{inquiryId}/reply', [AdminProductInquiryController::class, 'destroyReply'])
            ->middleware('permission:admin,sirsoft-ecommerce.inquiries.update')
            ->whereNumber('inquiryId')
            ->name('admin.inquiries.reply.destroy');

        Route::delete('/{inquiryId}', [AdminProductInquiryController::class, 'destroy'])
            ->middleware('permission:admin,sirsoft-ecommerce.inquiries.delete')
            ->whereNumber('inquiryId')
            ->name('admin.inquiries.destroy');
    });

});
