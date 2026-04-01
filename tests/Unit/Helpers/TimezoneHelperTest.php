<?php

namespace Tests\Unit\Helpers;

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TimezoneHelper 단위 테스트
 */
class TimezoneHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 시간 고정 (UTC 기준 2025-01-15 00:00:00)
        Carbon::setTestNow(Carbon::parse('2025-01-15 00:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * user_timezone 바인딩이 없을 때 config 기본값을 반환하는지 테스트합니다.
     */
    public function test_get_user_timezone_returns_config_default(): void
    {
        App::forgetInstance('user_timezone');

        $this->assertEquals(
            config('app.default_user_timezone', 'Asia/Seoul'),
            TimezoneHelper::getUserTimezone()
        );
    }

    /**
     * user_timezone 바인딩이 있을 때 해당 값을 반환하는지 테스트합니다.
     */
    public function test_get_user_timezone_returns_bound_value(): void
    {
        App::instance('user_timezone', 'America/New_York');

        $this->assertEquals('America/New_York', TimezoneHelper::getUserTimezone());
    }

    /**
     * null datetime에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_to_user_timezone_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::toUserTimezone(null));
    }

    /**
     * ISO8601 형식으로 사용자 타임존 변환이 올바른지 테스트합니다.
     */
    public function test_to_user_timezone_converts_to_iso8601(): void
    {
        App::instance('user_timezone', 'Asia/Seoul');

        $utcDatetime = Carbon::parse('2025-01-15 00:00:00', 'UTC');
        $result = TimezoneHelper::toUserTimezone($utcDatetime);

        $this->assertNotNull($result);

        // ISO8601 파싱 후 +09:00 확인
        $parsed = Carbon::parse($result);
        $this->assertEquals('+09:00', $parsed->timezone->getName());
        $this->assertEquals(9, $parsed->hour);
    }

    /**
     * Y-m-d H:i:s 형식 변환이 올바른지 테스트합니다.
     */
    public function test_to_user_date_time_string_converts_correctly(): void
    {
        App::instance('user_timezone', 'Asia/Seoul');

        $utcDatetime = Carbon::parse('2025-01-15 00:00:00', 'UTC');
        $result = TimezoneHelper::toUserDateTimeString($utcDatetime);

        $this->assertEquals('2025-01-15 09:00:00', $result);
    }

    /**
     * null datetime에 대해 toUserDateTimeString이 null을 반환하는지 테스트합니다.
     */
    public function test_to_user_date_time_string_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::toUserDateTimeString(null));
    }

    /**
     * Y-m-d 형식 변환이 올바른지 테스트합니다.
     */
    public function test_to_user_date_string_converts_correctly(): void
    {
        App::instance('user_timezone', 'Asia/Seoul');

        // UTC 23:00 → Asia/Seoul 다음날 08:00
        $utcDatetime = Carbon::parse('2025-01-15 23:00:00', 'UTC');
        $result = TimezoneHelper::toUserDateString($utcDatetime);

        // UTC 23시 + 9시간 = 다음날 08시
        $this->assertEquals('2025-01-16', $result);
    }

    /**
     * null datetime에 대해 toUserDateString이 null을 반환하는지 테스트합니다.
     */
    public function test_to_user_date_string_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::toUserDateString(null));
    }

    /**
     * toUserCarbon이 사용자 타임존의 Carbon 인스턴스를 반환하는지 테스트합니다.
     */
    public function test_to_user_carbon_returns_carbon_with_user_timezone(): void
    {
        App::instance('user_timezone', 'America/New_York');

        $utcDatetime = Carbon::parse('2025-01-15 12:00:00', 'UTC');
        $result = TimezoneHelper::toUserCarbon($utcDatetime);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
        $this->assertEquals(7, $result->hour); // UTC 12:00 → EST 07:00
    }

    /**
     * null datetime에 대해 toUserCarbon이 null을 반환하는지 테스트합니다.
     */
    public function test_to_user_carbon_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::toUserCarbon(null));
    }

    /**
     * toUserCarbon이 원본 datetime을 변경하지 않는지 테스트합니다 (immutability).
     */
    public function test_to_user_carbon_does_not_mutate_original(): void
    {
        App::instance('user_timezone', 'Asia/Seoul');

        $original = Carbon::parse('2025-01-15 00:00:00', 'UTC');
        TimezoneHelper::toUserCarbon($original);

        $this->assertEquals('UTC', $original->timezone->getName());
        $this->assertEquals(0, $original->hour);
    }

    /**
     * toUserTimezone도 원본 datetime을 변경하지 않는지 테스트합니다 (immutability).
     */
    public function test_to_user_timezone_does_not_mutate_original(): void
    {
        App::instance('user_timezone', 'Asia/Seoul');

        $original = Carbon::parse('2025-01-15 00:00:00', 'UTC');
        TimezoneHelper::toUserTimezone($original);

        $this->assertEquals('UTC', $original->timezone->getName());
        $this->assertEquals(0, $original->hour);
    }

    /**
     * toUserCarbon에서 diffForHumans를 사용할 수 있는지 테스트합니다.
     */
    public function test_to_user_carbon_supports_diff_for_humans(): void
    {
        App::instance('user_timezone', 'Asia/Seoul');

        $utcDatetime = Carbon::now('UTC')->subHours(2);
        $result = TimezoneHelper::toUserCarbon($utcDatetime);

        $this->assertNotNull($result);
        $this->assertIsString($result->diffForHumans());
    }

    // ==================== getSiteTimezone ====================

    /**
     * getSiteTimezone이 config 값을 반환하는지 테스트합니다.
     */
    public function test_get_site_timezone_returns_config_value(): void
    {
        $expected = config('app.default_user_timezone');

        $this->assertEquals($expected, TimezoneHelper::getSiteTimezone());
    }

    // ==================== toSiteDateString ====================

    /**
     * toSiteDateString이 사이트 타임존 기준 Y-m-d를 반환하는지 테스트합니다.
     */
    public function test_to_site_date_string_converts_correctly(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        // UTC 15:00 → KST 다음날 00:00
        $utcDatetime = Carbon::parse('2025-01-15 15:00:00', 'UTC');
        $result = TimezoneHelper::toSiteDateString($utcDatetime);

        $this->assertEquals('2025-01-16', $result);
    }

    /**
     * toSiteDateString이 null에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_to_site_date_string_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::toSiteDateString(null));
    }

    // ==================== fromSiteDateStartOfDay ====================

    /**
     * fromSiteDateStartOfDay가 사이트 타임존 00:00:00을 UTC로 변환하는지 테스트합니다.
     */
    public function test_from_site_date_start_of_day_converts_to_utc(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $result = TimezoneHelper::fromSiteDateStartOfDay('2025-03-15');

        // 2025-03-15 00:00:00 KST = 2025-03-14 15:00:00 UTC
        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2025-03-14 15:00:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    /**
     * fromSiteDateStartOfDay가 null에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_from_site_date_start_of_day_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::fromSiteDateStartOfDay(null));
    }

    // ==================== fromSiteDateEndOfDay ====================

    /**
     * fromSiteDateEndOfDay가 사이트 타임존 23:59:59를 UTC로 변환하는지 테스트합니다.
     */
    public function test_from_site_date_end_of_day_converts_to_utc(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $result = TimezoneHelper::fromSiteDateEndOfDay('2025-03-20');

        // 2025-03-20 23:59:59 KST = 2025-03-20 14:59:59 UTC
        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2025-03-20 14:59:59', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    /**
     * fromSiteDateEndOfDay가 null에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_from_site_date_end_of_day_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::fromSiteDateEndOfDay(null));
    }

    // ==================== 왕복 변환 테스트 ====================

    /**
     * fromSiteDateStartOfDay → toSiteDateString 왕복 변환이 원래 날짜를 유지하는지 테스트합니다.
     */
    public function test_round_trip_start_of_day_preserves_date(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $originalDate = '2025-06-15';
        $utcCarbon = TimezoneHelper::fromSiteDateStartOfDay($originalDate);
        $result = TimezoneHelper::toSiteDateString($utcCarbon);

        $this->assertEquals($originalDate, $result);
    }

    /**
     * fromSiteDateEndOfDay → toSiteDateString 왕복 변환이 원래 날짜를 유지하는지 테스트합니다.
     */
    public function test_round_trip_end_of_day_preserves_date(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $originalDate = '2025-06-15';
        $utcCarbon = TimezoneHelper::fromSiteDateEndOfDay($originalDate);
        $result = TimezoneHelper::toSiteDateString($utcCarbon);

        $this->assertEquals($originalDate, $result);
    }

    // ==================== toSiteDateTimeLocalString ====================

    /**
     * toSiteDateTimeLocalString이 datetime-local 호환 형식을 반환하는지 테스트합니다.
     */
    public function test_to_site_date_time_local_string_converts_correctly(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        // UTC 15:00 → KST 다음날 00:00
        $utcDatetime = Carbon::parse('2025-01-15 15:00:00', 'UTC');
        $result = TimezoneHelper::toSiteDateTimeLocalString($utcDatetime);

        $this->assertEquals('2025-01-16T00:00', $result);
    }

    /**
     * toSiteDateTimeLocalString이 null에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_to_site_date_time_local_string_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::toSiteDateTimeLocalString(null));
    }

    // ==================== fromSiteDateTime ====================

    /**
     * fromSiteDateTime이 datetime-local 입력을 사이트 타임존 기준 UTC로 변환하는지 테스트합니다.
     */
    public function test_from_site_date_time_converts_to_utc(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        // 2025-03-15 09:30 KST = 2025-03-15 00:30:00 UTC
        $result = TimezoneHelper::fromSiteDateTime('2025-03-15T09:30');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2025-03-15 00:30:00', $result->format('Y-m-d H:i:s'));
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    /**
     * fromSiteDateTime이 null에 대해 null을 반환하는지 테스트합니다.
     */
    public function test_from_site_date_time_returns_null_for_null(): void
    {
        $this->assertNull(TimezoneHelper::fromSiteDateTime(null));
    }

    // ==================== datetime-local 왕복 변환 ====================

    /**
     * fromSiteDateTime → toSiteDateTimeLocalString 왕복 변환이 원래 값을 유지하는지 테스트합니다.
     */
    public function test_round_trip_date_time_local_preserves_value(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $original = '2025-06-15T14:30';
        $utcCarbon = TimezoneHelper::fromSiteDateTime($original);
        $result = TimezoneHelper::toSiteDateTimeLocalString($utcCarbon);

        $this->assertEquals($original, $result);
    }
}
