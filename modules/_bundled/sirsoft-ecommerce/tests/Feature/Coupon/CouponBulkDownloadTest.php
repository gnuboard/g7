<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon;

use App\Extension\HookManager;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 일괄 받기 API 테스트
 *
 * 받을 수 있는 쿠폰을 한 번에 받는다. 한 장이 실패해도 나머지는 받고, 받지 못한 쿠폰은 사유 코드와 함께 돌려준다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/coupon-bulk-download.yaml
 */
class CouponBulkDownloadTest extends ModuleTestCase
{
    private const URL = '/api/modules/sirsoft-ecommerce/user/coupons/bulk-download';

    /**
     * 받을 수 있는 쿠폰 기본형을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     * @return Coupon 만든 쿠폰
     */
    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name' => ['ko' => '테스트 쿠폰', 'en' => 'Test Coupon'],
            'description' => ['ko' => '설명', 'en' => 'Desc'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT,
            'discount_type' => CouponDiscountType::FIXED,
            'discount_value' => 1000,
            'min_order_amount' => 10000,
            'target_scope' => CouponTargetScope::ALL,
            'issue_method' => CouponIssueMethod::DOWNLOAD,
            'issue_condition' => CouponIssueCondition::MANUAL,
            'issue_status' => CouponIssueStatus::ISSUING,
            'issue_from' => Carbon::now()->subDay(),
            'issue_to' => Carbon::now()->addMonth(),
            'per_user_limit' => 1,
            'total_quantity' => 100,
            'issued_count' => 0,
            'valid_type' => 'period',
            'valid_from' => Carbon::now()->subDay(),
            'valid_to' => Carbon::now()->addMonth(),
        ], $overrides));
    }

    /**
     * 결과 목록을 쿠폰 번호 => [상태, 사유] 로 바꿉니다.
     *
     * @param  array<int, array<string, mixed>>  $results  응답 결과 목록
     * @return array<int, array{0: string, 1: string|null}> 쿠폰 번호별 상태와 사유
     */
    private function byCoupon(array $results): array
    {
        $map = [];
        foreach ($results as $row) {
            $map[(int) $row['coupon_id']] = [$row['status'], $row['reason_code']];
        }

        return $map;
    }

    /**
     * 받을 수 있는 쿠폰은 받고, 못 받는 쿠폰은 사유 코드와 함께 건너뛴다(부분 성공 200).
     *
     * @scenario request=explicit_ids, outcome=mixed
     *
     * @effects bulk_issues_downloadable_coupons, bulk_skips_already_downloaded, bulk_reports_reason_codes, bulk_partial_success_returns_200
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_issues_what_it_can_and_reports_reasons(): void
    {
        $user = $this->createUser();
        $fresh = $this->makeCoupon();
        $already = $this->makeCoupon(['per_user_limit' => 2]);
        app(UserCouponService::class)->downloadCoupon($user->id, $already->id);
        $exhausted = $this->makeCoupon(['total_quantity' => 5, 'issued_count' => 5]);
        $expired = $this->makeCoupon(['valid_to' => Carbon::now()->subHour()]);
        $missingId = $expired->id + 1000;

        $response = $this->actingAs($user, 'sanctum')->postJson(self::URL, [
            'coupon_ids' => [$fresh->id, $already->id, $exhausted->id, $expired->id, $missingId],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.issued_count', 1)
            ->assertJsonPath('data.skipped_count', 4);

        $this->assertSame([
            $fresh->id => ['issued', null],
            $already->id => ['skipped', 'already_downloaded'],
            $exhausted->id => ['skipped', 'quantity_exhausted'],
            $expired->id => ['skipped', 'expired'],
            $missingId => ['skipped', 'not_downloadable'],
        ], $this->byCoupon($response->json('data.results')));

        // 이미 받은 쿠폰은 회원당 한도가 남아 있어도 한 장 더 받지 않는다
        $this->assertSame(1, CouponIssue::where('user_id', $user->id)->where('coupon_id', $already->id)->count());
        $this->assertSame(1, CouponIssue::where('user_id', $user->id)->where('coupon_id', $fresh->id)->count());
        $this->assertNotEmpty($response->json('message'));
    }

    /**
     * 영어 응답 문구는 받은 장수로 단·복수를 고른다 — `coupon(s)` 처럼 둘을 함께 적지 않는다.
     *
     * @scenario request=explicit_ids, outcome=english_plural
     *
     * @effects bulk_english_message_picks_singular_or_plural
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_english_message_picks_singular_or_plural(): void
    {
        $user = $this->createUser();
        $one = $this->makeCoupon();
        $taken = $this->makeCoupon();
        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => [$taken->id]])->assertOk();

        $this->actingAs($user, 'sanctum')->withHeaders(['Accept-Language' => 'en'])
            ->postJson(self::URL, ['coupon_ids' => [$one->id, $taken->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Downloaded 1 coupon. 1 could not be downloaded.');

        $second = $this->makeCoupon();
        $third = $this->makeCoupon();
        $this->actingAs($user, 'sanctum')->withHeaders(['Accept-Language' => 'en'])
            ->postJson(self::URL, ['coupon_ids' => [$second->id, $third->id]])
            ->assertOk()
            ->assertJsonPath('message', 'Downloaded 2 coupons.');
    }

    /**
     * 번호를 주지 않으면 그 회원이 받을 수 있는 쿠폰 전체를 받는다.
     *
     * @scenario request=no_ids, outcome=all_downloadable
     *
     * @effects bulk_without_ids_downloads_all_downloadable
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_without_ids_takes_every_downloadable_coupon(): void
    {
        $user = $this->createUser();
        $first = $this->makeCoupon();
        $second = $this->makeCoupon();
        // 받을 수 없는 쿠폰(자동 발급 전용)은 대상에 들지 않는다
        $this->makeCoupon(['issue_method' => CouponIssueMethod::AUTO]);

        $response = $this->actingAs($user, 'sanctum')->postJson(self::URL, []);

        $response->assertOk()->assertJsonPath('data.issued_count', 2);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($response->json('data.results'), 'coupon_id')
        );
    }

    /**
     * 번호 없이 부를 때 한 번에 다루는 50장은 아직 받지 않은 쿠폰부터 채운다.
     *
     * 최신 50장을 이미 받은 회원도 그보다 오래된 쿠폰을 받을 수 있어야 한다 — 최신순으로 50장을 먼저 자르면
     * 51번째부터는 몇 번을 눌러도 받지 못한다.
     *
     * @scenario request=no_ids, outcome=over_limit
     *
     * @effects bulk_without_ids_fills_limit_with_not_yet_downloaded
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_without_ids_prefers_coupons_not_yet_downloaded(): void
    {
        $user = $this->createUser();
        // 오래된 쿠폰 한 장(아직 받지 않음) + 그 뒤에 만든 50장(모두 받음)
        $older = $this->makeCoupon();
        Coupon::whereKey($older->id)->update(['created_at' => Carbon::now()->subDays(3)]);
        $service = app(UserCouponService::class);
        for ($i = 0; $i < 50; $i++) {
            $service->downloadCoupon($user->id, $this->makeCoupon()->id);
        }

        $response = $this->actingAs($user, 'sanctum')->postJson(self::URL, []);

        $response->assertOk()->assertJsonPath('data.issued_count', 1);
        $this->assertSame(['issued', null], $this->byCoupon($response->json('data.results'))[$older->id] ?? null);
    }

    /**
     * 받은 장이 없어도 200 이고 결과가 사유를 싣는다.
     *
     * @scenario request=explicit_ids, outcome=none_issued
     *
     * @effects bulk_none_issued_still_returns_200
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_with_nothing_issued_still_returns_200(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeCoupon();
        app(UserCouponService::class)->downloadCoupon($user->id, $coupon->id);

        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => [$coupon->id]])
            ->assertOk()
            ->assertJsonPath('data.issued_count', 0)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.results.0.reason_code', 'already_downloaded');
    }

    /**
     * 미리 센 뒤 다른 요청(다른 탭)이 먼저 받아 가 회원당 한도가 찼으면 download_limit_exceeded 로 건너뛴다.
     *
     * 「이미 받음」 건너뛰기는 한 번에 센 수로 판단하므로, 그 사이에 받은 장은 쿠폰별 잠금 안의 한도 검사가 잡는다.
     *
     * @scenario request=explicit_ids, outcome=concurrent_limit
     *
     * @effects bulk_reports_limit_exceeded_when_raced
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_reports_limit_exceeded_when_another_request_took_it_first(): void
    {
        $user = $this->createUser();
        $coupon = $this->makeCoupon(['per_user_limit' => 1]);
        $raced = false;
        $hook = 'sirsoft-ecommerce.user_coupon.before_download';
        HookManager::addAction($hook, function (int $userId, int $couponId) use (&$raced): void {
            if ($raced) {
                return;
            }
            $raced = true;
            // 다른 탭이 같은 쿠폰을 먼저 받았다 — 일괄 받기가 「이미 받은 수」를 센 뒤의 일
            app(UserCouponService::class)->downloadCoupon($userId, $couponId);
        });

        try {
            $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => [$coupon->id]])
                ->assertOk()
                ->assertJsonPath('data.issued_count', 0)
                ->assertJsonPath('data.skipped_count', 1)
                ->assertJsonPath('data.results.0.status', 'skipped')
                ->assertJsonPath('data.results.0.reason_code', 'download_limit_exceeded');
        } finally {
            HookManager::clearAction($hook);
        }

        // 한도 1 — 먼저 받은 1장만 남는다
        $this->assertSame(1, CouponIssue::where('user_id', $user->id)->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 비회원은 401, 잘못된 요청은 422.
     *
     * @scenario request=invalid, outcome=rejected
     *
     * @effects bulk_requires_login, bulk_rejects_invalid_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_download_requires_login_and_valid_ids(): void
    {
        $this->postJson(self::URL, [])->assertStatus(401);

        $user = $this->createUser();
        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => range(1, 51)])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => [1, 1]])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => ['abc']])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['coupon_ids' => []])->assertStatus(422);
    }

    /**
     * 「이미 받은 수」 조회는 쿠폰 수와 무관하게 한 번이다.
     *
     * @scenario request=explicit_ids, outcome=query_count
     *
     * @effects bulk_counts_issued_in_one_query
     *
     * @return void 반환값 없음
     */
    public function test_issued_counts_are_fetched_in_one_query(): void
    {
        $user = User::factory()->create();
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->makeCoupon()->id;
        }
        $service = app(UserCouponService::class);
        foreach ($ids as $id) {
            $service->downloadCoupon($user->id, $id);
        }

        $issueTable = (new CouponIssue)->getTable();
        $countQueries = 0;
        DB::listen(function ($query) use (&$countQueries, $issueTable) {
            if (str_contains($query->sql, $issueTable) && stripos($query->sql, 'count(') !== false) {
                $countQueries++;
            }
        });

        $result = $service->bulkDownloadCoupons($user->id, $ids);

        $this->assertSame(0, $result['issued_count']);
        $this->assertSame(6, $result['skipped_count']);
        $this->assertSame(1, $countQueries, '쿠폰 6장의 「이미 받은 수」는 한 번의 집계로 읽어야 한다');
    }
}
