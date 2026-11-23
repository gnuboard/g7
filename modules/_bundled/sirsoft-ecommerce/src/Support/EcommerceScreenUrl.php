<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Support;

use App\Support\ScreenUrl;

/**
 * 이커머스 화면 주소 — 코어 화면 주소 헬퍼({@see ScreenUrl})의 이커머스 래퍼 (#792).
 *
 * 사람이 여는 주소(알림 메일 링크·주문 완료 이동·결제 뒤 복귀·검색·사이트맵)는 이 래퍼로 만들어 「사용자 화면 URL」과
 * 주소 필터 `core.screen_url.filter_path` 를 따른다. 상점 주소 접두(`/shop` 등)는 부를 때마다 {@see ShopPathResolver}
 * 로 정한다. G7 엔진 SEO 캐시는 {@see ShopPathResolver::path()} 기본 경로를 그대로 쓴다.
 *
 * 서버가 받는 주소(PG 콜백·웹훅)는 이 래퍼가 아니라 SiteUrl 이다.
 */
final class EcommerceScreenUrl
{
    /**
     * 상품 상세 — `{shop}/products/{product_code}`.
     *
     * @param  string  $productCode  상품 코드
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function product(string $productCode, bool $absolute = false): string
    {
        return self::make('ecommerce.product', ShopPathResolver::path('products/{product_code}'), ['product_code' => $productCode], $absolute);
    }

    /**
     * 상품 목록 — `{shop}/products`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function products(bool $absolute = false): string
    {
        return self::make('ecommerce.products', ShopPathResolver::path('products'), [], $absolute);
    }

    /**
     * 카테고리 — `{shop}/category/{slug}`.
     *
     * @param  string  $slug  카테고리 슬러그
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function category(string $slug, bool $absolute = false): string
    {
        return self::make('ecommerce.category', ShopPathResolver::path('category/{slug}'), ['slug' => $slug], $absolute);
    }

    /**
     * 주문서 — `{shop}/checkout`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function checkout(bool $absolute = false): string
    {
        return self::make('ecommerce.checkout', ShopPathResolver::path('checkout'), [], $absolute);
    }

    /**
     * 주문 완료 — `{shop}/orders/{order_number}/complete`.
     *
     * @param  string  $orderNumber  주문 번호
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function orderComplete(string $orderNumber, bool $absolute = false): string
    {
        return self::make('ecommerce.order.complete', ShopPathResolver::path('orders/{order_number}/complete'), ['order_number' => $orderNumber], $absolute);
    }

    /**
     * 비회원 주문 조회 — `{shop}/guest/orders`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function guestOrders(bool $absolute = false): string
    {
        return self::make('ecommerce.guest.orders', ShopPathResolver::path('guest/orders'), [], $absolute);
    }

    /**
     * 비회원 클레임 상세 — `{shop}/guest/claims/{claim_number}`.
     *
     * @param  string  $claimNumber  클레임 번호
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function guestClaimDetail(string $claimNumber, bool $absolute = false): string
    {
        return self::make('ecommerce.guest.claim.detail', ShopPathResolver::path('guest/claims/{claim_number}'), ['claim_number' => $claimNumber], $absolute);
    }

    /**
     * 마이페이지 주문 목록 — `/mypage/orders`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageOrders(bool $absolute = false): string
    {
        return self::make('mypage.orders', '/mypage/orders', [], $absolute);
    }

    /**
     * 마이페이지 주문 상세 — `/mypage/orders/{order_number}`.
     *
     * @param  string  $orderNumber  주문 번호
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageOrderDetail(string $orderNumber, bool $absolute = false): string
    {
        return self::make('mypage.order.detail', '/mypage/orders/{order_number}', ['order_number' => $orderNumber], $absolute);
    }

    /**
     * 마이페이지 클레임 목록 — `/mypage/claims`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageClaims(bool $absolute = false): string
    {
        return self::make('mypage.claims', '/mypage/claims', [], $absolute);
    }

    /**
     * 마이페이지 클레임 상세 — `/mypage/claims/{claim_number}`.
     *
     * @param  string  $claimNumber  클레임 번호
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageClaimDetail(string $claimNumber, bool $absolute = false): string
    {
        return self::make('mypage.claim.detail', '/mypage/claims/{claim_number}', ['claim_number' => $claimNumber], $absolute);
    }

    /**
     * 마이페이지 상품 문의 — `/mypage/inquiries`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageInquiries(bool $absolute = false): string
    {
        return self::make('mypage.inquiries', '/mypage/inquiries', [], $absolute);
    }

    /**
     * 마이페이지 마일리지 — `/mypage/mileage`.
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageMileage(bool $absolute = false): string
    {
        return self::make('mypage.mileage', '/mypage/mileage', [], $absolute);
    }

    /**
     * 마이페이지 쿠폰함 — `/mypage/coupons`. (#952)
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageCoupons(bool $absolute = false): string
    {
        return self::make('mypage.coupons', '/mypage/coupons', [], $absolute);
    }

    /**
     * 마이페이지 내 리뷰 — `/mypage/reviews`. (#952)
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function mypageReviews(bool $absolute = false): string
    {
        return self::make('mypage.reviews', '/mypage/reviews', [], $absolute);
    }

    /**
     * 쿠폰존 — 상점 주소 하위 `coupons`. (#952)
     *
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function couponZone(bool $absolute = false): string
    {
        return self::make('ecommerce.coupons', ShopPathResolver::path('coupons'), [], $absolute);
    }

    /**
     * 관리자 화면 주소 (관리자 범위 — 베이스는 사이트 주소).
     *
     * @param  string  $key  화면 키
     * @param  string  $defaultPath  기본 경로
     * @param  array<string, scalar>  $params  자리 값
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    public static function admin(string $key, string $defaultPath, array $params = [], bool $absolute = false): string
    {
        return $absolute
            ? ScreenUrl::absolute($key, $defaultPath, $params, ScreenUrl::SCOPE_ADMIN)
            : ScreenUrl::path($key, $defaultPath, $params, ScreenUrl::SCOPE_ADMIN);
    }

    /**
     * 헬퍼 호출 (사용자 범위).
     *
     * @param  string  $key  화면 키
     * @param  string  $defaultPath  기본 경로
     * @param  array<string, scalar>  $params  자리 값
     * @param  bool  $absolute  절대 URL 여부
     * @return string 주소
     */
    private static function make(string $key, string $defaultPath, array $params, bool $absolute): string
    {
        return $absolute
            ? ScreenUrl::absolute($key, $defaultPath, $params)
            : ScreenUrl::path($key, $defaultPath, $params);
    }
}
