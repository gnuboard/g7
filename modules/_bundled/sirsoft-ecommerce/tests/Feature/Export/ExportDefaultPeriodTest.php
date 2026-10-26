<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use App\Helpers\TimezoneHelper;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 창 카탈로그의 기본 기간 — 기간이 필수인 유형(주문·마일리지)에서 목록의 조회 기간을 쓸 수 없으면(없거나 1년 초과)
 * 「오늘 포함 최근 1개월」을 미리 채울 수 있게 서버가 계산해 준다. 목록 기간이 1년 이내면 그대로 쓴다.
 *
 * 날짜는 그 유형의 직접 지정 기간을 해석하는 시간대로 계산한다 — 주문은 사이트 시간대, 마일리지는 운영자 시간대.
 */
class ExportDefaultPeriodTest extends ExportTestCase
{
    protected User $admin;

    /**
     * 세 유형을 조회할 수 있는 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.exports.create',
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.mileage.read',
        ]);
    }

    /**
     * 창 카탈로그를 받습니다.
     *
     * @param  array<string, string>  $query  쿼리
     * @return array<string, mixed> 카탈로그 data
     */
    private function catalog(array $query): array
    {
        return (array) $this->actingAs($this->admin)
            ->getJson($this->exportsUrl('/catalog?'.http_build_query($query)))
            ->assertOk()
            ->json('data');
    }

    /**
     * 주문 목록에 조회 기간이 없으면 사이트 시간대 기준 「오늘 포함 최근 1개월」 기본 기간을 준다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, scope=filter
     *
     * @effects missing_list_period_defaults_to_recent_month
     */
    public function test_orders_without_list_period_get_recent_month_default(): void
    {
        $today = Carbon::now(TimezoneHelper::getSiteTimezone())->startOfDay();

        $data = $this->catalog(['type' => 'orders']);

        $this->assertFalse($data['list_period_usable']);
        $this->assertSame([
            'mode' => 'custom',
            'date_type' => 'ordered_at',
            'start_date' => $today->copy()->subMonthNoOverflow()->toDateString(),
            'end_date' => $today->toDateString(),
        ], $data['default_period']);
    }

    /**
     * 목록 기간이 1년 이내면 그대로 쓰고, 1년을 넘으면 기본 기간을 쓴다(마일리지 — 운영자 시간대).
     *
     * @return void 반환값 없음
     *
     * @scenario type=mileage, scope=filter
     *
     * @effects missing_list_period_defaults_to_recent_month
     */
    public function test_list_period_used_only_when_within_one_year(): void
    {
        $within = $this->catalog(['type' => 'mileage', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->assertTrue($within['list_period_usable']);

        $tooLong = $this->catalog(['type' => 'mileage', 'start_date' => '2026-01-01', 'end_date' => '2027-01-01']);
        $this->assertFalse($tooLong['list_period_usable']);
        $today = Carbon::now(TimezoneHelper::getUserTimezone())->toDateString();
        $this->assertSame($today, $tooLong['default_period']['end_date']);
    }

    /**
     * 기간이 필수가 아닌 상품은 기본 기간을 주지 않는다(목록 기간이 없어도 그대로 요청할 수 있다).
     *
     * @return void 반환값 없음
     *
     * @scenario type=products, scope=filter
     *
     * @effects missing_list_period_defaults_to_recent_month
     */
    public function test_products_get_no_default_period(): void
    {
        $data = $this->catalog(['type' => 'products']);

        $this->assertNull($data['default_period']);
        $this->assertTrue($data['list_period_usable']);
    }
}
