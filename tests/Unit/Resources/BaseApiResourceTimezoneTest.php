<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\BaseApiResource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class BaseApiResourceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 시딩 실행
        $this->seed();
    }

    /**
     * 테스트용 타임존을 설정합니다.
     * SetTimezone 미들웨어가 하는 것과 동일하게 App::instance()로 설정합니다.
     */
    protected function setTimezone(string $timezone): void
    {
        App::instance('user_timezone', $timezone);
    }

    /**
     * formatDateTimeForUser가 UTC를 사용자 timezone으로 변환하는지 테스트합니다.
     */
    public function test_format_datetime_for_user_converts_to_user_timezone(): void
    {
        // Asia/Seoul 타임존 설정
        $this->setTimezone('Asia/Seoul');

        // UTC 시간 생성
        $utcDateTime = Carbon::parse('2025-01-15 00:00:00', 'UTC');

        // 테스트용 Resource 생성
        $resource = new class(['created_at' => $utcDateTime]) extends BaseApiResource {
            public function testFormatDateTime($datetime)
            {
                return $this->formatDateTimeForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTime($utcDateTime);

        // ISO8601 형식이고 Asia/Seoul timezone이 적용되어야 함
        $this->assertStringContainsString('+09:00', $result);

        // 시간이 9시간 더해져야 함 (UTC 00:00 -> KST 09:00)
        $resultCarbon = Carbon::parse($result);
        $this->assertEquals(9, $resultCarbon->hour);
    }

    /**
     * formatDateTimeForUser가 null을 올바르게 처리하는지 테스트합니다.
     */
    public function test_format_datetime_for_user_handles_null(): void
    {
        $this->setTimezone('Asia/Seoul');

        $resource = new class([]) extends BaseApiResource {
            public function testFormatDateTime($datetime)
            {
                return $this->formatDateTimeForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTime(null);

        $this->assertNull($result);
    }

    /**
     * 미들웨어가 타임존을 설정하지 않은 경우 기본 timezone이 적용되는지 테스트합니다.
     */
    public function test_format_datetime_uses_default_timezone_when_not_set(): void
    {
        // 타임존을 설정하지 않음 (미들웨어가 실행되지 않은 상태)
        $utcDateTime = Carbon::parse('2025-01-15 00:00:00', 'UTC');

        $resource = new class(['created_at' => $utcDateTime]) extends BaseApiResource {
            public function testFormatDateTime($datetime)
            {
                return $this->formatDateTimeForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTime($utcDateTime);

        // 기본 timezone인 Asia/Seoul이 적용되어야 함
        $this->assertStringContainsString('+09:00', $result);
    }

    /**
     * America/New_York timezone 변환이 올바른지 테스트합니다.
     */
    public function test_format_datetime_for_new_york_timezone(): void
    {
        $this->setTimezone('America/New_York');

        // UTC 정오
        $utcDateTime = Carbon::parse('2025-01-15 17:00:00', 'UTC');

        $resource = new class(['created_at' => $utcDateTime]) extends BaseApiResource {
            public function testFormatDateTime($datetime)
            {
                return $this->formatDateTimeForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTime($utcDateTime);

        // America/New_York는 UTC-5이므로 12:00이어야 함
        $resultCarbon = Carbon::parse($result);
        $this->assertEquals(12, $resultCarbon->hour);
    }

    /**
     * formatDateTimeStringForUser가 Y-m-d H:i:s 형식으로 반환하는지 테스트합니다.
     */
    public function test_format_datetime_string_for_user_returns_ymd_his_format(): void
    {
        $this->setTimezone('Asia/Seoul');

        $utcDateTime = Carbon::parse('2025-01-15 00:00:00', 'UTC');

        $resource = new class(['created_at' => $utcDateTime]) extends BaseApiResource {
            public function testFormatDateTimeString($datetime)
            {
                return $this->formatDateTimeStringForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTimeString($utcDateTime);

        // Y-m-d H:i:s 형식이어야 함 (ISO8601이 아닌)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);

        // Asia/Seoul (UTC+9)이므로 09:00:00이어야 함
        $this->assertEquals('2025-01-15 09:00:00', $result);
    }

    /**
     * formatDateTimeStringForUser가 null을 올바르게 처리하는지 테스트합니다.
     */
    public function test_format_datetime_string_for_user_handles_null(): void
    {
        $this->setTimezone('Asia/Seoul');

        $resource = new class([]) extends BaseApiResource {
            public function testFormatDateTimeString($datetime)
            {
                return $this->formatDateTimeStringForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTimeString(null);

        $this->assertNull($result);
    }

    /**
     * formatDateTimeStringForUser가 다른 timezone에서도 올바르게 변환하는지 테스트합니다.
     */
    public function test_format_datetime_string_for_user_with_different_timezone(): void
    {
        $this->setTimezone('America/New_York');

        $utcDateTime = Carbon::parse('2025-01-15 17:00:00', 'UTC');

        $resource = new class(['created_at' => $utcDateTime]) extends BaseApiResource {
            public function testFormatDateTimeString($datetime)
            {
                return $this->formatDateTimeStringForUser($datetime);
            }
        };

        $result = $resource->testFormatDateTimeString($utcDateTime);

        // Y-m-d H:i:s 형식이어야 함
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);

        // America/New_York (UTC-5)이므로 12:00:00이어야 함
        $this->assertEquals('2025-01-15 12:00:00', $result);
    }

    /**
     * formatTimestamps가 created_at, updated_at을 변환하는지 테스트합니다.
     */
    public function test_format_timestamps_converts_both_fields(): void
    {
        $this->setTimezone('UTC');

        $createdAt = Carbon::parse('2025-01-15 10:00:00', 'UTC');
        $updatedAt = Carbon::parse('2025-01-15 15:00:00', 'UTC');

        $resource = new class([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]) extends BaseApiResource {
            public function testFormatTimestamps()
            {
                return $this->formatTimestamps();
            }
        };

        $result = $resource->testFormatTimestamps();

        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);
    }
}