<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Public;

use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰존 공개 목록 API (#952 C)
 */
class PublicCouponZoneTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    /** 공개 목록 주소 */
    private const URL = '/api/modules/sirsoft-ecommerce/coupons/downloadable';

    /** 회원 목록 주소 */
    private const USER_URL = '/api/modules/sirsoft-ecommerce/user/coupons/downloadable';

    /** 응답에 나오면 안 되는 내부 칸 */
    private const INTERNAL_KEYS = ['notify_enabled', 'notify_marketing_only', 'first_purchase_trigger_status', 'created_by', 'issued_count', 'total_quantity'];

    /**
     * 제한기 기록을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('sirsoft-ecommerce.public-coupons');
    }

    /**
     * 비로그인으로 받기 가능(다운로드·수동·발급중) 쿠폰만 보이고, 받은 여부와 내부 칸은 없다.
     *
     * @scenario case=coupon_zone_guest
     *
     * @effects coupon_zone_lists_downloadable_only
     *
     * @return void 반환값 없음
     */
    public function test_guest_sees_only_downloadable_issuing_coupons_without_internal_fields(): void
    {
        $visible = $this->makeDownloadCoupon(['name' => ['ko' => 'i952 받기', 'en' => 'i952 dl']]);
        $stopped = $this->makeDownloadCoupon(['issue_status' => CouponIssueStatus::STOPPED]);
        $auto = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);

        $response = $this->getJson(self::URL);

        $response->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($stopped->id, $ids);
        $this->assertNotContains($auto->id, $ids);

        $item = collect($response->json('data.data'))->firstWhere('id', $visible->id);
        $this->assertArrayNotHasKey('is_downloaded', $item);
        foreach (self::INTERNAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $item, $key);
        }
        $this->assertArrayHasKey('benefit_formatted', $item);
        $this->assertArrayHasKey('remaining_quantity', $item);
    }

    /**
     * 회원용 받기 목록도 쿠폰 내부 칸(알림·발급 시점)을 내보내지 않고, 공개 목록과 같은 표시 칸을 갖는다.
     *
     * @scenario case=coupon_zone_member
     *
     * @effects coupon_zone_member_shape_matches
     *
     * @return void 반환값 없음
     */
    public function test_member_list_hides_internal_fields_and_matches_public_shape(): void
    {
        $coupon = $this->makeDownloadCoupon();
        $user = $this->createUser();

        $public = collect($this->getJson(self::URL)->json('data.data'))->firstWhere('id', $coupon->id);
        $member = collect($this->actingAs($user)->getJson(self::USER_URL)->assertOk()->json('data.data'))->firstWhere('id', $coupon->id);

        $this->assertNotNull($member);
        foreach (['notify_enabled', 'notify_marketing_only', 'first_purchase_trigger_status'] as $key) {
            $this->assertArrayNotHasKey($key, $member, $key);
        }
        $this->assertArrayHasKey('is_downloaded', $member);
        foreach (array_keys($public) as $key) {
            $this->assertArrayHasKey($key, $member, "회원 목록에 {$key} 가 없다");
        }
    }

    /**
     * 같은 공개 주소를 로그인 회원이 토큰을 실어 부르면 받은 여부가 실린다 — 쿠폰존 화면은 로그인 확인 전후로 주소를 바꾸지 않는다.
     *
     * @scenario case=coupon_zone_member_on_public
     *
     * @effects coupon_zone_member_shape_matches
     *
     * @return void 반환값 없음
     */
    public function test_public_list_with_member_token_carries_download_state(): void
    {
        $received = $this->makeDownloadCoupon();
        $fresh = $this->makeDownloadCoupon();
        $user = $this->createUser();
        \Modules\Sirsoft\Ecommerce\Models\CouponIssue::query()->create([
            'coupon_id' => $received->id, 'user_id' => $user->id, 'coupon_code' => 'DL-'.uniqid(), 'status' => 'available', 'issued_at' => now(),
        ]);

        $rows = collect($this->actingAs($user)->getJson(self::URL)->assertOk()->json('data.data'))->keyBy('id');

        $this->assertTrue($rows[$received->id]['is_downloaded']);
        $this->assertFalse($rows[$fresh->id]['is_downloaded']);
        foreach (['notify_enabled', 'notify_marketing_only', 'first_purchase_trigger_status'] as $key) {
            $this->assertArrayNotHasKey($key, $rows[$received->id], $key);
        }
    }

    /**
     * 공개 목록은 분당 60회를 넘으면 429 다.
     *
     * @scenario case=coupon_zone_throttle
     *
     * @effects coupon_zone_throttled
     *
     * @return void 반환값 없음
     */
    public function test_public_list_is_throttled(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(self::URL)->assertOk();
        }

        $this->getJson(self::URL)->assertStatus(429);
    }

    /**
     * per_page 상한(48)을 넘으면 422 다.
     *
     * @scenario case=coupon_zone_validation
     *
     * @effects coupon_zone_validates_paging
     *
     * @return void 반환값 없음
     */
    public function test_per_page_over_limit_is_rejected(): void
    {
        $this->getJson(self::URL.'?per_page=49')->assertStatus(422);
    }
}
