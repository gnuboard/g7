<?php

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Concerns;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Plugins\Sirsoft\PayKginicis\Concerns\ValidatesTimestampFreshness;

/**
 * Signature replay 방어용 timestamp 신선도 검증 트레이트 단위 테스트.
 *
 * 검증 범위:
 *  - 14자리 yyyyMMddHHmmss (CBT 표준)
 *  - 13자리 epoch ms (PC/모바일 표준)
 *  - ±300초 윈도우 내부는 fresh, 이외는 stale
 *  - 잘못된 포맷은 거부
 */
class ValidatesTimestampFreshnessTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new class {
            use ValidatesTimestampFreshness {
                isTimestampFresh as public;
            }
        };
    }

    public function test_current_yyyy_mm_dd_hh_mm_ss_is_fresh(): void
    {
        $ts = Carbon::now()->format('YmdHis');
        $this->assertTrue($this->subject->isTimestampFresh($ts));
    }

    public function test_current_epoch_ms_is_fresh(): void
    {
        $ts = (string) (Carbon::now()->getTimestamp() * 1000);
        $this->assertTrue($this->subject->isTimestampFresh($ts));
    }

    public function test_5_minutes_ago_is_at_window_boundary(): void
    {
        // 정확히 300초 — 경계값. 라운딩 흡수 위해 299s 사용
        $ts = Carbon::now()->subSeconds(299)->format('YmdHis');
        $this->assertTrue($this->subject->isTimestampFresh($ts));
    }

    public function test_6_minutes_ago_is_stale(): void
    {
        $ts = Carbon::now()->subSeconds(360)->format('YmdHis');
        $this->assertFalse($this->subject->isTimestampFresh($ts));
    }

    public function test_1_hour_ago_yyyy_mm_dd_hh_mm_ss_is_stale(): void
    {
        $ts = Carbon::now()->subHour()->format('YmdHis');
        $this->assertFalse($this->subject->isTimestampFresh($ts));
    }

    public function test_1_hour_ago_epoch_ms_is_stale(): void
    {
        $ts = (string) ((Carbon::now()->getTimestamp() - 3600) * 1000);
        $this->assertFalse($this->subject->isTimestampFresh($ts));
    }

    public function test_future_timestamp_beyond_window_is_stale(): void
    {
        $ts = Carbon::now()->addSeconds(600)->format('YmdHis');
        $this->assertFalse($this->subject->isTimestampFresh($ts));
    }

    public function test_future_within_window_is_fresh(): void
    {
        $ts = Carbon::now()->addSeconds(60)->format('YmdHis');
        $this->assertTrue($this->subject->isTimestampFresh($ts));
    }

    public function test_empty_string_is_stale(): void
    {
        $this->assertFalse($this->subject->isTimestampFresh(''));
    }

    public function test_non_numeric_is_stale(): void
    {
        $this->assertFalse($this->subject->isTimestampFresh('abc'));
    }

    public function test_invalid_yyyy_mm_dd_hh_mm_ss_is_stale(): void
    {
        // 14자리이지만 잘못된 날짜 (월 13)
        $this->assertFalse($this->subject->isTimestampFresh('20261399000000'));
    }

    public function test_short_numeric_is_stale(): void
    {
        // 12자리 — 13/14자리 어느 포맷도 아님
        $this->assertFalse($this->subject->isTimestampFresh('123456789012'));
    }

    public function test_15_digits_is_stale(): void
    {
        $this->assertFalse($this->subject->isTimestampFresh('123456789012345'));
    }

    /**
     * 회귀: 클라이언트가 Asia/Seoul 로컬 시각으로 14자리 타임스탬프를 생성하고
     *       서버가 UTC 로 동작할 때 freshness 가 올바르게 판정되어야 한다.
     *
     * 기존 코드는 Carbon::createFromFormat('YmdHis', $ts) 가 default timezone
     * (= app.timezone, 보통 UTC) 으로 파싱하여 KST 클라이언트 → 9시간 차이
     * → 항상 stale 로 잘못 판정하던 버그.
     *
     * 수정 후: 14자리 포맷은 명시적으로 Asia/Seoul 로 파싱하여 gnuboard5
     * date('YmdHis') 컨벤션과 정합.
     */
    public function test_current_kst_timestamp_is_fresh_when_app_is_utc(): void
    {
        // 프로덕션 Laravel 은 app.timezone=UTC 로 date_default_timezone_set('UTC') 를
        // 호출. PHP CLI 기본 TZ 가 다를 수 있어 명시적으로 UTC 강제하여 환경 시뮬레이션.
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            // 클라이언트가 KST 로 생성한 14자리 timestamp
            $kstNow = Carbon::now('UTC')->setTimezone('Asia/Seoul')->format('YmdHis');

            $this->assertTrue(
                $this->subject->isTimestampFresh($kstNow),
                'KST timestamp from client must be valid on UTC server (gnuboard5 date(YmdHis) 컨벤션)'
            );
        } finally {
            date_default_timezone_set($originalTz);
        }
    }
}
