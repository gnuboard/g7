<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Traits;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * FormatsBoardDate Trait 단위 테스트
 *
 * formatCreatedAtFormat(): 표준형/유동형 표시용 포맷
 * formatCreatedAt(): 요일 포함 전체 날짜 포맷 (tooltip용)
 */
class FormatsBoardDateTest extends ModuleTestCase
{
    /**
     * Trait을 사용하는 테스트용 클래스
     */
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class {
            use FormatsBoardDate;

            public function callFormatCreatedAtFormat(mixed $dateTime, string $format = 'standard'): string
            {
                return $this->formatCreatedAtFormat($dateTime, $format);
            }

            public function callFormatCreatedAt(mixed $dateTime): string
            {
                return $this->formatCreatedAt($dateTime);
            }
        };
    }

    // =========================================================================
    // formatCreatedAtFormat() — 표준형 (standard)
    // =========================================================================

    #[Test]
    public function standard_format_returns_방금전_when_less_than_1_minute(): void
    {
        $dateTime = Carbon::now()->subSeconds(30);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'standard');

        $this->assertEquals('방금 전', $result);
    }

    #[Test]
    public function standard_format_returns_N분전_when_less_than_10_minutes(): void
    {
        $dateTime = Carbon::now()->subMinutes(5);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'standard');

        $this->assertEquals('5분 전', $result);
    }

    #[Test]
    public function standard_format_returns_rounded_10분단위_when_10_minutes_or_more(): void
    {
        $dateTime = Carbon::now()->subMinutes(21);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'standard');

        $this->assertEquals('20분 전', $result);
    }

    #[Test]
    public function standard_format_returns_N시간전_when_less_than_24_hours(): void
    {
        $dateTime = Carbon::now()->subHours(3);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'standard');

        $this->assertEquals('3시간 전', $result);
    }

    #[Test]
    public function standard_format_returns_MM_DD_when_same_year(): void
    {
        $dateTime = Carbon::now()->subDays(5);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'standard');

        $this->assertEquals($dateTime->format('m-d'), $result);
    }

    #[Test]
    public function standard_format_returns_YY_MM_DD_when_previous_year(): void
    {
        $dateTime = Carbon::now()->subYears(2);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'standard');

        $this->assertEquals($dateTime->format('y-m-d'), $result);
    }

    // =========================================================================
    // formatCreatedAtFormat() — 유동형 (relative)
    // =========================================================================

    #[Test]
    public function relative_format_returns_N일전_when_few_days_ago(): void
    {
        $dateTime = Carbon::now()->subDays(3);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'relative');

        $this->assertEquals('3일 전', $result);
    }

    #[Test]
    public function relative_format_returns_N개월전_when_months_ago(): void
    {
        $dateTime = Carbon::now()->subMonths(2);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'relative');

        $this->assertEquals('2개월 전', $result);
    }

    #[Test]
    public function relative_format_returns_N년전_when_years_ago(): void
    {
        $dateTime = Carbon::now()->subYears(3);

        $result = $this->subject->callFormatCreatedAtFormat($dateTime, 'relative');

        $this->assertEquals('3년 전', $result);
    }

    // =========================================================================
    // formatCreatedAtFormat() — 엣지 케이스
    // =========================================================================

    #[Test]
    public function format_returns_empty_string_when_null(): void
    {
        $result = $this->subject->callFormatCreatedAtFormat(null);

        $this->assertEquals('', $result);
    }

    #[Test]
    public function format_accepts_string_datetime(): void
    {
        $dateString = Carbon::now()->subDays(5)->format('Y-m-d H:i:s');

        $result = $this->subject->callFormatCreatedAtFormat($dateString, 'standard');

        $this->assertNotEmpty($result);
    }

    // =========================================================================
    // formatCreatedAt() — 요일 포함 전체 날짜 포맷 (tooltip용)
    // =========================================================================

    #[Test]
    public function created_at_returns_date_with_weekday_and_time(): void
    {
        // UTC 기준으로 Carbon 생성 후 결과 포맷만 검증 (타임존 변환은 TimezoneHelper가 처리)
        $dateTime = Carbon::now();

        $result = $this->subject->callFormatCreatedAt($dateTime);

        // YYYY-MM-DD 요일명 HH:MM 형식인지 검증
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} [가-힣]+요일 \d{2}:\d{2}$/', $result);
    }

    #[Test]
    public function created_at_returns_correct_weekday(): void
    {
        // 2026-03-22 = 일요일 (UTC+0 기준, 타임존 오프셋 무관하게 같은 날)
        $dateTime = Carbon::create(2026, 3, 22, 12, 0, 0, 'UTC');
        $userCarbon = TimezoneHelper::toUserCarbon($dateTime);
        $weekdays = ['일', '월', '화', '수', '목', '금', '토'];
        $expectedWeekday = $weekdays[$userCarbon->dayOfWeek].'요일';

        $result = $this->subject->callFormatCreatedAt($dateTime);

        $this->assertStringContainsString($expectedWeekday, $result);
    }

    #[Test]
    public function created_at_returns_empty_string_when_null(): void
    {
        $result = $this->subject->callFormatCreatedAt(null);

        $this->assertEquals('', $result);
    }

    #[Test]
    public function created_at_accepts_string_datetime(): void
    {
        $result = $this->subject->callFormatCreatedAt('2026-03-18 14:30:00');

        $this->assertStringContainsString('2026-03-18', $result);
        $this->assertStringContainsString('요일', $result);
    }

    #[Test]
    public function created_at_format_matches_expected_pattern(): void
    {
        $dateTime = Carbon::create(2026, 1, 5, 9, 5, 0); // 월요일

        $result = $this->subject->callFormatCreatedAt($dateTime);

        // "YYYY-MM-DD 요일명 HH:MM" 형식 검증
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} [가-힣]+요일 \d{2}:\d{2}$/', $result);
    }
}
