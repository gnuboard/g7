<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `seo_cache_stats.url` 확장 마이그레이션의 왕복(up → down → up) 안전성.
 *
 * 캐시 키 URL(경로 + 정규화 쿼리 최대 512바이트)이 255자 컬럼에 들어가지 않아 긴 주소의
 * 통계 기록이 조용히 실패하던 것을 768자로 넓힌 마이그레이션이다. 되돌릴 때는 잘리는 행을
 * 먼저 지워야 엄격 모드에서 ALTER 가 성공한다.
 */
class ModifyUrlInSeoCacheStatsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_08_000001_modify_url_in_seo_cache_stats_table.php';

    /**
     * up → down → up 왕복 뒤 컬럼 길이가 복원되고 url 인덱스가 보존된다.
     *
     * @scenario bot_state=bot, cache_state=hit, ip_budget=within, query_shape=normal, store_state=under_caps
     *
     * @effects stats_url_column_fits_normalized_cache_url
     */
    public function test_round_trip_restores_column_length_and_keeps_index(): void
    {
        $this->assertSame(768, $this->urlLength());

        Artisan::call('migrate:rollback', ['--path' => [self::MIGRATION], '--force' => true]);
        $this->assertSame(255, $this->urlLength());

        Artisan::call('migrate', ['--path' => [self::MIGRATION], '--force' => true]);
        $this->assertSame(768, $this->urlLength());

        $this->assertContains(
            'idx_seo_cache_stats_url',
            array_column(Schema::getIndexes('seo_cache_stats'), 'name'),
            'url 인덱스는 컬럼 변경 뒤에도 남아야 한다'
        );
    }

    /**
     * down 은 255자를 넘는 행을 먼저 지운다 — 엄격 모드에서는 잘리는 값이 하나라도 있으면
     * ALTER 자체가 실패해 되돌리기가 막힌다.
     *
     * @scenario bot_state=bot, cache_state=hit, ip_budget=within, query_shape=normal, store_state=under_caps
     *
     * @effects stats_url_column_fits_normalized_cache_url
     */
    public function test_down_removes_rows_that_would_not_fit_before_shrinking(): void
    {
        $long = '/shop?'.str_repeat('a=0123456789&', 30);

        $this->assertGreaterThan(255, strlen($long));

        DB::table('seo_cache_stats')->insert([
            ['url' => $long, 'locale' => 'ko', 'type' => 'hit'],
            ['url' => '/short', 'locale' => 'ko', 'type' => 'hit'],
        ]);

        try {
            Artisan::call('migrate:rollback', ['--path' => [self::MIGRATION], '--force' => true]);

            $this->assertSame(255, $this->urlLength());
            $this->assertDatabaseMissing('seo_cache_stats', ['url' => $long]);
            $this->assertDatabaseHas('seo_cache_stats', ['url' => '/short']);
        } finally {
            // DDL 이 테스트 트랜잭션을 암묵 커밋하므로 남는 행은 직접 치운다.
            Artisan::call('migrate', ['--path' => [self::MIGRATION], '--force' => true]);
            DB::table('seo_cache_stats')->where('url', '/short')->delete();
        }
    }

    /**
     * url 컬럼의 선언 길이를 읽습니다.
     *
     * @return int 문자 수 (varchar(N) 의 N)
     */
    private function urlLength(): int
    {
        foreach (Schema::getColumns('seo_cache_stats') as $column) {
            if ($column['name'] === 'url') {
                preg_match('/\((\d+)\)/', (string) $column['type'], $matches);

                return (int) ($matches[1] ?? 0);
            }
        }

        $this->fail('seo_cache_stats.url 컬럼이 없다');
    }
}
