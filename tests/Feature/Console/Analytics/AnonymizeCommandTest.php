<?php

namespace Tests\Feature\Console\Analytics;

use App\Enums\Analytics\ArchiveRunType;
use App\Extension\HookManager;
use App\Models\Analytics\AnalyticsArchiveRun;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 소급 익명화 — 이미 쌓인 기록을 되돌릴 수 없게 바꾸는 길.
 *
 * 실수로 돌면 복구할 수 없으므로 「무엇이 없으면 돌지 않는가」를 먼저 잠그고, 돌았을
 * 때 어느 칸이 어떻게 바뀌는지를 값으로 확인한다.
 */
class AnonymizeCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 표준시를 UTC 로 고정.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC', 'g7_settings.core.general.timezone' => 'UTC']);
    }

    /**
     * 주소와 회원 정보를 가진 방문을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return AnalyticsVisit 만들어진 방문
     */
    private function visit(array $attributes = []): AnalyticsVisit
    {
        return AnalyticsVisit::factory()->create(array_merge([
            'visit_date' => Carbon::now('UTC')->toDateString(),
            'ip' => '192.0.2.50',
            'ip_hash' => null,
            'user_id' => 42,
            'user_hash' => null,
            'referrer_url' => 'https://search.example.test/search?q=비밀검색어',
        ], $attributes));
    }

    /**
     * 마스킹이 저장된 주소를 실제로 깎는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects retro_masking_rewrites_stored_addresses, retro_anonymization_pseudonymizes_member_ids, retro_anonymization_reduces_referrers_to_their_domain
     */
    public function test_masking_rewrites_the_stored_address(): void
    {
        $visit = $this->visit();

        $this->artisan('analytics:anonymize', ['--mask-octets' => 2, '--force' => true])->assertSuccessful();

        $visit->refresh();
        $this->assertSame('192.0.0.0', (string) $visit->ip);
        $this->assertNull($visit->user_id, '회원 번호는 지우고 해시만 남는다');
        $this->assertNotNull($visit->user_hash);
        $this->assertSame('https://search.example.test', $visit->referrer_url, '검색어가 붙은 경로는 도메인까지만 남는다');
    }

    /**
     * 봇 기록·실패 로그의 주소에도 소급 적용이 걸리는지 확인합니다.
     *
     * 수집할 때는 세 표가 같은 마스킹 규칙을 따릅니다. 소급 적용이 방문 원장에만 걸리면
     * 운영자가 「기존 데이터도 소급 적용」을 눌러도 봇 기록·실패 로그에는 과거 주소가
     * 그대로 남습니다.
     *
     * @return void 없음
     *
     * @scenario target=bot_hits, row_age=within
     *
     * @effects retro_masking_reaches_bot_hits_and_failure_logs
     */
    public function test_masking_also_rewrites_bot_hits_and_failure_logs(): void
    {
        $this->visit();

        $botHit = AnalyticsBotHit::factory()->create([
            'ip' => '198.51.100.77',
            'occurred_at' => Carbon::now('UTC'),
        ]);

        $failure = AnalyticsCollectFailure::factory()->create([
            'ip' => '203.0.113.88',
            'occurred_at' => Carbon::now('UTC'),
        ]);

        $this->artisan('analytics:anonymize', ['--mask-octets' => 2, '--force' => true])->assertSuccessful();

        $this->assertSame('198.51.0.0', (string) $botHit->refresh()->ip, '봇 기록도 같은 옥텟까지 가려진다');
        $this->assertSame('203.0.0.0', (string) $failure->refresh()->ip, '실패 로그도 같은 옥텟까지 가려진다');
    }

    /**
     * 해시 모드에서 봇 기록·실패 로그의 주소가 지워지는지 확인합니다.
     *
     * 두 표에는 해시를 담을 칸이 없습니다 — 되돌릴 수 없게 만드는 것이 목적이므로
     * 담을 곳이 없으면 지웁니다.
     *
     * @return void 없음
     *
     * @scenario target=bot_hits, row_age=within
     *
     * @effects retro_masking_reaches_bot_hits_and_failure_logs
     */
    public function test_hashing_clears_addresses_in_bot_hits_and_failure_logs(): void
    {
        $this->visit();

        $botHit = AnalyticsBotHit::factory()->create([
            'ip' => '198.51.100.77',
            'occurred_at' => Carbon::now('UTC'),
        ]);

        $failure = AnalyticsCollectFailure::factory()->create([
            'ip' => '203.0.113.88',
            'occurred_at' => Carbon::now('UTC'),
        ]);

        $this->artisan('analytics:anonymize', ['--hash' => true, '--force' => true])->assertSuccessful();

        $this->assertNull($botHit->refresh()->ip, '봇 기록의 주소는 지워진다');
        $this->assertNull($failure->refresh()->ip, '실패 로그의 주소는 지워진다');
    }

    /**
     * 해시 모드가 주소를 지우고 해시만 남기는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects retro_hashing_clears_the_address_and_keeps_a_hash
     */
    public function test_hashing_clears_the_address(): void
    {
        $visit = $this->visit();

        $this->artisan('analytics:anonymize', ['--hash' => true, '--force' => true])->assertSuccessful();

        $visit->refresh();
        $this->assertNull($visit->ip);
        $this->assertNotNull($visit->ip_hash);
        $this->assertSame(64, strlen((string) $visit->ip_hash));
    }

    /**
     * 집계 표가 그대로 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=stats_hourly, row_age=within
     *
     * @effects retro_anonymization_leaves_aggregates_untouched
     */
    public function test_aggregates_are_left_untouched(): void
    {
        $this->visit();
        AnalyticsStatsDaily::factory()->create(['date' => Carbon::now('UTC')->toDateString(), 'visits' => 7]);

        $this->artisan('analytics:anonymize', ['--hash' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(7, (int) AnalyticsStatsDaily::query()->value('visits'), '집계에는 개인을 가리키는 값이 없다');
    }

    /**
     * 실행 이력이 남고 훅이 발화하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=archive_runs, row_age=within
     *
     * @effects retro_anonymization_records_progress_in_a_run
     */
    public function test_a_run_is_recorded_and_the_hook_fires(): void
    {
        $this->visit();

        $fired = [];
        HookManager::addAction('core.analytics.anonymized', function ($payload) use (&$fired): void {
            $fired[] = $payload;
        });

        $this->artisan('analytics:anonymize', ['--mask-octets' => 1, '--force' => true])->assertSuccessful();

        $run = AnalyticsArchiveRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(ArchiveRunType::Anonymize->value, $this->valueOf($run->run_type));
        $this->assertSame(1, (int) $run->processed_visits);
        $this->assertSame(100, (int) $run->progress_percent);
        $this->assertCount(1, $fired);
    }

    /**
     * 마스킹도 해시도 지정하지 않으면 돌지 않는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=visits, row_age=within
     *
     * @effects anonymize_command_requires_a_mask_or_hash_option
     */
    public function test_it_refuses_to_run_without_a_mask_or_hash(): void
    {
        $visit = $this->visit();

        $this->artisan('analytics:anonymize', ['--force' => true])->assertFailed();
        $this->artisan('analytics:anonymize', ['--mask-octets' => 9, '--force' => true])->assertFailed();

        $visit->refresh();
        $this->assertSame('192.0.2.50', (string) $visit->ip);
        $this->assertSame(0, AnalyticsArchiveRun::query()->count());
    }

    /**
     * Enum 이거나 문자열인 값에서 문자열을 꺼냅니다.
     *
     * @param  mixed  $value  대상 값
     * @return string 문자열 값
     */
    private function valueOf(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
