<?php

namespace Tests\Unit\Enums;

use App\Enums\SitemapChangeFreq;
use Tests\TestCase;

/**
 * SitemapChangeFreq Enum 테스트
 *
 * sitemaps.org 폐쇄 어휘(7값)와 normalize() 정규화/차단 동작을 검증합니다.
 */
class SitemapChangeFreqTest extends TestCase
{
    /**
     * sitemaps.org 의 7개 값이 모두 존재해야 한다.
     */
    public function test_cases_cover_sitemaps_org_vocabulary(): void
    {
        $values = array_map(fn (SitemapChangeFreq $c) => $c->value, SitemapChangeFreq::cases());

        $this->assertSame(
            ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'],
            $values
        );
    }

    /**
     * 유효한 값은 그대로 정규화된다.
     */
    public function test_normalize_returns_valid_value(): void
    {
        $this->assertSame('daily', SitemapChangeFreq::normalize('daily'));
        $this->assertSame('monthly', SitemapChangeFreq::normalize('monthly'));
    }

    /**
     * 대소문자·앞뒤 공백은 흡수한다.
     */
    public function test_normalize_absorbs_case_and_whitespace(): void
    {
        $this->assertSame('weekly', SitemapChangeFreq::normalize('WEEKLY'));
        $this->assertSame('weekly', SitemapChangeFreq::normalize('  Weekly  '));
    }

    /**
     * 폐쇄 어휘에 없는 값·빈 값·null 은 null 로 떨어진다 (XML 유입 차단).
     */
    public function test_normalize_rejects_invalid_and_empty(): void
    {
        $this->assertNull(SitemapChangeFreq::normalize('biweekly'));
        $this->assertNull(SitemapChangeFreq::normalize('yes'));
        $this->assertNull(SitemapChangeFreq::normalize(''));
        $this->assertNull(SitemapChangeFreq::normalize('   '));
        $this->assertNull(SitemapChangeFreq::normalize(null));
    }
}
