<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 수정 요청의 조건부 검증 대칭성
 *
 * StoreCouponRequest 는 valid_type 에 따라 유효기간 필드를 required_if 로 강제하지만
 * UpdateCouponRequest 에는 그 조건부 규칙이 없어, 수정 경로로 "기간 지정" 쿠폰의
 * 시작일/종료일을 비운 채 저장할 수 있었습니다(검증 우회). 반대로 per_user_limit 은
 * sometimes 가 없어 다른 탭만 수정할 때도 항상 전송해야 했습니다(부분 수정 실패).
 */
class CouponUpdateConditionalValidationTest extends ModuleTestCase
{
    private string $baseUrl = '/api/modules/sirsoft-ecommerce/admin/promotion-coupons';

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
    }

    /**
     * 쿠폰 생성 데이터
     *
     * @param  array  $overrides  오버라이드할 속성
     * @return array 생성 요청 본문
     */
    private function validCouponData(array $overrides = []): array
    {
        return array_merge([
            'name' => ['ko' => '유효기간 쿠폰', 'en' => 'Validity Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 5000,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'period',
            'valid_from' => now()->format('Y-m-d'),
            'valid_to' => now()->addMonth()->format('Y-m-d'),
        ], $overrides);
    }

    /**
     * 테스트용 쿠폰을 생성하고 ID를 반환합니다.
     *
     * @param  array  $overrides  생성 데이터 오버라이드
     * @return int 생성된 쿠폰 ID
     */
    private function createCoupon(array $overrides = []): int
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson($this->baseUrl, $this->validCouponData($overrides));

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /**
     * 기간 지정 쿠폰의 시작일/종료일을 비운 수정은 거부됩니다.
     */
    public function test_update_period_type_without_dates_fails(): void
    {
        $couponId = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->baseUrl}/{$couponId}", [
                'valid_type' => 'period',
                'valid_from' => null,
                'valid_to' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_from', 'valid_to']);
    }

    /**
     * 발급일 기준 쿠폰의 유효일수를 비운 수정은 거부됩니다.
     */
    public function test_update_days_from_issue_type_without_valid_days_fails(): void
    {
        $couponId = $this->createCoupon([
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'valid_from' => null,
            'valid_to' => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->baseUrl}/{$couponId}", [
                'valid_type' => 'days_from_issue',
                'valid_days' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['valid_days']);
    }

    /**
     * 유효기간을 함께 보내는 정상 수정은 통과합니다.
     */
    public function test_update_period_type_with_dates_passes(): void
    {
        $couponId = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->baseUrl}/{$couponId}", [
                'valid_type' => 'period',
                'valid_from' => now()->addDay()->format('Y-m-d'),
                'valid_to' => now()->addMonths(2)->format('Y-m-d'),
            ]);

        $response->assertStatus(200);
    }

    /**
     * valid_type 을 보내지 않는 부분 수정은 유효기간 필드를 요구하지 않습니다.
     *
     * required_if 는 조건 필드가 요청에 없으면 발화하지 않으므로, 다른 탭만 수정하는
     * 요청이 유효기간 때문에 막히지 않아야 합니다.
     */
    public function test_update_without_valid_type_does_not_require_dates(): void
    {
        $couponId = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->baseUrl}/{$couponId}", [
                'min_order_amount' => 7000,
            ]);

        $response->assertStatus(200);
    }

    /**
     * per_user_limit 을 보내지 않는 부분 수정이 통과합니다.
     *
     * 같은 파일의 다른 필드는 모두 sometimes 를 갖는데 per_user_limit 만 누락돼 있어
     * 부분 수정 요청이 "1인당 사용 제한" 미전송으로 422 가 되던 결함입니다.
     */
    public function test_update_without_per_user_limit_passes(): void
    {
        $couponId = $this->createCoupon();

        $response = $this->actingAs($this->adminUser)
            ->putJson("{$this->baseUrl}/{$couponId}", [
                'min_order_amount' => 8000,
            ]);

        $response->assertStatus(200);
    }
}
