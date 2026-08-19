<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 is_combinable boolean 정규화 — 공개 이슈 #97 회귀
 *
 * 관리자 쿠폰 폼의 라디오가 문자열 "true"/"false" 를 전송하면 `boolean` 규칙에서
 * 422 로 저장이 실패했다. Store/UpdateCouponRequest 의 prepareForValidation 이
 * 문자열 표기를 boolean 으로 정규화("false" → false 가 핵심 — truthy 오변환 방지)
 * 하고, 해석 불가값은 건드리지 않아 boolean 규칙이 422 로 처리함을 고정한다.
 */
class CouponCombinableBooleanTest extends ModuleTestCase
{
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
     * 쿠폰 생성 시 필요한 기본 데이터
     *
     * @param  array  $overrides  오버라이드할 속성
     */
    private function validCouponData(array $overrides = []): array
    {
        return array_merge([
            'name' => ['ko' => '중복사용 테스트 쿠폰', 'en' => 'Combinable Test Coupon'],
            'description' => ['ko' => '테스트 설명', 'en' => 'Test description'],
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
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
        ], $overrides);
    }

    /**
     * 수용해야 하는 boolean 표기 6종과 기대 저장값
     *
     * @return array<string, array{mixed, bool}> [입력값, 기대 boolean]
     */
    private function acceptedNotations(): array
    {
        return [
            "문자열 'true'" => ['true', true],
            "문자열 'false'" => ['false', false],
            "문자열 '1'" => ['1', true],
            "문자열 '0'" => ['0', false],
            'boolean true' => [true, true],
            'boolean false' => [false, false],
        ];
    }

    /**
     * 생성: boolean 표기 6종 모두 201 + DB boolean 저장
     */
    public function test_store_accepts_boolean_notations_and_persists_boolean(): void
    {
        foreach ($this->acceptedNotations() as $label => [$input, $expected]) {
            $response = $this->actingAs($this->adminUser)
                ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData([
                    'is_combinable' => $input,
                ]));

            $response->assertStatus(201);

            $coupon = Coupon::find($response->json('data.id'));
            $this->assertSame(
                $expected,
                $coupon->is_combinable,
                "생성 {$label} 입력이 boolean {$this->boolLabel($expected)} 로 저장되어야 합니다."
            );
        }
    }

    /**
     * 수정: boolean 표기 6종 모두 200 + DB boolean 저장
     */
    public function test_update_accepts_boolean_notations_and_persists_boolean(): void
    {
        $createResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData());
        $createResponse->assertStatus(201);
        $couponId = $createResponse->json('data.id');

        foreach ($this->acceptedNotations() as $label => [$input, $expected]) {
            $response = $this->actingAs($this->adminUser)
                ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                    'is_combinable' => $input,
                    'per_user_limit' => 0,
                ]);

            $response->assertStatus(200);
            $this->assertSame(
                $expected,
                Coupon::find($couponId)->is_combinable,
                "수정 {$label} 입력이 boolean {$this->boolLabel($expected)} 로 저장되어야 합니다."
            );
        }
    }

    /**
     * 생성: 해석 불가값('abc')은 정규화하지 않고 boolean 규칙이 422 처리
     */
    public function test_store_rejects_unparsable_value_with_422(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData([
                'is_combinable' => 'abc',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_combinable']);
    }

    /**
     * 수정: 해석 불가값('abc')은 정규화하지 않고 boolean 규칙이 422 처리
     */
    public function test_update_rejects_unparsable_value_with_422(): void
    {
        $createResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData());
        $createResponse->assertStatus(201);
        $couponId = $createResponse->json('data.id');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'is_combinable' => 'abc',
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_combinable']);

        // 실패한 요청이 저장값을 건드리지 않아야 한다
        $this->assertTrue(Coupon::find($couponId)->is_combinable);
    }

    /**
     * 생성: is_combinable 미전송이면 정규화가 개입하지 않고 그대로 통과 (has 가드)
     */
    public function test_store_without_is_combinable_passes(): void
    {
        $data = $this->validCouponData();
        unset($data['is_combinable']);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $data);

        $response->assertStatus(201);
    }

    /**
     * 수정: is_combinable 미전송이면 기존 저장값이 유지된다 (has 가드)
     */
    public function test_update_without_is_combinable_preserves_stored_value(): void
    {
        $createResponse = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/promotion-coupons', $this->validCouponData([
                'is_combinable' => false,
            ]));
        $createResponse->assertStatus(201);
        $couponId = $createResponse->json('data.id');

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$couponId}", [
                'per_user_limit' => 0,
            ]);

        $response->assertStatus(200);
        $this->assertFalse(Coupon::find($couponId)->is_combinable);
    }

    /**
     * 단언 메시지용 boolean 표기
     */
    private function boolLabel(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
