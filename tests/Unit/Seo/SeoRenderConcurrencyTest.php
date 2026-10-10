<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoCacheBounds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 미스 렌더의 동시성 상한을 검증합니다 (KVE-2026-2335 자기증폭 DoS).
 *
 * IP 당 분당 예산은 요청이 끝나야 차감이 보여 한꺼번에 몰아친 요청을 막지 못했고(동시 15건
 * 전량 MISS), 파일 캐시 카운터는 읽고-쓰기 경쟁으로 실제보다 적게 셌다(15건 뒤 6). 예산 판정·
 * 차감을 잠금 안에서 묶고, 사이트 전체 동시 렌더 슬롯을 둔다.
 */
class SeoRenderConcurrencyTest extends TestCase
{
    /**
     * 테스트 IP.
     */
    private const IP = '10.9.8.7';

    /**
     * 테스트 초기화 - 예산 카운터와 잠금 저장소를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('seo-render:'.self::IP);
    }

    /**
     * 예산 판정과 차감이 한 번에 일어나 한도를 넘겨 통과시키지 않고, 카운터가 정확하다.
     *
     * @effects render_budget_claim_is_atomic
     *
     * @return void 반환값 없음
     */
    public function test_claim_render_checks_and_counts_in_one_step(): void
    {
        config(['core.seo_cache_limits.render_misses_per_minute' => 3]);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = SeoCacheBounds::claimRender(self::IP);
        }

        $this->assertSame([true, true, true, false, false], $results);
        $this->assertSame(3, (int) RateLimiter::attempts('seo-render:'.self::IP));
    }

    /**
     * 같은 IP 의 판정 잠금을 다른 요청이 쥐고 있으면 기다린 뒤 렌더하지 않는다(판정을 건너뛰지 않는다).
     *
     * @effects render_budget_claim_is_atomic
     *
     * @return void 반환값 없음
     */
    public function test_claim_render_waits_on_the_ip_lock_instead_of_skipping_it(): void
    {
        $held = Cache::lock('seo-render-budget:'.self::IP, 10);
        $this->assertTrue($held->get());

        try {
            $this->assertFalse(SeoCacheBounds::claimRender(self::IP));
            $this->assertSame(0, (int) RateLimiter::attempts('seo-render:'.self::IP), '잠금 밖에서 차감하면 안 된다');
        } finally {
            $held->release();
        }

        $this->assertTrue(SeoCacheBounds::claimRender(self::IP));
    }

    /**
     * 슬롯이 모두 차면 null, 하나를 돌려주면 다시 얻을 수 있다.
     *
     * @effects concurrent_render_over_cap_gets_spa_bypass, render_slot_released_after_render
     *
     * @return void 반환값 없음
     */
    public function test_render_slots_are_capped_and_reusable(): void
    {
        config(['core.seo_cache_limits.max_concurrent_renders' => 2]);

        $first = SeoCacheBounds::acquireRenderSlot();
        $second = SeoCacheBounds::acquireRenderSlot();

        $this->assertInstanceOf(\Closure::class, $first);
        $this->assertInstanceOf(\Closure::class, $second);
        $this->assertNull(SeoCacheBounds::acquireRenderSlot(), '상한을 넘긴 세 번째 렌더');

        $first();

        $third = SeoCacheBounds::acquireRenderSlot();
        $this->assertInstanceOf(\Closure::class, $third, '반환된 슬롯은 다시 쓸 수 있다');

        $second();
        $third();
    }

    /**
     * 상한이 0 이하면 동시 렌더를 제한하지 않는다.
     *
     * @effects zero_cap_disables_concurrency_limit
     *
     * @return void 반환값 없음
     */
    public function test_zero_cap_disables_concurrency_limit(): void
    {
        config(['core.seo_cache_limits.max_concurrent_renders' => 0]);

        for ($i = 0; $i < 10; $i++) {
            $this->assertInstanceOf(\Closure::class, SeoCacheBounds::acquireRenderSlot());
        }
    }

    /**
     * 되돌림은 차감을 정확히 1 줄인다 (슬롯이 없어 렌더하지 못한 요청의 예산 복원).
     *
     * @effects slot_full_refunds_render_budget
     *
     * @return void 반환값 없음
     */
    public function test_refund_restores_exactly_one_claim(): void
    {
        $this->assertTrue(SeoCacheBounds::claimRender(self::IP));
        $this->assertTrue(SeoCacheBounds::claimRender(self::IP));

        SeoCacheBounds::refundRender(self::IP);

        $this->assertSame(1, (int) RateLimiter::attempts('seo-render:'.self::IP));
    }
}
