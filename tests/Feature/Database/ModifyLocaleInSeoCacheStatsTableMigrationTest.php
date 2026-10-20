<?php

namespace Tests\Feature\Database;

use App\Seo\SeoCacheStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `seo_cache_stats.locale` 확장 마이그레이션(5 → 20자) 테스트 (#148).
 *
 * 다섯 글자보다 긴 언어(`zh-Hant-TW`)의 검색엔진 캐시 통계는 기록이 엄격 모드에서 실패하고
 * 서비스가 예외를 삼켜 조용히 빠졌다.
 */
class ModifyLocaleInSeoCacheStatsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_19_000002_modify_locale_in_seo_cache_stats_table.php';

    /**
     * 긴 지역 표기 언어의 캐시 히트도 통계에 남는다.
     *
     * @scenario shape=region_tagged
     *
     * @effects seo_stats_locale_column_holds_region_tag
     *
     * @return void 반환값 없음
     */
    public function test_region_tagged_locale_hit_is_recorded(): void
    {
        $this->assertSame(20, $this->localeLength());

        app(SeoCacheStatsService::class)->recordHit('/x', 'zh-Hant-TW');

        $this->assertDatabaseHas('seo_cache_stats', ['url' => '/x', 'locale' => 'zh-Hant-TW', 'type' => 'hit']);
    }

    /**
     * up → down → up 왕복 — down 은 다섯 글자를 넘는 통계 행(파생 데이터)을 지운 뒤 칸을 줄인다.
     *
     * @scenario shape=over_length
     *
     * @effects seo_stats_locale_column_holds_region_tag
     *
     * @return void 반환값 없음
     */
    public function test_round_trip_removes_long_rows_before_shrinking(): void
    {
        DB::table('seo_cache_stats')->insert([
            ['url' => '/long', 'locale' => 'zh-Hant-TW', 'type' => 'hit'],
            ['url' => '/short', 'locale' => 'ko', 'type' => 'hit'],
        ]);

        try {
            Artisan::call('migrate:rollback', ['--path' => [self::MIGRATION], '--force' => true]);

            $this->assertSame(5, $this->localeLength());
            $this->assertDatabaseMissing('seo_cache_stats', ['url' => '/long']);
            $this->assertDatabaseHas('seo_cache_stats', ['url' => '/short']);
        } finally {
            // DDL 이 테스트 트랜잭션을 암묵 커밋하므로 남는 행은 직접 치운다.
            Artisan::call('migrate', ['--path' => [self::MIGRATION], '--force' => true]);
            DB::table('seo_cache_stats')->whereIn('url', ['/long', '/short'])->delete();
        }

        $this->assertSame(20, $this->localeLength());
    }

    /**
     * locale 컬럼의 선언 길이를 읽습니다.
     *
     * @return int 문자 수 (varchar(N) 의 N)
     */
    private function localeLength(): int
    {
        foreach (Schema::getColumns('seo_cache_stats') as $column) {
            if ($column['name'] === 'locale') {
                preg_match('/\((\d+)\)/', (string) $column['type'], $matches);

                return (int) ($matches[1] ?? 0);
            }
        }

        $this->fail('seo_cache_stats.locale 컬럼이 없다');
    }
}
