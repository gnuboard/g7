<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 쿠폰 시더
 *
 * 약 50개의 다양한 쿠폰 더미 데이터를 생성합니다.
 * 적용 대상(상품금액/주문금액/배송비), 할인 유형(정액/정률),
 * 발급 방법/조건/상태 등 모든 Enum 조합을 골고루 포함합니다.
 */
class CouponSeeder extends Seeder
{
    /**
     * 쿠폰 시더를 실행합니다.
     */
    public function run(): void
    {
        $this->command->info('쿠폰 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingData();
        $coupons = $this->createCoupons();
        $this->attachTargetScopes($coupons);
        $this->createIssueRecords($coupons);

        $couponCount = Coupon::count();
        $issueCount = CouponIssue::count();
        $this->command->info("쿠폰 더미 데이터 {$couponCount}건, 발급 내역 {$issueCount}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 쿠폰 데이터를 삭제합니다.
     */
    private function deleteExistingData(): void
    {
        $issueCount = CouponIssue::count();
        if ($issueCount > 0) {
            CouponIssue::query()->delete();
            $this->command->warn("기존 쿠폰 발급 내역 {$issueCount}건을 삭제했습니다.");
        }

        $couponCount = Coupon::withTrashed()->count();
        if ($couponCount > 0) {
            Coupon::withTrashed()->forceDelete();
            $this->command->warn("기존 쿠폰 {$couponCount}건을 삭제했습니다.");
        }
    }

    /**
     * 50개 쿠폰을 생성합니다.
     *
     * @return array<int, Coupon> 생성된 쿠폰 배열
     */
    private function createCoupons(): array
    {
        $adminUserId = User::first()?->id;
        $now = Carbon::now();

        $couponsData = [
            // ============================================================
            // 그룹 1: 상품금액 할인 쿠폰 (20개, #1~#20)
            // ============================================================

            // #1 - 정액 1,000원 할인 (수동, 직접발급, 전체상품)
            [
                'name' => ['ko' => '상품 1,000원 할인 쿠폰', 'en' => 'Product 1,000 KRW Off'],
                'description' => ['ko' => '전 상품 1,000원 할인', 'en' => '1,000 KRW off on all products'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 1000,
                'discount_max_amount' => null,
                'min_order_amount' => 10000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 1000,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(30),
                'valid_to' => $now->copy()->addDays(60),
                'issue_from' => $now->copy()->subDays(30),
                'issue_to' => $now->copy()->addDays(30),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #2 - 정액 3,000원 할인 (수동, 다운로드, 전체상품)
            [
                'name' => ['ko' => '상품 3,000원 할인 쿠폰', 'en' => 'Product 3,000 KRW Off'],
                'description' => ['ko' => '3,000원 할인 다운로드 쿠폰', 'en' => '3,000 KRW off download coupon'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 3000,
                'discount_max_amount' => null,
                'min_order_amount' => 20000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 500,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(15),
                'issue_to' => $now->copy()->addDays(45),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #3 - 정액 5,000원 할인 (회원가입, 자동발급, 전체상품)
            [
                'name' => ['ko' => '신규회원 가입축하 5,000원 할인', 'en' => 'Welcome 5,000 KRW Off'],
                'description' => ['ko' => '회원가입 시 자동 발급', 'en' => 'Auto-issued on signup'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 30000,
                'issue_method' => 'auto',
                'issue_condition' => 'signup',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #4 - 정액 10,000원 할인 (수동, 직접발급, 특정상품) - 중복할인불가
            [
                'name' => ['ko' => '특가 상품 10,000원 할인 (중복할인불가)', 'en' => 'Special Product 10,000 KRW Off (Non-combinable)'],
                'description' => ['ko' => '특정 상품 한정 할인 (다른 쿠폰과 중복 사용 불가)', 'en' => 'Discount on selected products only (Cannot combine with other coupons)'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 10000,
                'discount_max_amount' => null,
                'min_order_amount' => 50000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 200,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(7),
                'valid_to' => $now->copy()->addDays(30),
                'issue_from' => $now->copy()->subDays(7),
                'issue_to' => $now->copy()->addDays(14),
                'is_combinable' => false,
                'target_scope' => 'products',
            ],

            // #5 - 정액 15,000원 할인 (수동, 다운로드, 특정카테고리)
            [
                'name' => ['ko' => '카테고리 한정 15,000원 할인', 'en' => 'Category 15,000 KRW Off'],
                'description' => ['ko' => '특정 카테고리 상품 할인', 'en' => 'Discount on selected categories'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 15000,
                'discount_max_amount' => null,
                'min_order_amount' => 70000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 300,
                'issued_count' => 0,
                'per_user_limit' => 2,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(10),
                'valid_to' => $now->copy()->addDays(50),
                'issue_from' => $now->copy()->subDays(10),
                'issue_to' => $now->copy()->addDays(20),
                'is_combinable' => true,
                'target_scope' => 'categories',
            ],

            // #6 - 정액 20,000원 할인 (첫구매, 자동발급, 전체상품)
            [
                'name' => ['ko' => '첫구매 20,000원 할인', 'en' => 'First Purchase 20,000 KRW Off'],
                'description' => ['ko' => '첫 구매 시 자동 발급', 'en' => 'Auto-issued on first purchase'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 20000,
                'discount_max_amount' => null,
                'min_order_amount' => 100000,
                'issue_method' => 'auto',
                'issue_condition' => 'first_purchase',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #7 - 정액 50,000원 할인 (수동, 직접발급, 특정상품, 발급중단)
            [
                'name' => ['ko' => 'VIP 전용 50,000원 할인', 'en' => 'VIP Exclusive 50,000 KRW Off'],
                'description' => ['ko' => 'VIP 회원 전용 대폭 할인', 'en' => 'Exclusive discount for VIP members'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 50000,
                'discount_max_amount' => null,
                'min_order_amount' => 200000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 50,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(60),
                'valid_to' => $now->copy()->subDays(10),
                'issue_from' => $now->copy()->subDays(60),
                'issue_to' => $now->copy()->subDays(30),
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // #8 - 정률 5% 할인 (수동, 직접발급, 전체상품)
            [
                'name' => ['ko' => '상품 5% 할인 쿠폰', 'en' => 'Product 5% Off'],
                'description' => ['ko' => '전 상품 5% 할인', 'en' => '5% off on all products'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 5,
                'discount_max_amount' => 5000,
                'min_order_amount' => 10000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 2000,
                'issued_count' => 0,
                'per_user_limit' => 3,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(20),
                'valid_to' => $now->copy()->addDays(40),
                'issue_from' => $now->copy()->subDays(20),
                'issue_to' => $now->copy()->addDays(20),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #9 - 정률 10% 할인 (수동, 다운로드, 전체상품)
            [
                'name' => ['ko' => '상품 10% 할인 쿠폰', 'en' => 'Product 10% Off'],
                'description' => ['ko' => '전 상품 10% 할인', 'en' => '10% off on all products'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 10,
                'discount_max_amount' => 10000,
                'min_order_amount' => 20000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 1000,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(5),
                'issue_to' => $now->copy()->addDays(60),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #10 - 정률 15% 할인 (회원가입, 자동발급, 전체상품)
            [
                'name' => ['ko' => '신규회원 15% 할인', 'en' => 'New Member 15% Off'],
                'description' => ['ko' => '회원가입 축하 15% 할인', 'en' => '15% off welcome coupon'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 15,
                'discount_max_amount' => 15000,
                'min_order_amount' => 30000,
                'issue_method' => 'auto',
                'issue_condition' => 'signup',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 7,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #11 - 정률 20% 할인 (수동, 직접발급, 특정카테고리)
            [
                'name' => ['ko' => '여름 시즌 20% 할인', 'en' => 'Summer Season 20% Off'],
                'description' => ['ko' => '여름 시즌 한정 카테고리 할인', 'en' => 'Summer season category discount'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 20,
                'discount_max_amount' => 20000,
                'min_order_amount' => 30000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 500,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(5),
                'valid_to' => $now->copy()->addDays(90),
                'issue_from' => $now->copy()->subDays(5),
                'issue_to' => $now->copy()->addDays(30),
                'is_combinable' => true,
                'target_scope' => 'categories',
            ],

            // #12 - 정률 25% 할인 (생일, 자동발급, 전체상품)
            [
                'name' => ['ko' => '생일축하 25% 할인', 'en' => 'Birthday 25% Off'],
                'description' => ['ko' => '생일 축하 특별 할인', 'en' => 'Special birthday discount'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 25,
                'discount_max_amount' => 25000,
                'min_order_amount' => 50000,
                'issue_method' => 'auto',
                'issue_condition' => 'birthday',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 7,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #13 - 정률 30% 할인 (수동, 다운로드, 특정상품, 발급중단)
            [
                'name' => ['ko' => '초특가 30% 할인', 'en' => 'Super Sale 30% Off'],
                'description' => ['ko' => '특정 상품 한정 30% 할인 (종료)', 'en' => '30% off on selected products (ended)'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 30,
                'discount_max_amount' => 50000,
                'min_order_amount' => 50000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 100,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(90),
                'valid_to' => $now->copy()->subDays(30),
                'issue_from' => $now->copy()->subDays(90),
                'issue_to' => $now->copy()->subDays(60),
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // #14 - 정액 2,000원 할인 (수동, 직접발급, 전체상품)
            [
                'name' => ['ko' => '주간 한정 2,000원 할인', 'en' => 'Weekly 2,000 KRW Off'],
                'description' => ['ko' => '매주 선착순 할인', 'en' => 'Weekly first-come discount'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 2000,
                'discount_max_amount' => null,
                'min_order_amount' => 15000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 100,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->startOfWeek(),
                'valid_to' => $now->copy()->endOfWeek(),
                'issue_from' => $now->copy()->startOfWeek(),
                'issue_to' => $now->copy()->endOfWeek(),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #15 - 정액 7,000원 할인 (수동, 다운로드, 전체상품, 중복가능)
            [
                'name' => ['ko' => '앱 전용 7,000원 할인', 'en' => 'App Exclusive 7,000 KRW Off'],
                'description' => ['ko' => '앱에서만 다운로드 가능', 'en' => 'Download only via app'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 7000,
                'discount_max_amount' => null,
                'min_order_amount' => 30000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 5000,
                'issued_count' => 0,
                'per_user_limit' => 3,
                'valid_type' => 'days_from_issue',
                'valid_days' => 60,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(10),
                'issue_to' => $now->copy()->addDays(90),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #16 - 정률 3% 할인 (수동, 직접발급, 특정상품)
            [
                'name' => ['ko' => '인기상품 3% 할인', 'en' => 'Popular Product 3% Off'],
                'description' => ['ko' => '인기 상품 한정 소폭 할인', 'en' => 'Small discount on popular products'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 3,
                'discount_max_amount' => 3000,
                'min_order_amount' => 0,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 10000,
                'issued_count' => 0,
                'per_user_limit' => 5,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(30),
                'valid_to' => $now->copy()->addDays(120),
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // #17 - 정률 7% 할인 (첫구매, 자동발급, 전체상품)
            [
                'name' => ['ko' => '첫구매 7% 할인', 'en' => 'First Purchase 7% Off'],
                'description' => ['ko' => '첫 구매 회원 전용', 'en' => 'For first-time buyers only'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 7,
                'discount_max_amount' => 7000,
                'min_order_amount' => 20000,
                'issue_method' => 'auto',
                'issue_condition' => 'first_purchase',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #18 - 정액 30,000원 할인 (수동, 직접발급, 특정카테고리) - 중복할인불가
            [
                'name' => ['ko' => '프리미엄 카테고리 30,000원 할인 (중복할인불가)', 'en' => 'Premium Category 30,000 KRW Off (Non-combinable)'],
                'description' => ['ko' => '프리미엄 카테고리 한정 (다른 쿠폰과 중복 사용 불가)', 'en' => 'Premium category only (Cannot combine with other coupons)'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 30000,
                'discount_max_amount' => null,
                'min_order_amount' => 100000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 100,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(3),
                'valid_to' => $now->copy()->addDays(27),
                'issue_from' => $now->copy()->subDays(3),
                'issue_to' => $now->copy()->addDays(10),
                'is_combinable' => false,
                'target_scope' => 'categories',
            ],

            // #19 - 정률 12% 할인 (생일, 자동발급, 전체상품)
            [
                'name' => ['ko' => '생일 특별 12% 할인', 'en' => 'Birthday Special 12% Off'],
                'description' => ['ko' => '생일 회원 전용', 'en' => 'Birthday members only'],
                'target_type' => 'product_amount',
                'discount_type' => 'rate',
                'discount_value' => 12,
                'discount_max_amount' => 12000,
                'min_order_amount' => 30000,
                'issue_method' => 'auto',
                'issue_condition' => 'birthday',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #20 - 정액 5,000원 할인 (수동, 다운로드, 특정상품, 발급중단)
            [
                'name' => ['ko' => '이벤트 종료 5,000원 할인', 'en' => 'Event Ended 5,000 KRW Off'],
                'description' => ['ko' => '이벤트 종료 쿠폰', 'en' => 'Event ended coupon'],
                'target_type' => 'product_amount',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 25000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 200,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(45),
                'valid_to' => $now->copy()->subDays(15),
                'issue_from' => $now->copy()->subDays(45),
                'issue_to' => $now->copy()->subDays(30),
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // ============================================================
            // 그룹 2: 주문금액 할인 쿠폰 (20개, #21~#40)
            // ============================================================

            // #21 - 정액 2,000원 (수동, 직접발급, 전체)
            [
                'name' => ['ko' => '주문 2,000원 할인', 'en' => 'Order 2,000 KRW Off'],
                'description' => ['ko' => '주문금액 기준 2,000원 할인', 'en' => '2,000 KRW off on order amount'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 2000,
                'discount_max_amount' => null,
                'min_order_amount' => 15000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 3000,
                'issued_count' => 0,
                'per_user_limit' => 2,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(14),
                'valid_to' => $now->copy()->addDays(45),
                'issue_from' => $now->copy()->subDays(14),
                'issue_to' => $now->copy()->addDays(14),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #22 - 정액 5,000원 (수동, 다운로드, 전체)
            [
                'name' => ['ko' => '주문 5,000원 할인', 'en' => 'Order 5,000 KRW Off'],
                'description' => ['ko' => '5만원 이상 주문 시', 'en' => 'On orders over 50,000 KRW'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 50000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 1000,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(7),
                'issue_to' => $now->copy()->addDays(60),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #23 - 정액 10,000원 (회원가입, 자동, 전체)
            [
                'name' => ['ko' => '가입축하 주문 10,000원 할인', 'en' => 'Welcome Order 10,000 KRW Off'],
                'description' => ['ko' => '신규 회원 주문금액 할인', 'en' => 'New member order discount'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 10000,
                'discount_max_amount' => null,
                'min_order_amount' => 70000,
                'issue_method' => 'auto',
                'issue_condition' => 'signup',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #24 - 정액 15,000원 (첫구매, 자동, 전체)
            [
                'name' => ['ko' => '첫주문 15,000원 할인', 'en' => 'First Order 15,000 KRW Off'],
                'description' => ['ko' => '첫 주문 시 15,000원 할인', 'en' => '15,000 KRW off on first order'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 15000,
                'discount_max_amount' => null,
                'min_order_amount' => 80000,
                'issue_method' => 'auto',
                'issue_condition' => 'first_purchase',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #25 - 정액 3,000원 (생일, 자동, 전체, 중복가능)
            [
                'name' => ['ko' => '생일축하 주문 3,000원 할인', 'en' => 'Birthday Order 3,000 KRW Off'],
                'description' => ['ko' => '생일 축하 주문 할인', 'en' => 'Birthday order discount'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 3000,
                'discount_max_amount' => null,
                'min_order_amount' => 20000,
                'issue_method' => 'auto',
                'issue_condition' => 'birthday',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 7,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #26 - 정률 5% (수동, 직접발급, 전체)
            [
                'name' => ['ko' => '주문금액 5% 할인', 'en' => 'Order 5% Off'],
                'description' => ['ko' => '주문금액 5% 할인', 'en' => '5% off on order amount'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 5,
                'discount_max_amount' => 5000,
                'min_order_amount' => 20000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 2000,
                'issued_count' => 0,
                'per_user_limit' => 2,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(10),
                'valid_to' => $now->copy()->addDays(50),
                'issue_from' => $now->copy()->subDays(10),
                'issue_to' => $now->copy()->addDays(20),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #27 - 정률 10% (수동, 다운로드, 특정상품)
            [
                'name' => ['ko' => '인기상품 주문 10% 할인', 'en' => 'Popular Product Order 10% Off'],
                'description' => ['ko' => '인기 상품 주문 시 10% 할인', 'en' => '10% off on popular product orders'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 10,
                'discount_max_amount' => 15000,
                'min_order_amount' => 30000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 500,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(5),
                'valid_to' => $now->copy()->addDays(55),
                'issue_from' => $now->copy()->subDays(5),
                'issue_to' => $now->copy()->addDays(25),
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // #28 - 정률 15% (수동, 직접발급, 특정카테고리, 중복가능)
            [
                'name' => ['ko' => '카테고리 주문 15% 할인', 'en' => 'Category Order 15% Off'],
                'description' => ['ko' => '특정 카테고리 주문 15% 할인', 'en' => '15% off on category orders'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 15,
                'discount_max_amount' => 20000,
                'min_order_amount' => 40000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 300,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(7),
                'valid_to' => $now->copy()->addDays(23),
                'issue_from' => $now->copy()->subDays(7),
                'issue_to' => $now->copy()->addDays(7),
                'is_combinable' => true,
                'target_scope' => 'categories',
            ],

            // #29 - 정률 20% (수동, 다운로드, 전체)
            [
                'name' => ['ko' => '봄맞이 주문 20% 할인', 'en' => 'Spring Order 20% Off'],
                'description' => ['ko' => '봄 시즌 주문 할인', 'en' => 'Spring season order discount'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 20,
                'discount_max_amount' => 30000,
                'min_order_amount' => 50000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 800,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(3),
                'issue_to' => $now->copy()->addDays(60),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #30 - 정률 8% (회원가입, 자동, 전체)
            [
                'name' => ['ko' => '가입축하 주문 8% 할인', 'en' => 'Welcome Order 8% Off'],
                'description' => ['ko' => '회원가입 축하 주문금액 할인', 'en' => 'Welcome order discount'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 8,
                'discount_max_amount' => 8000,
                'min_order_amount' => 25000,
                'issue_method' => 'auto',
                'issue_condition' => 'signup',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #31 - 정액 20,000원 (수동, 직접발급, 전체, 발급중단)
            [
                'name' => ['ko' => '연말 특별 20,000원 할인 (종료)', 'en' => 'Year-end 20,000 KRW Off (Ended)'],
                'description' => ['ko' => '연말 이벤트 종료', 'en' => 'Year-end event ended'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 20000,
                'discount_max_amount' => null,
                'min_order_amount' => 100000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 500,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(120),
                'valid_to' => $now->copy()->subDays(60),
                'issue_from' => $now->copy()->subDays(120),
                'issue_to' => $now->copy()->subDays(90),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #32 - 정률 25% (첫구매, 자동, 전체) - 중복할인불가
            [
                'name' => ['ko' => '첫주문 25% 할인 (중복할인불가)', 'en' => 'First Order 25% Off (Non-combinable)'],
                'description' => ['ko' => '첫 주문 고객 특별 25% 할인 (다른 쿠폰과 중복 사용 불가)', 'en' => 'Special 25% off for first order (Cannot combine with other coupons)'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 25,
                'discount_max_amount' => 25000,
                'min_order_amount' => 50000,
                'issue_method' => 'auto',
                'issue_condition' => 'first_purchase',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => false,
                'target_scope' => 'all',
            ],

            // #33 - 정액 8,000원 (수동, 다운로드, 특정카테고리)
            [
                'name' => ['ko' => '카테고리 주문 8,000원 할인', 'en' => 'Category Order 8,000 KRW Off'],
                'description' => ['ko' => '특정 카테고리 주문 시 할인', 'en' => 'Discount on category orders'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 8000,
                'discount_max_amount' => null,
                'min_order_amount' => 40000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 400,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(5),
                'valid_to' => $now->copy()->addDays(35),
                'issue_from' => $now->copy()->subDays(5),
                'issue_to' => $now->copy()->addDays(15),
                'is_combinable' => true,
                'target_scope' => 'categories',
            ],

            // #34 - 정률 12% (생일, 자동, 전체, 중복가능)
            [
                'name' => ['ko' => '생일 주문 12% 할인', 'en' => 'Birthday Order 12% Off'],
                'description' => ['ko' => '생일 축하 주문 12% 할인', 'en' => 'Birthday order 12% discount'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 12,
                'discount_max_amount' => 15000,
                'min_order_amount' => 30000,
                'issue_method' => 'auto',
                'issue_condition' => 'birthday',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 7,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #35 - 정액 1,000원 (수동, 직접발급, 전체, 무제한)
            [
                'name' => ['ko' => '소액 주문 1,000원 할인', 'en' => 'Small Order 1,000 KRW Off'],
                'description' => ['ko' => '소액 주문 할인', 'en' => 'Small order discount'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 1000,
                'discount_max_amount' => null,
                'min_order_amount' => 10000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 5,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(60),
                'valid_to' => $now->copy()->addDays(180),
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #36 - 정률 30% (수동, 직접발급, 특정상품, 발급중단)
            [
                'name' => ['ko' => '블프 주문 30% 할인 (종료)', 'en' => 'Black Friday Order 30% Off (Ended)'],
                'description' => ['ko' => '블랙프라이데이 이벤트 종료', 'en' => 'Black Friday event ended'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 30,
                'discount_max_amount' => 50000,
                'min_order_amount' => 80000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 200,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(100),
                'valid_to' => $now->copy()->subDays(70),
                'issue_from' => $now->copy()->subDays(100),
                'issue_to' => $now->copy()->subDays(85),
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // #37 - 정액 12,000원 (수동, 다운로드, 전체)
            [
                'name' => ['ko' => '대량주문 12,000원 할인', 'en' => 'Bulk Order 12,000 KRW Off'],
                'description' => ['ko' => '대량 주문 시 할인', 'en' => 'Discount on bulk orders'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 12000,
                'discount_max_amount' => null,
                'min_order_amount' => 80000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 600,
                'issued_count' => 0,
                'per_user_limit' => 2,
                'valid_type' => 'days_from_issue',
                'valid_days' => 60,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(10),
                'issue_to' => $now->copy()->addDays(50),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #38 - 정률 7% (수동, 직접발급, 전체, 미래 발급)
            [
                'name' => ['ko' => '가을 시즌 7% 할인 (예정)', 'en' => 'Autumn Season 7% Off (Upcoming)'],
                'description' => ['ko' => '가을 시즌 예정 쿠폰', 'en' => 'Upcoming autumn season coupon'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 7,
                'discount_max_amount' => 10000,
                'min_order_amount' => 30000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 1000,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->addDays(30),
                'valid_to' => $now->copy()->addDays(90),
                'issue_from' => $now->copy()->addDays(30),
                'issue_to' => $now->copy()->addDays(60),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #39 - 정액 25,000원 (수동, 직접발급, 특정카테고리) - 중복할인불가
            [
                'name' => ['ko' => '카테고리 주문 25,000원 할인 (중복할인불가)', 'en' => 'Category Order 25,000 KRW Off (Non-combinable)'],
                'description' => ['ko' => '특정 카테고리 대량 주문 할인 (다른 쿠폰과 중복 사용 불가)', 'en' => 'Category bulk order discount (Cannot combine with other coupons)'],
                'target_type' => 'order_amount',
                'discount_type' => 'fixed',
                'discount_value' => 25000,
                'discount_max_amount' => null,
                'min_order_amount' => 100000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 150,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(7),
                'valid_to' => $now->copy()->addDays(23),
                'issue_from' => $now->copy()->subDays(7),
                'issue_to' => $now->copy()->addDays(7),
                'is_combinable' => false,
                'target_scope' => 'categories',
            ],

            // #40 - 정률 18% (첫구매, 자동, 전체, 중복가능)
            [
                'name' => ['ko' => '첫주문 18% 특별 할인', 'en' => 'First Order 18% Special Off'],
                'description' => ['ko' => '첫 주문 고객 18% 특별 할인', 'en' => 'Special 18% off for first-time orders'],
                'target_type' => 'order_amount',
                'discount_type' => 'rate',
                'discount_value' => 18,
                'discount_max_amount' => 20000,
                'min_order_amount' => 40000,
                'issue_method' => 'auto',
                'issue_condition' => 'first_purchase',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // ============================================================
            // 그룹 3: 배송비 할인 쿠폰 (10개, #41~#50)
            // ============================================================

            // #41 - 무료배송 (수동, 직접발급, 전체)
            [
                'name' => ['ko' => '무료배송 쿠폰', 'en' => 'Free Shipping Coupon'],
                'description' => ['ko' => '배송비 전액 무료', 'en' => 'Free shipping on all orders'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 30000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 2000,
                'issued_count' => 0,
                'per_user_limit' => 2,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(14),
                'valid_to' => $now->copy()->addDays(45),
                'issue_from' => $now->copy()->subDays(14),
                'issue_to' => $now->copy()->addDays(14),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #42 - 무료배송 (회원가입, 자동, 전체)
            [
                'name' => ['ko' => '신규회원 무료배송', 'en' => 'New Member Free Shipping'],
                'description' => ['ko' => '회원가입 시 무료배송 쿠폰 자동 발급', 'en' => 'Free shipping on signup'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 0,
                'issue_method' => 'auto',
                'issue_condition' => 'signup',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 30,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #43 - 배송비 50% 할인 (수동, 다운로드, 전체)
            [
                'name' => ['ko' => '배송비 50% 할인', 'en' => 'Shipping 50% Off'],
                'description' => ['ko' => '배송비 반값 할인', 'en' => '50% off shipping fee'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'rate',
                'discount_value' => 50,
                'discount_max_amount' => 3000,
                'min_order_amount' => 20000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 1000,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 14,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => $now->copy()->subDays(5),
                'issue_to' => $now->copy()->addDays(60),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #44 - 배송비 100% 할인 (첫구매, 자동, 전체) - 중복할인불가
            [
                'name' => ['ko' => '첫구매 무료배송 (중복할인불가)', 'en' => 'First Purchase Free Shipping (Non-combinable)'],
                'description' => ['ko' => '첫 구매 시 배송비 무료 (다른 쿠폰과 중복 사용 불가)', 'en' => 'Free shipping on first purchase (Cannot combine with other coupons)'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'rate',
                'discount_value' => 100,
                'discount_max_amount' => null,
                'min_order_amount' => 0,
                'issue_method' => 'auto',
                'issue_condition' => 'first_purchase',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 60,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => false,
                'target_scope' => 'all',
            ],

            // #45 - 배송비 2,000원 할인 (수동, 직접발급, 특정상품)
            [
                'name' => ['ko' => '특정상품 배송비 2,000원 할인', 'en' => 'Product Shipping 2,000 KRW Off'],
                'description' => ['ko' => '특정 상품 배송비 할인', 'en' => 'Shipping discount on selected products'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 2000,
                'discount_max_amount' => null,
                'min_order_amount' => 15000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 500,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(10),
                'valid_to' => $now->copy()->addDays(50),
                'issue_from' => $now->copy()->subDays(10),
                'issue_to' => $now->copy()->addDays(20),
                'is_combinable' => true,
                'target_scope' => 'products',
            ],

            // #46 - 생일 무료배송 (생일, 자동, 전체)
            [
                'name' => ['ko' => '생일축하 무료배송', 'en' => 'Birthday Free Shipping'],
                'description' => ['ko' => '생일 축하 무료배송 쿠폰', 'en' => 'Free shipping for birthday'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 0,
                'issue_method' => 'auto',
                'issue_condition' => 'birthday',
                'issue_status' => 'issuing',
                'total_quantity' => null,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'days_from_issue',
                'valid_days' => 7,
                'valid_from' => null,
                'valid_to' => null,
                'issue_from' => null,
                'issue_to' => null,
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #47 - 배송비 3,000원 할인 (수동, 다운로드, 특정카테고리)
            [
                'name' => ['ko' => '카테고리 배송비 3,000원 할인', 'en' => 'Category Shipping 3,000 KRW Off'],
                'description' => ['ko' => '특정 카테고리 배송비 할인', 'en' => 'Shipping discount on categories'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 3000,
                'discount_max_amount' => null,
                'min_order_amount' => 20000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 300,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(7),
                'valid_to' => $now->copy()->addDays(53),
                'issue_from' => $now->copy()->subDays(7),
                'issue_to' => $now->copy()->addDays(30),
                'is_combinable' => true,
                'target_scope' => 'categories',
            ],

            // #48 - 무료배송 (수동, 직접발급, 전체, 발급중단)
            [
                'name' => ['ko' => '겨울 무료배송 (종료)', 'en' => 'Winter Free Shipping (Ended)'],
                'description' => ['ko' => '겨울 이벤트 종료', 'en' => 'Winter event ended'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 30000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 500,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(90),
                'valid_to' => $now->copy()->subDays(30),
                'issue_from' => $now->copy()->subDays(90),
                'issue_to' => $now->copy()->subDays(60),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #49 - 배송비 30% 할인 (수동, 다운로드, 전체, 발급중단)
            [
                'name' => ['ko' => '배송비 30% 할인 (종료)', 'en' => 'Shipping 30% Off (Ended)'],
                'description' => ['ko' => '배송비 30% 할인 이벤트 종료', 'en' => 'Shipping 30% off event ended'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'rate',
                'discount_value' => 30,
                'discount_max_amount' => 2000,
                'min_order_amount' => 20000,
                'issue_method' => 'download',
                'issue_condition' => 'manual',
                'issue_status' => 'stopped',
                'total_quantity' => 300,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->subDays(60),
                'valid_to' => $now->copy()->subDays(20),
                'issue_from' => $now->copy()->subDays(60),
                'issue_to' => $now->copy()->subDays(40),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],

            // #50 - 무료배송 (수동, 직접발급, 전체, 미래 발급 예정)
            [
                'name' => ['ko' => '설날 무료배송 (예정)', 'en' => 'Lunar New Year Free Shipping (Upcoming)'],
                'description' => ['ko' => '설날 특별 무료배송 예정', 'en' => 'Upcoming Lunar New Year free shipping'],
                'target_type' => 'shipping_fee',
                'discount_type' => 'fixed',
                'discount_value' => 5000,
                'discount_max_amount' => null,
                'min_order_amount' => 20000,
                'issue_method' => 'direct',
                'issue_condition' => 'manual',
                'issue_status' => 'issuing',
                'total_quantity' => 3000,
                'issued_count' => 0,
                'per_user_limit' => 1,
                'valid_type' => 'period',
                'valid_days' => null,
                'valid_from' => $now->copy()->addDays(15),
                'valid_to' => $now->copy()->addDays(45),
                'issue_from' => $now->copy()->addDays(15),
                'issue_to' => $now->copy()->addDays(30),
                'is_combinable' => true,
                'target_scope' => 'all',
            ],
        ];

        $createdCoupons = [];
        foreach ($couponsData as $index => $data) {
            $data['created_by'] = $adminUserId;
            $coupon = Coupon::create($data);
            $createdCoupons[] = $coupon;

            $count = $index + 1;
            if ($count % 10 === 0) {
                $this->command->line("  - 쿠폰 {$count}개 생성 완료");
            }
        }

        $this->command->line('  - 쿠폰 총 '.count($createdCoupons).'개 생성 완료');

        return $createdCoupons;
    }

    /**
     * target_scope에 따라 상품/카테고리 피벗 테이블을 연결합니다.
     *
     * @param  array<int, Coupon>  $coupons  생성된 쿠폰 배열
     */
    private function attachTargetScopes(array $coupons): void
    {
        $productIds = Product::pluck('id')->toArray();
        $categoryIds = Category::pluck('id')->toArray();

        if (empty($productIds) && empty($categoryIds)) {
            $this->command->warn('상품/카테고리 데이터가 없어 피벗 연결을 건너뜁니다.');

            return;
        }

        $attachedProducts = 0;
        $attachedCategories = 0;

        foreach ($coupons as $coupon) {
            if ($coupon->target_scope->value === 'products' && ! empty($productIds)) {
                $count = min(rand(2, 5), count($productIds));
                $selectedIds = array_rand(array_flip($productIds), $count);
                if (! is_array($selectedIds)) {
                    $selectedIds = [$selectedIds];
                }

                $pivotData = [];
                foreach ($selectedIds as $productId) {
                    $pivotData[$productId] = ['type' => 'include'];
                }
                $coupon->products()->attach($pivotData);
                $attachedProducts += count($selectedIds);
            }

            if ($coupon->target_scope->value === 'categories' && ! empty($categoryIds)) {
                $count = min(rand(1, 3), count($categoryIds));
                $selectedIds = array_rand(array_flip($categoryIds), $count);
                if (! is_array($selectedIds)) {
                    $selectedIds = [$selectedIds];
                }

                $pivotData = [];
                foreach ($selectedIds as $categoryId) {
                    $pivotData[$categoryId] = ['type' => 'include'];
                }
                $coupon->categories()->attach($pivotData);
                $attachedCategories += count($selectedIds);
            }
        }

        $this->command->line("  - 상품 피벗 {$attachedProducts}건, 카테고리 피벗 {$attachedCategories}건 연결 완료");
    }

    /**
     * 모든 쿠폰을 모든 사용자에게 사용 가능한 상태로 발급합니다.
     *
     * @param  array<int, Coupon>  $coupons  생성된 쿠폰 배열
     */
    private function createIssueRecords(array $coupons): void
    {
        $userIds = User::pluck('id')->toArray();
        if (empty($userIds)) {
            $this->command->warn('사용자 데이터가 없어 발급 내역 생성을 건너뜁니다.');

            return;
        }

        $now = Carbon::now();
        $totalIssues = 0;
        $userCount = count($userIds);

        $this->command->line("  - 쿠폰을 사용자({$userCount}명)에게 발급 시작...");

        foreach ($coupons as $index => $coupon) {
            $couponIssueCount = 0;

            // download 방식 쿠폰은 쿠폰별로 약 30%만 발급 (나머지는 미다운로드 상태로 테스트 가능)
            // 결정론적: 쿠폰 인덱스 기준으로 3개 중 1개만 발급
            if ($coupon->issue_method->value === 'download') {
                if ($index % 3 !== 0) {
                    continue;
                }
            }
            $targetUserIds = $userIds;

            foreach ($targetUserIds as $userId) {
                $issuedAt = $now->copy()->subDays(rand(1, 7));

                // 만료일 계산 (충분히 긴 유효기간 설정)
                $expiredAt = null;
                if ($coupon->valid_type === 'days_from_issue' && $coupon->valid_days) {
                    $expiredAt = $issuedAt->copy()->addDays($coupon->valid_days);
                } elseif ($coupon->valid_to) {
                    $expiredAt = $coupon->valid_to;
                }

                // 만료일이 이미 지났으면 미래로 연장
                if ($expiredAt && $expiredAt->lt($now)) {
                    $expiredAt = $now->copy()->addDays(30);
                }

                // 쿠폰 코드 (download 방식만)
                $couponCode = null;
                if ($coupon->issue_method->value === 'download') {
                    $couponCode = 'CPN'.strtoupper(substr(md5((string) $coupon->id.$userId.rand()), 0, 8));
                }

                CouponIssue::create([
                    'coupon_id' => $coupon->id,
                    'user_id' => $userId,
                    'coupon_code' => $couponCode,
                    'status' => 'available', // 모두 사용 가능한 상태로 발급
                    'issued_at' => $issuedAt,
                    'expired_at' => $expiredAt,
                    'used_at' => null,
                    'order_id' => null,
                    'discount_amount' => null,
                ]);

                $couponIssueCount++;
                $totalIssues++;
            }

            // issued_count 업데이트
            $coupon->update(['issued_count' => $couponIssueCount]);

            $count = $index + 1;
            if ($count % 10 === 0) {
                $this->command->line("  - 쿠폰 {$count}개 발급 완료");
            }
        }

        $this->command->line("  - 발급 내역 {$totalIssues}건 생성 완료 (다운로드 쿠폰은 약 1/3만 발급)");
    }
}
