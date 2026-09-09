<?php

namespace Tests\Unit\Seo;

use App\Seo\SeoCacheBounds;
use Tests\TestCase;

/**
 * SeoCacheBounds 단위 테스트
 *
 * 캐시 키 정규화(시스템 파라미터 제거·정렬·상한)와 저장 규모 상한을 검증한다.
 */
class SeoCacheBoundsTest extends TestCase
{
    /**
     * 시스템 파라미터는 캐시 키에서 빠진다 — 같은 페이지가 두 벌 저장되면 안 된다.
     *
     * @effects system_query_params_excluded_from_key
     */
    public function test_normalize_query_drops_system_parameters(): void
    {
        $this->assertSame(
            ['page' => '2'],
            SeoCacheBounds::normalizeQuery(['locale' => 'en', '_escaped_fragment_' => '', 'page' => '2'])
        );
    }

    /**
     * 같은 조합은 순서가 달라도 같은 키가 되어야 한다.
     *
     * @effects system_query_params_excluded_from_key
     */
    public function test_normalize_query_sorts_keys(): void
    {
        $this->assertSame(
            ['a' => '1', 'b' => '2', 'c' => '3'],
            SeoCacheBounds::normalizeQuery(['c' => '3', 'a' => '1', 'b' => '2'])
        );
    }

    /**
     * 파라미터 수 상한을 넘기면 캐시 불가(null)다.
     *
     * @effects oversized_query_bypasses_cache_and_render
     */
    public function test_normalize_query_returns_null_when_too_many_parameters(): void
    {
        config(['core.seo_cache_limits.max_query_params' => 10]);

        $query = [];
        for ($i = 0; $i < 11; $i++) {
            $query['p'.$i] = '1';
        }

        $this->assertNull(SeoCacheBounds::normalizeQuery($query));

        array_pop($query);
        $this->assertIsArray(SeoCacheBounds::normalizeQuery($query), '상한과 같은 개수는 허용된다');
    }

    /**
     * 정규화된 쿼리 문자열 길이 상한을 넘기면 캐시 불가다.
     *
     * @effects oversized_query_bypasses_cache_and_render
     */
    public function test_normalize_query_returns_null_when_query_string_too_long(): void
    {
        config(['core.seo_cache_limits.max_query_length' => 32]);

        $this->assertNull(SeoCacheBounds::normalizeQuery(['q' => str_repeat('x', 64)]));
        $this->assertIsArray(SeoCacheBounds::normalizeQuery(['q' => 'x']));
    }

    /**
     * 시스템 파라미터는 길이·개수 상한 계산에서도 빠진다.
     *
     * @effects system_query_params_excluded_from_key
     */
    public function test_normalize_query_excludes_system_parameters_from_limits(): void
    {
        config(['core.seo_cache_limits.max_query_params' => 1]);

        $this->assertSame(
            ['page' => '2'],
            SeoCacheBounds::normalizeQuery(['locale' => 'en', '_escaped_fragment_' => '', 'page' => '2'])
        );
    }

    /**
     * 같은 경로의 변종 수가 상한에 닿으면 새 URL 을 저장하지 않는다.
     *
     * @effects store_skips_write_at_path_variant_cap
     */
    public function test_can_store_rejects_new_variant_at_path_cap(): void
    {
        config([
            'core.seo_cache_limits.max_variants_per_path' => 3,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        $index = [
            'k1' => ['url' => '/shop?page=1', 'locale' => 'ko'],
            'k2' => ['url' => '/shop?page=2', 'locale' => 'ko'],
            'k3' => ['url' => '/shop?page=3', 'locale' => 'ko'],
        ];

        $this->assertFalse(SeoCacheBounds::canStore($index, '/shop?page=4', 'ko'));
        // 다른 경로는 자기 예산을 따로 쓴다
        $this->assertTrue(SeoCacheBounds::canStore($index, '/board?page=1', 'ko'));
    }

    /**
     * 전체 항목 수 상한에 닿으면 어떤 경로도 저장하지 않는다.
     *
     * @effects store_skips_write_at_global_cap
     */
    public function test_can_store_rejects_when_global_cap_reached(): void
    {
        config([
            'core.seo_cache_limits.max_entries' => 2,
            'core.seo_cache_limits.max_variants_per_path' => 50,
        ]);

        $index = [
            'k1' => ['url' => '/a', 'locale' => 'ko'],
            'k2' => ['url' => '/b', 'locale' => 'ko'],
        ];

        $this->assertFalse(SeoCacheBounds::canStore($index, '/c', 'ko'));
    }

    /**
     * 렌더 예산은 IP 단위로 소진된다.
     *
     * @effects bot_miss_over_limit_gets_spa_bypass
     */
    public function test_render_budget_is_per_ip(): void
    {
        config(['core.seo_cache_limits.render_misses_per_minute' => 2]);

        $this->assertTrue(SeoCacheBounds::renderAllowed('10.0.0.1'));

        SeoCacheBounds::recordRender('10.0.0.1');
        SeoCacheBounds::recordRender('10.0.0.1');

        $this->assertFalse(SeoCacheBounds::renderAllowed('10.0.0.1'));
        $this->assertTrue(SeoCacheBounds::renderAllowed('10.0.0.2'), '다른 IP 는 자기 예산을 쓴다');
    }

    /**
     * 통계 기록 예산도 IP 단위다 — 통계 테이블이 새 증식 축이 되지 않아야 한다.
     *
     * @effects stats_recording_capped_per_ip
     */
    public function test_stats_budget_is_per_ip(): void
    {
        config(['core.seo_cache_limits.stats_records_per_minute' => 1]);

        $this->assertTrue(SeoCacheBounds::statsAllowed('10.0.0.3'));

        SeoCacheBounds::recordStat('10.0.0.3');

        $this->assertFalse(SeoCacheBounds::statsAllowed('10.0.0.3'));
    }

    /**
     * 경로당 변종 상한은 언어별로 따로 센다 — 인덱스 항목은 url|locale 별이므로 경로만 보고
     * 합산하면 언어 수만큼 실효 상한이 줄어든다.
     *
     * @effects store_counts_path_variants_per_locale
     */
    public function test_can_store_counts_path_variants_per_locale(): void
    {
        config([
            'core.seo_cache_limits.max_variants_per_path' => 2,
            'core.seo_cache_limits.max_entries' => 20000,
        ]);

        $index = [
            'k1' => ['url' => '/shop?page=1', 'locale' => 'ko'],
            'k2' => ['url' => '/shop?page=2', 'locale' => 'ko'],
            'k3' => ['url' => '/shop?page=1', 'locale' => 'en'],
        ];

        $this->assertFalse(SeoCacheBounds::canStore($index, '/shop?page=3', 'ko'));
        $this->assertTrue(SeoCacheBounds::canStore($index, '/shop?page=2', 'en'), '다른 언어는 자기 예산을 따로 쓴다');
    }
}
