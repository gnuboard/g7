<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 쿠폰 유효기간·발급기간 표기의 날짜 기준 테스트
 *
 * 관리자가 고른 날짜는 사이트 시간대 자정으로 받아 UTC 로 저장된다(예: 한국 10월 1일 0시 →
 * `2026-09-30 15:00:00`). 표기 문자열이 저장값의 UTC 날짜를 그대로 찍으면 시작일이 하루 이르게
 * 보였다. 표기는 같은 응답의 `valid_from`(사이트 날짜)과 같은 날짜여야 한다.
 */
class CouponPeriodFormattedTimezoneTest extends ModuleTestCase
{
    /**
     * 사이트 시간대를 한국으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.default_user_timezone' => 'Asia/Seoul']);
    }

    /**
     * 유효기간 표기는 사이트 시간대의 날짜로 찍힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=c01
     *
     * @effects coupon_period_uses_site_timezone
     */
    public function test_valid_period_formatted_uses_site_timezone_dates(): void
    {
        $coupon = Coupon::make([
            'valid_type' => 'period',
            'valid_from' => Carbon::parse('2026-09-30 15:00:00', 'UTC'),
            'valid_to' => Carbon::parse('2026-12-31 14:59:59', 'UTC'),
        ]);

        $this->assertSame('2026-10-01 ~ 2026-12-31', $coupon->valid_period_formatted);
    }

    /**
     * 발급기간 표기도 사이트 시간대의 날짜로 찍힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=c02
     *
     * @effects coupon_period_uses_site_timezone
     */
    public function test_issue_period_formatted_uses_site_timezone_dates(): void
    {
        $coupon = Coupon::make([
            'issue_from' => Carbon::parse('2026-09-30 15:00:00', 'UTC'),
            'issue_to' => Carbon::parse('2026-10-31 14:59:59', 'UTC'),
        ]);

        $this->assertSame('2026-10-01 ~ 2026-10-31', $coupon->issue_period_formatted);
    }
}
