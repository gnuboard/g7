<?php

namespace Tests\Unit\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\BucketLabeler;
use PHPUnit\Framework\TestCase;

/**
 * 체류시간·본 페이지 수 구간 라벨 — 경계값이 어느 구간에 드는가.
 *
 * 경계 한 칸이 어긋나면 「10초 안에 떠난 방문」이 옆 구간으로 새어 분포가 조용히 틀린다.
 */
class BucketLabelerTest extends TestCase
{
    /** 기본 체류 경계 (초) */
    private const DURATION = [10, 30, 60, 180, 600, 1800];

    /** 기본 페이지 수 경계 */
    private const PAGES = [1, 2, 3, 4, 5, 7, 10, 14, 20];

    /**
     * 체류 구간은 (하한, 상한] — 첫 구간만 [0, 상한]이고 마지막 경계를 넘으면 열린 구간이다.
     *
     * @scenario bucket=duration
     *
     * @effects duration_bucket_boundaries
     *
     * @return void 없음
     */
    public function test_duration_boundaries(): void
    {
        $this->assertSame('0-10', BucketLabeler::duration(0, self::DURATION));
        $this->assertSame('0-10', BucketLabeler::duration(10, self::DURATION));
        $this->assertSame('10-30', BucketLabeler::duration(11, self::DURATION));
        $this->assertSame('600-1800', BucketLabeler::duration(1800, self::DURATION));
        $this->assertSame('1800+', BucketLabeler::duration(1801, self::DURATION));
        $this->assertSame('0-10', BucketLabeler::duration(-5, self::DURATION), '음수는 0 으로');
    }

    /**
     * 페이지 구간은 하한·상한을 모두 포함하고, 한 숫자 구간은 한 숫자 라벨이다.
     *
     * @scenario bucket=pages
     *
     * @effects pages_bucket_boundaries
     *
     * @return void 없음
     */
    public function test_pages_boundaries(): void
    {
        $this->assertSame('1', BucketLabeler::pages(1, self::PAGES));
        $this->assertSame('5', BucketLabeler::pages(5, self::PAGES));
        $this->assertSame('6-7', BucketLabeler::pages(6, self::PAGES));
        $this->assertSame('6-7', BucketLabeler::pages(7, self::PAGES));
        $this->assertSame('15-20', BucketLabeler::pages(20, self::PAGES));
        $this->assertSame('21+', BucketLabeler::pages(21, self::PAGES));
    }

    /**
     * 정렬용 하한과 라벨 풀이가 세 모양(한 숫자·범위·열린 구간)을 모두 읽는다.
     *
     * @scenario bucket=parse
     *
     * @effects bucket_labels_parse_back
     *
     * @return void 없음
     */
    public function test_lower_bound_and_parse(): void
    {
        $this->assertSame(60, BucketLabeler::lowerBound('60-180'));
        $this->assertSame(1800, BucketLabeler::lowerBound('1800+'));
        $this->assertSame(PHP_INT_MAX, BucketLabeler::lowerBound('weird'));
        $this->assertSame([6, 7], BucketLabeler::parse('6-7'));
        $this->assertSame([21, null], BucketLabeler::parse('21+'));
        $this->assertSame([3, 3], BucketLabeler::parse('3'));
        $this->assertNull(BucketLabeler::parse('x'));
    }

    /**
     * 설정 값 풀이는 순서가 깨진 값을 기본값으로 돌린다.
     *
     * @scenario bucket=config
     *
     * @effects broken_bounds_fall_back_to_default
     *
     * @return void 없음
     */
    public function test_parse_bounds_falls_back_on_broken_values(): void
    {
        $this->assertSame([5, 20], AnalyticsConfig::parseBounds('5,20', AnalyticsConfig::DEFAULT_DURATION_BUCKET_BOUNDS));
        $this->assertSame(self::DURATION, AnalyticsConfig::parseBounds('30,10', AnalyticsConfig::DEFAULT_DURATION_BUCKET_BOUNDS));
        $this->assertSame(self::PAGES, AnalyticsConfig::parseBounds('x', AnalyticsConfig::DEFAULT_PAGES_BUCKET_BOUNDS));
    }
}
