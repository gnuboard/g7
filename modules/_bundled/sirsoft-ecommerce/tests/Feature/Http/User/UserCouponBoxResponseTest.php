<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\User;

use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마이페이지 쿠폰함 응답 (#952 C)
 */
class UserCouponBoxResponseTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    /** 쿠폰함 주소 */
    private const URL = '/api/modules/sirsoft-ecommerce/user/coupons';

    /**
     * 발급 건을 만듭니다.
     *
     * @param  Coupon  $coupon  쿠폰
     * @param  int  $userId  회원 ID
     * @param  string  $status  상태
     * @return CouponIssue 발급 건
     */
    private function issue(Coupon $coupon, int $userId, string $status = 'available'): CouponIssue
    {
        return CouponIssue::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $userId,
            'coupon_code' => 'DL-'.uniqid(),
            'status' => $status,
            'issued_at' => now(),
            'expired_at' => now()->addDays(5),
            'used_at' => $status === CouponIssueRecordStatus::USED->value ? now() : null,
        ]);
    }

    /**
     * 카드마다 쿠폰 블록(혜택·적용 대상 등)과 남은 날 수가 실리고, 내부 칸은 없다.
     *
     * @scenario case=user_coupons_coupon_block
     *
     * @effects coupon_box_card_block
     *
     * @return void 반환값 없음
     */
    public function test_each_card_has_coupon_block_and_days_left(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeDownloadCoupon(['notify_enabled' => true]);
        $this->issue($coupon, $user->id);

        $item = $this->actingAs($user)->getJson(self::URL)->assertOk()->json('data.coupons.data.0');

        $this->assertSame($coupon->id, $item['coupon']['id']);
        $this->assertArrayHasKey('benefit_formatted', $item['coupon']);
        $this->assertArrayHasKey('included_product_names', $item['coupon']);
        $this->assertArrayNotHasKey('notify_enabled', $item['coupon']);
        $this->assertSame(5, $item['expires_in_days']);
    }

    /**
     * 상태 필터는 그 상태만, 남의 쿠폰은 보이지 않는다.
     *
     * @scenario case=user_coupons_status_filter
     *
     * @effects coupon_box_status_filter
     *
     * @return void 반환값 없음
     */
    public function test_status_filter_and_ownership(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $coupon = $this->makeDownloadCoupon(['per_user_limit' => 0]);
        $this->issue($coupon, $user->id);
        $used = $this->issue($coupon, $user->id, CouponIssueRecordStatus::USED->value);
        $this->issue($coupon, $other->id);

        $all = collect($this->actingAs($user)->getJson(self::URL)->json('data.coupons.data'));
        $usedOnly = collect($this->actingAs($user)->getJson(self::URL.'?status=used')->json('data.coupons.data'));

        $this->assertCount(2, $all);
        $this->assertSame([$used->id], $usedOnly->pluck('id')->all());
        $this->actingAs($user)->getJson(self::URL.'?status=bogus')->assertStatus(422);
    }

    /**
     * 적용 상품 이름은 3개까지만 싣고 전체 수를 함께 준다. 쿠폰이 지워진 발급 건은 쿠폰 블록이 null 이다.
     *
     * @scenario case=user_coupons_names_and_deleted
     *
     * @effects coupon_box_name_limit
     *
     * @return void 반환값 없음
     */
    public function test_target_names_limited_and_deleted_coupon_block_is_null(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeDownloadCoupon(['target_scope' => \Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope::PRODUCTS]);
        $products = \Modules\Sirsoft\Ecommerce\Models\Product::factory()->count(4)->create();
        $coupon->includedProducts()->attach($products->pluck('id')->all());
        $this->issue($coupon, $user->id);

        $item = $this->actingAs($user)->getJson(self::URL)->json('data.coupons.data.0');
        $this->assertCount(3, $item['coupon']['included_product_names']);
        $this->assertSame(4, $item['coupon']['included_product_count']);

        $coupon->delete();
        $after = $this->actingAs($user)->getJson(self::URL)->json('data.coupons.data');
        foreach ($after as $row) {
            $this->assertNull($row['coupon']);
        }
    }

    /**
     * 관리자 발급 내역 응답에는 쿠폰함용 쿠폰 블록이 붙지 않는다.
     *
     * @scenario case=admin_coupon_issues
     *
     * @effects coupon_box_block_user_only
     *
     * @return void 반환값 없음
     */
    public function test_admin_issue_list_has_no_coupon_block(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeDownloadCoupon();
        $this->issue($coupon, $user->id);
        $admin = $this->createAdminUser(['sirsoft-ecommerce.promotion-coupon.read']);

        $response = $this->actingAs($admin)->getJson("/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issues")->assertOk();
        $first = collect($response->json('data.data') ?? $response->json('data.issues.data') ?? [])->first();

        $this->assertNotNull($first);
        $this->assertArrayNotHasKey('coupon', $first);
        $this->assertArrayHasKey('issue_source', $first);
    }
}
