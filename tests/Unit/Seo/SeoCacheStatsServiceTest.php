<?php

namespace Tests\Unit\Seo;

use App\Models\SeoCacheStat;
use App\Seo\SeoCacheStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SeoCacheStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private SeoCacheStatsService $statsService;

    /**
     * 테스트 초기화 - SeoCacheStatsService 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->statsService = new SeoCacheStatsService;
    }

    /**
     * recordHit이 type='hit'인 레코드를 생성합니다.
     */
    public function test_record_hit_creates_hit_record(): void
    {
        $this->statsService->recordHit('/products/1', 'ko', 'shop/show', 'sirsoft-ecommerce');

        $this->assertDatabaseHas('seo_cache_stats', [
            'url' => '/products/1',
            'locale' => 'ko',
            'layout_name' => 'shop/show',
            'module_identifier' => 'sirsoft-ecommerce',
            'type' => 'hit',
            'response_time_ms' => null,
        ]);
    }

    /**
     * recordMiss가 type='miss'이고 response_time_ms를 포함한 레코드를 생성합니다.
     */
    public function test_record_miss_creates_miss_record_with_response_time(): void
    {
        $this->statsService->recordMiss('/products/1', 'ko', 'shop/show', 'sirsoft-ecommerce', 150);

        $this->assertDatabaseHas('seo_cache_stats', [
            'url' => '/products/1',
            'locale' => 'ko',
            'layout_name' => 'shop/show',
            'module_identifier' => 'sirsoft-ecommerce',
            'type' => 'miss',
            'response_time_ms' => 150,
        ]);
    }

    /**
     * recordHit에서 예외 발생 시 Log::warning으로 처리됩니다.
     */
    public function test_record_hit_handles_exception_gracefully(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, '캐시 히트 기록 실패')
                    && $context['url'] === '/products/1';
            });

        // 테이블을 삭제하여 DB 예외를 강제 발생
        Schema::drop('seo_cache_stats');

        // 예외가 전파되지 않음을 확인
        $this->statsService->recordHit('/products/1', 'ko');
    }

    /**
     * recordMiss에서 예외 발생 시 Log::warning으로 처리됩니다.
     */
    public function test_record_miss_handles_exception_gracefully(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, '캐시 미스 기록 실패')
                    && $context['url'] === '/products/1';
            });

        // 테이블을 삭제하여 DB 예외를 강제 발생
        Schema::drop('seo_cache_stats');

        // 예외가 전파되지 않음을 확인
        $this->statsService->recordMiss('/products/1', 'ko', null, null, 200);
    }

    /**
     * 데이터가 없을 때 getStats가 기본값을 반환합니다.
     */
    public function test_get_stats_returns_defaults_with_no_data(): void
    {
        $stats = $this->statsService->getStats();

        $this->assertSame(0, $stats['total_entries']);
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0.0, $stats['hit_rate']);
        $this->assertNull($stats['avg_response_time_ms']);
    }

    /**
     * getStats가 올바른 히트율을 계산합니다.
     */
    public function test_get_stats_returns_correct_hit_rate(): void
    {
        // 3개 히트, 2개 미스 = 60% 히트율
        SeoCacheStat::create(['url' => '/p/1', 'locale' => 'ko', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/2', 'locale' => 'ko', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/3', 'locale' => 'ko', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/4', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 100]);
        SeoCacheStat::create(['url' => '/p/5', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 200]);

        $stats = $this->statsService->getStats();

        $this->assertSame(5, $stats['total_entries']);
        $this->assertSame(3, $stats['hits']);
        $this->assertSame(2, $stats['misses']);
        $this->assertSame(60.0, $stats['hit_rate']);
    }

    /**
     * getStats가 since 파라미터로 기간 필터링합니다.
     */
    public function test_get_stats_filters_by_since_date(): void
    {
        // 오래된 레코드 (5일 전)
        $old = SeoCacheStat::create(['url' => '/old', 'locale' => 'ko', 'type' => 'hit']);
        $old->created_at = Carbon::now()->subDays(5);
        $old->save();

        // 최근 레코드 (1일 전)
        $recent = SeoCacheStat::create(['url' => '/recent', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 100]);
        $recent->created_at = Carbon::now()->subDays(1);
        $recent->save();

        // 3일 이내 데이터만 조회
        $stats = $this->statsService->getStats(Carbon::now()->subDays(3));

        $this->assertSame(1, $stats['total_entries']);
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
    }

    /**
     * getStats가 미스 레코드의 평균 응답 시간을 계산합니다.
     */
    public function test_get_stats_calculates_avg_response_time_from_misses_only(): void
    {
        // 히트 (response_time_ms 없음)
        SeoCacheStat::create(['url' => '/p/1', 'locale' => 'ko', 'type' => 'hit']);

        // 미스 (response_time_ms 있음) - 평균 = (100 + 300) / 2 = 200
        SeoCacheStat::create(['url' => '/p/2', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 100]);
        SeoCacheStat::create(['url' => '/p/3', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 300]);

        // 미스이지만 response_time_ms가 null
        SeoCacheStat::create(['url' => '/p/4', 'locale' => 'ko', 'type' => 'miss']);

        $stats = $this->statsService->getStats();

        $this->assertSame(4, $stats['total_entries']);
        $this->assertSame(200.0, $stats['avg_response_time_ms']);
    }

    /**
     * getStatsByLayout이 레이아웃별로 통계를 그룹화합니다.
     */
    public function test_get_stats_by_layout_groups_by_layout_name(): void
    {
        // shop/show 레이아웃: 2히트, 1미스
        SeoCacheStat::create(['url' => '/p/1', 'locale' => 'ko', 'layout_name' => 'shop/show', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/2', 'locale' => 'ko', 'layout_name' => 'shop/show', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/3', 'locale' => 'ko', 'layout_name' => 'shop/show', 'type' => 'miss', 'response_time_ms' => 150]);

        // shop/category 레이아웃: 1히트, 1미스
        SeoCacheStat::create(['url' => '/c/1', 'locale' => 'ko', 'layout_name' => 'shop/category', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/c/2', 'locale' => 'ko', 'layout_name' => 'shop/category', 'type' => 'miss', 'response_time_ms' => 200]);

        $result = $this->statsService->getStatsByLayout();

        $this->assertCount(2, $result);

        // layout_name 기준으로 정렬하여 검증
        $byLayout = collect($result)->keyBy('layout_name');

        $showStats = $byLayout['shop/show'];
        $this->assertSame(3, $showStats['total']);
        $this->assertSame(2, $showStats['hits']);
        $this->assertSame(1, $showStats['misses']);
        $this->assertSame(66.67, $showStats['hit_rate']);
        $this->assertSame(150.0, $showStats['avg_response_time_ms']);

        $categoryStats = $byLayout['shop/category'];
        $this->assertSame(2, $categoryStats['total']);
        $this->assertSame(1, $categoryStats['hits']);
        $this->assertSame(1, $categoryStats['misses']);
        $this->assertSame(50.0, $categoryStats['hit_rate']);
        $this->assertSame(200.0, $categoryStats['avg_response_time_ms']);
    }

    /**
     * getStatsByModule이 모듈별로 통계를 그룹화합니다.
     */
    public function test_get_stats_by_module_groups_by_module_identifier(): void
    {
        // sirsoft-ecommerce 모듈: 3히트, 1미스
        SeoCacheStat::create(['url' => '/p/1', 'locale' => 'ko', 'module_identifier' => 'sirsoft-ecommerce', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/2', 'locale' => 'ko', 'module_identifier' => 'sirsoft-ecommerce', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/3', 'locale' => 'ko', 'module_identifier' => 'sirsoft-ecommerce', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/p/4', 'locale' => 'ko', 'module_identifier' => 'sirsoft-ecommerce', 'type' => 'miss', 'response_time_ms' => 250]);

        // sirsoft-blog 모듈: 1히트, 2미스
        SeoCacheStat::create(['url' => '/b/1', 'locale' => 'ko', 'module_identifier' => 'sirsoft-blog', 'type' => 'hit']);
        SeoCacheStat::create(['url' => '/b/2', 'locale' => 'ko', 'module_identifier' => 'sirsoft-blog', 'type' => 'miss', 'response_time_ms' => 100]);
        SeoCacheStat::create(['url' => '/b/3', 'locale' => 'ko', 'module_identifier' => 'sirsoft-blog', 'type' => 'miss', 'response_time_ms' => 300]);

        $result = $this->statsService->getStatsByModule();

        $this->assertCount(2, $result);

        $byModule = collect($result)->keyBy('module_identifier');

        $ecommerceStats = $byModule['sirsoft-ecommerce'];
        $this->assertSame(4, $ecommerceStats['total']);
        $this->assertSame(3, $ecommerceStats['hits']);
        $this->assertSame(1, $ecommerceStats['misses']);
        $this->assertSame(75.0, $ecommerceStats['hit_rate']);
        $this->assertSame(250.0, $ecommerceStats['avg_response_time_ms']);

        $blogStats = $byModule['sirsoft-blog'];
        $this->assertSame(3, $blogStats['total']);
        $this->assertSame(1, $blogStats['hits']);
        $this->assertSame(2, $blogStats['misses']);
        $this->assertSame(33.33, $blogStats['hit_rate']);
        $this->assertSame(200.0, $blogStats['avg_response_time_ms']);
    }

    /**
     * cleanup이 오래된 레코드를 삭제하고 최근 레코드를 유지합니다.
     */
    public function test_cleanup_deletes_old_records_and_keeps_recent(): void
    {
        // 오래된 레코드 (40일 전)
        $old1 = SeoCacheStat::create(['url' => '/old/1', 'locale' => 'ko', 'type' => 'hit']);
        $old1->created_at = Carbon::now()->subDays(40);
        $old1->save();

        $old2 = SeoCacheStat::create(['url' => '/old/2', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 100]);
        $old2->created_at = Carbon::now()->subDays(35);
        $old2->save();

        // 최근 레코드 (10일 전)
        $recent1 = SeoCacheStat::create(['url' => '/recent/1', 'locale' => 'ko', 'type' => 'hit']);
        $recent1->created_at = Carbon::now()->subDays(10);
        $recent1->save();

        // 오늘 레코드
        SeoCacheStat::create(['url' => '/today/1', 'locale' => 'ko', 'type' => 'miss', 'response_time_ms' => 200]);

        $deleted = $this->statsService->cleanup(30);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseCount('seo_cache_stats', 2);
        $this->assertDatabaseMissing('seo_cache_stats', ['url' => '/old/1']);
        $this->assertDatabaseMissing('seo_cache_stats', ['url' => '/old/2']);
        $this->assertDatabaseHas('seo_cache_stats', ['url' => '/recent/1']);
        $this->assertDatabaseHas('seo_cache_stats', ['url' => '/today/1']);
    }
}
